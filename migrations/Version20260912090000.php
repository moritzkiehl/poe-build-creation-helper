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
        // A bare `ADD ... NOT NULL` backfills every existing row with an empty
        // string, not an empty JSON array: MariaDB accepts it because JSON is a
        // LONGTEXT alias and does not check the CHECK constraint against the
        // implicit backfill value. The explicit default is a valid JSON array,
        // and MariaDB does apply column defaults, TEXT/BLOB/JSON included, when
        // backfilling existing rows, so this leaves them at a valid `[]`.
        $this->addSql("ALTER TABLE catalog_passive ADD recipe JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_passive DROP recipe');
    }
}
