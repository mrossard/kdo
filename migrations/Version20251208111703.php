<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251208111703 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE wish ADD COLUMN price NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__wish AS SELECT id, name, description, url, user_id FROM wish');
        $this->addSql('DROP TABLE wish');
        $this->addSql('CREATE TABLE wish (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_D7D174C9A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO wish (id, name, description, url, user_id) SELECT id, name, description, url, user_id FROM __temp__wish');
        $this->addSql('DROP TABLE __temp__wish');
        $this->addSql('CREATE INDEX IDX_D7D174C9A76ED395 ON wish (user_id)');
    }
}
