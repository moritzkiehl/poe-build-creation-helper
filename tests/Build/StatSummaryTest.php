<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\StatSummary;
use PHPUnit\Framework\TestCase;

final class StatSummaryTest extends TestCase
{
    public function testAFamilyIsTheIdWithoutItsNumber(): void
    {
        self::assertSame('area_attacks', StatSummary::family('area_attacks38'));
        self::assertSame('criticals', StatSummary::family('criticals7'));
    }

    public function testATrailingUnderscoreGoesWithTheNumber(): void
    {
        self::assertSame('melee', StatSummary::family('melee22_'));
    }

    public function testAnIdWithNoNumberIsItsOwnFamily(): void
    {
        self::assertSame('strength', StatSummary::family('strength'));
    }

    public function testRepeatedLinesAreSummed(): void
    {
        $summary = StatSummary::of([
            'criticals1' => ['10% increased Critical Hit Chance'],
            'criticals2' => ['10% increased Critical Hit Chance'],
            'criticals3' => ['10% increased Critical Hit Chance'],
        ]);

        self::assertCount(1, $summary);
        self::assertSame('criticals', $summary[0]['key']);
        self::assertSame(3, $summary[0]['nodes']);
        self::assertSame(['30% increased Critical Hit Chance'], $summary[0]['summed']);
        self::assertSame([], $summary[0]['listed']);
    }

    public function testDifferentNumbersOnTheSameLineStillSum(): void
    {
        $summary = StatSummary::of([
            'criticals1' => ['10% increased Critical Hit Chance'],
            'criticals2' => ['25% increased Critical Hit Chance'],
        ]);

        self::assertSame(['35% increased Critical Hit Chance'], $summary[0]['summed']);
    }

    public function testASignedValueKeepsItsSign(): void
    {
        $summary = StatSummary::of([
            'fire1' => ['+2% to Maximum Fire Resistance'],
            'fire2' => ['+1% to Maximum Fire Resistance'],
        ]);

        self::assertSame(['+3% to Maximum Fire Resistance'], $summary[0]['summed']);
    }

    public function testADecimalSums(): void
    {
        $summary = StatSummary::of([
            'regen1' => ['0.5% of Life Regenerated per second'],
            'regen2' => ['0.25% of Life Regenerated per second'],
        ]);

        self::assertSame(['0.75% of Life Regenerated per second'], $summary[0]['summed']);
    }

    public function testALineWithTwoNumbersIsListedNotSummed(): void
    {
        $summary = StatSummary::of([
            'hybrid1' => ['Adds 3 to 7 Physical Damage'],
            'hybrid2' => ['Adds 3 to 7 Physical Damage'],
        ]);

        self::assertSame([], $summary[0]['summed']);
        self::assertSame([['text' => 'Adds 3 to 7 Physical Damage', 'count' => 2]], $summary[0]['listed']);
    }

    public function testALineWithNoNumberIsListedNotSummed(): void
    {
        $summary = StatSummary::of([
            'keystone1' => ['You cannot be interrupted'],
        ]);

        self::assertSame([], $summary[0]['summed']);
        self::assertSame([['text' => 'You cannot be interrupted', 'count' => 1]], $summary[0]['listed']);
    }

    public function testOneNodeCanContributeSummableAndUnsummableLines(): void
    {
        $summary = StatSummary::of([
            'mixed1' => ['10% increased Armour', 'You cannot be interrupted'],
            'mixed2' => ['15% increased Armour'],
        ]);

        self::assertSame(['25% increased Armour'], $summary[0]['summed']);
        self::assertSame([['text' => 'You cannot be interrupted', 'count' => 1]], $summary[0]['listed']);
    }

    public function testFamiliesAreSeparateAndOrdered(): void
    {
        $summary = StatSummary::of([
            'zeal1' => ['10% increased Zeal'],
            'armour1' => ['10% increased Armour'],
        ]);

        self::assertSame(['armour', 'zeal'], array_column($summary, 'key'));
    }

    public function testNothingAllocatedIsAnEmptySummary(): void
    {
        self::assertSame([], StatSummary::of([]));
    }
}
