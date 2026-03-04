<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add allow_borrowing column to library_profiles.
 *
 * Controls whether a library accepts borrow requests from followers via the hub.
 * Defaults to TRUE for backward compatibility (existing libraries keep current behavior).
 */
final class Version20260304130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add allow_borrowing column to library_profiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles ADD COLUMN allow_borrowing BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles DROP COLUMN allow_borrowing');
    }
}
