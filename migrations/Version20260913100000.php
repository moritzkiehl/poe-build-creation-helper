<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The build\'s declared jewel keystone — app-only, never exported.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE build ADD jewel_keystone VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE build DROP jewel_keystone');
    }
}
