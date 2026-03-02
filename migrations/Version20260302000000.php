<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enrich cached_catalogs with title and author data.
 *
 * Adds a catalog_payload column containing JSON array of {isbn, title, author} objects.
 * The existing isbn_payload column is kept for backward compatibility.
 */
final class Version20260302000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add catalog_payload (enriched ISBN+title+author) to cached_catalogs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_catalogs ADD COLUMN catalog_payload TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_catalogs DROP COLUMN catalog_payload');
    }
}
