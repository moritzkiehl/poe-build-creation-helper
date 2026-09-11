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
}
