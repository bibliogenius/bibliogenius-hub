<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\DiscoveryCache;
use App\Repository\DiscoveryCacheRepository;
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
 *  - the 24h resolutions counter reads the same population and column
 *    (updated_at) as the existing nonResolvedSharePercentLast24h(), so the
 *    two figures shown side by side on the dashboard actually agree;
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

    public function testCountResolutionsLast24hUsesSameColumnAsNonResolvedShare(): void
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
        $this->assertStringContainsStringIgnoringCase("'reason' = :reason", $capturedSql);
        $this->assertSame(
            ['reason' => 'outbound_budget_exhausted', 'since' => '2026-08-14 12:00:00'],
            $capturedParams,
        );
    }
}
