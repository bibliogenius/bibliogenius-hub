<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wikidata SPARQL client for the discovery resolver (ADR-060): the
 * notable-reach primary for series membership (P179 reverse + P1545
 * ordinal qualifier, per ADR-052's source decision).
 *
 * Queries are built from resolved identifiers only (a validated Q-id),
 * never from user-provided text: the request 'name' is a tiebreaker
 * applied hub-side to labels, it is never interpolated into SPARQL.
 *
 * Every outbound call consumes one token of the hub-wide budget first.
 */
class WikidataClient
{
    private const SPARQL_URL = 'https://query.wikidata.org/sparql';
    private const USER_AGENT = 'BiblioGenius-Hub/discovery (+https://bibliogenius.org)';
    private const TIMEOUT_SECONDS = 10;

    private readonly string $sparqlUrl;

    public function __construct(
        private readonly HttpClientInterface $discoveryHttpClient,
        private readonly OutboundBudget $budget,
        ?string $sparqlUrl = null,
    ) {
        $this->sparqlUrl = $sparqlUrl ?? self::SPARQL_URL;
    }

    /**
     * Member works of a series with their ordinal, label, year, authors and
     * original-language code. One row per volume (deduplicated on the
     * volume IRI, first row wins).
     *
     * @return list<array{qid: string, label: ?string, ordinal: ?string, year: ?int, authors: list<string>, original_lang: ?string}>
     */
    public function seriesMembers(string $qid): array
    {
        if (preg_match('/^Q\d+$/', $qid) !== 1) {
            return [];
        }

        $query = sprintf(
            // The author label accepts "mul" alongside en/fr: Wikidata moved
            // labels shared across Latin-script languages to that code and
            // dropped the per-language ones, so an en-only filter yields no
            // author at all for most writers. STR() drops the language tag
            // so DISTINCT dedupes an author carrying several of them.
            'SELECT ?volume ?volumeLabel ?ordinal ?year
                    (GROUP_CONCAT(DISTINCT STR(?authorLabel); separator="|") AS ?authors) ?origLangCode
             WHERE {
               ?volume p:P179 ?membership .
               ?membership ps:P179 wd:%s .
               OPTIONAL { ?membership pq:P1545 ?ordinal . }
               OPTIONAL { ?volume wdt:P577 ?pubDate . BIND(YEAR(?pubDate) AS ?year) }
               OPTIONAL { ?volume wdt:P50 ?author .
                          ?author rdfs:label ?authorLabel .
                          FILTER(LANG(?authorLabel) IN ("en", "fr", "mul")) }
               OPTIONAL { ?volume wdt:P407 ?origLang . ?origLang wdt:P218 ?origLangCode . }
               SERVICE wikibase:label { bd:serviceParam wikibase:language "en,fr". }
             }
             GROUP BY ?volume ?volumeLabel ?ordinal ?year ?origLangCode',
            $qid,
        );

        $bindings = $this->select($query);

        $members = [];
        foreach ($bindings as $row) {
            $iri = $row['volume']['value'] ?? null;
            if (!is_string($iri) || preg_match('~/(Q\d+)$~', $iri, $m) !== 1) {
                continue;
            }
            $volumeQid = $m[1];
            if (isset($members[$volumeQid])) {
                continue;
            }
            $authors = [];
            $rawAuthors = $row['authors']['value'] ?? '';
            if (is_string($rawAuthors) && $rawAuthors !== '') {
                $authors = array_values(array_filter(explode('|', $rawAuthors)));
            }
            $members[$volumeQid] = [
                'qid' => $volumeQid,
                'label' => isset($row['volumeLabel']['value']) ? (string) $row['volumeLabel']['value'] : null,
                'ordinal' => isset($row['ordinal']['value']) ? (string) $row['ordinal']['value'] : null,
                'year' => isset($row['year']['value']) ? (int) $row['year']['value'] : null,
                'authors' => $authors,
                'original_lang' => isset($row['origLangCode']['value']) ? (string) $row['origLangCode']['value'] : null,
            ];
        }

        return array_values($members);
    }

    /**
     * Request options for one SPARQL call.
     *
     * TIMEOUT_SECONDS is an INACTIVITY timeout and is deliberately left
     * alone; max_duration caps the call against the time the resolution
     * has left, so the 10s allowance cannot outlive a 6s deadline.
     *
     * @return array<string, mixed>
     */
    private function requestOptions(string $query): array
    {
        $options = [
            'query' => ['query' => $query, 'format' => 'json'],
            'headers' => ['User-Agent' => self::USER_AGENT],
            'timeout' => self::TIMEOUT_SECONDS,
        ];
        $maxDuration = $this->budget->remainingSeconds();
        if ($maxDuration !== null) {
            $options['max_duration'] = $maxDuration;
        }

        return $options;
    }

    /**
     * @return list<array<string, array{value?: string}>>
     *
     * @throws DiscoveryBudgetExhaustedException
     * @throws DiscoverySourceException
     */
    private function select(string $query): array
    {
        $this->budget->consumeOrFail();
        try {
            $response = $this->discoveryHttpClient->request('GET', $this->sparqlUrl, $this->requestOptions($query));
            if ($response->getStatusCode() !== 200) {
                throw new DiscoverySourceException(sprintf('Wikidata returned HTTP %d', $response->getStatusCode()));
            }
            $data = json_decode($response->getContent(), true);
        } catch (DiscoverySourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DiscoverySourceException(sprintf('Wikidata request failed: %s', $e->getMessage()), 0, $e);
        }
        $bindings = $data['results']['bindings'] ?? null;
        if (!is_array($bindings)) {
            throw new DiscoverySourceException('Wikidata returned an undecodable body');
        }

        return $bindings;
    }
}
