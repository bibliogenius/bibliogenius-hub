<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hub_events table for BO-visible critical event logging';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE hub_events (
            id SERIAL PRIMARY KEY,
            level VARCHAR(10) NOT NULL,
            channel VARCHAR(30) NOT NULL,
            message VARCHAR(500) NOT NULL,
            context TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )');
        $this->addSql('CREATE INDEX idx_hub_events_created ON hub_events (created_at DESC)');
        $this->addSql('CREATE INDEX idx_hub_events_channel ON hub_events (channel)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS hub_events');
    }
}
