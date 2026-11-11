<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111180504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add imageUrl and priority to wish and gift_idea';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gift_idea ADD image_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE gift_idea ADD priority SMALLINT DEFAULT 2 NOT NULL');
        $this->addSql('ALTER TABLE wish ADD image_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE wish ADD priority SMALLINT DEFAULT 2 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gift_idea DROP image_url');
        $this->addSql('ALTER TABLE gift_idea DROP priority');
        $this->addSql('ALTER TABLE wish DROP image_url');
        $this->addSql('ALTER TABLE wish DROP priority');
    }
}
