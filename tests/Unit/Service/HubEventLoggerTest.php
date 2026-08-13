<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\HubEventLogger;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guards the alerting contract of HubEventLogger::critical():
 *
 *  - it MUST log to Monolog at critical (level 500), the only level the
 *    host-side cron alerter (scripts/alert_critical_logs.sh) greps for in
 *    docker logs; error() stays at 400 and never pages;
 *  - it MUST persist the hub_events row with level 'error' so the event
 *    keeps counting in the dashboard errors tile (there is no 'critical'
 *    tile and the recent-errors query filters on level = 'error');
 *  - the Monolog emission MUST survive a DB failure: paging matters most
 *    precisely when the database is degraded.
 */
#[AllowMockObjectsWithoutExpectations]
final class HubEventLoggerTest extends TestCase
{
    public function testCriticalLogsAtCriticalLevelAndPersistsAsErrorRow(): void
    {
        $insertArgs = null;
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('insert')
            ->with(
                $this->equalTo('hub_events'),
                $this->callback(function (array $data) use (&$insertArgs) {
                    $insertArgs = $data;
                    return true;
                }),
            );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->with('[maintenance] catalog_coverage_degraded', ['count' => 42]);
        $logger->expects($this->never())->method('error');

        (new HubEventLogger($conn, $logger))
            ->critical('maintenance', 'catalog_coverage_degraded', ['count' => 42]);

        $this->assertNotNull($insertArgs);
        $this->assertSame('error', $insertArgs['level'], 'row must count in the errors tile');
        $this->assertSame('maintenance', $insertArgs['channel']);
        $this->assertSame('catalog_coverage_degraded', $insertArgs['message']);
        $this->assertSame(['count' => 42], json_decode($insertArgs['context'], true));
    }

    public function testAuditPersistsAtInfoLevelSoDashboardTilesStayClean(): void
    {
        $insertArgs = null;
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('insert')
            ->with(
                $this->equalTo('hub_events'),
                $this->callback(function (array $data) use (&$insertArgs) {
                    $insertArgs = $data;
                    return true;
                }),
            );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        (new HubEventLogger($conn, $logger))->audit('admin', 'library_export', ['node_id' => 'abc']);

        self::assertNotNull($insertArgs);
        // 'warning' or 'error' here would make a routine admin action show up
        // in the dashboard alert tiles.
        self::assertSame('info', $insertArgs['level']);
        self::assertSame('admin', $insertArgs['channel']);
        self::assertSame('library_export', $insertArgs['message']);
        self::assertStringContainsString('abc', (string) $insertArgs['context']);
    }

    public function testCriticalStillPagesWhenDbInsertFails(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('insert')->willThrowException(new \RuntimeException('DB down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('critical');

        // Must not throw either: alerting is best-effort on the DB side.
        (new HubEventLogger($conn, $logger))->critical('maintenance', 'catalog_coverage_degraded');
    }

    public function testErrorStaysAtErrorLevel(): void
    {
        // Contrast guard: if error() ever silently escalates to critical,
        // every error-level event starts paging and the alert loses meaning.
        $conn = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $logger->expects($this->never())->method('critical');

        (new HubEventLogger($conn, $logger))->error('directory', 'some failure');
    }
}
