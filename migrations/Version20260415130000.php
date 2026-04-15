<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add app_version to registration_failures.
 * Captures the client build that produced a rejected registration so admins
 * can tell "outdated app" apart from "malicious/forged request" at a glance.
 */
final class Version20260415130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_version column to registration_failures for per-version diagnostics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registration_failures ADD COLUMN app_version VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registration_failures DROP COLUMN app_version');
    }
}
