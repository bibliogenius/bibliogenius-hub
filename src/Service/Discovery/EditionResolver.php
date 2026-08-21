<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * Edition candidates for a set of works (ADR-060), shared by the series
 * and author lanes: both ask the same question ("which editions of this
 * work could a reader be offered"), and both have to defend against the
 * same false positive.
 *
 * Everything produced here is language-neutral: the pooled cache stores
 * every language and DiscoveryResolverService filters per reader language
 * at serve time (section 2, hybrid decision).
 *
 * Inventaire is the edition source in every case: Wikidata holds works,
 * Inventaire holds their editions with ISBN, language and cover.
 */
class EditionResolver
{
    /** Bound the neutral payload: up to this many editions per language. */
    public const MAX_EDITIONS_PER_LANG = 2;

    /** Bound the neutral payload: hard cap on editions per work. */
    public const MAX_EDITIONS_PER_ITEM = 24;

    public function __construct(
        private readonly InventaireClient $inventaire,
        private readonly EntityLookup $entities,
    ) {
    }

    /**
     * Fetch and attach edition candidates (ISBN, language code, cover URL)
     * to each item, keyed by its 'work_uri'. Editions without a
     * checksum-valid ISBN are useless to the client and skipped.
     *
     * Returns, per item index, how many editions the source knows for that
     * work BEFORE any filtering: the author lane publishes it as the
     * popularity proxy `editions_count`, the series lane ignores it.
     *
     * @param list<array<string, mixed>> $items modified in place
     *
     * @return array<int, int> item index => source-known edition count
     */
    public function attachTo(array &$items): array
    {
        $urisByItem = [];
        $counts = [];
        $allUris = [];
        foreach ($items as $i => $item) {
            $uris = $this->inventaire->reverseClaims('wdt:P629', (string) $item['work_uri']);
            $urisByItem[$i] = $uris;
            $counts[$i] = count($uris);
            $allUris = array_merge($allUris, $uris);
        }
        if ($allUris === []) {
            return $counts;
        }

        // An edition claimed by several of the works we are resolving is an
        // omnibus or a box set: the sources link it to every work it
        // contains. It is an edition of none of them individually, and
        // offering it as "the missing volume N" or as one work of an author
        // would have the client import a collection under a single title.
        // Dropped before the fetch, so it costs no outbound call either.
        $shared = [];
        foreach (array_count_values($allUris) as $uri => $count) {
            if ($count > 1) {
                $shared[$uri] = true;
            }
        }
        if ($shared !== []) {
            foreach ($urisByItem as $i => $uris) {
                $urisByItem[$i] = array_values(
                    array_filter($uris, static fn (string $u): bool => !isset($shared[$u])),
                );
            }
            $allUris = array_filter($allUris, static fn (string $u): bool => !isset($shared[$u]));
            if ($allUris === []) {
                return $counts;
            }
        }

        $editions = $this->inventaire->entitiesByUris(array_values(array_unique($allUris)));

        $langUris = [];
        foreach ($editions as $edition) {
            if (is_array($edition)) {
                $langUris = array_merge($langUris, Claims::values($edition, 'wdt:P407'));
            }
        }
        $langCodes = $this->entities->languageCodes(array_values(array_unique($langUris)));

        foreach ($items as $i => &$item) {
            $item['editions'] = $this->keptEditions($urisByItem[$i], $editions, $langCodes);
        }
        unset($item);

        return $counts;
    }

    /**
     * @param list<string>                             $uris
     * @param array<string, array<string, mixed>|null> $editions
     * @param array<string, string>                    $langCodes
     *
     * @return list<array{isbn: string, lang: ?string, cover_url: ?string}>
     */
    private function keptEditions(array $uris, array $editions, array $langCodes): array
    {
        $perLang = [];
        $kept = [];
        foreach ($uris as $uri) {
            if (count($kept) >= self::MAX_EDITIONS_PER_ITEM) {
                break;
            }
            $edition = $editions[$uri] ?? null;
            if (!is_array($edition)) {
                continue;
            }
            // The cross-work rule above only sees the works of THIS
            // resolution. An edition whose own wdt:P629 claims several
            // works is an omnibus whatever else we fetched: "Oeuvres" and
            // publisher box sets carry a dozen of them, and they are how a
            // reader ends up with a complete-works volume imported under
            // the title of one novel.
            if (Claims::count($edition, 'wdt:P629') > 1) {
                continue;
            }
            $isbnValues = Claims::values($edition, 'wdt:P212');
            $isbn13 = isset($isbnValues[0]) ? Isbn::toIsbn13($isbnValues[0]) : null;
            if ($isbn13 === null) {
                continue;
            }
            $editionLangs = Claims::values($edition, 'wdt:P407');
            $lang = isset($editionLangs[0]) ? ($langCodes[$editionLangs[0]] ?? null) : null;
            $langKey = $lang ?? '?';
            $perLang[$langKey] = ($perLang[$langKey] ?? 0) + 1;
            if ($perLang[$langKey] > self::MAX_EDITIONS_PER_LANG) {
                continue;
            }
            $imageHashes = Claims::values($edition, 'invp:P2');
            $kept[] = [
                'isbn' => $isbn13,
                'lang' => $lang,
                'cover_url' => isset($imageHashes[0])
                    ? 'https://inventaire.io/img/entities/' . $imageHashes[0]
                    : null,
            ];
        }

        return $kept;
    }
}
