<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\PassiveTreeNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The fixture mimics the shape of the official skill tree export without
 * carrying any of its content: node ids, names and coordinates are invented.
 * Real excerpts must not live in this repository — see measure 5 in the design
 * document.
 */
final class PassiveTreeNormalizerTest extends TestCase
{
    public function testNodesWithoutAnIdAreNotAllocatableAndAreDropped(): void
    {
        $nodes = $this->normalize()->nodes;

        self::assertNotContains(null, array_column($nodes, 'id'));
        self::assertCount(5, $nodes);
    }

    public function testTheSyntheticRootIsNotAPassive(): void
    {
        self::assertNotContains('root', array_column($this->normalize()->nodes, 'id'));
    }

    public function testNodeKindIsReadFromTheExportFlags(): void
    {
        $byId = array_column($this->normalize()->nodes, null, 'id');

        self::assertSame('small', $byId['synthetic12']['kind']);
        self::assertSame('notable', $byId['synthetic13_']['kind']);
        self::assertSame('keystone', $byId['synthetic14']['kind']);
    }

    public function testAnAscendancyNodeKeepsItsAscendancyKey(): void
    {
        $byId = array_column($this->normalize()->nodes, null, 'id');

        self::assertSame('Synthetic1', $byId['AscendancySynthetic1Small1']['ascendancy_key']);
        self::assertNull($byId['synthetic12']['ascendancy_key']);
    }

    public function testTrailingUnderscoresInIdsSurviveVerbatim(): void
    {
        self::assertContains('synthetic13_', array_column($this->normalize()->nodes, 'id'));
    }

    public function testEdgesAreCollectedBetweenAllocatableNodesOnly(): void
    {
        $edges = $this->normalize()->edges;

        self::assertContains(['synthetic12', 'synthetic13_'], $edges);
        foreach ($edges as [$from, $to]) {
            self::assertNotSame('root', $from);
            self::assertNotSame('root', $to);
        }
    }

    public function testEveryClassIsReadFromTheExportWithItsStartNode(): void
    {
        $classes = array_column($this->normalize()->classes, null, 'id');

        self::assertCount(2, $classes);
        self::assertSame('syntheticstart1', $classes['Warrior']['start_node_id']);
        self::assertSame('syntheticstart1', $classes['Sorceress']['start_node_id'], 'two classes share one physical start node');
        self::assertSame(15, $classes['Warrior']['base_str']);
    }

    public function testAClassCarriesItsAscendancies(): void
    {
        $classes = array_column($this->normalize()->classes, null, 'id');

        self::assertSame([['id' => 'Synthetic1', 'name' => 'Synthetic Ascendant']], $classes['Warrior']['ascendancies']);
        self::assertSame([], $classes['Sorceress']['ascendancies']);
    }

    private function normalize(): \App\Catalog\NormalizedTree
    {
        $json = file_get_contents(__DIR__.'/../fixtures/catalog/tree-shape.json');
        self::assertIsString($json);

        return new PassiveTreeNormalizer()->normalize($json);
    }
}
