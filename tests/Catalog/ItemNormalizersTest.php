<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\BaseItemNormalizer;
use App\Catalog\ModNormalizer;
use App\Catalog\UniqueNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Shape without content again: every identifier and name below is invented.
 */
final class ItemNormalizersTest extends TestCase
{
    public function testUniquesKeepTheirRepoeKeyBecauseNamesAreNotUnique(): void
    {
        $uniques = new UniqueNormalizer()->normalize($this->fixture('uniques-shape.json'));

        self::assertCount(3, $uniques);
        self::assertSame(['101', '102', '103'], array_column($uniques, 'id'));
    }

    /**
     * Grand Spectrum, Grip of Kulemak and Guiding Palm each name several
     * genuinely different uniques in 0.5.5 — eleven items behind three names,
     * and none of them alternate art. Since `.build` identifies a unique by name
     * alone, the catalog has to be able to say a name is ambiguous.
     */
    public function testAnAmbiguousNameIsVisibleInTheData(): void
    {
        $names = array_column(new UniqueNormalizer()->normalize($this->fixture('uniques-shape.json')), 'name');

        self::assertCount(2, array_keys($names, 'Synthetic Twin', true));
    }

    public function testBaseItemsDropGemsCurrencyAndUnreleasedEntries(): void
    {
        $bases = new BaseItemNormalizer()->normalize($this->fixture('base-items-shape.json'));

        self::assertCount(1, $bases->items);
        self::assertSame('Metadata/Items/Amulets/SyntheticAmulet', $bases->items[0]['id']);
    }

    public function testBaseItemTagsAreKeptBecauseModsSpawnAgainstThem(): void
    {
        $bases = new BaseItemNormalizer()->normalize($this->fixture('base-items-shape.json'));

        self::assertSame(['amulet', 'default'], array_column($bases->tags, 'tag'));
    }

    /**
     * At least one base lists the same tag twice (ExpeditionLogbook in 0.5.5).
     * The pair is a primary key, so a duplicate aborts the whole sync.
     */
    public function testARepeatedTagIsStoredOnce(): void
    {
        $tags = new BaseItemNormalizer()->normalize($this->fixture('base-items-shape.json'))->tags;

        self::assertSame(['amulet', 'default'], array_column($tags, 'tag'));
    }

    public function testARepeatedSpawnTagIsStoredOnce(): void
    {
        $spawns = new ModNormalizer()->normalize($this->fixture('mods-shape.json'))->spawnTags;
        $pairs = array_map(static fn (array $s): string => $s['mod_id'].'|'.$s['tag'], $spawns);

        self::assertSame($pairs, array_values(array_unique($pairs)));
    }

    public function testOnlyCraftableItemModsAreKept(): void
    {
        $mods = new ModNormalizer()->normalize($this->fixture('mods-shape.json'));

        self::assertSame(['SyntheticStrengthSuffix1', 'SyntheticDamagePrefix1'], array_column($mods->mods, 'id'));
    }

    /**
     * Unique-generation mods carry readable text but nothing maps them to an
     * item, so storing them would look like a feature while being unusable.
     */
    public function testOrphanedUniqueModsAreLeftOut(): void
    {
        $ids = array_column(new ModNormalizer()->normalize($this->fixture('mods-shape.json'))->mods, 'id');

        self::assertNotContains('SyntheticUniqueMod1', $ids);
    }

    public function testAModThatCannotRollOnATagIsNotLinkedToIt(): void
    {
        $spawns = new ModNormalizer()->normalize($this->fixture('mods-shape.json'))->spawnTags;

        self::assertSame(
            [['mod_id' => 'SyntheticStrengthSuffix1', 'tag' => 'amulet', 'weight' => 1000], ['mod_id' => 'SyntheticDamagePrefix1', 'tag' => 'weapon', 'weight' => 500]],
            $spawns,
        );
    }

    private function fixture(string $name): string
    {
        $json = file_get_contents(__DIR__.'/../fixtures/catalog/'.$name);
        self::assertIsString($json);

        return $json;
    }
}
