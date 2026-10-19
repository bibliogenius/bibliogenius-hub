<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * End-to-end encrypted directory catalog (ADR-072).
 *
 * cached_catalogs.catalog_format: null for the clear JSON list every client
 * pushed so far and public libraries keep pushing; 'e2ee-v1' for an opaque
 * ciphertext the hub stores and serves without reading. Clear and encrypted
 * rows coexist for as long as the public directory exists.
 *
 * follows.encrypted_catalog_key: the followed library's catalog key, sealed
 * for this follower, written through the follow approval and the contact
 * re-broadcast batch, returned to the follower with its follows. Opaque to
 * the hub, like encrypted_contact next to it, and kept apart from it so the
 * contact card can be withheld from a reader who must still open the catalog.
 *
 * Mirrored in docker-entrypoint.sh (dual migration system - required, else
 * prod and dev drift apart silently).
 */
final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cached_catalogs.catalog_format and follows.encrypted_catalog_key (ADR-072 encrypted directory catalog)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_catalogs ADD COLUMN IF NOT EXISTS catalog_format VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE follows ADD COLUMN IF NOT EXISTS encrypted_catalog_key TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE follows DROP COLUMN IF EXISTS encrypted_catalog_key');
        $this->addSql('ALTER TABLE cached_catalogs DROP COLUMN IF EXISTS catalog_format');
    }
}
