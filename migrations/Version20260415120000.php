<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add app_version to library_profiles.
 * Populated by clients at every register/heartbeat. Used to correlate
 * hub-side anomalies (orphan relay mailboxes, failed syncs, stale tokens)
 * with a specific client build. NULL for legacy clients that never send it.
 */
final class Version20260415120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_version column to library_profiles for client version diagnostics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles ADD COLUMN app_version VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles DROP COLUMN app_version');
    }
}
