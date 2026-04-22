<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * deposit_404_log: aggregated counter for "deposit to non-existent mailbox"
 * events, replacing the per-event warning rows that were previously written
 * to hub_events.
 *
 * Why: hub_events is capped at 1000 rows. On 2026-04-22 the table held 795
 * deposit-404 warnings (~80% of rows), all coming from 6 mailbox UUIDs -- a
 * handful of peers holding stale write_tokens and retrying in a loop. That
 * flood was evicting legitimate signals (directory, hijack attempts) from
 * the BO dashboard window.
 *
 * The composite PK (mailbox_uuid, hour_bucket) means one upsert per UUID
 * per hour: 6 noisy UUIDs * 24 buckets = 144 rows/day worst case, versus
 * hundreds of rows/day before. The `count` column preserves the true hit
 * volume so the "Deposit 404s (24h)" dashboard tile keeps reporting an
 * accurate number via SUM(count).
 *
 * Mirrored in docker-entrypoint.sh.
 */
final class Version20260422120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'deposit_404_log: aggregated counter for deposit-404 warnings (replaces hub_events rows)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS deposit_404_log (
                mailbox_uuid VARCHAR(36) NOT NULL,
                hour_bucket TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                count INTEGER NOT NULL DEFAULT 1,
                first_seen TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                last_seen TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY (mailbox_uuid, hour_bucket)
            )
        SQL);

        // Supports the dashboard tile query (hour_bucket >= NOW() - INTERVAL '1 day')
        // and the nightly prune (hour_bucket < NOW() - INTERVAL '30 days').
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_deposit_404_log_hour_bucket ON deposit_404_log (hour_bucket DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_deposit_404_log_hour_bucket');
        $this->addSql('DROP TABLE IF EXISTS deposit_404_log');
    }
}
