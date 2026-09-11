<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911113453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename catalog_sync.upstream_last_modified to upstream_revision: raw.githubusercontent.com sends an ETag and no Last-Modified, so the stored value is whichever validator upstream offers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_sync ADD upstream_revision VARCHAR(255) DEFAULT NULL, DROP upstream_last_modified');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_sync ADD upstream_last_modified VARCHAR(128) DEFAULT NULL, DROP upstream_revision');
    }
}
