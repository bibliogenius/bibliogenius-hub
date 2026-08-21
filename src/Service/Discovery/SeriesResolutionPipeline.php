<?php

declare(strict_types=1);

namespace App\Service\Discovery;

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

    public function __construct(
        private readonly InventaireClient $inventaire,
        private readonly WikidataClient $wikidata,
        private readonly EntityLookup $entities,
        private readonly EditionResolver $editions,
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

        $this->editions->attachTo($volumes);
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
     * Normalized label form used by the name tiebreaker: lowercase, fold
     * diacritics, keep alphanumeric words joined by single spaces. The
     * implementation lives in Labels, shared with the author lane; this
     * entry point stays because the resolver tiebreaker reads it here.
     */
    public static function normalizeLabel(string $s): string
    {
        return Labels::normalize($s);
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
            $langs = self::claimValues($work, 'wdt:P407');
            $ordinals = self::claimValues($work, 'wdt:P1545');
            $members[] = [
                'work_uri' => $workUri,
                'label' => self::pickLabel($work),
                'ordinal' => $ordinals[0] ?? null,
                'year' => Claims::year($work, 'wdt:P577'),
                'authors' => $authors,
                'original_lang' => isset($langs[0]) ? ($langCodes[$langs[0]] ?? null) : null,
            ];
        }

        return $members;
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
        return $this->entities->labels($uris);
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
        return $this->entities->languageCodes($uris);
    }

    /**
     * @param array<string, mixed> $entity
     *
     * @return list<string>
     */
    private static function claimValues(array $entity, string $property): array
    {
        return Claims::values($entity, $property);
    }

    /** @param array<string, mixed> $entity */
    private static function pickLabel(array $entity): ?string
    {
        return Labels::pick($entity);
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
