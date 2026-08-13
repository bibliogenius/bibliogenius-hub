<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Aggregated counter of "deposit to non-existent mailbox" events.
 *
 * One row per (mailbox_uuid, hour_bucket). recordHit() is an atomic upsert:
 * first 404 for a given mailbox in a given hour creates the row, every
 * subsequent 404 in that hour increments count.
 *
 * Introduced to stop the deposit-404 flood (~80% of rows on 2026-04-22)
 * from evicting legitimate signals from the capped hub_events table.
 */
class Deposit404LogRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Increments the hit counter for the given mailbox in the current hour
     * bucket. Best-effort: a DB failure must never turn a 404 into a 500.
     */
    public function recordHit(string $mailboxUuid): void
    {
        try {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO deposit_404_log (mailbox_uuid, hour_bucket, count, first_seen, last_seen)
                VALUES (?, DATE_TRUNC('hour', NOW()), 1, NOW(), NOW())
                ON CONFLICT (mailbox_uuid, hour_bucket)
                DO UPDATE SET
                    count = deposit_404_log.count + 1,
                    last_seen = NOW()
                SQL,
                [$mailboxUuid],
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[relay] deposit_404_log upsert failed',
                ['uuid' => substr($mailboxUuid, 0, 36), 'reason' => $e->getMessage()],
            );
        }
    }

    /**
     * Total number of 404 hits since the given instant. Used by the admin
     * dashboard tile. Sums the `count` column because a single row can
     * cover dozens of hits within the same hour bucket.
     */
    public function countSince(\DateTimeInterface $since): int
    {
        $result = $this->connection->fetchOne(
            'SELECT SUM(count) FROM deposit_404_log WHERE hour_bucket >= ?',
            [$since->format('Y-m-d H:i:s')],
        );

        return $result === null || $result === false ? 0 : (int) $result;
    }

    /**
     * Total 404 hits recorded for one mailbox, all buckets. Sums the `count`
     * column for the same reason countSince() does: one row covers a whole
     * hour of hits.
     */
    public function countByMailbox(string $mailboxUuid): int
    {
        $result = $this->connection->fetchOne(
            'SELECT SUM(count) FROM deposit_404_log WHERE mailbox_uuid = ?',
            [$mailboxUuid],
        );

        return $result === null || $result === false ? 0 : (int) $result;
    }

    /**
     * Nightly cleanup. Keeps the table bounded independently of the rest
     * of the system: 6 noisy UUIDs * 24 buckets/day * 30 days = ~4k rows
     * worst case, with a TTL-enforced floor.
     */
    public function pruneOlderThanDays(int $ttlDays): int
    {
        return (int) $this->connection->executeStatement(
            sprintf("DELETE FROM deposit_404_log WHERE hour_bucket < NOW() - INTERVAL '%d days'", $ttlDays),
        );
    }
}
