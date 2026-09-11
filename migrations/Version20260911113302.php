<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911113302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the catalog tables: passives, passive edges, and the sync log.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_passive (id VARCHAR(128) NOT NULL, name VARCHAR(255) NOT NULL, kind VARCHAR(16) NOT NULL, ascendancy_key VARCHAR(64) DEFAULT NULL, pos_x DOUBLE PRECISION NOT NULL, pos_y DOUBLE PRECISION NOT NULL, stats JSON NOT NULL, INDEX idx_passive_kind (kind), INDEX idx_passive_ascendancy (ascendancy_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE catalog_passive_edge (from_id VARCHAR(128) NOT NULL, to_id VARCHAR(128) NOT NULL, INDEX idx_edge_to (to_id), PRIMARY KEY (from_id, to_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE catalog_sync (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(64) NOT NULL, ran_at DATETIME NOT NULL, upstream_last_modified VARCHAR(128) DEFAULT NULL, game_version VARCHAR(32) DEFAULT NULL, status VARCHAR(16) NOT NULL, count INT NOT NULL, error LONGTEXT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_passive');
        $this->addSql('DROP TABLE catalog_passive_edge');
        $this->addSql('DROP TABLE catalog_sync');
    }
}
