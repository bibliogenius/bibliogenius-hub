<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\PruneCommand;
use App\Repository\Deposit404LogRepository;
use App\Repository\InviteTokenRepository;
use App\Service\DirectoryService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Guards the observability contract shipped alongside the "Last prune" BO tile:
 * each successful run MUST insert one marker row into hub_events with
 * channel=maintenance, message=prune_run, and a JSON context carrying
 * total_deleted + per_table. If this insert disappears, the dashboard tile
 * goes permanently stale and a broken VPS cron becomes invisible again.
 */
final class PruneCommandTest extends TestCase
{
    public function testExecuteInsertsPruneRunEventWithCorrectPayload(): void
    {
        $conn = $this->createMock(Connection::class);
        $inviteRepo = $this->createStub(InviteTokenRepository::class);
        $deposit404Repo = $this->createStub(Deposit404LogRepository::class);
        $directoryService = $this->createStub(DirectoryService::class);

        // DELETE counts: relay_messages, relay_mailboxes (2 calls), registration_failures, hub_events TTL
        // pruneHubEvents also does fetchOne + optional cap DELETE; mock count <= MAX to skip cap.
        $conn->expects($this->exactly(5))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(
                10, // relay_messages
                3,  // relay_mailboxes (last_accessed branch)
                2,  // relay_mailboxes (null last_accessed branch)
                7,  // registration_failures
                4,  // hub_events TTL delete
            );
        $conn->method('fetchOne')->willReturn(500); // hub_events count, below cap
        $inviteRepo->method('deleteExpired')->willReturn(6);
        $deposit404Repo->method('pruneOlderThanDays')->willReturn(9);
        // Orphan-cover sweep (ADR-033): 2 files deleted across all nodes.
        $directoryService->method('pruneOrphanCoversForAllNodes')->willReturn(2);

        $insertArgs = null;
        $conn->expects($this->once())
            ->method('insert')
            ->with(
                $this->equalTo('hub_events'),
                $this->callback(function (array $data) use (&$insertArgs) {
                    $insertArgs = $data;
                    return true;
                }),
            );

        $tester = $this->buildCommandTester($conn, $inviteRepo, $deposit404Repo, $directoryService);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertNotNull($insertArgs, 'hub_events insert must be called');
        $this->assertSame('info', $insertArgs['level']);
        $this->assertSame('maintenance', $insertArgs['channel']);
        $this->assertSame('prune_run', $insertArgs['message']);

        $context = json_decode($insertArgs['context'], true);
        $this->assertIsArray($context, 'context must be valid JSON');
        $this->assertArrayHasKey('total_deleted', $context);
        $this->assertArrayHasKey('per_table', $context);

        // 10 + (3+2) + 6 + 7 + 4 + 9 + 2 = 43
        $this->assertSame(43, $context['total_deleted']);
        $this->assertSame(
            [
                'relay_messages' => 10,
                'relay_mailboxes' => 5,
                'invite_tokens' => 6,
                'registration_failures' => 7,
                'hub_events' => 4,
                'deposit_404_log' => 9,
                'orphan_covers' => 2,
            ],
            $context['per_table'],
        );
    }

    public function testInsertFailureDoesNotBreakCommand(): void
    {
        $conn = $this->createMock(Connection::class);
        $inviteRepo = $this->createStub(InviteTokenRepository::class);
        $deposit404Repo = $this->createStub(Deposit404LogRepository::class);
        $directoryService = $this->createStub(DirectoryService::class);

        $conn->method('executeStatement')->willReturn(0);
        $conn->method('fetchOne')->willReturn(0);
        $inviteRepo->method('deleteExpired')->willReturn(0);
        $deposit404Repo->method('pruneOlderThanDays')->willReturn(0);
        $directoryService->method('pruneOrphanCoversForAllNodes')->willReturn(0);

        $conn->expects($this->once())
            ->method('insert')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        $tester = $this->buildCommandTester($conn, $inviteRepo, $deposit404Repo, $directoryService);
        $tester->execute([]);

        // Prune itself succeeded; observability failure is best-effort only.
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Failed to log prune_run event', $tester->getDisplay());
    }

    public function testOrphanCoversStepFailureDoesNotBreakCommand(): void
    {
        // A runtime error in the cover sweep (e.g. I/O failure, unreadable dir)
        // must not abort the other prune steps. The marker event still fires
        // with orphan_covers=0 so the dashboard does not wedge.
        $conn = $this->createMock(Connection::class);
        $inviteRepo = $this->createStub(InviteTokenRepository::class);
        $deposit404Repo = $this->createStub(Deposit404LogRepository::class);
        $directoryService = $this->createStub(DirectoryService::class);

        $conn->method('executeStatement')->willReturn(0);
        $conn->method('fetchOne')->willReturn(0);
        $inviteRepo->method('deleteExpired')->willReturn(0);
        $deposit404Repo->method('pruneOlderThanDays')->willReturn(0);
        $directoryService->method('pruneOrphanCoversForAllNodes')
            ->willThrowException(new \RuntimeException('disk I/O'));

        $insertArgs = null;
        $conn->expects($this->once())
            ->method('insert')
            ->with(
                $this->equalTo('hub_events'),
                $this->callback(function (array $data) use (&$insertArgs) {
                    $insertArgs = $data;
                    return true;
                }),
            );

        $tester = $this->buildCommandTester($conn, $inviteRepo, $deposit404Repo, $directoryService);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertNotNull($insertArgs);
        $context = json_decode($insertArgs['context'], true);
        $this->assertSame(0, $context['per_table']['orphan_covers']);
    }

    private function buildCommandTester(
        Connection $conn,
        InviteTokenRepository $inviteRepo,
        Deposit404LogRepository $deposit404Repo,
        DirectoryService $directoryService,
    ): CommandTester {
        $command = new PruneCommand($conn, $inviteRepo, $deposit404Repo, $directoryService);
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('app:db:prune'));
    }
}
