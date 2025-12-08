<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251206213534 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__contact_request AS SELECT id, status, created_at, updated_at, sender_id, recipient_id FROM contact_request');
        $this->addSql('DROP TABLE contact_request');
        $this->addSql('CREATE TABLE contact_request (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(20) DEFAULT \'PENDING\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, sender_id INTEGER NOT NULL, recipient_id INTEGER DEFAULT NULL, recipient_email VARCHAR(255) DEFAULT NULL, invitation_token CHAR(36) DEFAULT NULL, CONSTRAINT FK_A1B8AE1EF624B39D FOREIGN KEY (sender_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A1B8AE1EE92F8F78 FOREIGN KEY (recipient_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO contact_request (id, status, created_at, updated_at, sender_id, recipient_id) SELECT id, status, created_at, updated_at, sender_id, recipient_id FROM __temp__contact_request');
        $this->addSql('DROP TABLE __temp__contact_request');
        $this->addSql('CREATE INDEX IDX_A1B8AE1EE92F8F78 ON contact_request (recipient_id)');
        $this->addSql('CREATE INDEX IDX_A1B8AE1EF624B39D ON contact_request (sender_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A1B8AE1E33FC351A ON contact_request (invitation_token)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__contact_request AS SELECT id, status, created_at, updated_at, sender_id, recipient_id FROM contact_request');
        $this->addSql('DROP TABLE contact_request');
        $this->addSql('CREATE TABLE contact_request (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(20) DEFAULT \'PENDING\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, sender_id INTEGER NOT NULL, recipient_id INTEGER NOT NULL, CONSTRAINT FK_A1B8AE1EF624B39D FOREIGN KEY (sender_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A1B8AE1EE92F8F78 FOREIGN KEY (recipient_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO contact_request (id, status, created_at, updated_at, sender_id, recipient_id) SELECT id, status, created_at, updated_at, sender_id, recipient_id FROM __temp__contact_request');
        $this->addSql('DROP TABLE __temp__contact_request');
        $this->addSql('CREATE INDEX IDX_A1B8AE1EF624B39D ON contact_request (sender_id)');
        $this->addSql('CREATE INDEX IDX_A1B8AE1EE92F8F78 ON contact_request (recipient_id)');
    }
}
