<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\Deposit404LogRepository;
use App\Repository\InviteTokenRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Nightly database pruning — run via cron to keep tables lean.
 *
 * Tables covered:
 *   relay_messages       — TTL 7 days  (E2EE blobs, largest table)
 *   relay_mailboxes      — TTL 90 days (last_accessed or created_at)
 *   invite_tokens        — TTL 30 days
 *   registration_failures — TTL 90 days (audit trail)
 *   hub_events           — TTL 30 days + cap 1000 rows
 *   deposit_404_log      — TTL 30 days (aggregated, so cap is implicit)
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
        ];
        $total = array_sum($perTable);

        $this->logPruneRun($total, $perTable, $io);

        $io->success(sprintf('Done — %d rows deleted.', $total));

        return Command::SUCCESS;
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
}
