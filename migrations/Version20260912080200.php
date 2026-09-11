<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912080200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create build_event: one row per edit, carrying the whole editable state afterwards, so a build can be reverted to any point.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE build_event (id INT AUTO_INCREMENT NOT NULL, build_id INT NOT NULL, created_at DATETIME NOT NULL, action VARCHAR(32) NOT NULL, payload JSON NOT NULL, snapshot JSON NOT NULL, is_named_snapshot TINYINT(1) NOT NULL, snapshot_name VARCHAR(120) DEFAULT NULL, INDEX IDX_BUILD_EVENT_BUILD (build_id), INDEX idx_build_event_prune (created_at, is_named_snapshot), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE build_event ADD CONSTRAINT FK_BUILD_EVENT_BUILD FOREIGN KEY (build_id) REFERENCES build (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE build_event');
    }
}
