<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\IntervalMode;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class IntervalModeTest extends TestCase
{
    public function testPassivesSharingOneIntervalAreUniform(): void
    {
        self::assertTrue(IntervalMode::passivesAreUniform($this->passives([[1, 100], [1, 100]])));
    }

    public function testPassivesWithDifferentIntervalsAreNot(): void
    {
        self::assertFalse(IntervalMode::passivesAreUniform($this->passives([[1, 100], [34, 60]])));
    }

    public function testNoPassivesCountsAsUniform(): void
    {
        self::assertTrue(IntervalMode::passivesAreUniform(new BuildDocument(name: 'Build')), 'an empty tree opens in the simple mode');
    }

    public function testTheSpanIsTheWidest(): void
    {
        self::assertSame([12, 90], IntervalMode::passiveSpan($this->passives([[34, 60], [12, 55], [40, 90]])));
    }

    public function testTheSpanOfNothingIsTheWholeGame(): void
    {
        self::assertSame([1, 100], IntervalMode::passiveSpan(new BuildDocument(name: 'Build')));
    }

    public function testSupportsMatchingTheirSkillFollowIt(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [
            ['id' => 'a', 'level_interval' => [12, 100], 'support_skills' => [
                ['id' => 's1', 'level_interval' => [12, 100]],
            ]],
        ]);

        self::assertTrue(IntervalMode::supportsFollowTheirSkills($document));
    }

    public function testOneStaggeredSupportBreaksIt(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [
            ['id' => 'a', 'level_interval' => [12, 100], 'support_skills' => [
                ['id' => 's1', 'level_interval' => [12, 100]],
                ['id' => 's2', 'level_interval' => [34, 100]],
            ]],
        ]);

        self::assertFalse(IntervalMode::supportsFollowTheirSkills($document), 'a support slotted later is real planning and must keep its own control');
    }

    public function testSkillsMayDifferFromEachOther(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [
            ['id' => 'a', 'level_interval' => [12, 100], 'support_skills' => [['id' => 's1', 'level_interval' => [12, 100]]]],
            ['id' => 'b', 'level_interval' => [34, 100], 'support_skills' => [['id' => 's2', 'level_interval' => [34, 100]]]],
        ]);

        self::assertTrue(IntervalMode::supportsFollowTheirSkills($document), 'only supports collapse into their skill; skills stay independent');
    }

    public function testASkillWithNoSupportsCannotBreakIt(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [['id' => 'a', 'level_interval' => [12, 100]]]);

        self::assertTrue(IntervalMode::supportsFollowTheirSkills($document));
    }

    /**
     * @param list<array{int, int}> $intervals
     */
    private function passives(array $intervals): BuildDocument
    {
        return new BuildDocument(
            name: 'Build',
            passives: array_map(
                static fn (array $i): array => ['id' => 'n'.$i[0], 'level_interval' => $i, 'additional_text' => ''],
                $intervals,
            ),
        );
    }
}
