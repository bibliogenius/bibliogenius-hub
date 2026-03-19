<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add relay credential fields to library_profiles for relay-only peer recovery';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles ADD COLUMN relay_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE library_profiles ADD COLUMN relay_mailbox_id VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE library_profiles ADD COLUMN relay_write_token VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles DROP COLUMN relay_url');
        $this->addSql('ALTER TABLE library_profiles DROP COLUMN relay_mailbox_id');
        $this->addSql('ALTER TABLE library_profiles DROP COLUMN relay_write_token');
    }
}
