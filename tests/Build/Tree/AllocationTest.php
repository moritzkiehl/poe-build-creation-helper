<?php

declare(strict_types=1);

namespace App\Tests\Build\Tree;

use App\Build\Tree\Allocation;
use App\Build\Tree\WeaponSet;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class AllocationTest extends TestCase
{
    public function testAbsentWeaponSetMeansShared(): void
    {
        self::assertSame(WeaponSet::Shared, WeaponSet::fromWire(null));
        self::assertSame(WeaponSet::Shared, WeaponSet::fromWire(0));
        self::assertSame(WeaponSet::One, WeaponSet::fromWire(1));
        self::assertSame(WeaponSet::Two, WeaponSet::fromWire('2'));
    }

    public function testSharedIsWrittenAsAnAbsentKeyNotAsZero(): void
    {
        // The value 0 appears in no file the game has ever written; absence is
        // what shared looks like on the wire.
        self::assertNull(WeaponSet::Shared->toWire());
        self::assertSame(1, WeaponSet::One->toWire());
    }

    public function testASetSeesItsOwnNodesAndTheSharedOnesButNotTheOtherSet(): void
    {
        $allocation = Allocation::of($this->document());

        self::assertSame(['trunk'], $allocation->idsVisibleTo(WeaponSet::Shared));
        self::assertSame(['trunk', 'ailment'], $allocation->idsVisibleTo(WeaponSet::One));
        self::assertSame(['trunk', 'cooldown'], $allocation->idsVisibleTo(WeaponSet::Two));
    }

    public function testItReadsTheSetOfEachAllocatedNode(): void
    {
        $allocation = Allocation::of($this->document());

        self::assertSame(WeaponSet::Shared, $allocation->setOf('trunk'));
        self::assertSame(WeaponSet::One, $allocation->setOf('ailment'));
        self::assertNull($allocation->setOf('never_allocated'));
    }

    private function document(): BuildDocument
    {
        return new BuildDocument(
            name: 'test',
            passives: [
                ['id' => 'trunk', 'level_interval' => [1, 100]],
                ['id' => 'ailment', 'level_interval' => [1, 100], 'weapon_set' => 1],
                ['id' => 'cooldown', 'level_interval' => [1, 100], 'weapon_set' => 2],
            ],
        );
    }
}
