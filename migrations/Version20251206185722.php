<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251206185722 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE gift_project (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, created_at DATETIME NOT NULL, owner_id INTEGER NOT NULL, recipient_id INTEGER NOT NULL, CONSTRAINT FK_43E9E3417E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_43E9E341E92F8F78 FOREIGN KEY (recipient_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_43E9E3417E3C61F9 ON gift_project (owner_id)');
        $this->addSql('CREATE INDEX IDX_43E9E341E92F8F78 ON gift_project (recipient_id)');
        $this->addSql('CREATE TABLE gift_project_user (gift_project_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (gift_project_id, user_id), CONSTRAINT FK_C88CE94BB9CD877 FOREIGN KEY (gift_project_id) REFERENCES gift_project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C88CE94A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C88CE94BB9CD877 ON gift_project_user (gift_project_id)');
        $this->addSql('CREATE INDEX IDX_C88CE94A76ED395 ON gift_project_user (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE gift_project');
        $this->addSql('DROP TABLE gift_project_user');
    }
}
