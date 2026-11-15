<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115113010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT fk_9474526c3735dcd2');
        $this->addSql('DROP INDEX idx_9474526c3735dcd2');
        $this->addSql('ALTER TABLE comment RENAME COLUMN gift_idea_id TO project_id');
        $this->addSql('UPDATE comment c SET project_id = gi.gift_project_id FROM gift_idea gi WHERE c.project_id = gi.id');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C166D1F9C FOREIGN KEY (project_id) REFERENCES gift_project (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9474526C166D1F9C ON comment (project_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT FK_9474526C166D1F9C');
        $this->addSql('DROP INDEX IDX_9474526C166D1F9C');
        $this->addSql('ALTER TABLE comment RENAME COLUMN project_id TO gift_idea_id');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT fk_9474526c3735dcd2 FOREIGN KEY (gift_idea_id) REFERENCES gift_idea (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_9474526c3735dcd2 ON comment (gift_idea_id)');
    }
}
