<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DiscoveryCache;
use App\Repository\DiscoveryCacheRepository;
use App\Service\Discovery\DiscoveryBudgetExhaustedException;
use App\Service\Discovery\SeriesResolutionPipeline;
use App\Service\DiscoveryResolverService;
use App\Service\HubEventLogger;
use PHPUnit\Framework\TestCase;

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

    private function service(
        DiscoveryCacheRepository $cache,
        SeriesResolutionPipeline $pipeline,
        ?HubEventLogger $logger = null,
    ): DiscoveryResolverService {
        return new DiscoveryResolverService(
            $cache,
            $pipeline,
            $logger ?? $this->createStub(HubEventLogger::class),
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

        $this->assertSame(['status' => 'unavailable'], $envelope);
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
}
