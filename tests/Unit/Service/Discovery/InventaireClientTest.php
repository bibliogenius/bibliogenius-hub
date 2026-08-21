<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Discovery;

use App\Service\Discovery\DiscoveryBudgetExhaustedException;
use App\Service\Discovery\DiscoverySourceException;
use App\Service\Discovery\InventaireClient;
use App\Service\Discovery\OutboundBudget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Freezes how the source's HTTP status is read (ADR-060).
 *
 * The distinction is not cosmetic: a client error is an ANSWER ("this query
 * is not resolvable") that the resolver negative-caches, while 429 and 5xx
 * are "try later" and must never be cached. Getting it wrong on the 400
 * cost two things at once, measured against the live API: a checksum-valid
 * but structurally invalid ISBN (real libraries hold them) aborted the whole
 * lookup including its valid anchors, and the failure was never cached, so
 * every sweep re-issued the same doomed outbound call.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class InventaireClientTest extends TestCase
{
    private function client(MockResponse|callable $response): InventaireClient
    {
        $handler = is_callable($response) ? $response : static fn (): MockResponse => $response;

        return new InventaireClient(
            new MockHttpClient($handler),
            $this->createStub(OutboundBudget::class),
            'https://inventaire.test',
        );
    }

    public function testEntitiesAreReturnedWithRedirectsFoldedIn(): void
    {
        $client = $this->client(new MockResponse((string) json_encode([
            'entities' => ['inv:abc' => ['uri' => 'inv:abc', 'labels' => ['fr' => 'Le livre']]],
            'redirects' => ['isbn:9782070360024' => 'inv:abc'],
        ])));

        $entities = $client->entitiesByUris(['isbn:9782070360024']);

        // The API keys entities by their CANONICAL uri; callers address them
        // by the uri they asked for.
        $this->assertArrayHasKey('isbn:9782070360024', $entities);
        $this->assertSame('inv:abc', $entities['isbn:9782070360024']['uri']);
    }

    /**
     * Measured on the live API (2026-08-21): `isbn:9791234567896` passes an
     * ISBN-13 checksum but answers 400 "invalid uri id", while
     * `isbn:9782079999997` answers 200 with zero entities. Both mean the
     * same thing to a reader, and both must reach the resolver as "nothing
     * found" so the answer is negative-cached once instead of retried
     * forever.
     */
    public function testClientErrorOnTheAnchorIsAnEmptyAnswerRatherThanAFailure(): void
    {
        foreach ([400, 404] as $status) {
            $client = $this->client(new MockResponse('{"status":400,"message":"invalid uri id"}', ['http_code' => $status]));

            $this->assertSame([], $client->entitiesByUris(['isbn:9791234567896']), "HTTP $status");
        }
    }

    /**
     * The leniency stops at the single-uri anchor. A batch that degraded to
     * an empty answer would drop fifty entities silently and let the caller
     * cache a TRUNCATED bibliography as a complete one for thirty days,
     * which is exactly the "resolved but wrong" shape the drift monitoring
     * of ADR-060 section 3.5 cannot see. Same reasoning for reverse-claims,
     * whose value is always source-derived: a 4xx there is an outage or our
     * own bug, never a reader's malformed ISBN.
     */
    public function testClientErrorOnABatchOrAReverseClaimStaysAFailure(): void
    {
        $client = $this->client(new MockResponse('{"status":400}', ['http_code' => 400]));

        try {
            $client->entitiesByUris(['wd:Q1', 'wd:Q2']);
            $this->fail('a 400 on a multi-uri batch must not degrade to an empty answer');
        } catch (DiscoverySourceException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
        }

        try {
            $client->reverseClaims('wdt:P50', 'wd:Q34670');
            $this->fail('a 400 on reverse-claims must not degrade to an empty answer');
        } catch (DiscoverySourceException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
        }
    }

    public function testRateLimitAndServerErrorsStayFailures(): void
    {
        foreach ([429, 500, 503] as $status) {
            $client = $this->client(new MockResponse('nope', ['http_code' => $status]));

            try {
                $client->reverseClaims('wdt:P50', 'wd:Q34670');
                $this->fail("HTTP $status should have thrown");
            } catch (DiscoverySourceException $e) {
                // "Try later" must never be frozen into the pooled cache.
                $this->assertStringContainsString((string) $status, $e->getMessage());
            }
        }
    }

    public function testUndecodableBodyIsAFailure(): void
    {
        $this->expectException(DiscoverySourceException::class);
        $this->client(new MockResponse('<html>maintenance</html>'))->reverseClaims('wdt:P50', 'wd:Q1');
    }

    public function testBudgetIsConsumedBeforeAnyCall(): void
    {
        $budget = $this->createStub(OutboundBudget::class);
        $budget->method('consumeOrFail')
            ->willThrowException(new DiscoveryBudgetExhaustedException('exhausted'));

        $client = new InventaireClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('{}')),
            $budget,
            'https://inventaire.test',
        );

        $this->expectException(DiscoveryBudgetExhaustedException::class);
        $client->entitiesByUris(['wd:Q1']);
    }
}
