<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Lightweight event logger that stores critical events in the hub_events
 * table for BO review, in addition to the standard Monolog stderr output.
 *
 * Security: NEVER log tokens, keys, passwords, IPs, or error messages.
 * Allowed context: truncated node_ids, display names,
 * counts, sizes, mailbox UUIDs.
 *
 * Auto-purges entries older than 30 days (probabilistic, ~2% of writes).
 */
class HubEventLogger
{
    /** Max critical events kept in DB (~200 bytes each, ~200 KB at cap). */
    /**
     * Marker written by app:db:prune when its row cap actually cuts into
     * the journal, on the 'maintenance' channel so the evidence survives
     * the very cap it describes. Its context carries 'cutoff', the oldest
     * ordinary event still standing afterwards, which is the frontier
     * every reader of a time window needs to know whether its window is
     * still whole. Named here rather than in either party because it is
     * the event log's own vocabulary: PruneCommand writes it,
     * DiscoveryCacheRepository reads it, neither owns it.
     */
    public const MARKER_HUB_EVENTS_CAPPED = 'hub_events_capped';

    private const MAX_ENTRIES = 1000;
    private const TTL_DAYS = 30;
    private const CLEANUP_PROBABILITY = 50;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {}

    /** Info: stderr only (not stored in DB to keep volume low). */
    public function info(string $channel, string $message, array $context = []): void
    {
        $this->logger->info("[$channel] $message", $context);
    }

    /** Warning: stored in DB (capped at 100 entries) + stderr. */
    public function warning(string $channel, string $message, array $context = []): void
    {
        $this->write('warning', $channel, $message, $context);
        $this->logger->warning("[$channel] $message", $context);
    }

    /** Error: stored in DB (capped at 100 entries) + stderr. */
    public function error(string $channel, string $message, array $context = []): void
    {
        $this->write('error', $channel, $message, $context);
        $this->logger->error("[$channel] $message", $context);
    }

    /**
     * Critical: stored in DB as an error row (so it counts in the dashboard
     * errors tile) but emitted to Monolog at critical (level 500), the level
     * the host-side cron alerter (scripts/alert_critical_logs.sh) greps for.
     * Use for invariant violations that must page even when nobody is
     * watching the dashboard; error() stays at level 400 and never pages.
     */
    public function critical(string $channel, string $message, array $context = []): void
    {
        $this->write('error', $channel, $message, $context);
        $this->logger->critical("[$channel] $message", $context);
    }

    /**
     * Audit: stored in DB at level 'info' + stderr at info.
     *
     * For deliberate admin actions that must leave a trace without being
     * mistaken for a problem: the dashboard tiles count level = 'warning' and
     * level = 'error' only, so an audit row is visible in the events list and
     * invisible to the alerting surfaces. info() alone would not do, it never
     * reaches the database.
     */
    public function audit(string $channel, string $message, array $context = []): void
    {
        $this->write('info', $channel, $message, $context);
        $this->logger->info("[$channel] $message", $context);
    }

    private function write(string $level, string $channel, string $message, array $context): void
    {
        try {
            // Sanitize context: truncate node_ids, remove anything sensitive
            $safeContext = $this->sanitizeContext($context);

            $this->connection->insert('hub_events', [
                'level' => $level,
                'channel' => substr($channel, 0, 30),
                'message' => substr($message, 0, 500),
                'context' => !empty($safeContext) ? json_encode($safeContext, JSON_UNESCAPED_UNICODE) : null,
            ]);

            // Probabilistic cleanup
            if (random_int(1, self::CLEANUP_PROBABILITY) === 1) {
                $this->cleanup();
            }
        } catch (\Throwable) {
            // Best-effort: never break the main flow
        }
    }

    private function sanitizeContext(array $context): array
    {
        $safe = [];
        $allowed = ['uuid', 'mailbox', 'node_id', 'name', 'size', 'msg_id', 'count', 'status', 'reason'];
        foreach ($context as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if (is_string($value)) {
                // Truncate long values (node_ids, error messages)
                $safe[$key] = substr($value, 0, 100);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $safe[$key] = $value;
            }
        }
        return $safe;
    }

    /**
     * Record where a row-cap cut just landed, so that readers of a time
     * window can tell "these events were deleted" from "these events never
     * happened". Nothing else can tell them apart after the fact, and
     * guessing from an absence is wrong in both directions: a quiet period
     * and a period where everything succeeded look exactly like a cut.
     *
     * Called by both cutters, this logger's own probabilistic cleanup and
     * app:db:prune, which is why it is public. The context is written
     * directly rather than through write(): 'cutoff' and 'deleted' are
     * machine-generated bookkeeping, not caller input, and the sanitizer
     * would drop both.
     *
     * Kept to ONE row, refreshed in place: the frontier only ever moves
     * forward, so the latest cut is all a reader needs, and appending
     * instead would grow the very table the cap is defending, on the
     * maintenance channel the cap cannot touch. The 30-day TTL eventually
     * collects it, which is correct: a cut that old cannot overlap any
     * window this marker serves.
     *
     * Best-effort: losing the marker must never break a write or a prune.
     * It only makes one monitoring window unreadable, which is the
     * conservative direction.
     */
    public function recordCapCut(int $deleted): void
    {
        try {
            $cutoff = $this->connection->fetchOne(
                "SELECT MIN(created_at) FROM hub_events WHERE channel <> 'maintenance'"
            );
            $context = json_encode(
                [
                    // NULL means the cut left no ordinary event at all, and
                    // the marker's own timestamp is then the frontier.
                    'cutoff' => is_string($cutoff) && $cutoff !== '' ? $cutoff : null,
                    'deleted' => $deleted,
                ],
                JSON_UNESCAPED_UNICODE
            );

            $refreshed = (int) $this->connection->executeStatement(
                "UPDATE hub_events SET created_at = NOW(), level = 'info', context = :context
                  WHERE channel = 'maintenance' AND message = :marker",
                ['context' => $context, 'marker' => self::MARKER_HUB_EVENTS_CAPPED]
            );
            if ($refreshed === 0) {
                $this->connection->insert('hub_events', [
                    'level' => 'info',
                    'channel' => 'maintenance',
                    'message' => self::MARKER_HUB_EVENTS_CAPPED,
                    'context' => $context,
                ]);
            }
        } catch (\Throwable) {
            // Best-effort
        }
    }

    private function cleanup(): void
    {
        try {
            // Delete entries older than TTL
            $this->connection->executeStatement(
                "DELETE FROM hub_events WHERE created_at < NOW() - INTERVAL '" . self::TTL_DAYS . " days'"
            );

            // Cap total entries. Maintenance markers (e.g. prune_run) are exempt so that
            // a noisy relay cannot evict the observability signals the dashboard depends on.
            $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM hub_events');
            if ($count > self::MAX_ENTRIES) {
                $excess = $count - self::MAX_ENTRIES;
                $cut = (int) $this->connection->executeStatement(
                    "DELETE FROM hub_events WHERE id IN (SELECT id FROM hub_events WHERE channel <> 'maintenance' ORDER BY created_at ASC LIMIT $excess)"
                );
                if ($cut > 0) {
                    $this->recordCapCut($cut);
                }
            }
        } catch (\Throwable) {
            // Best-effort
        }
    }
}
