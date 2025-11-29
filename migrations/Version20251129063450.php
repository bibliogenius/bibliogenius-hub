<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251129063450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE language (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D4DB71B577153098 ON language (code)');
        $this->addSql('CREATE TABLE registered_libraries (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, url VARCHAR(500) NOT NULL, tags CLOB NOT NULL, description CLOB DEFAULT NULL, last_heartbeat DATETIME NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE TABLE translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locale VARCHAR(5) NOT NULL, key_name VARCHAR(255) NOT NULL, content CLOB NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX unique_translation ON translation (locale, key_name)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE language');
        $this->addSql('DROP TABLE registered_libraries');
        $this->addSql('DROP TABLE translation');
    }
}
