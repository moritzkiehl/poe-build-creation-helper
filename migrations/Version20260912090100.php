<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which passives the player declared instilled. App-only: the game reconstructs the modifier from the amulet, so it never enters the exported document.';
    }

    public function up(Schema $schema): void
    {
        // A bare `ADD ... NOT NULL` backfills every existing row with an empty
        // string, not an empty JSON array — see Version20260912090000 for the
        // measurement. The explicit default keeps existing `build` rows at a
        // valid `[]`.
        $this->addSql("ALTER TABLE build ADD instilled_passives JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE build DROP instilled_passives');
    }
}
