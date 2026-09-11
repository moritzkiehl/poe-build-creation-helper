<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\DocumentEditor;
use App\Build\Edit\InvalidEditCommand;
use App\Build\InventorySlots;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class DocumentEditorPassivesTest extends TestCase
{
    private DocumentEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new DocumentEditor(new InventorySlots(__DIR__.'/../../config/inventory_slots.yaml'));
    }

    public function testAllocatingAPassiveAddsItWithTheDefaultsTheCorpusShows(): void
    {
        $document = $this->editor->allocatePassive(new BuildDocument(name: 'Build'), 'strength89');

        self::assertSame(
            [['id' => 'strength89', 'level_interval' => [1, 100], 'additional_text' => '']],
            $document->passives,
        );
    }

    public function testAllocatingTwiceChangesNothing(): void
    {
        $once = $this->editor->allocatePassive(new BuildDocument(name: 'Build'), 'strength89');
        $twice = $this->editor->allocatePassive($once, 'strength89');

        self::assertCount(1, $twice->passives);
    }

    public function testATrailingUnderscoreInAnIdSurvives(): void
    {
        $document = $this->editor->allocatePassive(new BuildDocument(name: 'Build'), 'melee22_');

        self::assertSame('melee22_', $document->passives[0]['id']);
    }

    public function testDeallocatingRemovesOnlyThatPassiveAndKeepsTheListAList(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [
            ['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => ''],
            ['id' => 'b', 'level_interval' => [1, 100], 'additional_text' => ''],
            ['id' => 'c', 'level_interval' => [1, 100], 'additional_text' => ''],
        ]);

        $changed = $this->editor->deallocatePassive($document, 'b');

        self::assertSame(['a', 'c'], array_column($changed->passives, 'id'));
        self::assertSame([0, 1], array_keys($changed->passives));
    }

    public function testDeallocatingSomethingAbsentChangesNothing(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => '']]);

        self::assertEquals($document, $this->editor->deallocatePassive($document, 'zzz'));
    }

    public function testTheLevelIntervalIsSetInPlaceAndKeepsEveryOtherField(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [
            ['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => 'keep me', 'weapon_set' => 2],
        ]);

        $changed = $this->editor->setPassiveLevelInterval($document, 'a', 34, 60);

        self::assertSame(
            ['id' => 'a', 'level_interval' => [34, 60], 'additional_text' => 'keep me', 'weapon_set' => 2],
            $changed->passives[0],
        );
    }

    public function testALevelOutsideTheGamesRangeIsRefused(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => '']]);

        $this->expectException(InvalidEditCommand::class);

        $this->editor->setPassiveLevelInterval($document, 'a', 1, 101);
    }

    public function testAnIntervalThatEndsBeforeItStartsIsRefused(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => '']]);

        $this->expectException(InvalidEditCommand::class);

        $this->editor->setPassiveLevelInterval($document, 'a', 60, 34);
    }

    public function testSettingTheIntervalOfAPassiveThatIsNotAllocatedIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setPassiveLevelInterval(new BuildDocument(name: 'Build'), 'a', 1, 100);
    }
}
