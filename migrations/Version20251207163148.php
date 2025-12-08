<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251207163148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE gift_project ADD COLUMN delivered_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__gift_project AS SELECT id, name, description, created_at, is_locked, is_archived, last_activity_at, owner_id, recipient_id FROM gift_project');
        $this->addSql('DROP TABLE gift_project');
        $this->addSql('CREATE TABLE gift_project (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, created_at DATETIME NOT NULL, is_locked BOOLEAN DEFAULT 0 NOT NULL, is_archived BOOLEAN DEFAULT 0 NOT NULL, last_activity_at DATETIME DEFAULT NULL, owner_id INTEGER NOT NULL, recipient_id INTEGER NOT NULL, CONSTRAINT FK_43E9E3417E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_43E9E341E92F8F78 FOREIGN KEY (recipient_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO gift_project (id, name, description, created_at, is_locked, is_archived, last_activity_at, owner_id, recipient_id) SELECT id, name, description, created_at, is_locked, is_archived, last_activity_at, owner_id, recipient_id FROM __temp__gift_project');
        $this->addSql('DROP TABLE __temp__gift_project');
        $this->addSql('CREATE INDEX IDX_43E9E3417E3C61F9 ON gift_project (owner_id)');
        $this->addSql('CREATE INDEX IDX_43E9E341E92F8F78 ON gift_project (recipient_id)');
    }
}
