<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create relay_mailboxes and relay_messages tables for E2EE blind relay hub.
 *
 * The hub acts as an opaque mailbox store: it never sees plaintext,
 * only encrypted blobs deposited by authenticated peers.
 */
final class Version20260220170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create relay_mailboxes and relay_messages tables for E2EE relay';
    }

    public function up(Schema $schema): void
    {
        // relay_mailboxes — one row per anonymous mailbox
        $this->addSql('
            CREATE TABLE relay_mailboxes (
                uuid VARCHAR(36) NOT NULL PRIMARY KEY,
                read_token VARCHAR(64) NOT NULL,
                write_token VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_accessed DATETIME DEFAULT NULL
            )
        ');

        // relay_messages — encrypted blobs waiting for collection
        $this->addSql('
            CREATE TABLE relay_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                mailbox_uuid VARCHAR(36) NOT NULL,
                blob BLOB NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (mailbox_uuid) REFERENCES relay_mailboxes(uuid) ON DELETE CASCADE
            )
        ');

        // Indexes for poll queries and TTL cleanup
        $this->addSql('CREATE INDEX idx_relay_messages_mailbox ON relay_messages (mailbox_uuid)');
        $this->addSql('CREATE INDEX idx_relay_messages_created ON relay_messages (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS relay_messages');
        $this->addSql('DROP TABLE IF EXISTS relay_mailboxes');
    }
}
