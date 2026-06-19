<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\Deposit404LogRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Guards the observability contract of the deposit_404_log table:
 *
 *  - recordHit() MUST be a single parameterised upsert so repeated 404s for
 *    the same mailbox within the same hour collapse into one row with an
 *    incremented count. If this regresses to a plain INSERT, the table grows
 *    one-row-per-warning and we are back to the hub_events flood the table
 *    was introduced to fix.
 *  - countSince() MUST sum the `count` column, not COUNT(*). The dashboard
 *    tile displays the true number of 404 hits in the window; COUNT(*) would
 *    under-report dramatically (one row can cover dozens of hits).
 *  - A DB failure in recordHit() MUST be swallowed so a logging issue can
 *    never turn a 404 response into a 500.
 */
// Several tests configure stub-only collaborators (Connection used via
// willReturn, never verified); opt out of PHPUnit 12.5's no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class Deposit404LogRepositoryTest extends TestCase
{
    private const UUID = '2c418b23-00ba-449e-89ad-0a956478d0ae';

    public function testRecordHitIssuesParameterisedUpsert(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql) {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->callback(function (array $params) use (&$capturedParams) {
                    $capturedParams = $params;
                    return true;
                }),
            )
            ->willReturn(1);

        (new Deposit404LogRepository($conn))->recordHit(self::UUID);

        $this->assertNotNull($capturedSql);
        $this->assertStringContainsStringIgnoringCase('INSERT INTO deposit_404_log', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('ON CONFLICT', $capturedSql);
        $this->assertStringContainsStringIgnoringCase("DATE_TRUNC('hour'", $capturedSql);
        $this->assertStringContainsStringIgnoringCase('count = deposit_404_log.count + 1', $capturedSql);

        $this->assertSame([self::UUID], $capturedParams, 'uuid must be the only bound parameter');
    }

    public function testRecordHitSwallowsDatabaseFailure(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willThrowException(new \RuntimeException('DB down'));

        // Must not propagate: a logging failure must never turn a 404 into a 500.
        (new Deposit404LogRepository($conn))->recordHit(self::UUID);
        $this->expectNotToPerformAssertions();
    }

    public function testCountSinceSumsCountColumn(): void
    {
        $since = new \DateTimeImmutable('2026-04-22 04:46:00');

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (string $sql) {
                    return str_contains($sql, 'SUM(count)')
                        && str_contains($sql, 'FROM deposit_404_log')
                        && str_contains($sql, 'hour_bucket >=');
                }),
                $this->equalTo([$since->format('Y-m-d H:i:s')]),
            )
            ->willReturn('42');

        $result = (new Deposit404LogRepository($conn))->countSince($since);

        $this->assertSame(42, $result);
    }

    public function testCountSinceReturnsZeroOnNull(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(null); // empty table

        $this->assertSame(
            0,
            (new Deposit404LogRepository($conn))->countSince(new \DateTimeImmutable('-1 day')),
        );
    }

    public function testPruneDeletesRowsOlderThanTtl(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains("hour_bucket < NOW() - INTERVAL '30 days'"))
            ->willReturn(17);

        $this->assertSame(17, (new Deposit404LogRepository($conn))->pruneOlderThanDays(30));
    }
}
