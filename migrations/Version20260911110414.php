<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911110414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the build table: share slug, hashed edit token, the documented scalars, and the passives/skills/inventory_slots document as JSON.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE build (id INT AUTO_INCREMENT NOT NULL, share_slug VARCHAR(22) NOT NULL, edit_token_hash VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, author VARCHAR(255) DEFAULT NULL, link VARCHAR(1024) DEFAULT NULL, description LONGTEXT DEFAULT NULL, ascendancy_key VARCHAR(255) DEFAULT NULL, game_version VARCHAR(32) NOT NULL, document JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_BDA0F2DB255A96FB (share_slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE build');
    }
}
