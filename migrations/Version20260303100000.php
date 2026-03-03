<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Library view counter: tracks how many times a library catalog is consulted.
 *
 * Adds view_count to library_profiles and creates a cooldown table
 * to enforce 15-minute anti-spam per visitor.
 */
final class Version20260303100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add view_count to library_profiles and create library_view_cooldowns table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles ADD COLUMN view_count INTEGER NOT NULL DEFAULT 0');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS library_view_cooldowns (
                profile_node_id VARCHAR(128) NOT NULL,
                visitor_id VARCHAR(128) NOT NULL,
                last_counted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (profile_node_id, visitor_id)
            )
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles DROP COLUMN view_count');
        $this->addSql('DROP TABLE IF EXISTS library_view_cooldowns');
    }
}
