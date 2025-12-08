<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251207130433 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__gift_idea AS SELECT id, name, description, url, price, gift_project_id, proposer_id, status, from_wish_id FROM gift_idea');
        $this->addSql('DROP TABLE gift_idea');
        $this->addSql('CREATE TABLE gift_idea (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, price NUMERIC(10, 2) DEFAULT NULL, gift_project_id INTEGER NOT NULL, proposer_id INTEGER NOT NULL, status VARCHAR(20) DEFAULT \'PROPOSED\' NOT NULL, from_wish_id INTEGER DEFAULT NULL, purchase_price NUMERIC(10, 2) DEFAULT NULL, purchased_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_F8F63818BB9CD877 FOREIGN KEY (gift_project_id) REFERENCES gift_project (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F8F63818B13FA634 FOREIGN KEY (proposer_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F8F63818C4E6D537 FOREIGN KEY (from_wish_id) REFERENCES wish (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F8F6381851D43F65 FOREIGN KEY (purchased_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO gift_idea (id, name, description, url, price, gift_project_id, proposer_id, status, from_wish_id) SELECT id, name, description, url, price, gift_project_id, proposer_id, status, from_wish_id FROM __temp__gift_idea');
        $this->addSql('DROP TABLE __temp__gift_idea');
        $this->addSql('CREATE INDEX IDX_F8F63818C4E6D537 ON gift_idea (from_wish_id)');
        $this->addSql('CREATE INDEX IDX_F8F63818BB9CD877 ON gift_idea (gift_project_id)');
        $this->addSql('CREATE INDEX IDX_F8F63818B13FA634 ON gift_idea (proposer_id)');
        $this->addSql('CREATE INDEX IDX_F8F6381851D43F65 ON gift_idea (purchased_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__gift_idea AS SELECT id, name, description, url, price, status, gift_project_id, proposer_id, from_wish_id FROM gift_idea');
        $this->addSql('DROP TABLE gift_idea');
        $this->addSql('CREATE TABLE gift_idea (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, price NUMERIC(10, 2) DEFAULT NULL, status VARCHAR(20) DEFAULT \'PROPOSED\' NOT NULL, gift_project_id INTEGER NOT NULL, proposer_id INTEGER NOT NULL, from_wish_id INTEGER DEFAULT NULL, CONSTRAINT FK_F8F63818BB9CD877 FOREIGN KEY (gift_project_id) REFERENCES gift_project (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F8F63818B13FA634 FOREIGN KEY (proposer_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F8F63818C4E6D537 FOREIGN KEY (from_wish_id) REFERENCES wish (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO gift_idea (id, name, description, url, price, status, gift_project_id, proposer_id, from_wish_id) SELECT id, name, description, url, price, status, gift_project_id, proposer_id, from_wish_id FROM __temp__gift_idea');
        $this->addSql('DROP TABLE __temp__gift_idea');
        $this->addSql('CREATE INDEX IDX_F8F63818BB9CD877 ON gift_idea (gift_project_id)');
        $this->addSql('CREATE INDEX IDX_F8F63818B13FA634 ON gift_idea (proposer_id)');
        $this->addSql('CREATE INDEX IDX_F8F63818C4E6D537 ON gift_idea (from_wish_id)');
    }
}
