<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912080100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the planning fields to build: class, target level, note and archetype. They never enter the exported document.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE build ADD class_key VARCHAR(64) DEFAULT NULL, ADD target_level INT DEFAULT NULL, ADD note LONGTEXT DEFAULT NULL, ADD archetype_key VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE build DROP class_key, DROP target_level, DROP note, DROP archetype_key');
    }
}
