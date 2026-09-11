<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog_class: the twelve classes, their attribute bases, their ascendancies and the tree node each one starts on.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_class (id VARCHAR(64) NOT NULL, start_node_id VARCHAR(128) NOT NULL, base_str INT NOT NULL, base_dex INT NOT NULL, base_int INT NOT NULL, ascendancies JSON NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_class');
    }
}
