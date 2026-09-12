<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the Distilled Emotions that instil a passive: 875 nodes carry a three-ingredient recipe the normaliser used to discard.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_passive ADD recipe JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_passive DROP recipe');
    }
}
