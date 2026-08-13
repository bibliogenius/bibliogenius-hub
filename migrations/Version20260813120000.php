<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index on cached_catalogs.catalog_hash (ADR-055 duplicate-library detection).
 *
 * The dashboard groups live catalogs by hash to spot one library published
 * under two node ids. Without an index that grouping is a full scan of
 * cached_catalogs on every dashboard render and every nightly prune.
 *
 * Mirrored in docker-entrypoint.sh.
 */
final class Version20260813120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index cached_catalogs.catalog_hash (ADR-055 duplicate-library detection)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_cached_catalogs_hash ON cached_catalogs (catalog_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_cached_catalogs_hash');
    }
}
