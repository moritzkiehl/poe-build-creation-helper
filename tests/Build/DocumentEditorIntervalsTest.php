<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\DocumentEditor;
use App\Build\Edit\InvalidEditCommand;
use App\Build\InventorySlots;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class DocumentEditorIntervalsTest extends TestCase
{
    private DocumentEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new DocumentEditor(new InventorySlots(__DIR__.'/../../config/inventory_slots.yaml'));
    }

    public function testSettingAllPassiveIntervalsTouchesEveryOneAndKeepsOtherFields(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [
            ['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => 'keep me', 'weapon_set' => 2],
            ['id' => 'b', 'level_interval' => [34, 60], 'additional_text' => ''],
        ]);

        $changed = $this->editor->setAllPassiveLevelIntervals($document, 12, 90);

        self::assertSame([[12, 90], [12, 90]], array_column($changed->passives, 'level_interval'));
        self::assertSame('keep me', $changed->passives[0]['additional_text']);
        self::assertSame(2, $changed->passives[0]['weapon_set']);
    }

    public function testSettingAllPassiveIntervalsIsRangeChecked(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setAllPassiveLevelIntervals(new BuildDocument(name: 'Build', passives: [['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => '']]), 60, 34);
    }

    public function testCascadingASkillIntervalCarriesItsSupportsWithIt(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [
            ['id' => 'a', 'level_interval' => [1, 100], 'support_skills' => [
                ['id' => 's1', 'level_interval' => [1, 100]],
                ['id' => 's2', 'level_interval' => [34, 100]],
            ]],
            ['id' => 'b', 'level_interval' => [1, 100]],
        ]);

        $changed = $this->editor->setSkillLevelIntervalCascading($document, 0, 12, 90);
        $supports = $changed->skills[0]['support_skills'];
        self::assertIsArray($supports);

        self::assertSame([12, 90], $changed->skills[0]['level_interval']);
        self::assertSame([[12, 90], [12, 90]], array_column($supports, 'level_interval'));
        self::assertSame([1, 100], $changed->skills[1]['level_interval'], 'other skills are independent');
    }

    public function testCascadingASkillWithNoSupportsJustSetsTheSkill(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [['id' => 'a', 'level_interval' => [1, 100]]]);

        $changed = $this->editor->setSkillLevelIntervalCascading($document, 0, 12, 90);

        self::assertSame(['id' => 'a', 'level_interval' => [12, 90]], $changed->skills[0], 'no empty support_skills key appears');
    }

    public function testCascadingAnAbsentSkillIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setSkillLevelIntervalCascading(new BuildDocument(name: 'Build'), 3, 12, 90);
    }
}
