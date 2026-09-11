<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\DocumentEditor;
use App\Build\Edit\InvalidEditCommand;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class DocumentEditorSkillsTest extends TestCase
{
    private DocumentEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new DocumentEditor();
    }

    public function testAddingASkillCarriesNoAdditionalTextAndNoSupportsYet(): void
    {
        $document = $this->editor->addSkill(new BuildDocument(name: 'Build'), 'Metadata/Items/Gems/SkillGemEarthquake');

        self::assertSame(
            [['id' => 'Metadata/Items/Gems/SkillGemEarthquake', 'level_interval' => [1, 100]]],
            $document->skills,
            'the corpus shows additional_text on passives and slots, never on skills',
        );
    }

    public function testBothGemPathPrefixesSurviveVerbatim(): void
    {
        $document = $this->editor->addSkill(new BuildDocument(name: 'Build'), 'Metadata/Items/Gem/SkillGemHatefulFocus');
        $document = $this->editor->addSkill($document, 'Metadata/Items/Gems/SkillGemEarthquake');

        self::assertSame(
            ['Metadata/Items/Gem/SkillGemHatefulFocus', 'Metadata/Items/Gems/SkillGemEarthquake'],
            array_column($document->skills, 'id'),
        );
    }

    public function testTheSameGemMayBeAddedTwice(): void
    {
        $document = $this->editor->addSkill(new BuildDocument(name: 'Build'), 'Metadata/Items/Gems/SkillGemEarthquake');
        $document = $this->editor->addSkill($document, 'Metadata/Items/Gems/SkillGemEarthquake');

        self::assertCount(2, $document->skills, 'one gem at two level intervals is a real plan, not a mistake');
    }

    public function testRemovingASkillClosesTheGapInTheList(): void
    {
        $document = $this->skills(['a', 'b', 'c']);

        $changed = $this->editor->removeSkill($document, 1);

        self::assertSame(['a', 'c'], array_column($changed->skills, 'id'));
        self::assertSame([0, 1], array_keys($changed->skills));
    }

    public function testRemovingASkillThatIsNotThereIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->removeSkill($this->skills(['a']), 7);
    }

    public function testASkillsLevelIntervalIsSetInPlace(): void
    {
        $changed = $this->editor->setSkillLevelInterval($this->skills(['a']), 0, 12, 100);

        self::assertSame([12, 100], $changed->skills[0]['level_interval']);
    }

    public function testASkillsLevelIntervalIsRangeChecked(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setSkillLevelInterval($this->skills(['a']), 0, 0, 101);
    }

    public function testTheSupportsKeyAppearsOnlyOnceASupportIsAdded(): void
    {
        $document = $this->editor->addSupport($this->skills(['a']), 0, 'Metadata/Items/Gems/SupportGemFastForward');

        self::assertSame(
            ['id' => 'a', 'level_interval' => [1, 100], 'support_skills' => [
                ['id' => 'Metadata/Items/Gems/SupportGemFastForward', 'level_interval' => [1, 100]],
            ]],
            $document->skills[0],
        );
    }

    public function testAddingTheSameSupportTwiceToOneSkillChangesNothing(): void
    {
        $document = $this->editor->addSupport($this->skills(['a']), 0, 'support1');
        $document = $this->editor->addSupport($document, 0, 'support1');

        self::assertCount(1, $this->supportSkillsOf($document->skills[0]));
    }

    public function testOneSupportMayServeTwoDifferentSkills(): void
    {
        $document = $this->editor->addSupport($this->skills(['a', 'b']), 0, 'support1');
        $document = $this->editor->addSupport($document, 1, 'support1');

        self::assertCount(1, $this->supportSkillsOf($document->skills[0]));
        self::assertCount(1, $this->supportSkillsOf($document->skills[1]), 'support uniqueness per character was lifted in 0.3');
    }

    public function testRemovingTheLastSupportRemovesTheKeyAgain(): void
    {
        $document = $this->editor->addSupport($this->skills(['a']), 0, 'support1');

        $changed = $this->editor->removeSupport($document, 0, 'support1');

        self::assertSame(['id' => 'a', 'level_interval' => [1, 100]], $changed->skills[0]);
    }

    public function testASupportsLevelIntervalIsSetInPlace(): void
    {
        $document = $this->editor->addSupport($this->skills(['a']), 0, 'support1');

        $changed = $this->editor->setSupportLevelInterval($document, 0, 'support1', 34, 100);

        self::assertSame([34, 100], $this->supportSkillsOf($changed->skills[0])[0]['level_interval']);
    }

    public function testTouchingASupportThatIsNotOnThatSkillIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setSupportLevelInterval($this->skills(['a']), 0, 'absent', 1, 100);
    }

    /**
     * The format's `support_skills` value is untyped from PHPStan's point of
     * view (it lives in a `array<string, mixed>` skill entry), so counting or
     * indexing into it directly is an offset access on `mixed`. Narrow it
     * once here instead of loosening the property's declared type.
     *
     * @param array<string, mixed> $skill
     *
     * @return list<array<string, mixed>>
     */
    private function supportSkillsOf(array $skill): array
    {
        $supports = $skill['support_skills'] ?? [];
        self::assertIsArray($supports);

        /** @var list<array<string, mixed>> $supports */
        return $supports;
    }

    /**
     * @param list<string> $ids
     */
    private function skills(array $ids): BuildDocument
    {
        return new BuildDocument(
            name: 'Build',
            skills: array_map(static fn (string $id): array => ['id' => $id, 'level_interval' => [1, 100]], $ids),
        );
    }
}
