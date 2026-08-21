<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * Author resolution internals (ADR-060 section 3.2, author lane),
 * deliberately separate from the controller and the cache (section 3.7
 * discipline: this is the part a future openbook-resolver crate would
 * replace).
 *
 * Pipeline, anchor first and name verified: anchor ISBN -> edition ->
 * work -> author claim (P50) with the entity's label, then, once the label
 * has been verified against the requested name by the resolver, author ->
 * works (Inventaire reverse-claims on P50) -> edition candidates for the
 * most notable of them.
 *
 * Why Inventaire's reverse-claims and not a Wikidata SPARQL fan-out on
 * P50: Inventaire's graph is bibliographic only and indexes the Wikidata
 * entities as well, so one query returns the author's BOOKS. The same
 * question asked to SPARQL returns every scholarly article, painting and
 * screenplay the person ever signed, which no amount of type filtering
 * cleans up cheaply. Complementing sparse answers with BnF or Open
 * Library (section 3.2) is deliberately not built here.
 *
 * Everything returned is language-neutral: serve-time filtering per reader
 * language happens in DiscoveryResolverService.
 */
class AuthorResolutionPipeline
{
    /**
     * Bibliography cap. Unlike the series cap, which REJECTS an oversized
     * "series" because gap arithmetic on a franchise is noise, this one
     * truncates by rank: the client shows at most two works, so the tail
     * of a large bibliography is dead payload, not a precision problem.
     */
    public const MAX_WORKS = 40;

    /**
     * Only the most notable works get their editions fetched. Each one
     * costs a reverse-claims call plus its share of entity batches, and
     * the measured cost of a cold series resolution (about 21 outbound
     * calls, ADR-060 consequences) is the budget this lane holds itself
     * to: six works land a cold author resolution in the same range.
     *
     * Six rather than two because the reader owns some of them already:
     * the client membrane drops every work already in the library, and a
     * favorite author is by definition one whose books are on the shelf.
     */
    public const EDITION_WORKS_MAX = 6;

    public function __construct(
        private readonly InventaireClient $inventaire,
        private readonly EntityLookup $entities,
        private readonly EditionResolver $editions,
    ) {
    }

    /**
     * Candidate author entities for one anchor ISBN: edition -> work(s) ->
     * author claims, each with its display label.
     *
     * The label travels with the uri on purpose: the resolver caches this
     * result per ISBN and verifies the requested name against it, so a
     * cached anchor verifies a name at zero outbound cost.
     *
     * @return list<array{uri: string, label: string}>
     */
    public function resolveAnchor(string $isbn13): array
    {
        $editionUri = 'isbn:' . $isbn13;
        $entities = $this->inventaire->entitiesByUris([$editionUri]);
        $edition = $entities[$editionUri] ?? null;
        if (!is_array($edition)) {
            return [];
        }
        $workUris = Claims::values($edition, 'wdt:P629');
        if ($workUris === []) {
            return [];
        }

        $works = $this->inventaire->entitiesByUris($workUris);
        $authorUris = [];
        foreach ($works as $work) {
            if (is_array($work)) {
                $authorUris = array_merge($authorUris, Claims::values($work, 'wdt:P50'));
            }
        }
        $authorUris = array_values(array_unique($authorUris));
        if ($authorUris === []) {
            return [];
        }

        $labels = $this->entities->labels($authorUris);
        $candidates = [];
        foreach ($authorUris as $uri) {
            // An author entity without a usable label cannot be verified
            // against the requested name, so it is not a candidate at all.
            if (isset($labels[$uri]) && $labels[$uri] !== '') {
                $candidates[] = ['uri' => $uri, 'label' => $labels[$uri]];
            }
        }

        return $candidates;
    }

    /**
     * Language-neutral bibliography payload for a verified author entity,
     * or null when the author has no usable work (unknown, negative-cached
     * by the caller).
     *
     * [$label] comes from the anchor stage rather than a fresh fetch: the
     * resolver has just verified the requested name against it, and the
     * entity row must be self-contained for the readers that hit the cache
     * later.
     *
     * @return array{source: string, source_id: string, label: string, works: list<array<string, mixed>>}|null
     */
    public function resolveAuthorEntity(string $authorUri, string $label): ?array
    {
        $workUris = $this->inventaire->reverseClaims('wdt:P50', $authorUri);
        if ($workUris === []) {
            return null;
        }
        $entities = $this->inventaire->entitiesByUris($workUris);

        $works = [];
        $seenTitles = [];
        foreach ($workUris as $uri) {
            $entity = $entities[$uri] ?? null;
            if (!is_array($entity) || ($entity['type'] ?? '') !== 'work') {
                // Series and people answer the same P50 claim (a series
                // entity carries its author too); only works are offerable.
                continue;
            }
            $title = Labels::pick($entity);
            if ($title === null || trim($title) === '') {
                continue;
            }
            $titleKey = Labels::normalize($title);
            if ($titleKey === '' || isset($seenTitles[$titleKey])) {
                // The two graphs hold duplicates of the same work; two
                // cards for one book is a visible quality defect.
                continue;
            }
            $seenTitles[$titleKey] = true;
            $langUris = Claims::values($entity, 'wdt:P407');
            $works[] = [
                'work_uri' => $uri,
                'title' => $title,
                'labels' => self::stringLabels($entity),
                'year' => Claims::year($entity, 'wdt:P577'),
                'author_uris' => Claims::values($entity, 'wdt:P50'),
                'lang_uri' => $langUris[0] ?? null,
                'notability' => Labels::languageCount($entity),
                'editions_count' => null,
                'editions' => [],
            ];
        }
        if ($works === []) {
            return null;
        }

        // Most notable first (PHP sorts are stable, so source order breaks
        // ties), then truncate: the tail is dead payload.
        usort($works, static fn (array $a, array $b): int => $b['notability'] <=> $a['notability']);
        $works = array_slice($works, 0, self::MAX_WORKS);

        $this->attachAuthors($works, $authorUri, $label);
        $this->attachOriginalLanguages($works);
        $this->attachTopEditions($works);

        foreach ($works as &$work) {
            unset($work['work_uri'], $work['author_uris'], $work['lang_uri'], $work['notability']);
        }
        unset($work);

        return [
            'source' => str_starts_with($authorUri, 'wd:') ? 'wikidata' : 'inventaire',
            'source_id' => str_contains($authorUri, ':')
                ? substr($authorUri, (int) strpos($authorUri, ':') + 1)
                : $authorUri,
            'label' => $label,
            'works' => $works,
        ];
    }

    /**
     * Every label of an entity, language-keyed and string-valued.
     *
     * The pooled payload keeps them ALL because it is language-neutral by
     * ADR-060 section 2, exactly like the editions: serve time picks the
     * reader's title and the alternates their library may be catalogued
     * under. It costs no outbound call (the entity is already fetched) and
     * it is what keeps the title half of the client membrane alive across
     * translations, which is where author completion would otherwise offer
     * "The Stranger" to someone who owns "L'Etranger".
     *
     * @param array<string, mixed> $entity
     *
     * @return array<string, string>
     */
    private static function stringLabels(array $entity): array
    {
        $labels = $entity['labels'] ?? [];
        if (!is_array($labels)) {
            return [];
        }

        return array_filter($labels, 'is_string');
    }

    /**
     * Resolve co-author labels in one batch and write the per-work author
     * list.
     *
     * Authors are load-bearing, not decoration: the client drops a work
     * whose normalized title+author matches a book already in the library,
     * which is how "I own Dune in French" stops "Dune" in English from
     * being suggested. A work returned with an empty author list silently
     * disables that half of the membrane (the drift found in the volet 1
     * recette, 2026-08-21), so the verified author label backfills any
     * co-author the sources fail to name.
     *
     * @param list<array<string, mixed>> $works modified in place
     */
    private function attachAuthors(array &$works, string $authorUri, string $label): void
    {
        $uris = [];
        foreach ($works as $work) {
            foreach ($work['author_uris'] as $uri) {
                if ($uri !== $authorUri) {
                    $uris[$uri] = true;
                }
            }
        }
        $labels = $this->entities->labels(array_keys($uris));
        $labels[$authorUri] = $label;

        foreach ($works as &$work) {
            $authors = [];
            foreach ($work['author_uris'] as $uri) {
                if (isset($labels[$uri]) && !in_array($labels[$uri], $authors, true)) {
                    $authors[] = $labels[$uri];
                }
            }
            if ($authors === []) {
                $authors = [$label];
            }
            $work['authors'] = $authors;
        }
        unset($work);
    }

    /**
     * Resolve the works' original-language codes in one batch.
     *
     * The serve-time filter always keeps a work's original-language
     * edition on top of the reader's languages (ADR-060 section 2), so a
     * reader whose languages no edition matches still gets a card carrying
     * an ISBN instead of a bare title.
     *
     * @param list<array<string, mixed>> $works modified in place
     */
    private function attachOriginalLanguages(array &$works): void
    {
        $uris = [];
        foreach ($works as $work) {
            if (is_string($work['lang_uri'])) {
                $uris[$work['lang_uri']] = true;
            }
        }
        $codes = $this->entities->languageCodes(array_keys($uris));
        foreach ($works as &$work) {
            $work['original_lang'] = is_string($work['lang_uri'])
                ? ($codes[$work['lang_uri']] ?? null)
                : null;
        }
        unset($work);
    }

    /**
     * Fetch edition candidates for the top [self::EDITION_WORKS_MAX] works
     * and publish the source-known edition count as the popularity proxy
     * of ADR-060 section 3.2. Works past that rank keep a null count and
     * no edition: they can still be suggested as a title card (section
     * 4.2), they simply lose the ISBN-keyed niceties.
     *
     * @param list<array<string, mixed>> $works modified in place
     */
    private function attachTopEditions(array &$works): void
    {
        $top = array_slice($works, 0, self::EDITION_WORKS_MAX);
        if ($top === []) {
            return;
        }
        $counts = $this->editions->attachTo($top);
        foreach ($top as $i => $enriched) {
            $works[$i]['editions'] = $enriched['editions'];
            $works[$i]['editions_count'] = $counts[$i] ?? null;
        }
    }
}
