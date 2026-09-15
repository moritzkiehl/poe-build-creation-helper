<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\InventorySlots;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InventorySlotsTest extends KernelTestCase
{
    public function testTheVocabularyIsTheFourteenValuesTheCorpusShows(): void
    {
        self::bootKernel();
        $slots = self::getContainer()->get(InventorySlots::class);

        self::assertSame(
            ['Weapon1', 'Offhand1', 'Weapon2', 'Offhand2', 'Helm1', 'BodyArmour1', 'Gloves1', 'Boots1', 'Belt1', 'Amulet1', 'Ring1', 'Ring2', 'Trinket1', 'Flask1'],
            array_column($slots->all(), 'id'),
        );
        self::assertTrue($slots->isKnown('Amulet1'));
        self::assertFalse($slots->isKnown('Backpack1'));
    }

    public function testTheBeltStripListsEachFlaskAndCharmPosition(): void
    {
        self::bootKernel();
        $slots = self::getContainer()->get(InventorySlots::class);

        $belt = array_values(array_filter(
            $slots->positions(),
            static fn (array $position): bool => \in_array($position['id'], ['Trinket1', 'Flask1'], true),
        ));

        // Measured against the corpus, 2026-09-12: charms at slot_x 2-4, a
        // life flask at 0 and a mana flask at 1, on one shared strip.
        self::assertSame(
            [
                ['id' => 'Trinket1', 'x' => 2, 'key' => 'Trinket1@2', 'label' => 'Charm 1'],
                ['id' => 'Trinket1', 'x' => 3, 'key' => 'Trinket1@3', 'label' => 'Charm 2'],
                ['id' => 'Trinket1', 'x' => 4, 'key' => 'Trinket1@4', 'label' => 'Charm 3'],
                ['id' => 'Flask1', 'x' => 0, 'key' => 'Flask1@0', 'label' => 'Life flask'],
                ['id' => 'Flask1', 'x' => 1, 'key' => 'Flask1@1', 'label' => 'Mana flask'],
            ],
            $belt,
        );
        self::assertCount(17, $slots->positions(), '12 single slots, 3 charms, 2 flasks');
        self::assertTrue($slots->hasPosition('Trinket1', 3));
        self::assertFalse($slots->hasPosition('Trinket1', 0), 'the charm strip starts at 2');
        self::assertTrue($slots->hasPosition('Amulet1', 0));
    }
}
