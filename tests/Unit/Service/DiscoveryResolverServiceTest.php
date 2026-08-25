<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DiscoveryCache;
use App\Repository\DiscoveryCacheRepository;
use App\Service\Discovery\AuthorResolutionPipeline;
use App\Service\Discovery\DiscoveryBudgetExhaustedException;
use App\Service\Discovery\DiscoveryDeadlineExceededException;
use App\Service\Discovery\DiscoverySourceException;
use App\Service\Discovery\OutboundBudget;
use App\Service\Discovery\SeriesResolutionPipeline;
use App\Service\DiscoveryResolverService;
use App\Service\HubEventLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Freezes the ADR-060 resolver orchestration: two-level pooled cache
 * (lookup rows then entity rows), anchor intersection and name tiebreak,
 * negative caching, budget exhaustion mapping to 'unavailable' (never
 * cached), and the serve-time hybrid language filtering (reader languages
 * PLUS the always-included original-language edition, other_langs_exist
 * flag, cover-URL allowlist).
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class DiscoveryResolverServiceTest extends TestCase
{
    private const ISBN_HP3_FR = '9782070541270';
    private const ISBN_HP1_EN = '9780747532699';

    /** @return array<string, mixed> */
    private static function hpPayload(): array
    {
        return [
            'source' => 'wikidata',
            'source_id' => 'Q8337',
            'label' => 'Harry Potter',
            'volumes' => [
                [
                    'ordinal' => 1,
                    'title' => "Harry Potter and the Philosopher's Stone",
                    'authors' => ['J. K. Rowling'],
                    'year' => 1997,
                    'original_lang' => 'en',
                    'editions' => [
                        ['isbn' => self::ISBN_HP1_EN, 'lang' => 'en', 'cover_url' => 'https://inventaire.io/img/entities/aa'],
                        ['isbn' => '9782070518425', 'lang' => 'fr', 'cover_url' => 'http://inventaire.io/img/entities/bb'],
                        ['isbn' => '9782253006329', 'lang' => 'de', 'cover_url' => 'https://evil.example.com/x.jpg'],
                    ],
                ],
            ],
        ];
    }

    /** Real budget over in-memory storage: the admission check reads live tokens. */
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
        SeriesResolutionPipeline $pipeline,
        ?HubEventLogger $logger = null,
        ?OutboundBudget $budget = null,
    ): DiscoveryResolverService {
        return new DiscoveryResolverService(
            $cache,
            $pipeline,
            $logger ?? $this->createStub(HubEventLogger::class),
            $this->createStub(AuthorResolutionPipeline::class),
            $budget ?? $this->budget(60),
        );
    }

    public function testServesFromFreshCacheWithoutTouchingThePipeline(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturnMap([
            [DiscoveryCache::KIND_SERIES_LOOKUP, self::ISBN_HP3_FR, [
                'status' => 'resolved',
                'payload' => ['series_uris' => ['wd:Q8337']],
                'source' => null,
            ]],
            [DiscoveryCache::KIND_SERIES, 'wd:Q8337', [
                'status' => 'resolved',
                'payload' => self::hpPayload(),
                'source' => 'wikidata',
            ]],
        ]);
        $cache->expects($this->never())->method('put');

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->expects($this->never())->method('resolveAnchor');
        $pipeline->expects($this->never())->method('resolveSeriesEntity');

        $envelope = $this->service($cache, $pipeline)
            ->resolveSeries([self::ISBN_HP3_FR], null, ['fr']);

        $this->assertSame('resolved', $envelope['status']);
        $this->assertSame('wikidata', $envelope['series']['source']);
        $this->assertSame('Q8337', $envelope['series']['source_id']);

        $volume = $envelope['series']['volumes'][0];
        // fr requested + en original always included; de filtered out.
        $this->assertSame(['en', 'fr'], array_column($volume['editions'], 'lang'));
        $this->assertTrue($volume['other_langs_exist']);
        // The volume payload never leaks the neutral-cache-only fields.
        $this->assertArrayNotHasKey('original_lang', $volume);

        $byLang = array_column($volume['editions'], null, 'lang');
        // https + allowlisted host kept; plain http dropped.
        $this->assertSame('https://inventaire.io/img/entities/aa', $byLang['en']['cover_url']);
        $this->assertNull($byLang['fr']['cover_url']);
    }

    public function testAnchorMissIsNegativeCachedAsUnknown(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);
        $cache->expects($this->once())
            ->method('put')
            ->with(DiscoveryCache::KIND_SERIES_LOOKUP, self::ISBN_HP3_FR, DiscoveryCache::STATUS_UNKNOWN, null, null);

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')->willReturn([]);

        $envelope = $this->service($cache, $pipeline)->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertSame(['status' => 'unknown'], $envelope);
    }

    public function testDisjointAnchorsAreAmbiguous(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')->willReturnMap([
            [self::ISBN_HP3_FR, ['wd:Q8337']],
            [self::ISBN_HP1_EN, ['wd:Q999']],
        ]);
        $pipeline->expects($this->never())->method('resolveSeriesEntity');

        $envelope = $this->service($cache, $pipeline)
            ->resolveSeries([self::ISBN_HP3_FR, self::ISBN_HP1_EN], null, []);

        $this->assertSame(['status' => 'ambiguous'], $envelope);
    }

    public function testSeveralCandidatesWithoutNameAreAmbiguous(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')->willReturn(['wd:Q8337', 'wd:Q999']);
        $pipeline->expects($this->never())->method('resolveSeriesEntity');

        $envelope = $this->service($cache, $pipeline)->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertSame(['status' => 'ambiguous'], $envelope);
    }

    public function testNameTiebreakPicksTheExactNormalizedLabel(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')->willReturn(['wd:Q8337', 'wd:Q999']);
        $pipeline->method('entityLabels')->willReturn([
            'wd:Q8337' => 'Harry Potter',
            'wd:Q999' => 'Harry Potter: the Complete Collection',
        ]);
        $pipeline->expects($this->once())
            ->method('resolveSeriesEntity')
            ->with('wd:Q8337')
            ->willReturn(self::hpPayload());

        $envelope = $this->service($cache, $pipeline)
            ->resolveSeries([self::ISBN_HP3_FR], 'harry potter', ['en']);

        $this->assertSame('resolved', $envelope['status']);
        $this->assertSame('Q8337', $envelope['series']['source_id']);
    }

    public function testBudgetExhaustionMapsToUnavailableAndCachesNothing(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);
        $cache->expects($this->never())->method('put');

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')
            ->willThrowException(new DiscoveryBudgetExhaustedException('exhausted'));

        $envelope = $this->service($cache, $pipeline)->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertSame('unavailable', $envelope['status']);
    }

    public function testEntityWithoutUsableMembersIsNegativeCached(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);
        $puts = [];
        $cache->method('put')
            ->willReturnCallback(static function (...$args) use (&$puts): void {
                $puts[] = $args;
            });

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')->willReturn(['wd:Q8337']);
        $pipeline->method('resolveSeriesEntity')->willReturn(null);

        $envelope = $this->service($cache, $pipeline)->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertSame(['status' => 'unknown'], $envelope);
        $this->assertSame(
            [DiscoveryCache::KIND_SERIES, 'wd:Q8337', DiscoveryCache::STATUS_UNKNOWN, null, null],
            $puts[1],
        );
    }

    public function testResolutionWritesBothCacheLevels(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);
        $puts = [];
        $cache->method('put')
            ->willReturnCallback(static function (...$args) use (&$puts): void {
                $puts[] = $args;
            });

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')->willReturn(['wd:Q8337']);
        $pipeline->method('resolveSeriesEntity')->willReturn(self::hpPayload());

        $envelope = $this->service($cache, $pipeline)->resolveSeries([self::ISBN_HP3_FR], null, ['pt-BR']);

        $this->assertSame('resolved', $envelope['status']);
        $this->assertSame(
            [DiscoveryCache::KIND_SERIES_LOOKUP, self::ISBN_HP3_FR, DiscoveryCache::STATUS_RESOLVED, ['series_uris' => ['wd:Q8337']], null],
            $puts[0],
        );
        $this->assertSame(DiscoveryCache::KIND_SERIES, $puts[1][0]);
        $this->assertSame('wd:Q8337', $puts[1][1]);
        $this->assertSame(DiscoveryCache::STATUS_RESOLVED, $puts[1][2]);
        $this->assertSame('wikidata', $puts[1][4]);

        // pt-BR matches no edition; only the original-language edition
        // survives, and other_langs_exist says the cache holds more.
        $volume = $envelope['series']['volumes'][0];
        $this->assertSame(['en'], array_column($volume['editions'], 'lang'));
        $this->assertTrue($volume['other_langs_exist']);
    }

    // -------------------------------------------------------------------
    // Never start a cold resolution the budget cannot finish
    // -------------------------------------------------------------------

    /**
     * The entity stage is all or nothing and costs about twenty calls, so
     * a budget that cannot cover it must refuse BEFORE the first call.
     * Aborting halfway spends the tokens and caches nothing, which is the
     * failure that makes contention self-reinforcing.
     */
    public function testColdEntityStageIsRefusedWhenTheBudgetCannotCoverIt(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturnMap([
            [DiscoveryCache::KIND_SERIES_LOOKUP, self::ISBN_HP3_FR, [
                'status' => 'resolved',
                'payload' => ['series_uris' => ['wd:Q8337']],
                'source' => null,
            ]],
            [DiscoveryCache::KIND_SERIES, 'wd:Q8337', null],
        ]);
        $cache->expects($this->never())->method('put');

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->expects($this->never())->method('resolveSeriesEntity');

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
        )->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertSame('unavailable', $envelope['status']);
        $this->assertSame([DiscoveryBudgetExhaustedException::REASON_INSUFFICIENT], $reasons);
    }

    /**
     * The counterweight to the check above: refusing on an empty budget
     * must never touch a request the pool can already answer, or the
     * admission rule would empty the lane of its substance on the very
     * days it is meant to protect it. A warm request makes no source call,
     * so the budget has no say in it.
     */
    public function testAWarmResolutionIsStillServedOnAnEmptyBudget(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturnMap([
            [DiscoveryCache::KIND_SERIES_LOOKUP, self::ISBN_HP3_FR, [
                'status' => 'resolved',
                'payload' => ['series_uris' => ['wd:Q8337']],
                'source' => null,
            ]],
            [DiscoveryCache::KIND_SERIES, 'wd:Q8337', [
                'status' => 'resolved',
                'payload' => self::hpPayload(),
                'source' => 'wikidata',
            ]],
        ]);

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->expects($this->never())->method('resolveSeriesEntity');

        $envelope = $this->service($cache, $pipeline, null, $this->budget(0))
            ->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertSame('resolved', $envelope['status']);
        $this->assertSame('Q8337', $envelope['series']['source_id']);
    }

    /**
     * A resolution that outlives its wall-clock allowance answers
     * 'unavailable' like any source failure, but is journalled under its
     * own reason: "the hub gave up on purpose" and "the source failed" ask
     * for different fixes.
     */
    public function testDeadlineExceededIsJournalledUnderItsOwnReason(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);
        $cache->expects($this->never())->method('put');

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')
            ->willThrowException(new DiscoveryDeadlineExceededException('out of time'));

        $reasons = [];
        $logger = $this->createMock(HubEventLogger::class);
        $logger->method('error')->willReturnCallback(
            static function (string $channel, string $message, array $context) use (&$reasons): void {
                $reasons[] = $context['reason'] ?? null;
            },
        );

        $envelope = $this->service($cache, $pipeline, $logger)->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertSame('unavailable', $envelope['status']);
        $this->assertSame([DiscoveryDeadlineExceededException::REASON], $reasons);
    }

    /**
     * The wall-clock window belongs to one resolution: left open, it would
     * shorten the next one served by the same worker down to nothing.
     */
    public function testTheResolutionWindowIsClosedAfterwards(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')->willReturn([]);

        $budget = $this->budget(60);
        $this->service($cache, $pipeline, null, $budget)->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertNull($budget->remainingSeconds());
    }

    // -------------------------------------------------------------------
    // retry_after: the hub paces the client, the client does not guess
    // -------------------------------------------------------------------

    /**
     * The client's own throttle is a blind 24h, so without a hint from the
     * hub a refusal meant as "come back shortly" costs the reader a whole
     * day. The hint is carried by the transient outcome only.
     */
    public function testBudgetRefusalCarriesTheShortRetryHint(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturnMap([
            [DiscoveryCache::KIND_SERIES_LOOKUP, self::ISBN_HP3_FR, [
                'status' => 'resolved',
                'payload' => ['series_uris' => ['wd:Q8337']],
                'source' => null,
            ]],
            [DiscoveryCache::KIND_SERIES, 'wd:Q8337', null],
        ]);

        $envelope = $this->service(
            $cache,
            $this->createMock(SeriesResolutionPipeline::class),
            null,
            $this->budget(OutboundBudget::COLD_RESOLUTION_CALLS - 1),
        )->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertSame(
            DiscoveryResolverService::RETRY_AFTER_BUDGET_SECONDS,
            $envelope['retry_after'],
        );
    }

    /**
     * A source outage outlives a token bucket, so it gets the long hint.
     * Ordering matters: the short one must not leak onto this path.
     */
    public function testSourceFailureCarriesTheLongRetryHint(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')
            ->willThrowException(new DiscoverySourceException('Inventaire returned HTTP 503'));

        $envelope = $this->service($cache, $pipeline)->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertSame(
            DiscoveryResolverService::RETRY_AFTER_SOURCE_SECONDS,
            $envelope['retry_after'],
        );
        $this->assertGreaterThan(
            DiscoveryResolverService::RETRY_AFTER_BUDGET_SECONDS,
            DiscoveryResolverService::RETRY_AFTER_SOURCE_SECONDS,
        );
    }

    /**
     * The exact shape of the transient envelope, not just its status.
     *
     * The other tests on this path assert the status alone so a new field
     * does not break five of them at once, which is convenient and loses
     * something: nothing would notice a field accidentally added to an
     * envelope the client parses. This one holds that line in a single
     * place.
     */
    public function testTheUnavailableEnvelopeCarriesExactlyStatusAndRetryAfter(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')
            ->willThrowException(new DiscoverySourceException('Inventaire returned HTTP 503'));

        $envelope = $this->service($cache, $pipeline)->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertSame(['status', 'retry_after'], array_keys($envelope));
    }

    /**
     * A definitive negative must NOT carry a hint: it is negative-cached
     * hub-side for seven days, and a short retry would make the client ask
     * again for an answer that cannot have changed.
     */
    public function testDefinitiveNegativesCarryNoRetryHint(): void
    {
        $cache = $this->createMock(DiscoveryCacheRepository::class);
        $cache->method('findFresh')->willReturn(null);

        $pipeline = $this->createMock(SeriesResolutionPipeline::class);
        $pipeline->method('resolveAnchor')->willReturn([]);

        $envelope = $this->service($cache, $pipeline)->resolveSeries([self::ISBN_HP3_FR], null, []);

        $this->assertSame('unknown', $envelope['status']);
        $this->assertArrayNotHasKey('retry_after', $envelope);
    }
}
