<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111181600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reserved_by_id and reserved_at to wish';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wish ADD reserved_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE wish ADD reserved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN wish.reserved_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql(
            'ALTER TABLE wish ADD CONSTRAINT FK_D7D174C9BCDB4AF4 FOREIGN KEY (reserved_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE',
        );
        $this->addSql('CREATE INDEX IDX_D7D174C9BCDB4AF4 ON wish (reserved_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wish DROP CONSTRAINT FK_D7D174C9BCDB4AF4');
        $this->addSql('DROP INDEX IDX_D7D174C9BCDB4AF4');
        $this->addSql('ALTER TABLE wish DROP reserved_by_id');
        $this->addSql('ALTER TABLE wish DROP reserved_at');
    }
}
