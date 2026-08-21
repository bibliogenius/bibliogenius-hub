<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DiscoveryCache;
use App\Repository\DiscoveryCacheRepository;
use App\Service\Discovery\AuthorResolutionPipeline;
use App\Service\Discovery\DiscoveryBudgetExhaustedException;
use App\Service\Discovery\DiscoverySourceException;
use App\Service\Discovery\Labels;
use App\Service\Discovery\SeriesResolutionPipeline;

/**
 * External discovery resolver (ADR-060): pooled, anonymous resolution of
 * series membership and author bibliographies. Owns the cache
 * orchestration and the serve-time language filtering; the resolution
 * internals live in Discovery\SeriesResolutionPipeline and
 * Discovery\AuthorResolutionPipeline (section 3.7 discipline), the HTTP
 * validation in DiscoveryController.
 *
 * Callable internally (not only through /api/discovery): the curated
 * selections backoffice can verify its ISBNs on this same plumbing.
 *
 * Every failure mode converges on an envelope, never an exception:
 * 'resolved' | 'ambiguous' | 'unknown' | 'unavailable'. The client treats
 * everything but 'resolved' as "show nothing".
 */
class DiscoveryResolverService
{
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_AMBIGUOUS = 'ambiguous';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * Cover URLs are emitted only when https and on one of the sources' own
     * cover hosts: the S5 rule extended to hub output, so the hub cannot
     * become a URL-injection vector into clients.
     */
    private const COVER_HOST_ALLOWLIST = [
        'inventaire.io',
        'commons.wikimedia.org',
        'upload.wikimedia.org',
        'covers.openlibrary.org',
    ];

    public function __construct(
        private readonly DiscoveryCacheRepository $cache,
        private readonly SeriesResolutionPipeline $pipeline,
        private readonly HubEventLogger $eventLogger,
        private readonly AuthorResolutionPipeline $authorPipeline,
    ) {
    }

    /**
     * Resolve the series identified by 1..3 already-validated anchor
     * ISBN-13s, with an optional opaque name as tiebreaker, and filter
     * editions for the reader's language codes at serve time.
     *
     * @param list<string> $isbn13s
     * @param list<string> $langs
     *
     * @return array<string, mixed> the response envelope
     */
    public function resolveSeries(array $isbn13s, ?string $name, array $langs): array
    {
        try {
            $candidateSets = [];
            foreach ($isbn13s as $isbn13) {
                $candidateSets[] = $this->anchorSeriesUris($isbn13);
            }

            // Intersect the non-empty candidate sets: an anchor missing from
            // the sources contributes nothing rather than vetoing the rest
            // (the recall half of the 3-anchor decision).
            $nonEmpty = array_values(array_filter($candidateSets, static fn (array $s): bool => $s !== []));
            if ($nonEmpty === []) {
                $this->eventLogger->warning('discovery', 'series_unknown', [
                    'name' => $isbn13s[0] ?? '',
                    'reason' => 'no_anchor_resolved',
                ]);

                return ['status' => self::STATUS_UNKNOWN];
            }
            $candidates = array_shift($nonEmpty);
            foreach ($nonEmpty as $set) {
                $candidates = array_values(array_intersect($candidates, $set));
            }
            if ($candidates === []) {
                // Anchors resolving to disjoint series contradict each other:
                // ambiguous, show nothing (precision before breadth).
                $this->eventLogger->warning('discovery', 'series_ambiguous', [
                    'name' => $isbn13s[0] ?? '',
                    'reason' => 'disjoint_anchors',
                ]);

                return ['status' => self::STATUS_AMBIGUOUS];
            }

            $seriesUri = count($candidates) === 1
                ? $candidates[0]
                : $this->pickByName($candidates, $name);
            if ($seriesUri === null) {
                $this->eventLogger->warning('discovery', 'series_ambiguous', [
                    'name' => $isbn13s[0] ?? '',
                    'reason' => 'no_clear_winner',
                    'count' => count($candidates),
                ]);

                return ['status' => self::STATUS_AMBIGUOUS];
            }

            $payload = $this->seriesPayload($seriesUri);
        } catch (DiscoveryBudgetExhaustedException) {
            $this->eventLogger->error('discovery', 'series_unavailable', [
                'reason' => 'outbound_budget_exhausted',
            ]);

            return ['status' => self::STATUS_UNAVAILABLE];
        } catch (DiscoverySourceException $e) {
            $this->eventLogger->error('discovery', 'series_unavailable', [
                'reason' => substr($e->getMessage(), 0, 100),
            ]);

            return ['status' => self::STATUS_UNAVAILABLE];
        }

        if ($payload === null) {
            return ['status' => self::STATUS_UNKNOWN];
        }

        return [
            'status' => self::STATUS_RESOLVED,
            'series' => $this->filterForLangs($payload, $langs),
        ];
    }

    /**
     * Resolve the author identified by [name], anchored by 1..3
     * already-validated ISBN-13s of books the reader owns, and filter
     * editions for the reader's language codes at serve time.
     *
     * Anchor first, name verified (ADR-060 section 3.2): an anchor gives
     * the author ENTITY, and that entity's label must be the name the
     * client asked about. Homonymy is where author completion humiliates
     * itself, so a mismatch on every anchor shows nothing rather than
     * someone else's bibliography.
     *
     * @param list<string> $isbn13s
     * @param list<string> $langs
     *
     * @return array<string, mixed> the response envelope
     */
    public function resolveAuthor(string $name, array $isbn13s, array $langs): array
    {
        try {
            $anyCandidate = false;
            $verified = null;
            foreach ($isbn13s as $isbn13) {
                $candidates = $this->anchorAuthorCandidates($isbn13);
                if ($candidates === []) {
                    continue;
                }
                $anyCandidate = true;
                $matching = array_values(array_filter(
                    $candidates,
                    static fn (array $c): bool => Labels::sameName($c['label'], $name),
                ));
                // Exactly one match is the only clear answer. Several
                // entities carrying the same name are duplicates we cannot
                // tell apart, so the next anchor gets its chance and an
                // unresolved sweep ends as ambiguous.
                if (count($matching) === 1) {
                    $verified = $matching[0];
                    break;
                }
            }

            if ($verified === null) {
                $status = $anyCandidate ? self::STATUS_AMBIGUOUS : self::STATUS_UNKNOWN;
                $this->eventLogger->warning('discovery', 'author_' . $status, [
                    'name' => $isbn13s[0] ?? '',
                    'reason' => $anyCandidate ? 'name_not_verified' : 'no_anchor_resolved',
                ]);

                return ['status' => $status];
            }

            $payload = $this->authorPayload($verified['uri'], $verified['label']);
        } catch (DiscoveryBudgetExhaustedException) {
            $this->eventLogger->error('discovery', 'author_unavailable', [
                'reason' => 'outbound_budget_exhausted',
            ]);

            return ['status' => self::STATUS_UNAVAILABLE];
        } catch (DiscoverySourceException $e) {
            $this->eventLogger->error('discovery', 'author_unavailable', [
                'reason' => substr($e->getMessage(), 0, 100),
            ]);

            return ['status' => self::STATUS_UNAVAILABLE];
        }

        if ($payload === null) {
            return ['status' => self::STATUS_UNKNOWN];
        }

        return [
            'status' => self::STATUS_RESOLVED,
            'author' => $this->filterAuthorForLangs($payload, $langs),
        ];
    }

    /**
     * Candidate series URIs for one anchor, through the lookup-level cache.
     *
     * @return list<string>
     */
    private function anchorSeriesUris(string $isbn13): array
    {
        $row = $this->cache->findFresh(DiscoveryCache::KIND_SERIES_LOOKUP, $isbn13);
        if ($row !== null) {
            $uris = $row['payload']['series_uris'] ?? null;

            return is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];
        }

        $uris = $this->pipeline->resolveAnchor($isbn13);
        if ($uris === []) {
            $this->cache->put(DiscoveryCache::KIND_SERIES_LOOKUP, $isbn13, DiscoveryCache::STATUS_UNKNOWN, null, null);
        } else {
            $this->cache->put(
                DiscoveryCache::KIND_SERIES_LOOKUP,
                $isbn13,
                DiscoveryCache::STATUS_RESOLVED,
                ['series_uris' => $uris],
                null,
            );
        }

        return $uris;
    }

    /**
     * Entity-level payload through the pooled cache: the second user asking
     * about the same series through a different volume costs one lookup
     * row, not a re-resolution.
     *
     * @return array<string, mixed>|null
     */
    private function seriesPayload(string $seriesUri): ?array
    {
        $row = $this->cache->findFresh(DiscoveryCache::KIND_SERIES, $seriesUri);
        if ($row !== null) {
            return $row['status'] === DiscoveryCache::STATUS_RESOLVED && is_array($row['payload'])
                ? $row['payload']
                : null;
        }

        $payload = $this->pipeline->resolveSeriesEntity($seriesUri);
        if ($payload === null) {
            $this->cache->put(DiscoveryCache::KIND_SERIES, $seriesUri, DiscoveryCache::STATUS_UNKNOWN, null, null);
            $this->eventLogger->warning('discovery', 'series_unknown', [
                'name' => $seriesUri,
                'reason' => 'no_usable_members',
            ]);

            return null;
        }
        $this->cache->put(
            DiscoveryCache::KIND_SERIES,
            $seriesUri,
            DiscoveryCache::STATUS_RESOLVED,
            $payload,
            is_string($payload['source'] ?? null) ? $payload['source'] : null,
        );

        return $payload;
    }

    /**
     * Candidate author entities (uri and label) for one anchor, through
     * the lookup-level cache. The label is cached with the uri so a warm
     * anchor verifies the requested name without any outbound call.
     *
     * @return list<array{uri: string, label: string}>
     */
    private function anchorAuthorCandidates(string $isbn13): array
    {
        $row = $this->cache->findFresh(DiscoveryCache::KIND_AUTHOR_LOOKUP, $isbn13);
        if ($row !== null) {
            $cached = $row['payload']['authors'] ?? null;
            if (!is_array($cached)) {
                return [];
            }
            $candidates = [];
            foreach ($cached as $entry) {
                if (is_array($entry) && is_string($entry['uri'] ?? null) && is_string($entry['label'] ?? null)) {
                    $candidates[] = ['uri' => $entry['uri'], 'label' => $entry['label']];
                }
            }

            return $candidates;
        }

        $candidates = $this->authorPipeline->resolveAnchor($isbn13);
        if ($candidates === []) {
            $this->cache->put(DiscoveryCache::KIND_AUTHOR_LOOKUP, $isbn13, DiscoveryCache::STATUS_UNKNOWN, null, null);
        } else {
            $this->cache->put(
                DiscoveryCache::KIND_AUTHOR_LOOKUP,
                $isbn13,
                DiscoveryCache::STATUS_RESOLVED,
                ['authors' => $candidates],
                null,
            );
        }

        return $candidates;
    }

    /**
     * Entity-level bibliography through the pooled cache: every reader who
     * likes this author shares one resolution per 30 days, whichever of
     * their books anchored it.
     *
     * @return array<string, mixed>|null
     */
    private function authorPayload(string $authorUri, string $label): ?array
    {
        $row = $this->cache->findFresh(DiscoveryCache::KIND_AUTHOR, $authorUri);
        if ($row !== null) {
            return $row['status'] === DiscoveryCache::STATUS_RESOLVED && is_array($row['payload'])
                ? $row['payload']
                : null;
        }

        $payload = $this->authorPipeline->resolveAuthorEntity($authorUri, $label);
        if ($payload === null) {
            $this->cache->put(DiscoveryCache::KIND_AUTHOR, $authorUri, DiscoveryCache::STATUS_UNKNOWN, null, null);
            $this->eventLogger->warning('discovery', 'author_unknown', [
                'name' => $authorUri,
                'reason' => 'no_usable_works',
            ]);

            return null;
        }
        $this->cache->put(
            DiscoveryCache::KIND_AUTHOR,
            $authorUri,
            DiscoveryCache::STATUS_RESOLVED,
            $payload,
            is_string($payload['source'] ?? null) ? $payload['source'] : null,
        );

        return $payload;
    }

    /**
     * Name tiebreaker between several legitimate candidates (a cycle and an
     * integrale): unique normalized-label equality wins, then unique
     * containment either way; anything less clear is ambiguous.
     *
     * @param list<string> $candidates
     */
    private function pickByName(array $candidates, ?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $target = SeriesResolutionPipeline::normalizeLabel($name);
        if ($target === '') {
            return null;
        }

        $labels = $this->pipeline->entityLabels($candidates);
        $exact = [];
        $containing = [];
        foreach ($candidates as $uri) {
            $label = SeriesResolutionPipeline::normalizeLabel($labels[$uri] ?? '');
            if ($label === '') {
                continue;
            }
            if ($label === $target) {
                $exact[] = $uri;
            } elseif (str_contains($label, $target) || str_contains($target, $label)) {
                $containing[] = $uri;
            }
        }
        if (count($exact) === 1) {
            return $exact[0];
        }
        if ($exact === [] && count($containing) === 1) {
            return $containing[0];
        }

        return null;
    }

    /**
     * Serve-time language filtering of the language-neutral payload
     * (ADR-060 section 2, hybrid decision): editions in the reader's
     * languages PLUS the original-language edition, always present when
     * known, with other_langs_exist saying whether the neutral cache holds
     * more. Cover URLs are sanitized here, the single output choke point.
     *
     * @param array<string, mixed> $payload
     * @param list<string>         $langs
     *
     * @return array<string, mixed>
     */
    private function filterForLangs(array $payload, array $langs): array
    {
        $wanted = self::wantedLangs($langs);

        $volumes = [];
        foreach ($payload['volumes'] ?? [] as $volume) {
            if (!is_array($volume)) {
                continue;
            }
            [$kept, $otherLangsExist] = $this->keptEditions($volume, $wanted);
            $volumes[] = [
                'ordinal' => $volume['ordinal'] ?? null,
                'title' => $volume['title'] ?? '',
                'authors' => is_array($volume['authors'] ?? null) ? $volume['authors'] : [],
                'year' => $volume['year'] ?? null,
                'editions' => $kept,
                'other_langs_exist' => $otherLangsExist,
            ];
        }

        return [
            'source' => $payload['source'] ?? null,
            'source_id' => $payload['source_id'] ?? null,
            'label' => $payload['label'] ?? '',
            'volumes' => $volumes,
        ];
    }

    /**
     * Same serve-time filtering for the author lane, over works instead of
     * volumes: a work carries its popularity proxy (editions_count) and no
     * ordinal, and the ranking is the hub's, so the client can cap by
     * popularity without a second source call.
     *
     * @param array<string, mixed> $payload
     * @param list<string>         $langs
     *
     * @return array<string, mixed>
     */
    private function filterAuthorForLangs(array $payload, array $langs): array
    {
        $wanted = self::wantedLangs($langs);

        $works = [];
        foreach ($payload['works'] ?? [] as $work) {
            if (!is_array($work)) {
                continue;
            }
            [$kept, $otherLangsExist] = $this->keptEditions($work, $wanted);
            [$title, $titles] = self::titlesForLangs($work, $langs);
            $works[] = [
                'title' => $title,
                'titles' => $titles,
                'authors' => is_array($work['authors'] ?? null) ? $work['authors'] : [],
                'year' => $work['year'] ?? null,
                'editions_count' => $work['editions_count'] ?? null,
                'editions' => $kept,
                'other_langs_exist' => $otherLangsExist,
            ];
        }

        return [
            'source' => $payload['source'] ?? null,
            'source_id' => $payload['source_id'] ?? null,
            'label' => $payload['label'] ?? '',
            'works' => $works,
        ];
    }

    /**
     * Display title and matching titles of one work, from the neutral
     * label map (ADR-060 section 2 applied to titles, as it already is to
     * editions).
     *
     * The display title is the reader's own language when the sources know
     * it, so a French reader reads "L'Etranger" and not "The Stranger".
     * The alternates matter more: the client drops a work whose normalized
     * title and author already exist in the library, and a library is
     * catalogued in the reader's language while the sources answer in
     * theirs. Without the alternates that half of the membrane silently
     * matches nothing, which on the author lane means offering people the
     * translation of a book they own.
     *
     * @param array<string, mixed> $work
     * @param list<string>         $langs
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function titlesForLangs(array $work, array $langs): array
    {
        $labels = is_array($work['labels'] ?? null) ? $work['labels'] : [];
        $neutral = is_string($work['title'] ?? null) ? $work['title'] : '';

        $titles = [];
        foreach ($langs as $lang) {
            $lower = strtolower($lang);
            $base = strstr($lower, '-', true);
            foreach ([$lower, is_string($base) ? $base : null] as $code) {
                if ($code !== null && isset($labels[$code]) && is_string($labels[$code])) {
                    $titles[$labels[$code]] = true;
                }
            }
        }
        $display = $titles === [] ? $neutral : (string) array_key_first($titles);
        if ($neutral !== '') {
            $titles[$neutral] = true;
        }

        return [$display, array_values(array_map('strval', array_keys($titles)))];
    }

    /**
     * Reader language codes as a lookup set, each entry also matched on its
     * base subtag ("pt-BR" accepts a "pt" edition).
     *
     * @param list<string> $langs
     *
     * @return array<string, true>
     */
    private static function wantedLangs(array $langs): array
    {
        $wanted = [];
        foreach ($langs as $lang) {
            $lower = strtolower($lang);
            $wanted[$lower] = true;
            $base = strstr($lower, '-', true);
            if (is_string($base) && $base !== '') {
                $wanted[$base] = true;
            }
        }

        return $wanted;
    }

    /**
     * Editions of one volume or work kept for this reader: those in the
     * requested languages PLUS the original-language one, always included
     * when known, deduplicated by ISBN and with their cover URLs
     * sanitized. The second return value says whether the neutral cache
     * holds editions this reader does not see.
     *
     * @param array<string, mixed> $item
     * @param array<string, true>  $wanted
     *
     * @return array{0: list<array{isbn: string, lang: ?string, cover_url: ?string}>, 1: bool}
     */
    private function keptEditions(array $item, array $wanted): array
    {
        $all = is_array($item['editions'] ?? null) ? $item['editions'] : [];
        $originalLang = is_string($item['original_lang'] ?? null) ? strtolower($item['original_lang']) : null;

        $kept = [];
        $keptIsbns = [];
        foreach ($all as $edition) {
            if (!is_array($edition) || !is_string($edition['isbn'] ?? null)) {
                continue;
            }
            $lang = is_string($edition['lang'] ?? null) ? strtolower($edition['lang']) : null;
            $isReaderLang = $lang !== null && ($wanted === [] || isset($wanted[$lang]));
            $isOriginal = $lang !== null && $lang === $originalLang;
            if (!$isReaderLang && !$isOriginal) {
                continue;
            }
            if (isset($keptIsbns[$edition['isbn']])) {
                continue;
            }
            $keptIsbns[$edition['isbn']] = true;
            $kept[] = [
                'isbn' => $edition['isbn'],
                'lang' => $lang,
                'cover_url' => $this->sanitizedCoverUrl($edition['cover_url'] ?? null),
            ];
        }

        return [$kept, count($all) > count($kept)];
    }

    private function sanitizedCoverUrl(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');

        return in_array($host, self::COVER_HOST_ALLOWLIST, true) ? $url : null;
    }
}
