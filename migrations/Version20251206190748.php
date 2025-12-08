<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251206190748 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE gift_idea (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, price NUMERIC(10, 2) DEFAULT NULL, gift_project_id INTEGER NOT NULL, proposer_id INTEGER NOT NULL, CONSTRAINT FK_F8F63818BB9CD877 FOREIGN KEY (gift_project_id) REFERENCES gift_project (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F8F63818B13FA634 FOREIGN KEY (proposer_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_F8F63818BB9CD877 ON gift_idea (gift_project_id)');
        $this->addSql('CREATE INDEX IDX_F8F63818B13FA634 ON gift_idea (proposer_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE gift_idea');
    }
}
