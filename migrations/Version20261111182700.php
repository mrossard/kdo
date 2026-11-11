<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111182700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add participant_shares JSON column to gift_project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE gift_project ADD participant_shares JSON DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gift_project DROP participant_shares');
    }
}
