<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115094727 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE managed_person_co_manager (managed_person_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (managed_person_id, user_id))');
        $this->addSql('CREATE INDEX IDX_D614C3F82D137AF6 ON managed_person_co_manager (managed_person_id)');
        $this->addSql('CREATE INDEX IDX_D614C3F8A76ED395 ON managed_person_co_manager (user_id)');
        $this->addSql('ALTER TABLE managed_person_co_manager ADD CONSTRAINT FK_D614C3F82D137AF6 FOREIGN KEY (managed_person_id) REFERENCES managed_person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE managed_person_co_manager ADD CONSTRAINT FK_D614C3F8A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE managed_person_co_manager DROP CONSTRAINT FK_D614C3F82D137AF6');
        $this->addSql('ALTER TABLE managed_person_co_manager DROP CONSTRAINT FK_D614C3F8A76ED395');
        $this->addSql('DROP TABLE managed_person_co_manager');
    }
}
