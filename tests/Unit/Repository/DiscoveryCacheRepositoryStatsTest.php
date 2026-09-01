<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\DiscoveryCache;
use App\Repository\DiscoveryCacheRepository;
use App\Service\Discovery\DiscoveryBudgetExhaustedException;
use App\Service\Discovery\DiscoveryDeadlineExceededException;
use App\Service\DiscoveryResolverService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Guards the /admin discovery-monitoring counters (ADR-060 section 3.5):
 *
 *  - the pool breakdown groups by kind AND status, never one alone, so the
 *    dashboard can tell "no rows" from "rows but all unknown";
 *  - the expiring-soon window excludes rows already past expires_at (a
 *    prune-lag artefact, not an upcoming-expiry signal);
 *  - the 24h cache-writes counter reads updated_at, the column the pool
 *    is dated by, so the volume shown next to the ratios is the volume
 *    those ratios rest on;
 *  - the drift tripwire judges resolution QUALITY only: missing anchors
 *    (source coverage) and outages (source pressure) stay out of it, each
 *    measured by its own figure;
 *  - failure-reason and budget-exhaustion counters read hub_events context
 *    as jsonb, bound as parameters, never interpolated, on channel
 *    'discovery' only.
 *
 * DiscoveryCacheRepository extends ServiceEntityRepository, so it is built
 * here through a mocked ManagerRegistry / EntityManager pair, exactly what
 * that base class resolves lazily; the DBAL Connection mock underneath is
 * inspected the same way DirectoryHealthRepositoryTest inspects its own.
 */
#[AllowMockObjectsWithoutExpectations]
final class DiscoveryCacheRepositoryStatsTest extends TestCase
{
    private const NOW = '2026-08-21 12:00:00';

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function repositoryWithConnection(Connection $connection): DiscoveryCacheRepository
    {
        // A mock ClassMetadata leaves the promoted $name property
        // uninitialized, which EntityRepository::getEntityName() reads
        // eagerly; a real instance is cheap and side-effect-free.
        $classMetadata = new ClassMetadata(DiscoveryCache::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return new DiscoveryCacheRepository($registry);
    }

    // -------------------------------------------------------------------
    // Pool
    // -------------------------------------------------------------------

    /**
     * The byte cap keeps the freshest-expiry rows that fit in the budget
     * and drops the rest. Bytes, not rows: a '*_lookup' row costs a
     * hundred bytes and saves a re-resolution, an author payload costs
     * tens of KB, so a row cap would evict the cheap half of the pool and
     * leave the expensive one.
     */
    public function testPruneOverBudgetDropsTheRowsPastTheByteBudget(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $capturedTypes = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(
                function (string $sql, array $params, array $types) use (&$capturedSql, &$capturedParams, &$capturedTypes) {
                    $capturedSql = $sql;
                    $capturedParams = $params;
                    $capturedTypes = $types;

                    return 12;
                },
            );

        $deleted = $this->repositoryWithConnection($conn)->pruneOverBudget(1024);

        $this->assertSame(12, $deleted);
        $this->assertSame(['budget' => 1024], $capturedParams);
        // DBAL 4: ParameterType constants, never \PDO::PARAM_* ints.
        $this->assertSame(\Doctrine\DBAL\ParameterType::INTEGER, $capturedTypes['budget']);
        $this->assertStringContainsStringIgnoringCase('octet_length(payload)', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('ORDER BY expires_at DESC', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('running_bytes > :budget', $capturedSql);
    }

    public function testTotalPayloadBytesCoalescesTheEmptyPool(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn('0');

        $this->assertSame(0, $this->repositoryWithConnection($conn)->totalPayloadBytes());
    }

    public function testCountAllCountsEveryRow(): void
    {
        $capturedSql = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return '7';
            });

        $this->assertSame(7, $this->repositoryWithConnection($conn)->countAll());
        $this->assertStringContainsStringIgnoringCase('SELECT COUNT(*) FROM discovery_cache', $capturedSql);
    }

    public function testCountByKindAndStatusGroupsBothColumns(): void
    {
        $capturedSql = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return [
                    ['kind' => 'series_lookup', 'status' => 'resolved', 'count' => '3'],
                    ['kind' => 'series', 'status' => 'ambiguous', 'count' => '1'],
                ];
            });

        $rows = $this->repositoryWithConnection($conn)->countByKindAndStatus();

        $this->assertStringContainsStringIgnoringCase('GROUP BY kind, status', $capturedSql);
        $this->assertSame(
            [
                ['kind' => 'series_lookup', 'status' => 'resolved', 'count' => 3],
                ['kind' => 'series', 'status' => 'ambiguous', 'count' => 1],
            ],
            $rows,
        );
    }

    public function testNextExpiryAtParsesTimestampAndHandlesEmptyPool(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn('2026-09-10 08:00:00');
        $at = $this->repositoryWithConnection($conn)->nextExpiryAt();
        $this->assertSame('2026-09-10 08:00:00', $at?->format('Y-m-d H:i:s'));

        // MIN() over an empty table yields NULL: must map to "pool empty".
        $empty = $this->createMock(Connection::class);
        $empty->method('fetchOne')->willReturn(null);
        $this->assertNull($this->repositoryWithConnection($empty)->nextExpiryAt());
    }

    public function testCountExpiringWithinDaysExcludesAlreadyExpiredRows(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return '4';
            });

        $count = $this->repositoryWithConnection($conn)->countExpiringWithinDays(7, $this->now());

        $this->assertSame(4, $count);
        // Lower bound keeps already-expired (prune-lag) rows out of the count.
        $this->assertStringContainsStringIgnoringCase('expires_at >= :now', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('expires_at <= :cutoff', $capturedSql);
        $this->assertSame(
            ['now' => '2026-08-21 12:00:00', 'cutoff' => '2026-08-28 12:00:00'],
            $capturedParams,
        );
    }

    // -------------------------------------------------------------------
    // Resolution pressure (24h)
    // -------------------------------------------------------------------

    public function testCountResolutionsLast24hDatesThePoolByUpdatedAt(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return '19';
            });

        $count = $this->repositoryWithConnection($conn)->countResolutionsLast24h($this->now());

        $this->assertSame(19, $count);
        $this->assertStringContainsStringIgnoringCase('FROM discovery_cache', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('updated_at >= :since', $capturedSql);
        $this->assertSame(['since' => '2026-08-20 12:00:00'], $capturedParams);
    }

    // -------------------------------------------------------------------
    // Failure reasons and outbound budget (hub_events, channel 'discovery')
    // -------------------------------------------------------------------

    public function testCountFailureReasonsLast24hReadsDiscoveryChannelOnly(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [
                    ['reason' => 'no_anchor_resolved', 'count' => '5'],
                    ['reason' => 'disjoint_anchors', 'count' => '2'],
                    ['reason' => null, 'count' => '11'],
                ];
            });

        $reasons = $this->repositoryWithConnection($conn)->countFailureReasonsLast24h($this->now());

        $this->assertStringContainsStringIgnoringCase("channel = 'discovery'", $capturedSql);
        $this->assertStringContainsStringIgnoringCase('created_at >= :since', $capturedSql);
        $this->assertStringContainsStringIgnoringCase("context::jsonb", $capturedSql);
        $this->assertSame(['since' => '2026-08-20 12:00:00'], $capturedParams);
        // A NULL reason (event predating the field, or a channel oddity)
        // must not surface as a bogus "" key.
        $this->assertSame(['no_anchor_resolved' => 5, 'disjoint_anchors' => 2], $reasons);
    }

    public function testCountBudgetExhaustionsSinceFiltersOnReasonAndChannel(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return '3';
            });

        $since = $this->now()->modify('-7 days');
        $count = $this->repositoryWithConnection($conn)->countBudgetExhaustionsSince($since);

        $this->assertSame(3, $count);
        $this->assertStringContainsStringIgnoringCase("channel = 'discovery'", $capturedSql);
        $this->assertStringContainsStringIgnoringCase("(context::jsonb)->>'reason'", $capturedSql);
        $this->assertSame('2026-08-14 12:00:00', $capturedParams['since']);
    }

    // -------------------------------------------------------------------
    // Unavailable share: the failure RATE the cache alone cannot express
    // -------------------------------------------------------------------

    /**
     * The denominator has to come from BOTH stores, because each outcome
     * writes to exactly one of them: a cold success writes an entity row,
     * everything else writes a journal row. Reading only the cache is what
     * makes "three author_unavailable" unreadable today, since an
     * unavailable outcome never writes a row at all.
     */
    public function testUnavailableBreakdownCountsResolvedEntityRowsPlusJournalledOutcomes(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return '6';
            });
        $conn->method('fetchAllAssociative')->willReturn([
            ['message' => 'series_unavailable', 'reason' => DiscoveryBudgetExhaustedException::REASON_EXHAUSTED, 'count' => '3'],
            ['message' => 'author_unavailable', 'reason' => DiscoveryDeadlineExceededException::REASON, 'count' => '1'],
            ['message' => 'series_ambiguous', 'reason' => 'no_clear_winner', 'count' => '2'],
        ]);

        $breakdown = $this->repositoryWithConnection($conn)->unavailableBreakdownSince($this->now());

        // 6 resolved entity rows + 6 journalled outcomes.
        $this->assertSame(12, $breakdown['total']);
        $this->assertSame(6, $breakdown['resolved']);
        $this->assertSame(4, $breakdown['unavailable']);
        $this->assertSame(33, $breakdown['share_percent']);
        $this->assertSame(
            [
                DiscoveryBudgetExhaustedException::REASON_EXHAUSTED => 3,
                DiscoveryDeadlineExceededException::REASON => 1,
            ],
            $breakdown['reasons'],
        );

        // Lookup kinds excluded: one resolution writes up to three of them
        // besides its payload, so counting them would inflate the
        // denominator by an amount that varies with the anchor count.
        $this->assertStringContainsStringIgnoringCase('kind IN (:series, :author)', $capturedSql);
        // Non-resolved entity rows excluded: they are journalled too, and
        // would land in the denominator twice.
        $this->assertStringContainsStringIgnoringCase('status = :resolved', $capturedSql);
        $this->assertSame('series', $capturedParams['series']);
        $this->assertSame('author', $capturedParams['author']);
        $this->assertSame('resolved', $capturedParams['resolved']);
        $this->assertSame('2026-08-21 12:00:00', $capturedParams['since']);
    }

    /**
     * A cause added later (the deadline was, on 2026-08-25) has to appear
     * without editing the query, or the split silently under-reports the
     * newest failure mode.
     */
    public function testUnavailableBreakdownGroupsWhateverReasonWasWritten(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn('20');
        $conn->method('fetchAllAssociative')->willReturn([
            ['message' => 'author_unavailable', 'reason' => 'Inventaire returned HTTP 503', 'count' => '2'],
            ['message' => 'author_unavailable', 'reason' => null, 'count' => '1'],
        ]);

        $breakdown = $this->repositoryWithConnection($conn)->unavailableBreakdownSince($this->now());

        $this->assertSame(3, $breakdown['unavailable']);
        $this->assertSame(
            ['Inventaire returned HTTP 503' => 2, 'unspecified' => 1],
            $breakdown['reasons'],
        );
    }

    /** Below the statistical floor a percentage is noise dressed as signal. */
    public function testUnavailableShareIsNullBelowTheSampleFloor(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn('1');
        $conn->method('fetchAllAssociative')->willReturn([
            ['message' => 'series_unavailable', 'reason' => 'x', 'count' => '1'],
        ]);

        $breakdown = $this->repositoryWithConnection($conn)->unavailableBreakdownSince($this->now());

        $this->assertSame(2, $breakdown['total']);
        $this->assertNull($breakdown['share_percent']);
        // The raw counts stay readable even when the ratio is withheld.
        $this->assertSame(1, $breakdown['unavailable']);
    }

    /**
     * Refusing a resolution up front and dying halfway are both the budget
     * hurting readers, so the source-pressure card must count both. It
     * counted one reason only until the admission check existed.
     */
    public function testBudgetExhaustionCounterCoversBothBudgetReasons(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return '5';
            });

        $count = $this->repositoryWithConnection($conn)->countBudgetExhaustionsSince($this->now());

        $this->assertSame(5, $count);
        $this->assertStringContainsStringIgnoringCase("channel = 'discovery'", $capturedSql);
        $this->assertStringContainsStringIgnoringCase('IN (:exhausted, :insufficient)', $capturedSql);
        $this->assertSame(DiscoveryBudgetExhaustedException::REASON_EXHAUSTED, $capturedParams['exhausted']);
        $this->assertSame(DiscoveryBudgetExhaustedException::REASON_INSUFFICIENT, $capturedParams['insufficient']);
    }

    // -------------------------------------------------------------------
    // Entity-stage quality: the drift tripwire, rebased 2026-09-01
    // -------------------------------------------------------------------

    /**
     * The tripwire divides two things counted on the same occasions: cold
     * entity fetches that came back empty, over cold entity fetches. Every
     * other outcome is out, and each for its own reason. Missing anchors
     * are source coverage. Outages are source pressure. The anchor-stage
     * verdicts are the subtle one: they are decided after lookups that may
     * have been served warm, so they re-journal on repeat traffic while
     * their successful counterparts stay silent in the cache, and a ratio
     * built on them would climb with traffic instead of with breakage,
     * which is the exact failure this rebase removes.
     */
    public function testEntityQualityCountsOnlyWhatIsCountedOnTheSameOccasions(): void
    {
        $capturedSql = null;

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;

                return '9';
            });
        $conn->method('fetchAllAssociative')->willReturn([
            ['message' => 'series_unknown', 'reason' => DiscoveryResolverService::REASON_NO_ANCHOR_RESOLVED, 'count' => '40'],
            ['message' => 'author_unavailable', 'reason' => DiscoveryDeadlineExceededException::REASON, 'count' => '3'],
            ['message' => 'series_ambiguous', 'reason' => 'no_clear_winner', 'count' => '7'],
            ['message' => 'author_ambiguous', 'reason' => 'name_not_verified', 'count' => '5'],
            ['message' => 'series_unknown', 'reason' => DiscoveryResolverService::REASON_NO_USABLE_MEMBERS, 'count' => '1'],
        ]);

        $quality = $this->repositoryWithConnection($conn)->entityQualitySince($this->now());

        // 9 cold entity resolutions that produced a payload + the one that
        // came back empty. The other 55 journalled outcomes are none of
        // this ratio's business.
        $this->assertSame(10, $quality['total']);
        $this->assertSame(9, $quality['resolved']);
        $this->assertSame(1, $quality['failed']);
        $this->assertSame(10, $quality['share_percent']);
        $this->assertSame([DiscoveryResolverService::REASON_NO_USABLE_MEMBERS => 1], $quality['reasons']);
        // Successes are entity rows only: lookup rows would inflate the
        // denominator by an amount that varies with the anchor count.
        $this->assertStringContainsStringIgnoringCase('kind IN (:series, :author)', $capturedSql);
    }

    /**
     * Production, 2026-09-01: 11 outcomes whose anchors were simply not
     * indexed, one homonym refusal, zero resolutions. The old ratio read
     * 92% and fired the nightly alarm; the rebased one has nothing to
     * judge and must say so instead of inventing a signal.
     */
    public function testEntityQualityWithholdsTheRatioBelowTheSampleFloor(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn('0');
        $conn->method('fetchAllAssociative')->willReturn([
            ['message' => 'series_unknown', 'reason' => DiscoveryResolverService::REASON_NO_ANCHOR_RESOLVED, 'count' => '11'],
            ['message' => 'author_ambiguous', 'reason' => 'name_not_verified', 'count' => '1'],
        ]);

        $quality = $this->repositoryWithConnection($conn)->entityQualitySince($this->now());

        $this->assertSame(0, $quality['total']);
        $this->assertSame(0, $quality['failed']);
        $this->assertNull($quality['share_percent']);
    }

    /**
     * The two halves of the ratio are trimmed by different rules: cache
     * rows by a 30-day TTL, journal rows by the prune's row cap, oldest
     * first. Once that cap eats the far end of the window, failures
     * under-report while successes do not, and the share reads reassuringly
     * low. Refusing to answer is the only honest output.
     */
    public function testEntityQualityWithholdsTheRatioWhenTheCapCutIntoTheWindow(): void
    {
        // The prune cut and left its frontier INSIDE the window: events
        // older than that are gone, so the window is no longer whole.
        $quality = $this->qualityWithCapMarker([
            'created_at' => '2026-08-19 03:00:00',
            'cutoff' => '2026-08-17 22:00:00',
        ]);

        $this->assertTrue($quality['truncated']);
        $this->assertNull($quality['share_percent']);
        // The raw counts stay readable even when the ratio is withheld.
        $this->assertSame(10, $quality['total']);
        $this->assertSame(1, $quality['failed']);
    }

    /**
     * The guard reads positive evidence, never an absence. Three windows
     * that ARE whole and must be judged: no cut ever recorded, a cut whose
     * frontier predates the window, and a cut that left nothing standing
     * but happened before the window opened.
     *
     * The middle case is the one the first version of this guard got
     * wrong: it inferred a trim from "no event survives from before the
     * window", which is also what a quiet week and a week of nothing but
     * successful resolutions look like, successes being unjournalled.
     */
    public function testEntityQualityJudgesAWindowNoCutEverReachedInto(): void
    {
        $noCutEver = $this->qualityWithCapMarker(null);
        $this->assertFalse($noCutEver['truncated']);
        $this->assertSame(10, $noCutEver['share_percent']);

        $cutBeforeTheWindow = $this->qualityWithCapMarker([
            'created_at' => '2026-08-10 03:00:00',
            'cutoff' => '2026-08-02 07:00:00',
        ]);
        $this->assertFalse($cutBeforeTheWindow['truncated']);
        $this->assertSame(10, $cutBeforeTheWindow['share_percent']);

        // Nothing survived that cut, so the cut's own timestamp is the
        // frontier: still older than the window, so the window is whole.
        $wipedBeforeTheWindow = $this->qualityWithCapMarker([
            'created_at' => '2026-08-11 03:00:00',
            'cutoff' => null,
        ]);
        $this->assertFalse($wipedBeforeTheWindow['truncated']);
        $this->assertSame(10, $wipedBeforeTheWindow['share_percent']);
    }

    /**
     * The frontier is read out of the TEXT context column, not a typed
     * timestamp one, so an unparseable value is reachable without the
     * schema being violated. It must fail open: the dashboard controller
     * does not wrap these calls, so throwing here would 500 /admin over a
     * bookkeeping row.
     */
    public function testEntityQualityJudgesTheWindowWhenTheMarkerCannotBeDated(): void
    {
        $quality = $this->qualityWithCapMarker([
            'created_at' => 'not a date either',
            'cutoff' => 'not a date',
        ]);

        $this->assertFalse($quality['truncated']);
        $this->assertSame(10, $quality['share_percent']);
    }

    /** A cut that left nothing standing, inside the window: unreadable. */
    public function testEntityQualityTreatsAWipeInsideTheWindowAsUncovered(): void
    {
        $quality = $this->qualityWithCapMarker([
            'created_at' => '2026-08-18 03:00:00',
            'cutoff' => null,
        ]);

        $this->assertTrue($quality['truncated']);
        $this->assertNull($quality['share_percent']);
    }

    /**
     * One resolved entity short of the floor, nine plus one failure at it:
     * the fixture is always 9 successes and 1 empty entity, so every case
     * above would read 10% if its window were whole. $capMarker is the
     * latest hub_events_capped row, or null when the cap never cut.
     *
     * @param array<string, string|null>|null $capMarker
     *
     * @return array{total: int, resolved: int, failed: int, reasons: array<string, int>, share_percent: ?int, truncated: bool}
     */
    private function qualityWithCapMarker(?array $capMarker): array
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn('9');
        $conn->method('fetchAllAssociative')->willReturn([
            ['message' => 'series_unknown', 'reason' => DiscoveryResolverService::REASON_NO_USABLE_MEMBERS, 'count' => '1'],
        ]);
        $conn->method('fetchAssociative')->willReturn($capMarker ?? false);

        // Window: the seven days before self::NOW.
        return $this->repositoryWithConnection($conn)
            ->entityQualitySince($this->now()->modify('-7 days'));
    }

    // -------------------------------------------------------------------
    // Anchor coverage: a source fact, read against its own baseline    // -------------------------------------------------------------------
    // Anchor coverage: a source fact, read against its own baseline
    // -------------------------------------------------------------------

    public function testAnchorCoverageReadsLookupRowsOverBothWindows(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;

                return ['total_24' => '12', 'hits_24' => '3', 'total_7d' => '40', 'hits_7d' => '10'];
            });

        $coverage = $this->repositoryWithConnection($conn)->anchorCoverage($this->now());

        $this->assertSame(25, $coverage['last_24h']['share_percent']);
        $this->assertSame(25, $coverage['last_7d']['share_percent']);
        $this->assertSame(3, $coverage['last_24h']['hits']);
        $this->assertFalse($coverage['collapsed']);
        // Entity rows carry no anchor and must stay out of this ratio.
        $this->assertStringContainsStringIgnoringCase('kind IN (:series_lookup, :author_lookup)', $capturedSql);
        $this->assertSame(DiscoveryCache::KIND_SERIES_LOOKUP, $capturedParams['series_lookup']);
        $this->assertSame(DiscoveryCache::KIND_AUTHOR_LOOKUP, $capturedParams['author_lookup']);
        $this->assertSame('2026-08-20 12:00:00', $capturedParams['since24']);
        $this->assertSame('2026-08-14 12:00:00', $capturedParams['since7']);
    }

    /**
     * The one case an absolute threshold could never separate from a
     * catalogue the sources do not index: nothing matched today, while the
     * baseline says it used to. Both windows need enough samples, or a
     * quiet night alone would raise it.
     */
    public function testAnchorCoverageFlagsACollapseOnlyAgainstAPositiveBaseline(): void
    {
        $collapsed = $this->coverageOf(['total_24' => '15', 'hits_24' => '0', 'total_7d' => '60', 'hits_7d' => '18']);
        $this->assertTrue($collapsed['collapsed']);
        $this->assertSame(0, $collapsed['last_24h']['share_percent']);

        // Baseline never matched anything either: a thin catalogue, not a
        // broken anchor query.
        $neverMatched = $this->coverageOf(['total_24' => '15', 'hits_24' => '0', 'total_7d' => '60', 'hits_7d' => '0']);
        $this->assertFalse($neverMatched['collapsed']);

        // Too few lookups today to conclude anything at all.
        $quietDay = $this->coverageOf(['total_24' => '2', 'hits_24' => '0', 'total_7d' => '60', 'hits_7d' => '18']);
        $this->assertFalse($quietDay['collapsed']);
        $this->assertNull($quietDay['last_24h']['share_percent']);
    }

    /**
     * @param array<string, string> $row the aggregate row Postgres returns
     *
     * @return array{last_24h: array{total: int, hits: int, share_percent: ?int}, last_7d: array{total: int, hits: int, share_percent: ?int}, collapsed: bool}
     */
    private function coverageOf(array $row): array
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn($row);

        return $this->repositoryWithConnection($conn)->anchorCoverage($this->now());
    }
}
