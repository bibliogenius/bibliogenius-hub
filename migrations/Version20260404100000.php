<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recovery_code_hash to library_profiles for post-reinstall identity recovery';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles ADD COLUMN recovery_code_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles DROP COLUMN recovery_code_hash');
    }
}
