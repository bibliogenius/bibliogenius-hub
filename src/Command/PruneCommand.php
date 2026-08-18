<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\Deposit404LogRepository;
use App\Repository\DirectoryHealthRepository;
use App\Repository\InviteTokenRepository;
use App\Repository\LibraryProfileRepository;
use App\Repository\RelayMailboxRepository;
use App\Service\DirectoryService;
use App\Service\HubEventLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Nightly database pruning - run via cron to keep tables lean.
 *
 * Tables covered:
 *   relay_messages        - TTL 7 days  (E2EE blobs, largest table)
 *   relay_mailboxes       - TTL 90 days (last_accessed or created_at)
 *   invite_tokens         - TTL 30 days
 *   registration_failures - TTL 90 days (audit trail)
 *   hub_events            - TTL 30 days + cap 1000 rows
 *   deposit_404_log       - TTL 30 days (aggregated, so cap is implicit)
 *   cached_catalogs       - per-row expires_at (ADR-027 catalog cache)
 *   orphan_covers         - catalog-driven filesystem sweep (ADR-033)
 */
#[AsCommand(
    name: 'app:db:prune',
    description: 'Prune stale rows from all time-bounded tables',
)]
class PruneCommand extends Command
{
    private const RELAY_MESSAGE_TTL_DAYS = 7;
    private const RELAY_MAILBOX_TTL_DAYS = 90;
    private const INVITE_TOKEN_TTL_DAYS = 30;
    private const REGISTRATION_FAILURE_TTL_DAYS = 90;
    private const HUB_EVENTS_TTL_DAYS = 30;
    private const HUB_EVENTS_MAX_ROWS = 1000;
    private const DEPOSIT_404_LOG_TTL_DAYS = 30;

    public function __construct(
        private readonly Connection $connection,
        private readonly InviteTokenRepository $inviteTokenRepository,
        private readonly Deposit404LogRepository $deposit404LogRepository,
        private readonly DirectoryService $directoryService,
        private readonly LibraryProfileRepository $profileRepository,
        private readonly RelayMailboxRepository $relayMailboxRepository,
        private readonly DirectoryHealthRepository $directoryHealth,
        private readonly HubEventLogger $eventLogger,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(int:default:catalog_coverage_alert_threshold_default:CATALOG_COVERAGE_ALERT_THRESHOLD)%')]
        private readonly int $catalogCoverageAlertThreshold = 40,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('BiblioGenius DB prune');

        $perTable = [
            'relay_messages' => $this->pruneRelayMessages($io),
            'relay_mailboxes' => $this->pruneRelayMailboxes($io),
            'invite_tokens' => $this->pruneInviteTokens($io),
            'registration_failures' => $this->pruneRegistrationFailures($io),
            'hub_events' => $this->pruneHubEvents($io),
            'deposit_404_log' => $this->pruneDeposit404Log($io),
            'cached_catalogs' => $this->pruneCachedCatalogs($io),
            'orphan_covers' => $this->pruneOrphanCovers($io),
        ];
        $total = array_sum($perTable);

        $this->logPruneRun($total, $perTable, $io);

        $this->checkCatalogCoverage($io);
        $this->checkDuplicateLibraries($io);

        $io->success(sprintf('Done — %d rows deleted.', $total));

        return Command::SUCCESS;
    }

    /**
     * Duplicate-library check (ADR-055 phase 1): one catalog published under
     * two or more live node ids. Evaluated nightly for the same reason as the
     * coverage check, so the audit trail builds up whether or not anyone opens
     * the dashboard; that trail is the whole point of phase 1, which decides
     * later whether the case deserves a user-facing fix. Best-effort: a
     * monitoring failure must never fail the prune.
     */
    private function checkDuplicateLibraries(SymfonyStyle $io): void
    {
        try {
            $now = new \DateTimeImmutable();
            $duplicates = $this->directoryHealth->countDuplicateLiveLibraries($now);

            if (DirectoryHealthRepository::shouldEmitAlert(
                $duplicates,
                0,
                $this->directoryHealth->lastDuplicateLibraryAlertAt(),
                $now,
            )) {
                $this->eventLogger->warning('maintenance', 'duplicate_library_detected', [
                    'catalogs' => $duplicates,
                ]);
            }

            $io->writeln(sprintf(
                '  duplicate libraries: <info>%d catalog(s) on 2+ live nodes</info>',
                $duplicates,
            ));
        } catch (\Throwable $e) {
            $io->warning(sprintf('Duplicate library check failed: %s', $e->getMessage()));
        }
    }

    /**
     * Directory-health invariant check (ADR-027 keep-alive chain): the
     * dashboard evaluates it on render only, so this nightly pass makes the
     * coverage alert autonomous; the 24h dedup inside shouldEmitCoverageAlert
     * makes the double evaluation harmless. Runs after pruneCachedCatalogs so
     * the gap count reflects the post-prune state. Best-effort: a monitoring
     * failure must never fail the prune itself.
     */
    private function checkCatalogCoverage(SymfonyStyle $io): void
    {
        try {
            $now = new \DateTimeImmutable();
            $gaps = $this->directoryHealth->countCatalogCoverageGaps($now);

            if (DirectoryHealthRepository::shouldEmitCoverageAlert(
                $gaps,
                $this->catalogCoverageAlertThreshold,
                $this->directoryHealth->lastCoverageAlertAt(),
                $now,
            )) {
                $this->eventLogger->critical('maintenance', 'catalog_coverage_degraded', [
                    'count' => $gaps,
                ]);
            }

            $io->writeln(sprintf(
                '  catalog coverage: <info>%d gap(s)</info> (alert threshold %d)',
                $gaps,
                $this->catalogCoverageAlertThreshold,
            ));
        } catch (\Throwable $e) {
            $io->warning(sprintf('Catalog coverage check failed: %s', $e->getMessage()));
        }
    }

    /**
     * Record a marker event so the admin dashboard can display the age of the
     * last successful prune — the primary way to detect a broken VPS cron.
     *
     * Written with a direct INSERT rather than via HubEventLogger because the
     * logger sanitizes context to an allowlist that would strip per_table.
     */
    private function logPruneRun(int $total, array $perTable, SymfonyStyle $io): void
    {
        try {
            $this->connection->insert('hub_events', [
                'level' => 'info',
                'channel' => 'maintenance',
                'message' => 'prune_run',
                'context' => json_encode(
                    ['total_deleted' => $total, 'per_table' => $perTable],
                    JSON_UNESCAPED_UNICODE,
                ),
            ]);
        } catch (\Throwable $e) {
            $io->warning(sprintf('Failed to log prune_run event: %s', $e->getMessage()));
        }
    }

    private function pruneRelayMessages(SymfonyStyle $io): int
    {
        $deleted = (int) $this->connection->executeStatement(
            sprintf(
                "DELETE FROM relay_messages WHERE created_at < NOW() - INTERVAL '%d days'",
                self::RELAY_MESSAGE_TTL_DAYS,
            ),
        );
        $io->writeln(sprintf('  relay_messages   (%d-day TTL): <info>%d deleted</info>', self::RELAY_MESSAGE_TTL_DAYS, $deleted));

        return $deleted;
    }

    private function pruneRelayMailboxes(SymfonyStyle $io): int
    {
        $deleted = (int) $this->connection->executeStatement(
            sprintf(
                "DELETE FROM relay_mailboxes WHERE last_accessed IS NOT NULL AND last_accessed < NOW() - INTERVAL '%d days'",
                self::RELAY_MAILBOX_TTL_DAYS,
            ),
        );
        $deleted += (int) $this->connection->executeStatement(
            sprintf(
                "DELETE FROM relay_mailboxes WHERE last_accessed IS NULL AND created_at < NOW() - INTERVAL '%d days'",
                self::RELAY_MAILBOX_TTL_DAYS,
            ),
        );
        $io->writeln(sprintf('  relay_mailboxes  (%d-day TTL): <info>%d deleted</info>', self::RELAY_MAILBOX_TTL_DAYS, $deleted));

        // The profile side of the reference is soft (no FK): clear the
        // relay_mailbox_id of profiles whose mailbox was just deleted (or
        // vanished earlier), otherwise they pile up as orphan references on
        // the dashboard and never qualify for purgeStaleProfiles. Not counted
        // in the return value: these are cleared references, not deleted rows.
        $cleared = $this->relayMailboxRepository->clearDanglingProfileReferences();
        $io->writeln(sprintf('  profile mailbox refs (dangling): <info>%d cleared</info>', $cleared));

        return $deleted;
    }

    private function pruneInviteTokens(SymfonyStyle $io): int
    {
        $deleted = $this->inviteTokenRepository->deleteExpired(self::INVITE_TOKEN_TTL_DAYS);
        $io->writeln(sprintf('  invite_tokens    (%d-day TTL): <info>%d deleted</info>', self::INVITE_TOKEN_TTL_DAYS, $deleted));

        return $deleted;
    }

    private function pruneRegistrationFailures(SymfonyStyle $io): int
    {
        $deleted = (int) $this->connection->executeStatement(
            sprintf(
                "DELETE FROM registration_failures WHERE created_at < NOW() - INTERVAL '%d days'",
                self::REGISTRATION_FAILURE_TTL_DAYS,
            ),
        );
        $io->writeln(sprintf('  registration_failures (%d-day TTL): <info>%d deleted</info>', self::REGISTRATION_FAILURE_TTL_DAYS, $deleted));

        return $deleted;
    }

    private function pruneHubEvents(SymfonyStyle $io): int
    {
        $deleted = (int) $this->connection->executeStatement(
            sprintf(
                "DELETE FROM hub_events WHERE created_at < NOW() - INTERVAL '%d days'",
                self::HUB_EVENTS_TTL_DAYS,
            ),
        );

        // Secondary cap: keep at most MAX_ROWS newest entries.
        // Maintenance markers (e.g. prune_run) are exempt so that a noisy relay cannot
        // evict the observability signals the dashboard depends on.
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM hub_events');
        $capDeleted = 0;
        if ($count > self::HUB_EVENTS_MAX_ROWS) {
            $excess = $count - self::HUB_EVENTS_MAX_ROWS;
            $capDeleted = (int) $this->connection->executeStatement(
                "DELETE FROM hub_events WHERE id IN (SELECT id FROM hub_events WHERE channel <> 'maintenance' ORDER BY created_at ASC LIMIT $excess)",
            );
        }

        $io->writeln(sprintf(
            '  hub_events       (%d-day TTL, cap %d): <info>%d deleted</info>',
            self::HUB_EVENTS_TTL_DAYS,
            self::HUB_EVENTS_MAX_ROWS,
            $deleted + $capDeleted,
        ));

        return $deleted + $capDeleted;
    }

    private function pruneDeposit404Log(SymfonyStyle $io): int
    {
        $deleted = $this->deposit404LogRepository->pruneOlderThanDays(self::DEPOSIT_404_LOG_TTL_DAYS);
        $io->writeln(sprintf('  deposit_404_log  (%d-day TTL): <info>%d deleted</info>', self::DEPOSIT_404_LOG_TTL_DAYS, $deleted));

        return $deleted;
    }

    /**
     * Drops cached_catalogs rows whose per-row expires_at is in the past AND
     * whose owning profile has been inactive past the owner-inactivity window
     * (365 days, see LibraryProfileRepository): 1.0.x clients skip the
     * re-push of an unchanged catalog, so an active device can legitimately
     * sit behind an expired row.
     * Backstop for the in-process probabilistic cleanup
     * (DirectoryService::probabilisticCleanup): on a low-write hub the 1/50
     * roll rarely fires and expired rows accumulate. Production audit on
     * 2026-04-28 found 18/61 rows past their expires_at, the oldest by 4
     * days. This nightly prune guarantees an upper bound regardless of
     * write traffic.
     */
    private function pruneCachedCatalogs(SymfonyStyle $io): int
    {
        $deleted = $this->profileRepository->pruneExpiredCatalogs(new \DateTimeImmutable());
        $io->writeln(sprintf('  cached_catalogs  (per-row expires_at): <info>%d deleted</info>', $deleted));

        return $deleted;
    }

    /**
     * Catalog-driven orphan cover sweep (ADR-033, Option 3 safety net).
     * Delegates to DirectoryService which applies the 50% threshold guard
     * per node. Failures are swallowed so the observability marker still
     * fires with orphan_covers=0 and the dashboard does not wedge.
     */
    private function pruneOrphanCovers(SymfonyStyle $io): int
    {
        try {
            $deleted = $this->directoryService->pruneOrphanCoversForAllNodes();
        } catch (\Throwable $e) {
            $io->warning(sprintf('Orphan cover sweep failed: %s', $e->getMessage()));
            $deleted = 0;
        }
        $io->writeln(sprintf('  orphan_covers    (catalog-driven): <info>%d deleted</info>', $deleted));

        return $deleted;
    }
}
