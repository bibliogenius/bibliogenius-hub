<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Discovery;

use App\Service\Discovery\DiscoveryBudgetExhaustedException;
use App\Service\Discovery\InventaireClient;
use App\Service\Discovery\OutboundBudget;
use App\Service\Discovery\SeriesResolutionPipeline;
use App\Service\Discovery\WikidataClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Freezes the ADR-060 section 3.2 series pipeline against fixtures shaped
 * like the real source responses (Inventaire entities API, Wikidata SPARQL
 * bindings): anchor ISBN -> edition -> work -> series claim, member list
 * with source ordinals (Wikidata primary, Inventaire fallback), and
 * language-neutral edition candidates. Source drift will first show up
 * here as a fixture-vs-reality divergence.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class SeriesResolutionPipelineTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../Fixtures/discovery';

    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . '/' . $name), true);
    }

    private function inventaireHttp(): MockHttpClient
    {
        $entities = self::fixture('inventaire_entities.json');
        $reverse = self::fixture('inventaire_reverse_claims.json');
        $redirects = self::fixture('inventaire_redirects.json');

        return new MockHttpClient(static function (string $method, string $url) use ($entities, $reverse, $redirects): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            if (($q['action'] ?? '') === 'by-uris') {
                // The real API answers with entities keyed by their CANONICAL
                // uri and a separate redirects map from what was asked for.
                // An 'isbn:' uri is therefore never a key of `entities`.
                $selected = [];
                $followed = [];
                foreach (explode('|', (string) ($q['uris'] ?? '')) as $uri) {
                    $canonical = $redirects[$uri] ?? $uri;
                    if (!isset($entities[$canonical])) {
                        continue;
                    }
                    $selected[$canonical] = $entities[$canonical];
                    if ($canonical !== $uri) {
                        $followed[$uri] = $canonical;
                    }
                }

                return new MockResponse((string) json_encode([
                    'entities' => $selected,
                    'redirects' => (object) $followed,
                ]));
            }
            if (($q['action'] ?? '') === 'reverse-claims') {
                $key = ($q['property'] ?? '') . '|' . ($q['value'] ?? '');

                return new MockResponse((string) json_encode(['uris' => $reverse[$key] ?? []]));
            }

            return new MockResponse('{}', ['http_code' => 404]);
        });
    }

    private function pipeline(?callable $sparqlHandler = null, ?OutboundBudget $budget = null): SeriesResolutionPipeline
    {
        $budget ??= $this->createStub(OutboundBudget::class);
        $sparqlHandler ??= static fn (): MockResponse => new MockResponse(
            (string) file_get_contents(self::FIXTURES . '/wikidata_series_members_hp.json'),
        );

        return new SeriesResolutionPipeline(
            new InventaireClient($this->inventaireHttp(), $budget),
            new WikidataClient(new MockHttpClient($sparqlHandler), $budget),
        );
    }

    public function testResolveAnchorFollowsEditionWorkSeriesChain(): void
    {
        $this->assertSame(['wd:Q8337'], $this->pipeline()->resolveAnchor('9782070541270'));
    }

    public function testResolveAnchorUnknownIsbnYieldsNothing(): void
    {
        $this->assertSame([], $this->pipeline()->resolveAnchor('9780306406157'));
    }

    public function testResolveSeriesEntityFromWikidataPrimary(): void
    {
        $payload = $this->pipeline()->resolveSeriesEntity('wd:Q8337');

        $this->assertNotNull($payload);
        $this->assertSame('wikidata', $payload['source']);
        $this->assertSame('Q8337', $payload['source_id']);
        $this->assertSame('Harry Potter', $payload['label']);

        // The ordinal-less companion volume is dropped (a card must carry
        // "volume N"); the two remaining volumes are ordered by ordinal.
        $this->assertCount(2, $payload['volumes']);
        $this->assertSame([1, 3], array_column($payload['volumes'], 'ordinal'));

        $volume1 = $payload['volumes'][0];
        $this->assertSame("Harry Potter and the Philosopher's Stone", $volume1['title']);
        $this->assertSame(['J. K. Rowling'], $volume1['authors']);
        $this->assertSame(1997, $volume1['year']);
        $this->assertSame('en', $volume1['original_lang']);

        // Language-neutral edition candidates with resolved language codes.
        $editions = $volume1['editions'];
        $this->assertCount(2, $editions);
        $byLang = array_column($editions, null, 'lang');
        $this->assertSame('9780747532699', $byLang['en']['isbn']);
        $this->assertSame('9782070518425', $byLang['fr']['isbn']);
        $this->assertSame(
            'https://inventaire.io/img/entities/aa1122334455667788990011223344556677aabb',
            $byLang['en']['cover_url'],
        );
        $this->assertNull($byLang['fr']['cover_url']);
    }

    public function testWikidataFailureFallsBackToInventaireMembers(): void
    {
        $payload = $this->pipeline(
            static fn (): MockResponse => new MockResponse('boom', ['http_code' => 500]),
        )->resolveSeriesEntity('wd:Q8337');

        $this->assertNotNull($payload);
        $this->assertSame('inventaire', $payload['source']);
        // Members and their ordinals now come from Inventaire's direct
        // wdt:P1545 claims; authors are resolved through entity labels.
        $this->assertSame([1, 3], array_column($payload['volumes'], 'ordinal'));
        $this->assertSame(['J. K. Rowling'], $payload['volumes'][0]['authors']);
    }

    /**
     * Wikidata moved labels that are identical across Latin-script
     * languages to the "mul" code and dropped the per-language ones, so an
     * en-only filter on rdfs:label returns nothing for most writers
     * (J. K. Rowling included). Authors are load-bearing: without them the
     * client keeps only the ISBN half of its precision membrane and a
     * translation can be suggested as a missing volume.
     */
    public function testSeriesMembersQueryAcceptsMulAuthorLabels(): void
    {
        $captured = null;
        $handler = function (string $method, string $url) use (&$captured): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $captured = (string) ($q['query'] ?? '');

            return new MockResponse(
                (string) file_get_contents(self::FIXTURES . '/wikidata_series_members_hp.json'),
            );
        };

        $this->pipeline($handler)->resolveSeriesEntity('wd:Q8337');

        $this->assertIsString($captured);
        $this->assertStringContainsString('?authorLabel', $captured);
        $this->assertStringContainsString('"mul"', $captured);
    }

    public function testOversizedSeriesIsTreatedAsUnknown(): void
    {
        $bindings = [];
        for ($i = 1; $i <= SeriesResolutionPipeline::MAX_VOLUMES + 1; ++$i) {
            $bindings[] = [
                'volume' => ['type' => 'uri', 'value' => sprintf('http://www.wikidata.org/entity/Q%d', 100000 + $i)],
                'volumeLabel' => ['type' => 'literal', 'value' => sprintf('Volume %d', $i)],
                'ordinal' => ['type' => 'literal', 'value' => (string) $i],
            ];
        }
        $body = (string) json_encode(['results' => ['bindings' => $bindings]]);

        $payload = $this->pipeline(
            static fn (): MockResponse => new MockResponse($body),
        )->resolveSeriesEntity('wd:Q8337');

        // 41 "volumes" is a franchise, not a reading series: show nothing
        // rather than doing gap arithmetic on noise.
        $this->assertNull($payload);
    }

    public function testBudgetExhaustionPropagates(): void
    {
        $budget = $this->createStub(OutboundBudget::class);
        $budget->method('consumeOrFail')
            ->willThrowException(new DiscoveryBudgetExhaustedException('exhausted'));

        $this->expectException(DiscoveryBudgetExhaustedException::class);
        $this->pipeline(budget: $budget)->resolveAnchor('9782070541270');
    }

    public function testNormalizeLabelFoldsCaseDiacriticsAndPunctuation(): void
    {
        $this->assertSame('harry potter', SeriesResolutionPipeline::normalizeLabel("  Harry Pötter! "));
        $this->assertSame('l integrale', SeriesResolutionPipeline::normalizeLabel("L'Intégrale"));
        $this->assertSame('', SeriesResolutionPipeline::normalizeLabel('...'));
    }
}
