<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\PruneCommand;
use App\Repository\Deposit404LogRepository;
use App\Repository\DirectoryHealthRepository;
use App\Repository\DiscoveryCacheRepository;
use App\Repository\InviteTokenRepository;
use App\Repository\LibraryProfileRepository;
use App\Repository\RelayMailboxRepository;
use App\Service\DirectoryService;
use App\Service\HubEventLogger;
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
// The coverage-check tests configure stub-only collaborators (health repo
// via willReturn, never verified); opt out of PHPUnit 12.5's
// no-expectations notice.
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class PruneCommandTest extends TestCase
{
    public function testExecuteInsertsPruneRunEventWithCorrectPayload(): void
    {
        $conn = $this->createMock(Connection::class);
        $inviteRepo = $this->createStub(InviteTokenRepository::class);
        $deposit404Repo = $this->createStub(Deposit404LogRepository::class);
        $directoryService = $this->createStub(DirectoryService::class);
        $profileRepo = $this->createStub(LibraryProfileRepository::class);

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
        // Cached-catalogs prune: 12 expired rows dropped. Mirrors the audit
        // finding of 18/61 expired rows that motivated wiring this in.
        $profileRepo->method('pruneExpiredCatalogs')->willReturn(12);
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

        $tester = $this->buildCommandTester($conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo);
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

        // 10 + (3+2) + 6 + 7 + 4 + 9 + 12 + 2 + 0 = 55
        $this->assertSame(55, $context['total_deleted']);
        $this->assertSame(
            [
                'relay_messages' => 10,
                'relay_mailboxes' => 5,
                'invite_tokens' => 6,
                'registration_failures' => 7,
                'hub_events' => 4,
                'deposit_404_log' => 9,
                'cached_catalogs' => 12,
                'orphan_covers' => 2,
                'discovery_cache' => 0,
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
        $profileRepo = $this->createStub(LibraryProfileRepository::class);

        $conn->method('executeStatement')->willReturn(0);
        $conn->method('fetchOne')->willReturn(0);
        $inviteRepo->method('deleteExpired')->willReturn(0);
        $deposit404Repo->method('pruneOlderThanDays')->willReturn(0);
        $profileRepo->method('pruneExpiredCatalogs')->willReturn(0);
        $directoryService->method('pruneOrphanCoversForAllNodes')->willReturn(0);

        $conn->expects($this->once())
            ->method('insert')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        $tester = $this->buildCommandTester($conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo);
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
        $profileRepo = $this->createStub(LibraryProfileRepository::class);

        $conn->method('executeStatement')->willReturn(0);
        $conn->method('fetchOne')->willReturn(0);
        $inviteRepo->method('deleteExpired')->willReturn(0);
        $deposit404Repo->method('pruneOlderThanDays')->willReturn(0);
        $profileRepo->method('pruneExpiredCatalogs')->willReturn(0);
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

        $tester = $this->buildCommandTester($conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertNotNull($insertArgs);
        $context = json_decode($insertArgs['context'], true);
        $this->assertSame(0, $context['per_table']['orphan_covers']);
    }

    public function testCachedCatalogsStepReportsCount(): void
    {
        // Guards the wiring shipped to fix the production audit finding of
        // 18/61 expired cached_catalogs rows. If pruneExpiredCatalogs stops
        // being called, the per_table key disappears and the cache resumes
        // its slow drift back toward unbounded growth.
        $conn = $this->createMock(Connection::class);
        $inviteRepo = $this->createStub(InviteTokenRepository::class);
        $deposit404Repo = $this->createStub(Deposit404LogRepository::class);
        $directoryService = $this->createStub(DirectoryService::class);
        $profileRepo = $this->createMock(LibraryProfileRepository::class);

        $conn->method('executeStatement')->willReturn(0);
        $conn->method('fetchOne')->willReturn(0);
        $inviteRepo->method('deleteExpired')->willReturn(0);
        $deposit404Repo->method('pruneOlderThanDays')->willReturn(0);
        $directoryService->method('pruneOrphanCoversForAllNodes')->willReturn(0);
        $profileRepo->expects($this->once())
            ->method('pruneExpiredCatalogs')
            ->willReturn(18);

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

        $tester = $this->buildCommandTester($conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertNotNull($insertArgs);
        $context = json_decode($insertArgs['context'], true);
        $this->assertSame(18, $context['per_table']['cached_catalogs']);
        $this->assertStringContainsString('cached_catalogs', $tester->getDisplay());
    }

    public function testDanglingMailboxReferencesClearedOnEveryRun(): void
    {
        // The mailbox TTL prune leaves soft references dangling in
        // library_profiles (no FK); each run must clear them so pruned
        // mailboxes do not accumulate as dashboard orphan references and
        // the affected profiles stay eligible for purgeStaleProfiles. The
        // cleared count is logged but must NOT inflate total_deleted:
        // references are nulled, not rows deleted.
        [$conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo] = $this->quietCollaborators();

        $relayMailboxRepo = $this->createMock(RelayMailboxRepository::class);
        $relayMailboxRepo->expects($this->once())
            ->method('clearDanglingProfileReferences')
            ->willReturn(34);

        $insertArgs = null;
        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willReturn(0);
        $conn->method('fetchOne')->willReturn(0);
        $conn->method('insert')
            ->with(
                $this->equalTo('hub_events'),
                $this->callback(function (array $data) use (&$insertArgs) {
                    $insertArgs = $data;
                    return true;
                }),
            );

        $tester = $this->buildCommandTester(
            $conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo,
            relayMailboxRepo: $relayMailboxRepo,
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertStringContainsString('34 cleared', $tester->getDisplay());

        $this->assertNotNull($insertArgs);
        $context = json_decode($insertArgs['context'], true);
        $this->assertSame(0, $context['total_deleted']);
        $this->assertSame(0, $context['per_table']['relay_mailboxes']);
    }

    public function testCoverageAlertEmittedAboveThresholdViaCritical(): void
    {
        // The nightly run is what makes the coverage alert autonomous: it
        // must page through the critical level (the only one the cron log
        // alerter greps for), not through error().
        [$conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo] = $this->quietCollaborators();

        $health = $this->createMock(DirectoryHealthRepository::class);
        $health->method('countCatalogCoverageGaps')->willReturn(50);
        $health->method('lastCoverageAlertAt')->willReturn(null);

        $eventLogger = $this->createMock(HubEventLogger::class);
        $eventLogger->expects($this->once())
            ->method('critical')
            ->with('maintenance', 'catalog_coverage_degraded', ['count' => 50]);
        $eventLogger->expects($this->never())->method('error');

        $tester = $this->buildCommandTester(
            $conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo,
            directoryHealth: $health,
            eventLogger: $eventLogger,
            catalogCoverageAlertThreshold: 40,
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('catalog coverage', $tester->getDisplay());
    }

    public function testCoverageAlertDeduplicatedByRecentEmission(): void
    {
        // Same gap count, but an alert already went out 1h ago (e.g. from a
        // dashboard render): the nightly pass must stay silent.
        [$conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo] = $this->quietCollaborators();

        $health = $this->createMock(DirectoryHealthRepository::class);
        $health->method('countCatalogCoverageGaps')->willReturn(50);
        $health->method('lastCoverageAlertAt')->willReturn(new \DateTimeImmutable('-1 hour'));

        $eventLogger = $this->createMock(HubEventLogger::class);
        $eventLogger->expects($this->never())->method('critical');

        $tester = $this->buildCommandTester(
            $conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo,
            directoryHealth: $health,
            eventLogger: $eventLogger,
            catalogCoverageAlertThreshold: 40,
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }

    public function testCoverageCheckFailureDoesNotBreakCommand(): void
    {
        // Monitoring is secondary: a DB error in the health check must not
        // abort the prune (its primary job) nor fail the cron.
        [$conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo] = $this->quietCollaborators();

        $health = $this->createMock(DirectoryHealthRepository::class);
        $health->method('countCatalogCoverageGaps')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        $eventLogger = $this->createMock(HubEventLogger::class);
        $eventLogger->expects($this->never())->method('critical');

        $tester = $this->buildCommandTester(
            $conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo,
            directoryHealth: $health,
            eventLogger: $eventLogger,
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Catalog coverage check failed', $tester->getDisplay());
    }

    /**
     * The prune applies the same row cap as HubEventLogger's own cleanup,
     * from its own code path, so it must record the cut the same way: a
     * monitoring window otherwise cannot tell deleted events from events
     * that never happened.
     */
    public function testHubEventsCapRecordsTheCutThroughTheEventLog(): void
    {
        [, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo] = $this->quietCollaborators();

        $conn = $this->createMock(Connection::class);
        // Every DELETE reports rows removed, so the cap branch is taken.
        $conn->method('executeStatement')->willReturn(200);
        $conn->method('fetchOne')->willReturnCallback(
            static fn (string $sql): mixed => str_contains($sql, 'COUNT(*) FROM hub_events') ? 1200 : 0,
        );

        $eventLogger = $this->createMock(HubEventLogger::class);
        $eventLogger->expects($this->once())->method('recordCapCut')->with(200);

        $tester = $this->buildCommandTester(
            $conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo,
            eventLogger: $eventLogger,
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }

    /** Below the cap, nothing is cut and nothing is recorded. */
    public function testHubEventsBelowTheCapRecordsNoCut(): void
    {
        [$conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo] = $this->quietCollaborators();

        $eventLogger = $this->createMock(HubEventLogger::class);
        $eventLogger->expects($this->never())->method('recordCapCut');

        $tester = $this->buildCommandTester(
            $conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo,
            eventLogger: $eventLogger,
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }

    public function testDiscoveryCacheStepReportsCount(): void
    {
        // Guards the ADR-060 wiring: if pruneExpired stops being called, the
        // per_table key disappears and the pooled cache grows unbounded
        // (storage policy violation).
        [$conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo] = $this->quietCollaborators();

        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willReturn(0);
        $conn->method('fetchOne')->willReturn(0);
        $insertArgs = null;
        $conn->method('insert')
            ->with(
                $this->equalTo('hub_events'),
                $this->callback(function (array $data) use (&$insertArgs) {
                    $insertArgs = $data;

                    return true;
                }),
            );

        $discoveryRepo = $this->createMock(DiscoveryCacheRepository::class);
        $discoveryRepo->expects($this->once())
            ->method('pruneExpired')
            ->willReturn(21);
        $discoveryRepo->method('entityQualitySince')->willReturn(self::quality(null, 0, 0));
        $discoveryRepo->method('lastDriftAlertAt')->willReturn(null);

        $tester = $this->buildCommandTester(
            $conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo,
            discoveryCacheRepo: $discoveryRepo,
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertNotNull($insertArgs);
        $context = json_decode($insertArgs['context'], true);
        $this->assertSame(21, $context['per_table']['discovery_cache']);
        $this->assertStringContainsString('discovery_cache', $tester->getDisplay());
    }

    public function testDiscoveryDriftAlertEmittedAboveThresholdViaWarning(): void
    {
        // ADR-060 section 3.5: source drift pages through warning() (visible
        // in /admin), not critical(), and dedups on the last emission.
        [$conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo] = $this->quietCollaborators();

        $discoveryRepo = $this->createMock(DiscoveryCacheRepository::class);
        $discoveryRepo->method('pruneExpired')->willReturn(0);
        $discoveryRepo->method('entityQualitySince')->willReturn(self::quality(80, 8, 10));
        $discoveryRepo->method('lastDriftAlertAt')->willReturn(null);

        $eventLogger = $this->createMock(HubEventLogger::class);
        $eventLogger->expects($this->once())
            ->method('warning')
            ->with('maintenance', 'discovery_drift_degraded', ['count' => 80, 'failed' => 8, 'total' => 10]);

        $tester = $this->buildCommandTester(
            $conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo,
            eventLogger: $eventLogger,
            discoveryCacheRepo: $discoveryRepo,
            discoveryDriftAlertThreshold: 50,
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('discovery quality', $tester->getDisplay());
    }

    /**
     * A window the journal no longer covers cannot produce an honest share,
     * only a reassuringly low one: failures are trimmed by the row cap
     * while successes live on in the cache. The tripwire must then say
     * nothing rather than alert or, worse, silently report health.
     */
    public function testDiscoveryDriftStaysSilentWhenTheJournalWasTrimmed(): void
    {
        [$conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo] = $this->quietCollaborators();

        $discoveryRepo = $this->createMock(DiscoveryCacheRepository::class);
        $discoveryRepo->method('pruneExpired')->willReturn(0);
        // Above the threshold, but computed over an incomplete window: the
        // repository withholds the share and raises the flag.
        $discoveryRepo->method('entityQualitySince')->willReturn(self::quality(null, 8, 10, true));
        $discoveryRepo->method('lastDriftAlertAt')->willReturn(null);

        $eventLogger = $this->createMock(HubEventLogger::class);
        $eventLogger->expects($this->never())->method('warning');

        $tester = $this->buildCommandTester(
            $conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo,
            eventLogger: $eventLogger,
            discoveryCacheRepo: $discoveryRepo,
            discoveryDriftAlertThreshold: 50,
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('hub_events trimmed past the 7d window', $tester->getDisplay());
    }

    public function testDiscoveryDriftStaysSilentBelowThresholdAndWithoutSamples(): void
    {
        [$conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo] = $this->quietCollaborators();

        $discoveryRepo = $this->createMock(DiscoveryCacheRepository::class);
        $discoveryRepo->method('pruneExpired')->willReturn(0);
        // Below threshold: reported but no alert.
        $discoveryRepo->method('entityQualitySince')->willReturn(self::quality(20, 2, 10));
        $discoveryRepo->method('lastDriftAlertAt')->willReturn(null);

        $eventLogger = $this->createMock(HubEventLogger::class);
        $eventLogger->expects($this->never())->method('warning');

        $tester = $this->buildCommandTester(
            $conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo,
            eventLogger: $eventLogger,
            discoveryCacheRepo: $discoveryRepo,
            discoveryDriftAlertThreshold: 50,
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        // The window is part of the contract: entity fetches are too rare
        // for 24h to ever gather enough samples, so the tripwire looks back
        // a week and says so in its own output.
        $this->assertStringContainsString('20% empty entities (2 of 10 over 7d', $tester->getDisplay());
    }

    /**
     * Collaborators for tests that only exercise the coverage check: every
     * prune step is stubbed to a no-op so the command runs end to end.
     *
     * @return array{Connection, InviteTokenRepository, Deposit404LogRepository, DirectoryService, LibraryProfileRepository}
     */
    private function quietCollaborators(): array
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('executeStatement')->willReturn(0);
        $conn->method('fetchOne')->willReturn(0);

        $inviteRepo = $this->createStub(InviteTokenRepository::class);
        $inviteRepo->method('deleteExpired')->willReturn(0);

        $deposit404Repo = $this->createStub(Deposit404LogRepository::class);
        $deposit404Repo->method('pruneOlderThanDays')->willReturn(0);

        $directoryService = $this->createStub(DirectoryService::class);
        $directoryService->method('pruneOrphanCoversForAllNodes')->willReturn(0);

        $profileRepo = $this->createStub(LibraryProfileRepository::class);
        $profileRepo->method('pruneExpiredCatalogs')->willReturn(0);

        return [$conn, $inviteRepo, $deposit404Repo, $directoryService, $profileRepo];
    }

    /**
     * The entity-quality struct the drift check reads. Built here so a
     * change to its shape breaks one line, not seven.
     *
     * @return array{total: int, resolved: int, failed: int, reasons: array<string, int>, share_percent: ?int, truncated: bool}
     */
    private static function quality(?int $share, int $failed, int $total, bool $truncated = false): array
    {
        return [
            'total' => $total,
            'resolved' => $total - $failed,
            'failed' => $failed,
            'reasons' => [],
            'share_percent' => $share,
            'truncated' => $truncated,
        ];
    }

    private function buildCommandTester(
        Connection $conn,
        InviteTokenRepository $inviteRepo,
        Deposit404LogRepository $deposit404Repo,
        DirectoryService $directoryService,
        LibraryProfileRepository $profileRepo,
        ?DirectoryHealthRepository $directoryHealth = null,
        ?HubEventLogger $eventLogger = null,
        int $catalogCoverageAlertThreshold = 40,
        ?RelayMailboxRepository $relayMailboxRepo = null,
        ?DiscoveryCacheRepository $discoveryCacheRepo = null,
        int $discoveryDriftAlertThreshold = 50,
    ): CommandTester {
        if ($relayMailboxRepo === null) {
            $relayMailboxRepo = $this->createStub(RelayMailboxRepository::class);
            $relayMailboxRepo->method('clearDanglingProfileReferences')->willReturn(0);
        }
        if ($discoveryCacheRepo === null) {
            // Stub discovery cache: nothing pruned, not enough drift samples,
            // so legacy tests keep their totals and stay alert-free.
            $discoveryCacheRepo = $this->createStub(DiscoveryCacheRepository::class);
            $discoveryCacheRepo->method('pruneExpired')->willReturn(0);
            $discoveryCacheRepo->method('entityQualitySince')->willReturn(self::quality(null, 0, 0));
            $discoveryCacheRepo->method('lastDriftAlertAt')->willReturn(null);
        }

        $command = new PruneCommand(
            $conn,
            $inviteRepo,
            $deposit404Repo,
            $directoryService,
            $profileRepo,
            $relayMailboxRepo,
            $discoveryCacheRepo,
            // Stub health: 0 gaps, never alerted; below any threshold, so
            // legacy tests keep exercising the prune steps alert-free.
            $directoryHealth ?? $this->createStub(DirectoryHealthRepository::class),
            $eventLogger ?? $this->createStub(HubEventLogger::class),
            $catalogCoverageAlertThreshold,
            $discoveryDriftAlertThreshold,
        );
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('app:db:prune'));
    }
}
