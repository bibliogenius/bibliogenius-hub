<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use function Symfony\Component\String\u;

/**
 * Series resolution internals (ADR-060 section 3.2), deliberately separate
 * from the controller and the cache (section 3.7 discipline: this is the
 * part a future openbook-resolver crate would replace).
 *
 * Pipeline: anchor ISBN -> edition -> work -> series claim (P179), then
 * series -> ordered member list (Wikidata SPARQL as notable-reach primary,
 * Inventaire as FR long-tail primary, sequential with early exit), then
 * language-neutral edition candidates per volume (Inventaire, the edition
 * source in every case).
 *
 * Everything returned here is language-neutral: serve-time filtering per
 * reader languages happens in DiscoveryResolverService.
 */
class SeriesResolutionPipeline
{
    /**
     * A "series" past this size is a franchise or a catalogue artifact, not
     * a reading series; gap arithmetic on it would be noise. Treated as
     * unknown (negative-cached), and the drift monitoring surfaces it.
     */
    public const MAX_VOLUMES = 40;

    /** Bound the neutral payload: up to this many editions per language. */
    private const MAX_EDITIONS_PER_LANG = 2;

    /** Bound the neutral payload: hard cap on editions per volume. */
    private const MAX_EDITIONS_PER_VOLUME = 24;

    public function __construct(
        private readonly InventaireClient $inventaire,
        private readonly WikidataClient $wikidata,
    ) {
    }

    /**
     * Candidate series URIs for one anchor ISBN: edition -> work(s) ->
     * series claim(s). Empty when the anchor resolves to no series.
     *
     * @return list<string>
     */
    public function resolveAnchor(string $isbn13): array
    {
        $editionUri = 'isbn:' . $isbn13;
        $entities = $this->inventaire->entitiesByUris([$editionUri]);
        $edition = $entities[$editionUri] ?? null;
        if (!is_array($edition)) {
            return [];
        }
        $workUris = self::claimValues($edition, 'wdt:P629');
        if ($workUris === []) {
            return [];
        }

        $works = $this->inventaire->entitiesByUris($workUris);
        $seriesUris = [];
        foreach ($works as $work) {
            if (is_array($work)) {
                $seriesUris = array_merge($seriesUris, self::claimValues($work, 'wdt:P179'));
            }
        }

        return array_values(array_unique($seriesUris));
    }

    /**
     * Full language-neutral payload for a resolved series URI, or null when
     * the series has no usable members (unknown, negative-cached by the
     * caller).
     *
     * @return array{source: string, source_id: string, label: string, volumes: list<array<string, mixed>>}|null
     */
    public function resolveSeriesEntity(string $seriesUri): ?array
    {
        $label = $this->entityLabel($seriesUri) ?? '';

        [$members, $membersSource] = $this->memberList($seriesUri);
        if ($members === []) {
            return null;
        }
        if (count($members) > self::MAX_VOLUMES) {
            return null;
        }

        // Ordinals are the source's truth and the card's payload ("volume N
        // of a series you own"): a volume without an integer ordinal cannot
        // carry its reason and is not returned (precision before breadth).
        $volumes = [];
        foreach ($members as $member) {
            $ordinal = self::integerOrdinal($member['ordinal']);
            if ($ordinal === null) {
                continue;
            }
            $volumes[] = [
                'ordinal' => $ordinal,
                'title' => $member['label'] ?? '',
                'authors' => $member['authors'],
                'year' => $member['year'],
                'original_lang' => $member['original_lang'],
                'work_uri' => $member['work_uri'],
                'editions' => [],
            ];
        }
        if ($volumes === []) {
            return null;
        }
        usort($volumes, static fn (array $a, array $b): int => $a['ordinal'] <=> $b['ordinal']);

        $this->attachEditions($volumes);
        foreach ($volumes as &$volume) {
            unset($volume['work_uri']);
        }
        unset($volume);

        [$source, $sourceId] = self::splitUri($seriesUri, $membersSource);

        return [
            'source' => $source,
            'source_id' => $sourceId,
            'label' => $label,
            'volumes' => $volumes,
        ];
    }

    /**
     * Normalized label form shared by the name tiebreaker: lowercase, fold
     * diacritics, keep alphanumeric words joined by single spaces.
     */
    public static function normalizeLabel(string $s): string
    {
        $folded = u($s)->ascii()->lower()->toString();
        $words = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', $words ?: []);
    }

    /**
     * Member works with ordinals: Wikidata SPARQL first for 'wd:' series
     * (notable reach), Inventaire reverse-claims as fallback and for
     * Inventaire-native series. A primary-source transport failure falls
     * through to the secondary; the budget exception always propagates.
     *
     * @return array{0: list<array{work_uri: string, label: ?string, ordinal: ?string, year: ?int, authors: list<string>, original_lang: ?string}>, 1: string}
     */
    private function memberList(string $seriesUri): array
    {
        if (str_starts_with($seriesUri, 'wd:')) {
            try {
                $rows = $this->wikidata->seriesMembers(substr($seriesUri, 3));
                if ($rows !== []) {
                    $members = [];
                    foreach ($rows as $row) {
                        $members[] = [
                            'work_uri' => 'wd:' . $row['qid'],
                            'label' => $row['label'],
                            'ordinal' => $row['ordinal'],
                            'year' => $row['year'],
                            'authors' => $row['authors'],
                            'original_lang' => $row['original_lang'],
                        ];
                    }

                    return [$members, 'wikidata'];
                }
            } catch (DiscoverySourceException) {
                // Sequential fallback: Inventaire may still answer.
            }
        }

        return [$this->inventaireMembers($seriesUri), 'inventaire'];
    }

    /**
     * @return list<array{work_uri: string, label: ?string, ordinal: ?string, year: ?int, authors: list<string>, original_lang: ?string}>
     */
    private function inventaireMembers(string $seriesUri): array
    {
        $workUris = $this->inventaire->reverseClaims('wdt:P179', $seriesUri);
        if ($workUris === []) {
            return [];
        }
        $works = $this->inventaire->entitiesByUris($workUris);

        // Batch-resolve author and language entities once for all works.
        $authorUris = [];
        $langUris = [];
        foreach ($works as $work) {
            if (!is_array($work)) {
                continue;
            }
            $authorUris = array_merge($authorUris, self::claimValues($work, 'wdt:P50'));
            $langUris = array_merge($langUris, self::claimValues($work, 'wdt:P407'));
        }
        $authorLabels = $this->entityLabels(array_unique($authorUris));
        $langCodes = $this->languageCodes(array_unique($langUris));

        $members = [];
        foreach ($workUris as $workUri) {
            $work = $works[$workUri] ?? null;
            if (!is_array($work)) {
                continue;
            }
            $authors = [];
            foreach (self::claimValues($work, 'wdt:P50') as $authorUri) {
                if (isset($authorLabels[$authorUri])) {
                    $authors[] = $authorLabels[$authorUri];
                }
            }
            $dates = self::claimValues($work, 'wdt:P577');
            $langs = self::claimValues($work, 'wdt:P407');
            $ordinals = self::claimValues($work, 'wdt:P1545');
            $members[] = [
                'work_uri' => $workUri,
                'label' => self::pickLabel($work),
                'ordinal' => $ordinals[0] ?? null,
                'year' => isset($dates[0]) && preg_match('/^(\d{4})/', $dates[0], $m) === 1 ? (int) $m[1] : null,
                'authors' => $authors,
                'original_lang' => isset($langs[0]) ? ($langCodes[$langs[0]] ?? null) : null,
            ];
        }

        return $members;
    }

    /**
     * Fetch and attach language-neutral edition candidates (ISBN, language
     * code, cover URL) per volume. Editions without a checksum-valid ISBN
     * are useless to the client and skipped; editions are bounded per
     * language and per volume to keep the pooled payload small.
     *
     * @param list<array<string, mixed>> $volumes modified in place
     */
    private function attachEditions(array &$volumes): void
    {
        $editionUrisByVolume = [];
        $allEditionUris = [];
        foreach ($volumes as $i => $volume) {
            $uris = $this->inventaire->reverseClaims('wdt:P629', $volume['work_uri']);
            $editionUrisByVolume[$i] = $uris;
            $allEditionUris = array_merge($allEditionUris, $uris);
        }
        if ($allEditionUris === []) {
            return;
        }

        // An edition claimed by several works of the same series is an
        // omnibus or a box set: the sources link it to every work it
        // contains. It is an edition of none of them individually, and
        // offering it as "the missing volume N" would have the client
        // import a box set under one volume's title. Dropped before the
        // fetch, so it costs no outbound call either.
        $shared = [];
        foreach (array_count_values($allEditionUris) as $uri => $count) {
            if ($count > 1) {
                $shared[$uri] = true;
            }
        }
        if ($shared !== []) {
            foreach ($editionUrisByVolume as $i => $uris) {
                $editionUrisByVolume[$i] = array_values(
                    array_filter($uris, static fn (string $u): bool => !isset($shared[$u])),
                );
            }
            $allEditionUris = array_filter(
                $allEditionUris,
                static fn (string $u): bool => !isset($shared[$u]),
            );
            if ($allEditionUris === []) {
                return;
            }
        }

        $editions = $this->inventaire->entitiesByUris(array_unique($allEditionUris));

        $langUris = [];
        foreach ($editions as $edition) {
            if (is_array($edition)) {
                $langUris = array_merge($langUris, self::claimValues($edition, 'wdt:P407'));
            }
        }
        $langCodes = $this->languageCodes(array_unique($langUris));

        foreach ($volumes as $i => &$volume) {
            $perLang = [];
            $kept = [];
            foreach ($editionUrisByVolume[$i] as $editionUri) {
                if (count($kept) >= self::MAX_EDITIONS_PER_VOLUME) {
                    break;
                }
                $edition = $editions[$editionUri] ?? null;
                if (!is_array($edition)) {
                    continue;
                }
                $isbnValues = self::claimValues($edition, 'wdt:P212');
                $isbn13 = isset($isbnValues[0]) ? Isbn::toIsbn13($isbnValues[0]) : null;
                if ($isbn13 === null) {
                    continue;
                }
                $editionLangs = self::claimValues($edition, 'wdt:P407');
                $lang = isset($editionLangs[0]) ? ($langCodes[$editionLangs[0]] ?? null) : null;
                $langKey = $lang ?? '?';
                $perLang[$langKey] = ($perLang[$langKey] ?? 0) + 1;
                if ($perLang[$langKey] > self::MAX_EDITIONS_PER_LANG) {
                    continue;
                }
                $imageHashes = self::claimValues($edition, 'invp:P2');
                $kept[] = [
                    'isbn' => $isbn13,
                    'lang' => $lang,
                    'cover_url' => isset($imageHashes[0])
                        ? 'https://inventaire.io/img/entities/' . $imageHashes[0]
                        : null,
                ];
            }
            $volume['editions'] = $kept;
        }
        unset($volume);
    }

    /** Label of a single entity (en, then fr, then any), or null. */
    private function entityLabel(string $uri): ?string
    {
        $entities = $this->inventaire->entitiesByUris([$uri]);
        $entity = $entities[$uri] ?? null;

        return is_array($entity) ? self::pickLabel($entity) : null;
    }

    /**
     * Labels for a set of entity URIs (used by the resolver's name
     * tiebreaker as well as the Inventaire member path).
     *
     * @param list<string> $uris
     *
     * @return array<string, string> uri => label
     */
    public function entityLabels(array $uris): array
    {
        if ($uris === []) {
            return [];
        }
        $labels = [];
        foreach ($this->inventaire->entitiesByUris($uris) as $uri => $entity) {
            if (is_array($entity)) {
                $label = self::pickLabel($entity);
                if ($label !== null) {
                    $labels[$uri] = $label;
                }
            }
        }

        return $labels;
    }

    /**
     * ISO 639-1 codes for language entities (claim P218).
     *
     * @param list<string> $uris
     *
     * @return array<string, string> uri => code
     */
    private function languageCodes(array $uris): array
    {
        if ($uris === []) {
            return [];
        }
        $codes = [];
        foreach ($this->inventaire->entitiesByUris($uris) as $uri => $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $values = self::claimValues($entity, 'wdt:P218');
            if (isset($values[0])) {
                $codes[$uri] = strtolower($values[0]);
            }
        }

        return $codes;
    }

    /**
     * @param array<string, mixed> $entity
     *
     * @return list<string>
     */
    private static function claimValues(array $entity, string $property): array
    {
        $values = $entity['claims'][$property] ?? [];
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, 'is_string'));
    }

    /** @param array<string, mixed> $entity */
    private static function pickLabel(array $entity): ?string
    {
        $labels = $entity['labels'] ?? [];
        if (!is_array($labels) || $labels === []) {
            return null;
        }
        // 'mul' is Wikidata's multilingual code: a label identical across
        // Latin-script languages now lives there ALONE, the per-language
        // ones having been dropped. Without it, entities as central as the
        // Harry Potter series fall through to the arbitrary first label
        // below, which is whatever language sorts first.
        foreach (['en', 'fr', 'mul'] as $lang) {
            if (isset($labels[$lang]) && is_string($labels[$lang])) {
                return $labels[$lang];
            }
        }
        $first = reset($labels);

        return is_string($first) ? $first : null;
    }

    /** Source ordinals like "3" are kept; "2.5" or "III" are not integers. */
    private static function integerOrdinal(?string $raw): ?int
    {
        if ($raw === null || preg_match('/^\d+$/', trim($raw)) !== 1) {
            return null;
        }

        return (int) trim($raw);
    }

    /**
     * @return array{0: string, 1: string} [source label, bare source id]
     */
    private static function splitUri(string $uri, string $membersSource): array
    {
        $bare = str_contains($uri, ':') ? substr($uri, (int) strpos($uri, ':') + 1) : $uri;

        return [$membersSource, $bare];
    }
}
