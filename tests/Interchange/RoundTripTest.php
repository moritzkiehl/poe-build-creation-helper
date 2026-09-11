<?php

declare(strict_types=1);

namespace App\Tests\Interchange;

use App\Interchange\BuildDocumentReader;
use App\Interchange\BuildDocumentWriter;
use PHPUnit\Framework\TestCase;

final class RoundTripTest extends TestCase
{
    /**
     * The acceptance condition of iteration 1: whatever the game wrote, we hand
     * back unchanged. Compared as decoded data, because key order is not part of
     * the contract.
     */
    public function testReadingAndWritingPreservesTheWholeDocument(): void
    {
        $json = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($json);

        $written = new BuildDocumentWriter()->write(new BuildDocumentReader()->read($json));

        self::assertSame(json_decode($json, true), json_decode($written, true));
    }

    /**
     * The shapes below are not guesses: they were read off fourteen build files
     * exported by Path of Exile 2 at 0.5.5. A `level_interval` is a two-element
     * array, not an object, and `weapon_set` is 1 or 2 — the numbered halves of
     * the Weapon1/Offhand1 and Weapon2/Offhand2 slots.
     */
    public function testTheShapesTheGameActuallyWritesSurviveUntouched(): void
    {
        $json = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($json);

        $document = new BuildDocumentReader()->read($json);

        self::assertSame([34, 60], $document->passives[1]['level_interval']);
        self::assertSame(1, $document->passives[1]['weapon_set']);
        self::assertSame([12, 100], $document->skills[0]['level_interval']);
        self::assertSame('Amulet1', $document->inventorySlots[0]['inventory_id']);
    }
}
