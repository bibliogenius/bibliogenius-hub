<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a catalog_hash column to cached_catalogs so the hub can return
 * 304 Not Modified when a client re-pushes an unchanged catalog (ADR-027).
 *
 * The column is nullable for backward compatibility: existing catalogs
 * have no hash and will be rewritten on the next push.
 */
final class Version20260414120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add catalog_hash to cached_catalogs for diff-based push (ADR-027)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_catalogs ADD COLUMN catalog_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_catalogs DROP COLUMN catalog_hash');
    }
}
