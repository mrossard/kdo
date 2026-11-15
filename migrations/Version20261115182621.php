<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115182621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX idx_activity_project_created ON activity (project_id, created_at)');
        $this->addSql('CREATE INDEX idx_activity_created_at ON activity (created_at)');
        $this->addSql('CREATE INDEX idx_contact_request_email_status ON contact_request (recipient_email, status)');
        $this->addSql('ALTER TABLE project_read_status DROP CONSTRAINT fk_322e0456a76ed395');
        $this->addSql('ALTER TABLE project_read_status DROP CONSTRAINT fk_322e0456166d1f9c');
        $this->addSql('ALTER TABLE project_read_status ADD CONSTRAINT FK_322E0456A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project_read_status ADD CONSTRAINT FK_322E0456166D1F9C FOREIGN KEY (project_id) REFERENCES gift_project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX uniq_wishlist_share_owner_recipient ON wishlist_share (owner_id, recipient_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_activity_project_created');
        $this->addSql('DROP INDEX idx_activity_created_at');
        $this->addSql('DROP INDEX idx_contact_request_email_status');
        $this->addSql('ALTER TABLE project_read_status DROP CONSTRAINT FK_322E0456A76ED395');
        $this->addSql('ALTER TABLE project_read_status DROP CONSTRAINT FK_322E0456166D1F9C');
        $this->addSql('ALTER TABLE project_read_status ADD CONSTRAINT fk_322e0456a76ed395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE project_read_status ADD CONSTRAINT fk_322e0456166d1f9c FOREIGN KEY (project_id) REFERENCES gift_project (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP INDEX uniq_wishlist_share_owner_recipient');
    }
}
