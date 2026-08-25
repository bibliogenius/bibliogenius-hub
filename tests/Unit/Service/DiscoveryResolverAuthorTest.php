<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DiscoveryCache;
use App\Repository\DiscoveryCacheRepository;
use App\Service\Discovery\AuthorResolutionPipeline;
use App\Service\Discovery\DiscoveryBudgetExhaustedException;
use App\Service\Discovery\OutboundBudget;
use App\Service\Discovery\SeriesResolutionPipeline;
use App\Service\DiscoveryResolverService;
use App\Service\HubEventLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Freezes the ADR-060 author lane at the resolver level: anchor-first and
 * name-verified resolution (a homonym shows nothing), the two-level pooled
 * cache, negative caching, budget exhaustion mapping to 'unavailable'
 * without a cache write, and the serve-time language filtering applied to
 * titles as well as editions.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class DiscoveryResolverAuthorTest extends TestCase
{
    private const ANCHOR = '9782070360024';
    private const AUTHOR_URI = 'wd:Q34670';
    private const AUTHOR_NAME = 'Albert Camus';

    /** @return array<string, mixed> */
    private static function camusPayload(): array
    {
        return [
            'source' => 'wikidata',
            'source_id' => 'Q34670',
            'label' => self::AUTHOR_NAME,
            'works' => [
                [
                    'title' => 'The Stranger',
                    'labels' => ['en' => 'The Stranger', 'fr' => "L'Étranger", 'ja' => '異邦人'],
                    'authors' => [self::AUTHOR_NAME],
                    'year' => 1942,
                    'original_lang' => 'fr',
                    'editions_count' => 41,
                    'editions' => [
                        ['isbn' => '9784102114018', 'lang' => 'ja', 'cover_url' => 'https://inventaire.io/img/entities/aa'],
                        ['isbn' => '9782040160104', 'lang' => 'fr', 'cover_url' => 'https://evil.example.com/x.jpg'],
                        ['isbn' => '9780679720201', 'lang' => 'en', 'cover_url' => null],
                    ],
                ],
            ],
        ];
    }

    private function budget(int $limit): OutboundBudget
    {
        return new OutboundBudget(new RateLimiterFactory(
            [
                'id' => 'discovery_source_budget',
                'policy' => 'token_bucket',
                'limit' => $limit,
                'rate' => ['interval' => '1 minute', 'amount' => 60],
            ],
            new InMemoryStorage(),
        ));
    }

    private function service(
        DiscoveryCacheRepository $cache,
        AuthorResolutionPipeline $pipeline,
        ?HubEventLogger $logger = null,
        ?OutboundBudget $budget = null,
    ): DiscoveryResolverService {
        return new DiscoveryResolverService(
            $cache,
            $this->createStub(SeriesResolutionPipeline::class),
            $logger ?? $this->createStub(HubEventLogger::class),
            $pipeline,
            $budget ?? $this->budget(60),
        );
    }

    public function testServesFromFreshCacheWithoutTouchingThePipeline(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturnMap([
            [DiscoveryCache::KIND_AUTHOR_LOOKUP, self::ANCHOR, [
                'status' => 'resolved',
                'payload' => ['authors' => [['uri' => self::AUTHOR_URI, 'label' => self::AUTHOR_NAME]]],
                'source' => null,
            ]],
            [DiscoveryCache::KIND_AUTHOR, self::AUTHOR_URI, [
                'status' => 'resolved',
                'payload' => self::camusPayload(),
                'source' => 'wikidata',
            ]],
        ]);
        $cache->expects($this->never())->method('put');

        $pipeline = $this->createMock(AuthorResolutionPipeline::class);
        // A warm anchor verifies the name from the cached label: no
        // outbound call at all on the pooled path.
        $pipeline->expects($this->never())->method('resolveAnchor');
        $pipeline->expects($this->never())->method('resolveAuthorEntity');

        $envelope = $this->service($cache, $pipeline)
            ->resolveAuthor(self::AUTHOR_NAME, [self::ANCHOR], ['fr']);

        $this->assertSame('resolved', $envelope['status']);
        $this->assertSame('Q34670', $envelope['author']['source_id']);

        $work = $envelope['author']['works'][0];
        // The French reader reads the French title, and keeps the English
        // one among the alternates their library may be catalogued under.
        $this->assertSame("L'Étranger", $work['title']);
        $this->assertSame(["L'Étranger", 'The Stranger'], $work['titles']);
        $this->assertSame(41, $work['editions_count']);
        // fr requested, fr also original; ja and en filtered out.
        $this->assertSame(['fr'], array_column($work['editions'], 'lang'));
        $this->assertTrue($work['other_langs_exist']);
        // Off-allowlist cover dropped at the single output choke point.
        $this->assertNull($work['editions'][0]['cover_url']);
        // Neutral-cache-only fields never reach the client.
        $this->assertArrayNotHasKey('labels', $work);
        $this->assertArrayNotHasKey('original_lang', $work);
    }

    /**
     * Homonymy is where author completion humiliates itself: the anchor
     * resolves, but to someone else. Nothing is shown, and the
     * bibliography is never even fetched.
     */
    public function testNameMismatchIsAmbiguousAndResolvesNothing(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturnMap([
            [DiscoveryCache::KIND_AUTHOR_LOOKUP, self::ANCHOR, [
                'status' => 'resolved',
                'payload' => ['authors' => [['uri' => self::AUTHOR_URI, 'label' => 'Albert Camus']]],
                'source' => null,
            ]],
        ]);

        $pipeline = $this->createMock(AuthorResolutionPipeline::class);
        $pipeline->expects($this->never())->method('resolveAuthorEntity');

        $envelope = $this->service($cache, $pipeline)
            ->resolveAuthor('Renaud Camus', [self::ANCHOR], ['fr']);

        $this->assertSame(['status' => 'ambiguous'], $envelope);
    }

    public function testNameWrittenLastNameFirstStillVerifies(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturnMap([
            [DiscoveryCache::KIND_AUTHOR_LOOKUP, self::ANCHOR, [
                'status' => 'resolved',
                'payload' => ['authors' => [['uri' => self::AUTHOR_URI, 'label' => 'Ursula K. Le Guin']]],
                'source' => null,
            ]],
            [DiscoveryCache::KIND_AUTHOR, self::AUTHOR_URI, [
                'status' => 'resolved',
                'payload' => self::camusPayload(),
                'source' => 'wikidata',
            ]],
        ]);

        $envelope = $this->service($cache, $this->createMock(AuthorResolutionPipeline::class))
            ->resolveAuthor('Le Guin, Ursula K.', [self::ANCHOR], []);

        $this->assertSame('resolved', $envelope['status']);
    }

    public function testSeveralEntitiesCarryingTheNameFallThroughToTheNextAnchor(): void
    {
        $second = '9782070368228';
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturnMap([
            // Duplicate entities for the same name: nothing tells them
            // apart, so this anchor decides nothing.
            [DiscoveryCache::KIND_AUTHOR_LOOKUP, self::ANCHOR, [
                'status' => 'resolved',
                'payload' => ['authors' => [
                    ['uri' => 'inv:dupe', 'label' => self::AUTHOR_NAME],
                    ['uri' => self::AUTHOR_URI, 'label' => self::AUTHOR_NAME],
                ]],
                'source' => null,
            ]],
            [DiscoveryCache::KIND_AUTHOR_LOOKUP, $second, [
                'status' => 'resolved',
                'payload' => ['authors' => [['uri' => self::AUTHOR_URI, 'label' => self::AUTHOR_NAME]]],
                'source' => null,
            ]],
            [DiscoveryCache::KIND_AUTHOR, self::AUTHOR_URI, [
                'status' => 'resolved',
                'payload' => self::camusPayload(),
                'source' => 'wikidata',
            ]],
        ]);

        $envelope = $this->service($cache, $this->createMock(AuthorResolutionPipeline::class))
            ->resolveAuthor(self::AUTHOR_NAME, [self::ANCHOR, $second], []);

        $this->assertSame('resolved', $envelope['status']);
        $this->assertSame('Q34670', $envelope['author']['source_id']);
    }

    public function testAnchorMissIsNegativeCachedAsUnknown(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);
        $cache->expects($this->once())
            ->method('put')
            ->with(DiscoveryCache::KIND_AUTHOR_LOOKUP, self::ANCHOR, DiscoveryCache::STATUS_UNKNOWN, null, null);

        $pipeline = $this->createMock(AuthorResolutionPipeline::class);
        $pipeline->method('resolveAnchor')->willReturn([]);

        $envelope = $this->service($cache, $pipeline)->resolveAuthor(self::AUTHOR_NAME, [self::ANCHOR], []);

        // No anchor resolved at all is 'unknown'; an anchor that resolves
        // to the wrong person is 'ambiguous'. Both show nothing, but the
        // hub_events trail tells source sparsity from name drift.
        $this->assertSame(['status' => 'unknown'], $envelope);
    }

    public function testResolutionWritesBothCacheLevels(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);
        $writes = [];
        $cache->method('put')->willReturnCallback(
            function (string $kind, string $key, string $status, ?array $payload, ?string $source) use (&$writes): void {
                $writes[] = [$kind, $key, $status, $source];
            },
        );

        $pipeline = $this->createMock(AuthorResolutionPipeline::class);
        $pipeline->method('resolveAnchor')
            ->willReturn([['uri' => self::AUTHOR_URI, 'label' => self::AUTHOR_NAME]]);
        $pipeline->method('resolveAuthorEntity')->willReturn(self::camusPayload());

        $envelope = $this->service($cache, $pipeline)->resolveAuthor(self::AUTHOR_NAME, [self::ANCHOR], ['fr']);

        $this->assertSame('resolved', $envelope['status']);
        $this->assertSame([
            [DiscoveryCache::KIND_AUTHOR_LOOKUP, self::ANCHOR, DiscoveryCache::STATUS_RESOLVED, null],
            [DiscoveryCache::KIND_AUTHOR, self::AUTHOR_URI, DiscoveryCache::STATUS_RESOLVED, 'wikidata'],
        ], $writes);
    }

    public function testAuthorWithoutUsableWorksIsNegativeCached(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);
        $cache->expects($this->exactly(2))->method('put');

        $pipeline = $this->createMock(AuthorResolutionPipeline::class);
        $pipeline->method('resolveAnchor')
            ->willReturn([['uri' => self::AUTHOR_URI, 'label' => self::AUTHOR_NAME]]);
        $pipeline->method('resolveAuthorEntity')->willReturn(null);

        $envelope = $this->service($cache, $pipeline)->resolveAuthor(self::AUTHOR_NAME, [self::ANCHOR], []);

        $this->assertSame(['status' => 'unknown'], $envelope);
    }

    public function testBudgetExhaustionIsUnavailableAndNeverCached(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);
        $cache->expects($this->never())->method('put');

        $pipeline = $this->createMock(AuthorResolutionPipeline::class);
        $pipeline->method('resolveAnchor')
            ->willThrowException(new DiscoveryBudgetExhaustedException('exhausted'));

        $logger = $this->createMock(HubEventLogger::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('discovery', 'author_unavailable', ['reason' => 'outbound_budget_exhausted']);

        $envelope = $this->service($cache, $pipeline, $logger)
            ->resolveAuthor(self::AUTHOR_NAME, [self::ANCHOR], []);

        // A transport failure is never cached: the next reader retries.
        $this->assertSame('unavailable', $envelope['status']);
    }

    /**
     * PHP turns an all-digit array key into an int, and the titles are
     * collected in a keyed set to deduplicate them. A work titled "1984"
     * therefore round-trips through an int key, and without the string
     * casts the client would receive a number where it expects a title.
     */
    public function testAnAllDigitTitleStaysAString(): void
    {
        $payload = self::camusPayload();
        $payload['works'][0]['title'] = 'Nineteen Eighty-Four';
        $payload['works'][0]['labels'] = ['en' => 'Nineteen Eighty-Four', 'fr' => '1984'];

        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturnMap([
            [DiscoveryCache::KIND_AUTHOR_LOOKUP, self::ANCHOR, [
                'status' => 'resolved',
                'payload' => ['authors' => [['uri' => self::AUTHOR_URI, 'label' => self::AUTHOR_NAME]]],
                'source' => null,
            ]],
            [DiscoveryCache::KIND_AUTHOR, self::AUTHOR_URI, [
                'status' => 'resolved',
                'payload' => $payload,
                'source' => 'wikidata',
            ]],
        ]);

        $work = $this->service($cache, $this->createMock(AuthorResolutionPipeline::class))
            ->resolveAuthor(self::AUTHOR_NAME, [self::ANCHOR], ['fr'])['author']['works'][0];

        $this->assertSame('1984', $work['title']);
        $this->assertSame(['1984', 'Nineteen Eighty-Four'], $work['titles']);
    }

    /**
     * A reader whose languages no edition matches still gets the
     * original-language edition, so the card keeps an ISBN (ADR-060
     * section 2) instead of degrading to a bare title.
     */
    public function testOriginalLanguageEditionSurvivesAForeignReader(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturnMap([
            [DiscoveryCache::KIND_AUTHOR_LOOKUP, self::ANCHOR, [
                'status' => 'resolved',
                'payload' => ['authors' => [['uri' => self::AUTHOR_URI, 'label' => self::AUTHOR_NAME]]],
                'source' => null,
            ]],
            [DiscoveryCache::KIND_AUTHOR, self::AUTHOR_URI, [
                'status' => 'resolved',
                'payload' => self::camusPayload(),
                'source' => 'wikidata',
            ]],
        ]);

        $envelope = $this->service($cache, $this->createMock(AuthorResolutionPipeline::class))
            ->resolveAuthor(self::AUTHOR_NAME, [self::ANCHOR], ['bg']);

        $work = $envelope['author']['works'][0];
        $this->assertSame(['fr'], array_column($work['editions'], 'lang'));
        // No Bulgarian label either: the display title falls back to the
        // neutral one rather than to nothing.
        $this->assertSame('The Stranger', $work['title']);
    }

    /**
     * Author lane counterpart of the series admission rule. It matters
     * more here: a first launch sweeps up to five authors back to back, so
     * this is exactly where the budget runs out mid-flight and the reader
     * loses the tail of the sweep for 24h.
     *
     * The anchor stage is deliberately NOT gated, and the test proves it:
     * the verified identity it caches costs two or three calls, survives
     * the refusal, and makes the next attempt free.
     */
    public function testColdBibliographyIsRefusedWhenTheBudgetCannotCoverIt(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturnMap([
            [DiscoveryCache::KIND_AUTHOR_LOOKUP, self::ANCHOR, null],
            [DiscoveryCache::KIND_AUTHOR, self::AUTHOR_URI, null],
        ]);
        $puts = [];
        $cache->method('put')->willReturnCallback(
            static function (string $kind, string $key, string $status) use (&$puts): void {
                $puts[] = [$kind, $key, $status];
            },
        );

        $pipeline = $this->createMock(AuthorResolutionPipeline::class);
        $pipeline->method('resolveAnchor')
            ->willReturn([['uri' => self::AUTHOR_URI, 'label' => self::AUTHOR_NAME]]);
        $pipeline->expects($this->never())->method('resolveAuthorEntity');

        $reasons = [];
        $logger = $this->createMock(HubEventLogger::class);
        $logger->method('error')->willReturnCallback(
            static function (string $channel, string $message, array $context) use (&$reasons): void {
                $reasons[] = $context['reason'] ?? null;
            },
        );

        $envelope = $this->service(
            $cache,
            $pipeline,
            $logger,
            $this->budget(OutboundBudget::COLD_RESOLUTION_CALLS - 1),
        )->resolveAuthor(self::AUTHOR_NAME, [self::ANCHOR], []);

        $this->assertSame('unavailable', $envelope['status']);
        $this->assertSame([DiscoveryBudgetExhaustedException::REASON_INSUFFICIENT], $reasons);
        // The anchor row is written and kept: the identity is verified,
        // and the retry after the budget refills costs no outbound call.
        $this->assertSame([[DiscoveryCache::KIND_AUTHOR_LOOKUP, self::ANCHOR, 'resolved']], $puts);
    }

    /**
     * Author-lane half of the invariant the series lane already pins: only
     * the transient outcome names a retry window. A homonym is negative
     * cached hub-side for seven days, so a short retry would have the
     * client ask again for an answer that cannot have changed.
     */
    public function testDefinitiveNegativesCarryNoRetryHintOnTheAuthorLane(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);

        $pipeline = $this->createMock(AuthorResolutionPipeline::class);
        // Two entities carrying the requested name: duplicates we cannot
        // tell apart, so the lane answers 'ambiguous'.
        $pipeline->method('resolveAnchor')->willReturn([
            ['uri' => self::AUTHOR_URI, 'label' => self::AUTHOR_NAME],
            ['uri' => 'wd:Q999999', 'label' => self::AUTHOR_NAME],
        ]);

        $envelope = $this->service($cache, $pipeline)
            ->resolveAuthor(self::AUTHOR_NAME, [self::ANCHOR], []);

        $this->assertSame('ambiguous', $envelope['status']);
        $this->assertArrayNotHasKey('retry_after', $envelope);
    }
}
