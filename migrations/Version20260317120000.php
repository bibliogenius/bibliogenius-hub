<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused language and translation tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS language');
        $this->addSql('DROP TABLE IF EXISTS translation');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE language (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D4DB71B577153098 ON language (code)');
        $this->addSql('CREATE TABLE translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locale VARCHAR(5) NOT NULL, key_name VARCHAR(255) NOT NULL, content CLOB NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX unique_translation ON translation (locale, key_name)');
    }
}
