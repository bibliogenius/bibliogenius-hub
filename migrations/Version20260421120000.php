<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-031: mailbox ownership enforcement on the public directory.
 *
 * Adds:
 *   - relay_mailboxes.owner_node_id (nullable VARCHAR(128)) + index
 *   - library_profiles.hijack_attempts_total (INTEGER NOT NULL DEFAULT 0)
 *
 * Backfill: for each library_profiles row that already carries a
 * relay_mailbox_id, claim the matching relay_mailboxes row for that
 * node_id. Idempotent: only rows with owner_node_id IS NULL are touched.
 * If two profiles already point at the same mailbox (a hijack that
 * happened pre-migration), one arbitrary profile wins the claim; the
 * losing profile will be caught by the service-level ownership check
 * on its next upsert.
 */
final class Version20260421120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-031: mailbox ownership (owner_node_id column + backfill + hijack counter)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE relay_mailboxes ADD COLUMN owner_node_id VARCHAR(128) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_relay_mailboxes_owner_node_id ON relay_mailboxes (owner_node_id)');

        $this->addSql('ALTER TABLE library_profiles ADD COLUMN hijack_attempts_total INTEGER NOT NULL DEFAULT 0');

        // Backfill: deterministic, idempotent, O(n) on library_profiles rows
        // that already carry a relay_mailbox_id. PostgreSQL UPDATE ... FROM
        // picks one arbitrary lp row per rm row when multiple profiles point
        // at the same mailbox. Any hijack that happened before the migration
        // will surface on the next upsert via the service-level check.
        $this->addSql(<<<'SQL'
            UPDATE relay_mailboxes rm
            SET owner_node_id = lp.node_id
            FROM library_profiles lp
            WHERE rm.owner_node_id IS NULL
              AND lp.relay_mailbox_id IS NOT NULL
              AND LOWER(rm.uuid) = LOWER(lp.relay_mailbox_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_relay_mailboxes_owner_node_id');
        $this->addSql('ALTER TABLE relay_mailboxes DROP COLUMN owner_node_id');
        $this->addSql('ALTER TABLE library_profiles DROP COLUMN hijack_attempts_total');
    }
}
