<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add device_model and device_fingerprint to library_profiles.
 * Helps identify duplicate profiles after app reinstallation on Android.
 */
final class Version20260316130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add device_model and device_fingerprint columns to library_profiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles ADD COLUMN device_model VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE library_profiles ADD COLUMN device_fingerprint VARCHAR(128) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_library_profiles_fingerprint ON library_profiles (device_fingerprint)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_library_profiles_fingerprint');
        $this->addSql('ALTER TABLE library_profiles DROP COLUMN device_fingerprint');
        $this->addSql('ALTER TABLE library_profiles DROP COLUMN device_model');
    }
}
