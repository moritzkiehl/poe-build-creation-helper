<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\DocumentEditor;
use App\Build\Edit\InvalidEditCommand;
use App\Build\InventorySlots;
use App\Interchange\BuildDocument;
use App\Interchange\BuildDocumentReader;
use PHPUnit\Framework\TestCase;

final class DocumentEditorSlotsTest extends TestCase
{
    private DocumentEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new DocumentEditor(new InventorySlots(__DIR__.'/../../config/inventory_slots.yaml'));
    }

    public function testSettingASlotWritesEveryFieldTheGameWrites(): void
    {
        $document = $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Amulet1', 'Astramentis', 1, 100, '');

        self::assertSame(
            [['inventory_id' => 'Amulet1', 'slot_x' => 0, 'slot_y' => 0, 'level_interval' => [1, 100], 'additional_text' => '', 'unique_name' => 'Astramentis']],
            $document->inventorySlots,
        );
    }

    public function testASlotWithoutAUniqueCarriesNoUniqueNameKey(): void
    {
        $document = $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Weapon1', null, 1, 100, 'any two-hander');

        self::assertArrayNotHasKey('unique_name', $document->inventorySlots[0]);
        self::assertSame('any two-hander', $document->inventorySlots[0]['additional_text']);
    }

    public function testSettingTheSameSlotTwiceReplacesItInPlace(): void
    {
        $document = $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Ring1', 'First', 1, 100, '');
        $document = $this->editor->setInventorySlot($document, 'Ring2', 'Second', 1, 100, '');
        $document = $this->editor->setInventorySlot($document, 'Ring1', 'Replaced', 1, 100, '');

        self::assertCount(2, $document->inventorySlots);
        self::assertSame(['Ring1', 'Ring2'], array_column($document->inventorySlots, 'inventory_id'));
        self::assertSame('Replaced', $document->inventorySlots[0]['unique_name']);
    }

    /**
     * The regression this iteration's review found: `setInventorySlot()` used
     * to rebuild the whole entry, which reset `slot_x`/`slot_y` to 0 and
     * dropped any key the game had written that this command does not own.
     * `Trinket1` in the fixture carries `slot_x: 2` — an edit must keep it.
     */
    public function testEditingAnImportedSlotKeepsItsCoordinates(): void
    {
        $json = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($json);
        $document = new BuildDocumentReader()->read($json);

        $edited = $this->editor->setInventorySlot($document, 'Trinket1', null, 5, 90, 'a new note');

        $index = array_search('Trinket1', array_column($edited->inventorySlots, 'inventory_id'), true);
        self::assertIsInt($index);
        $slot = $edited->inventorySlots[$index];

        self::assertSame(2, $slot['slot_x']);
        self::assertSame(0, $slot['slot_y']);
        self::assertSame([5, 90], $slot['level_interval']);
        self::assertSame('a new note', $slot['additional_text']);
    }

    public function testClearingASlotRemovesItEntirely(): void
    {
        $document = $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Ring1', 'First', 1, 100, '');

        self::assertSame([], $this->editor->clearInventorySlot($document, 'Ring1')->inventorySlots);
    }

    public function testASlotOutsideTheVocabularyIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Backpack1', null, 1, 100, '');
    }

    public function testASlotLevelIntervalIsRangeChecked(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Ring1', null, 50, 20, '');
    }
}
