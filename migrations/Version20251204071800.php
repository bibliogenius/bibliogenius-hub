<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add direction field to peers table and create library_config table for P2P bidirectional notifications
 */
final class Version20251204071800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add direction field to peers and create library_config table';
    }

    public function up(Schema $schema): void
    {
        // Add direction column to peers table
        $this->addSql("ALTER TABLE peers ADD COLUMN direction VARCHAR(20) NOT NULL DEFAULT 'incoming'");

        // Create library_config table
        $this->addSql('CREATE TABLE library_config (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL)');
    }

    public function down(Schema $schema): void
    {
        // Remove direction column
        // Note: SQLite doesn't support DROP COLUMN directly, would need table recreation
        $this->addSql('CREATE TABLE peers_backup AS SELECT id, name, url, status, created_at FROM peers');
        $this->addSql('DROP TABLE peers');
        $this->addSql('ALTER TABLE peers_backup RENAME TO peers');

        // Drop library_config table
        $this->addSql('DROP TABLE library_config');
    }
}
