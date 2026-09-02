<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Follow;
use App\Repository\FollowRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Guards the /admin relationship counters.
 *
 * A follows row is directed, so counting rows answers "how many follow
 * gestures happened", never "how many libraries are connected to each
 * other". A hundred rows can be a hundred readers following one showcase
 * library with no mutual relationship anywhere. Reciprocity is the figure
 * an exchange, group or peer-suggestion feature actually rests on, so:
 *
 *  - a mutual pair is counted ONCE, via the ordering guard rather than a
 *    division: unique_follow already forbids a duplicate edge, but nothing
 *    forbids a node following itself, and the division would hide that;
 *  - both directions must be active: a pending answer to an active follow
 *    is not a relationship yet;
 *  - the status breakdown always carries the four known statuses, so a
 *    template can index it without guarding every key.
 *
 * FollowRepository extends ServiceEntityRepository, so it is built here
 * through a mocked ManagerRegistry / EntityManager pair, exactly what that
 * base class resolves lazily; the DBAL Connection mock underneath is
 * inspected the same way DiscoveryCacheRepositoryStatsTest inspects its own.
 */
#[AllowMockObjectsWithoutExpectations]
final class FollowRepositoryStatsTest extends TestCase
{
    private function repositoryWithConnection(Connection $connection): FollowRepository
    {
        // A mock ClassMetadata leaves the promoted $name property
        // uninitialized, which EntityRepository::getEntityName() reads
        // eagerly; a real instance is cheap and side-effect-free.
        $classMetadata = new ClassMetadata(Follow::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return new FollowRepository($registry);
    }

    /**
     * @param list<array<string,mixed>> $statusRows
     */
    private function connectionReturning(
        array $statusRows,
        int $reciprocalPairs,
        int $librariesWithActiveEdge,
        ?string &$capturedPairSql = null,
        ?array &$capturedPairParams = null,
    ): Connection {
        $conn = $this->createMock(Connection::class);

        $conn->method('fetchAllAssociative')->willReturn($statusRows);

        $conn->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params = []) use (
                $reciprocalPairs,
                $librariesWithActiveEdge,
                &$capturedPairSql,
                &$capturedPairParams,
            ) {
                if (str_contains($sql, 'JOIN follows')) {
                    $capturedPairSql = $sql;
                    $capturedPairParams = $params;

                    return (string) $reciprocalPairs;
                }

                return (string) $librariesWithActiveEdge;
            },
        );

        return $conn;
    }

    public function testReciprocalPairsCountsEachMutualPairOnce(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->connectionReturning(
            [['status' => Follow::STATUS_ACTIVE, 'count' => '4']],
            2,
            4,
            $capturedSql,
            $capturedParams,
        );

        $stats = $this->repositoryWithConnection($conn)->relationshipStats();

        $this->assertSame(2, $stats['reciprocal_pairs']);

        // The ordering guard is what makes a pair count once, and it also
        // drops a self-follow, which a COUNT(*) / 2 would round away.
        $this->assertStringContainsString('a.follower_node_id < a.followed_node_id', $capturedSql);
        $this->assertStringNotContainsString('/ 2', $capturedSql);
    }

    public function testReciprocalPairsRequiresBothDirectionsActive(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->connectionReturning([], 0, 0, $capturedSql, $capturedParams);

        $this->repositoryWithConnection($conn)->relationshipStats();

        // Both sides of the self-join are constrained, and the status travels
        // as a bound parameter rather than being interpolated into the SQL.
        $this->assertStringContainsString('a.status = ?', $capturedSql);
        $this->assertStringContainsString('b.status = ?', $capturedSql);
        $this->assertSame([Follow::STATUS_ACTIVE, Follow::STATUS_ACTIVE], $capturedParams);
    }

    public function testStatusBreakdownAlwaysCarriesTheFourKnownStatuses(): void
    {
        $conn = $this->connectionReturning(
            [
                ['status' => Follow::STATUS_ACTIVE, 'count' => '5'],
                ['status' => Follow::STATUS_PENDING, 'count' => '2'],
            ],
            1,
            6,
        );

        $stats = $this->repositoryWithConnection($conn)->relationshipStats();

        $this->assertSame(
            [
                Follow::STATUS_PENDING  => 2,
                Follow::STATUS_ACTIVE   => 5,
                Follow::STATUS_REJECTED => 0,
                Follow::STATUS_BLOCKED  => 0,
            ],
            $stats['by_status'],
        );
    }

    public function testTotalIsTheSumOfEveryStatus(): void
    {
        $conn = $this->connectionReturning(
            [
                ['status' => Follow::STATUS_ACTIVE, 'count' => '5'],
                ['status' => Follow::STATUS_PENDING, 'count' => '2'],
                ['status' => Follow::STATUS_BLOCKED, 'count' => '1'],
            ],
            1,
            6,
        );

        $stats = $this->repositoryWithConnection($conn)->relationshipStats();

        $this->assertSame(8, $stats['total']);
    }

    public function testAnEmptyTableReportsZeroesRatherThanMissingKeys(): void
    {
        $conn = $this->connectionReturning([], 0, 0);

        $stats = $this->repositoryWithConnection($conn)->relationshipStats();

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['reciprocal_pairs']);
        $this->assertSame(0, $stats['libraries_with_active_edge']);
        $this->assertSame(0, $stats['by_status'][Follow::STATUS_ACTIVE]);
    }
}
