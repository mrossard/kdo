<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251207145838 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE reimbursement (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, amount NUMERIC(10, 2) NOT NULL, created_at DATETIME NOT NULL, project_id INTEGER NOT NULL, from_user_id INTEGER NOT NULL, to_user_id INTEGER NOT NULL, CONSTRAINT FK_705D16B7166D1F9C FOREIGN KEY (project_id) REFERENCES gift_project (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_705D16B72130303A FOREIGN KEY (from_user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_705D16B729F6EE60 FOREIGN KEY (to_user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_705D16B7166D1F9C ON reimbursement (project_id)');
        $this->addSql('CREATE INDEX IDX_705D16B72130303A ON reimbursement (from_user_id)');
        $this->addSql('CREATE INDEX IDX_705D16B729F6EE60 ON reimbursement (to_user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE reimbursement');
    }
}
