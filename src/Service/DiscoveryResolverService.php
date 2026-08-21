<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DiscoveryCache;
use App\Repository\DiscoveryCacheRepository;
use App\Service\Discovery\DiscoveryBudgetExhaustedException;
use App\Service\Discovery\DiscoverySourceException;
use App\Service\Discovery\SeriesResolutionPipeline;

/**
 * External discovery resolver (ADR-060): pooled, anonymous resolution of
 * series membership. Owns the cache orchestration and the serve-time
 * language filtering; the resolution internals live in
 * Discovery\SeriesResolutionPipeline (section 3.7 discipline), the HTTP
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
        $wanted = [];
        foreach ($langs as $lang) {
            $lower = strtolower($lang);
            $wanted[$lower] = true;
            $base = strstr($lower, '-', true);
            if (is_string($base) && $base !== '') {
                $wanted[$base] = true;
            }
        }

        $volumes = [];
        foreach ($payload['volumes'] ?? [] as $volume) {
            if (!is_array($volume)) {
                continue;
            }
            $all = is_array($volume['editions'] ?? null) ? $volume['editions'] : [];
            $originalLang = is_string($volume['original_lang'] ?? null) ? strtolower($volume['original_lang']) : null;

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

            $volumes[] = [
                'ordinal' => $volume['ordinal'] ?? null,
                'title' => $volume['title'] ?? '',
                'authors' => is_array($volume['authors'] ?? null) ? $volume['authors'] : [],
                'year' => $volume['year'] ?? null,
                'editions' => $kept,
                'other_langs_exist' => count($all) > count($kept),
            ];
        }

        return [
            'source' => $payload['source'] ?? null,
            'source_id' => $payload['source_id'] ?? null,
            'label' => $payload['label'] ?? '',
            'volumes' => $volumes,
        ];
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
