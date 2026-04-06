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
        $allowed = ['uuid', 'mailbox', 'node_id', 'name', 'size', 'msg_id', 'count', 'status'];
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

    private function cleanup(): void
    {
        try {
            // Delete entries older than TTL
            $this->connection->executeStatement(
                "DELETE FROM hub_events WHERE created_at < NOW() - INTERVAL '" . self::TTL_DAYS . " days'"
            );

            // Cap total entries
            $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM hub_events');
            if ($count > self::MAX_ENTRIES) {
                $excess = $count - self::MAX_ENTRIES;
                $this->connection->executeStatement(
                    "DELETE FROM hub_events WHERE id IN (SELECT id FROM hub_events ORDER BY created_at ASC LIMIT $excess)"
                );
            }
        } catch (\Throwable) {
            // Best-effort
        }
    }
}
