<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogPort;
use App\Catalog\DoctrineCatalog;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The same expectations as the fake, against real MariaDB.
 */
final class DoctrineCatalogTest extends KernelTestCase
{
    use CatalogPortContract;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);

        foreach (['catalog_passive_edge', 'catalog_passive', 'catalog_gem_requirement', 'catalog_gem_recommended_support', 'catalog_gem_tag', 'catalog_gem', 'catalog_unique', 'catalog_sync'] as $table) {
            $this->db->executeStatement('DELETE FROM '.$table);
        }

        $this->seed();
    }

    public function testAnEmptyCatalogSaysSoRatherThanPretendingNothingExists(): void
    {
        $this->db->executeStatement('DELETE FROM catalog_sync');
        $this->db->executeStatement('DELETE FROM catalog_passive');

        $catalog = new DoctrineCatalog($this->db);

        self::assertFalse($catalog->isAvailable());
        self::assertNull($catalog->state());
    }

    private function catalog(): CatalogPort
    {
        return new DoctrineCatalog($this->db);
    }

    private function seed(): void
    {
        $passives = [
            ['strength89', 'Attribute', 'small', null],
            ['melee22_', 'Brutal Blows', 'notable', null],
            ['AscendancyTitan1', 'Titan Notable', 'notable', 'Titan1'],
        ];
        foreach ($passives as [$id, $name, $kind, $ascendancy]) {
            $this->db->executeStatement(
                'INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES (?, ?, ?, ?, 0, 0, ?, ?)',
                [$id, $name, $kind, $ascendancy, '[]', '[]'],
            );
        }
        $this->db->executeStatement('INSERT INTO catalog_passive_edge (from_id, to_id) VALUES (?, ?)', ['strength89', 'melee22_']);

        $gems = [
            ['Metadata/Items/Gems/SkillGemEarthquake', 'Earthquake', 'active', 'strength'],
            ['Metadata/Items/Gem/SupportGemAbidingHex', 'Abiding Hex', 'support', 'intelligence'],
        ];
        foreach ($gems as [$id, $name, $kind, $attribute]) {
            $this->db->executeStatement('INSERT INTO catalog_gem (id, name, kind, primary_attribute, icon) VALUES (?, ?, ?, ?, NULL)', [$id, $name, $kind, $attribute]);
        }
        foreach ([['Metadata/Items/Gems/SkillGemEarthquake', 'attack'], ['Metadata/Items/Gems/SkillGemEarthquake', 'melee'], ['Metadata/Items/Gems/SkillGemEarthquake', 'slam'], ['Metadata/Items/Gem/SupportGemAbidingHex', 'support'], ['Metadata/Items/Gem/SupportGemAbidingHex', 'curse']] as [$gem, $tag]) {
            $this->db->executeStatement('INSERT INTO catalog_gem_tag (gem_id, tag) VALUES (?, ?)', [$gem, $tag]);
        }

        $this->db->executeStatement(
            'INSERT INTO catalog_gem_requirement (gem_id, term, mode, origin, clause) VALUES (?, ?, ?, ?, ?)',
            ['Metadata/Items/Gem/SupportGemAbidingHex', 'Curse', 'requires', 'parsed', 'Supports [Curse] Skills you cast yourself.'],
        );

        foreach ([['Metadata/Items/Gems/SupportGemConcentratedEffect', 0], ['Metadata/Items/Gem/SupportGemMartialTempo', 1]] as [$support, $rank]) {
            $this->db->executeStatement('INSERT INTO catalog_gem_recommended_support (gem_id, support_id, rank) VALUES (?, ?, ?)', ['Metadata/Items/Gems/SkillGemEarthquake', $support, $rank]);
        }

        foreach ([['1', 'Astramentis', 'Amulet'], ['2', 'Grand Spectrum', 'Jewel'], ['3', 'Grand Spectrum', 'Jewel']] as [$id, $name, $class]) {
            $this->db->executeStatement('INSERT INTO catalog_unique (id, name, item_class, inventory_width, inventory_height, icon) VALUES (?, ?, ?, 1, 1, NULL)', [$id, $name, $class]);
        }

        $this->db->executeStatement(
            "INSERT INTO catalog_sync (source, ran_at, upstream_revision, game_version, status, count, error) VALUES ('items', ?, NULL, '0.5.5', 'ok', 3, NULL)",
            ['2026-09-11 12:00:00'],
        );
    }
}
