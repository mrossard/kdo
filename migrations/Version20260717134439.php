<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add target_date column to gift_project table.
 */
final class Version20260717134439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add target_date column to gift_project table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gift_project ADD target_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gift_project DROP target_date');
    }
}
