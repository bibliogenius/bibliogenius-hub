<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin Inventaire.io API client for the discovery resolver (ADR-060).
 *
 * Inventaire is the FR long-tail primary of ADR-052's source decision and
 * the edition source (ISBN, language, cover) in every case: Wikidata holds
 * works, Inventaire holds their editions. Entity URIs cross both worlds
 * ('wd:Q...' for Wikidata-backed entities, 'inv:...' and 'isbn:...' for
 * Inventaire-native ones) and this client is agnostic to them.
 *
 * Every outbound call consumes one token of the hub-wide budget first.
 */
class InventaireClient
{
    private const BASE_URL = 'https://inventaire.io';
    private const USER_AGENT = 'BiblioGenius-Hub/discovery (+https://bibliogenius.org)';
    private const TIMEOUT_SECONDS = 5;
    private const URIS_BATCH_SIZE = 50;

    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $discoveryHttpClient,
        private readonly OutboundBudget $budget,
        ?string $baseUrl = null,
    ) {
        $this->baseUrl = $baseUrl ?? self::BASE_URL;
    }

    /**
     * Entities by URIs ('isbn:978...', 'wd:Q...', 'inv:...'), batched to
     * stay under URL length limits. Returns the merged uri => entity map.
     *
     * The API answers with entities keyed by their CANONICAL uri plus a
     * `redirects` map from what was asked for. An 'isbn:' uri is therefore
     * never a key of `entities`, and neither is a merged entity's old id.
     * Those aliases are folded back in here so callers can address the
     * result by the uri they asked for, whichever form it took.
     *
     * @param list<string> $uris
     *
     * @return array<string, array<string, mixed>>
     */
    public function entitiesByUris(array $uris): array
    {
        $entities = [];
        foreach (array_chunk(array_values(array_unique($uris)), self::URIS_BATCH_SIZE) as $chunk) {
            $data = $this->get(
                '/api/entities',
                ['action' => 'by-uris', 'uris' => implode('|', $chunk)],
                // Only a single-uri request may degrade to "nothing found":
                // it is the anchor lookup, the one place a client-derived
                // uri reaches the source. A batch that answers 4xx would
                // otherwise drop fifty entities silently, and the caller
                // would cache the truncated result as a complete payload.
                count($chunk) === 1,
            );
            $batch = $data['entities'] ?? [];
            if (!is_array($batch)) {
                continue;
            }
            $entities += $batch;

            $redirects = $data['redirects'] ?? [];
            if (!is_array($redirects)) {
                continue;
            }
            foreach ($redirects as $requested => $canonical) {
                if (is_string($canonical) && isset($batch[$canonical])) {
                    $entities += [$requested => $batch[$canonical]];
                }
            }
        }

        return $entities;
    }

    /**
     * URIs of the entities holding claim `property = value` (e.g. the works
     * of a series via 'wdt:P179', the editions of a work via 'wdt:P629').
     *
     * @return list<string>
     */
    public function reverseClaims(string $property, string $value): array
    {
        $data = $this->get('/api/entities', [
            'action' => 'reverse-claims',
            'property' => $property,
            'value' => $value,
        ]);
        $uris = $data['uris'] ?? [];

        return is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];
    }

    /**
     * Request options for one outbound call.
     *
     * TIMEOUT_SECONDS is an INACTIVITY timeout, so it bounds one call and
     * nothing else; max_duration caps the call against the time the whole
     * resolution has left, which is what stops a single slow response from
     * walking past the resolution deadline while no between-calls check
     * can run. Outside a resolution the budget has no deadline and the
     * inactivity timeout stands alone.
     *
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function requestOptions(array $query): array
    {
        $options = [
            'query' => $query,
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
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     *
     * @throws DiscoveryBudgetExhaustedException
     * @throws DiscoverySourceException
     */
    private function get(string $path, array $query, bool $clientErrorIsEmpty = false): array
    {
        $this->budget->consumeOrFail();
        try {
            $response = $this->discoveryHttpClient->request('GET', $this->baseUrl . $path, $this->requestOptions($query));
            $status = $response->getStatusCode();
            if ($status !== 200) {
                // Where the caller allows it, a client error is an ANSWER
                // rather than a failure: "this query is not resolvable", so
                // the resolver negative-caches it and the reader is spared a
                // retry every 24h forever. Inventaire answers 400 on an ISBN
                // that passes our checksum but that it considers
                // structurally invalid, and real libraries carry those.
                // Treating that as a transport failure cost more than the
                // row it failed to cache: the exception aborted the whole
                // lookup, so one bad ISBN among three anchors vetoed the two
                // good ones.
                //
                // Everywhere else a 4xx stays a failure, deliberately. A
                // batch or a reverse-claims call that degraded to an empty
                // answer would let a TRUNCATED payload be cached as a
                // complete one for thirty days, which is precisely the
                // "resolved but wrong" shape the drift monitoring of section
                // 3.5 cannot see.
                //
                // 429 and 5xx always throw: they mean "try later", and
                // caching them would freeze an outage into the pool.
                if (!$clientErrorIsEmpty || $status === 429 || $status >= 500) {
                    throw new DiscoverySourceException(sprintf('Inventaire returned HTTP %d', $status));
                }

                return [];
            }
            $data = json_decode($response->getContent(), true);
        } catch (DiscoverySourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DiscoverySourceException(sprintf('Inventaire request failed: %s', $e->getMessage()), 0, $e);
        }
        if (!is_array($data)) {
            throw new DiscoverySourceException('Inventaire returned an undecodable body');
        }

        return $data;
    }
}
