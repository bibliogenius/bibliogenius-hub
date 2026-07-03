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
            '2026-06-03 10:00:00',
            $capturedParams['activeCutoff'],
            'inactivity window must be 30 days before the prune timestamp',
        );
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
