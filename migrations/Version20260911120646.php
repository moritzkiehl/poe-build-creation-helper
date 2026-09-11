<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911120646 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the item catalog: uniques, equippable base items with their tags, craftable mods and the tags they can roll on.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_base_item (id VARCHAR(190) NOT NULL, name VARCHAR(255) NOT NULL, item_class VARCHAR(64) NOT NULL, drop_level INT NOT NULL, inventory_width INT NOT NULL, inventory_height INT NOT NULL, icon VARCHAR(255) DEFAULT NULL, INDEX idx_base_class (item_class), INDEX idx_base_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE catalog_base_item_tag (base_item_id VARCHAR(190) NOT NULL, tag VARCHAR(64) NOT NULL, INDEX idx_base_tag_tag (tag), PRIMARY KEY (base_item_id, tag)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE catalog_mod (id VARCHAR(190) NOT NULL, name VARCHAR(255) NOT NULL, text LONGTEXT NOT NULL, generation_type VARCHAR(16) NOT NULL, required_level INT NOT NULL, INDEX idx_mod_type (generation_type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE catalog_mod_spawn_tag (mod_id VARCHAR(190) NOT NULL, tag VARCHAR(64) NOT NULL, weight INT NOT NULL, INDEX idx_spawn_tag (tag), PRIMARY KEY (mod_id, tag)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE catalog_unique (id VARCHAR(190) NOT NULL, name VARCHAR(255) NOT NULL, item_class VARCHAR(64) NOT NULL, inventory_width INT NOT NULL, inventory_height INT NOT NULL, icon VARCHAR(255) DEFAULT NULL, INDEX idx_unique_name (name), INDEX idx_unique_class (item_class), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_base_item');
        $this->addSql('DROP TABLE catalog_base_item_tag');
        $this->addSql('DROP TABLE catalog_mod');
        $this->addSql('DROP TABLE catalog_mod_spawn_tag');
        $this->addSql('DROP TABLE catalog_unique');
    }
}
