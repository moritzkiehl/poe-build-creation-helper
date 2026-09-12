<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tree legality data on catalog_passive, and the shape revision that forces a re-import.';
    }

    public function up(Schema $schema): void
    {
        // JSON NOT NULL without a DEFAULT backfills existing rows with '',
        // which is not valid JSON. The default is what keeps the 4912 rows
        // already in this table queryable.
        $this->addSql("ALTER TABLE catalog_passive ADD keystones_in_radius JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE catalog_passive ADD unlock_constraint JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_sync ADD shape_revision VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_passive DROP keystones_in_radius');
        $this->addSql('ALTER TABLE catalog_passive DROP unlock_constraint');
        $this->addSql('ALTER TABLE catalog_sync DROP shape_revision');
    }
}
