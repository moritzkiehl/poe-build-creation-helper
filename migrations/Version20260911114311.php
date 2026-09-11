<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911114311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the gem catalog: gems, tags, recommended supports, and the requirements read out of each support gem prose.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_gem (id VARCHAR(190) NOT NULL, name VARCHAR(255) NOT NULL, kind VARCHAR(16) NOT NULL, primary_attribute VARCHAR(16) DEFAULT NULL, icon VARCHAR(255) DEFAULT NULL, INDEX idx_gem_kind (kind), INDEX idx_gem_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE catalog_gem_recommended_support (gem_id VARCHAR(190) NOT NULL, support_id VARCHAR(190) NOT NULL, rank INT NOT NULL, PRIMARY KEY (gem_id, support_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE catalog_gem_requirement (id INT AUTO_INCREMENT NOT NULL, gem_id VARCHAR(190) NOT NULL, term VARCHAR(128) NOT NULL, mode VARCHAR(16) NOT NULL, origin VARCHAR(16) NOT NULL, clause LONGTEXT DEFAULT NULL, INDEX idx_requirement_gem (gem_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE catalog_gem_tag (gem_id VARCHAR(190) NOT NULL, tag VARCHAR(64) NOT NULL, INDEX idx_gem_tag_tag (tag), PRIMARY KEY (gem_id, tag)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_gem');
        $this->addSql('DROP TABLE catalog_gem_recommended_support');
        $this->addSql('DROP TABLE catalog_gem_requirement');
        $this->addSql('DROP TABLE catalog_gem_tag');
    }
}
