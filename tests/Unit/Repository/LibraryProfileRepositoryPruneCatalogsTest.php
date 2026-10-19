<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\LibraryProfileRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for LibraryProfileRepository::pruneExpiredCatalogs().
 *
 * Clients up to 1.0.x skip the catalog re-push when their library is
 * unchanged (false-success keep-alive), so an active device can sit behind
 * an expired cached_catalogs row forever. Pruning that row breaks the
 * directory catalog fallback for every peer while the owner is still
 * around. The prune must therefore only drop rows whose owning profile is
 * itself inactive (last_seen_at older than the inactivity window, NULL, or
 * profile gone). These tests freeze that condition into the SQL contract.
 */
// The repository is a partial double: only getEntityManager() is doubled so
// the real pruneExpiredCatalogs() runs. PHPUnit 12.5 would otherwise flag
// the EntityManager stub as a mock without expectations; opt that check out.
#[AllowMockObjectsWithoutExpectations]
final class LibraryProfileRepositoryPruneCatalogsTest extends TestCase
{
    public function testPruneOnlyTargetsCatalogsOfInactiveOwners(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams): int {
                $capturedSql = $sql;
                $capturedParams = $params;

                return 4;
            });

        $now = new \DateTimeImmutable('2026-07-03 10:00:00');
        $deleted = $this->buildRepository($conn)->pruneExpiredCatalogs($now);

        self::assertSame(4, $deleted, 'affected row count must be passed through');

        self::assertNotNull($capturedSql);
        $normalizedSql = strtolower(preg_replace('/\s+/', ' ', $capturedSql));
        self::assertStringContainsString(
            'expires_at < :now',
            $normalizedSql,
            'the per-row TTL condition must be kept',
        );
        self::assertStringContainsString(
            'not exists',
            $normalizedSql,
            'the delete must be gated on the owning profile being inactive',
        );
        self::assertStringContainsString(
            'last_seen_at >= :activecutoff',
            $normalizedSql,
            'an owner seen within the inactivity window must protect its catalog row',
        );

        self::assertSame('2026-07-03 10:00:00', $capturedParams['now']);
        self::assertSame(
            '2025-07-03 10:00:00',
            $capturedParams['activeCutoff'],
            'inactivity window must be 365 days before the prune timestamp',
        );
    }

    /**
     * The directory catalog is stored in clear. Without a listing or a
     * follow, nobody can legitimately ask for it, so the hub must not keep
     * it once its grace period is over.
     */
    public function testPruneUnsharedCatalogsKeepsOnlyListedOrFollowedLibraries(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams): int {
                $capturedSql = $sql;
                $capturedParams = $params;

                return 130;
            });

        $now = new \DateTimeImmutable('2026-10-19 03:00:00');
        $deleted = $this->buildRepository($conn)->pruneUnsharedCatalogs($now);

        self::assertSame(130, $deleted, 'affected row count must be passed through');

        self::assertNotNull($capturedSql);
        $normalizedSql = strtolower(preg_replace('/\s+/', ' ', $capturedSql));
        self::assertStringStartsWith('delete from cached_catalogs where updated_at < :gracecutoff and not', trim($normalizedSql));
        // An active client may go just under 6 days between two real pushes
        // (4-day keep-alive plus the 2-day local skip). Purging sooner would
        // leave a library that pairs in that gap without its hub copy.
        self::assertSame(
            '2026-10-12 03:00:00',
            $capturedParams['graceCutoff'],
            'grace period must outlast the longest gap between real pushes',
        );
        self::assertStringContainsString(
            'is_listed = true',
            $normalizedSql,
            'a published library keeps its catalog',
        );
        self::assertStringContainsString(
            'follower_node_id = cached_catalogs.node_id',
            $normalizedSql,
            'a follow made by the owner keeps its catalog',
        );
        self::assertStringContainsString(
            'followed_node_id = cached_catalogs.node_id',
            $normalizedSql,
            'a follow toward the owner keeps its catalog',
        );
        self::assertStringNotContainsString(
            'status',
            $normalizedSql,
            'a follow of any status (pending, refused) is a relationship',
        );
    }

    /**
     * Cover directories are kept for every node that still has a catalog row
     * or a catalog audience. Every node id the hub knows of is a candidate,
     * so a follow toward a deleted profile still counts.
     */
    public function testFindNodeIdsKeepingCoversShape(): void
    {
        $capturedSql = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return ['node-a', 'node-b'];
            });

        $kept = $this->buildRepository($conn)->findNodeIdsKeepingCovers();

        self::assertSame(['node-a', 'node-b'], $kept);
        $normalizedSql = strtolower(preg_replace('/\s+/', ' ', $capturedSql));
        foreach ([
            'select node_id from library_profiles',
            'select follower_node_id from follows',
            'select followed_node_id from follows',
            'select node_id from cached_catalogs',
        ] as $source) {
            self::assertStringContainsString($source, $normalizedSql, "candidates must include: $source");
        }
        self::assertStringContainsString(
            'exists (select 1 from cached_catalogs kept_c where kept_c.node_id = n.node_id)',
            $normalizedSql,
            'a node with a catalog row keeps its covers',
        );
        self::assertStringContainsString('is_listed = true', $normalizedSql, 'the audience rule applies');
    }

    private function buildRepository(Connection $conn): LibraryProfileRepository
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $repository = $this->getMockBuilder(LibraryProfileRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityManager'])
            ->getMock();
        $repository->method('getEntityManager')->willReturn($em);

        return $repository;
    }
}
