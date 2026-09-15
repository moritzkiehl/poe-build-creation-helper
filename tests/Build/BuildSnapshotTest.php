<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\BuildSnapshot;
use App\Entity\Build;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class BuildSnapshotTest extends TestCase
{
    public function testARevertRestoresTheJewelKeystoneAndTheInstilledPassives(): void
    {
        $build = $this->build();
        $build->setJewelKeystone('test_jewel_keystone');
        $build->addInstilledPassive('test_notable_a');
        $snapshot = BuildSnapshot::capture($build);

        $build->setJewelKeystone(null);
        $build->addInstilledPassive('test_notable_b');
        BuildSnapshot::restore($build, $snapshot);

        self::assertSame('test_jewel_keystone', $build->getJewelKeystone());
        self::assertSame(['test_notable_a'], $build->getInstilledPassives());
    }

    public function testASnapshotFromBeforeTheseFieldsLeavesThemAlone(): void
    {
        $build = $this->build();
        $snapshot = BuildSnapshot::capture($build);
        unset($snapshot['header']['jewel_keystone'], $snapshot['header']['instilled_passives']);

        $build->setJewelKeystone('test_jewel_keystone');
        $build->addInstilledPassive('test_notable_a');
        BuildSnapshot::restore($build, $snapshot);

        self::assertSame('test_jewel_keystone', $build->getJewelKeystone(), 'an old snapshot never recorded the jewel, so it must not clear it');
        self::assertSame(['test_notable_a'], $build->getInstilledPassives());
    }

    private function build(): Build
    {
        return new Build(document: new BuildDocument(name: 'Snapshot'), gameVersion: '0.5.5', shareSlug: str_repeat('a', 22), editTokenHash: 'hash');
    }
}
