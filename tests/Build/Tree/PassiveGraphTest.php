<?php

declare(strict_types=1);

namespace App\Tests\Build\Tree;

use App\Build\Tree\PassiveGraph;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PassiveGraphTest extends KernelTestCase
{
    public function testItReadsAdjacencyInBothDirections(): void
    {
        $graph = $this->graphOver(
            nodes: [['a', 'small'], ['b', 'small']],
            edges: [['a', 'b']],
        );

        self::assertSame(['b'], $graph->neighbours('a'));
        self::assertSame(['a'], $graph->neighbours('b'), 'an edge stored one way must still connect both ways');
        self::assertSame([], $graph->neighbours('nowhere'));
    }

    public function testItReadsKeystoneRadiiAndUnlockConstraints(): void
    {
        $graph = $this->graphOver(
            nodes: [['covered', 'small'], ['stone', 'keystone']],
            edges: [],
            radius: ['covered' => ['stone']],
            constraints: ['covered' => ['nodes' => ['gate'], 'ascendancy' => 'Druid1']],
        );

        self::assertSame(['stone'], $graph->keystonesCovering('covered'));
        self::assertSame(['nodes' => ['gate'], 'ascendancy' => 'Druid1'], $graph->unlockConstraintOf('covered'));
        self::assertNull($graph->unlockConstraintOf('stone'));
        self::assertTrue($graph->isKeystone('stone'));
        self::assertFalse($graph->isKeystone('covered'));
    }

    /**
     * @param list<array{0: string, 1: string}>                                  $nodes
     * @param list<array{0: string, 1: string}>                                  $edges
     * @param array<string, list<string>>                                        $radius
     * @param array<string, array{nodes: list<string>, ascendancy: string|null}> $constraints
     */
    private function graphOver(array $nodes, array $edges, array $radius = [], array $constraints = []): PassiveGraph
    {
        $db = self::getContainer()->get(Connection::class);

        $db->executeStatement('DELETE FROM catalog_passive_edge');
        $db->executeStatement('DELETE FROM catalog_passive');

        foreach ($nodes as [$id, $kind]) {
            $db->executeStatement(
                'INSERT INTO catalog_passive (id, name, kind, pos_x, pos_y, stats, recipe, keystones_in_radius, unlock_constraint) VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?)',
                [$id, ucfirst($id), $kind, '[]', '[]', json_encode($radius[$id] ?? [], \JSON_THROW_ON_ERROR), isset($constraints[$id]) ? json_encode($constraints[$id], \JSON_THROW_ON_ERROR) : null],
            );
        }

        foreach ($edges as [$from, $to]) {
            $db->executeStatement('INSERT INTO catalog_passive_edge (from_id, to_id) VALUES (?, ?)', [$from, $to]);
        }

        return new PassiveGraph($db);
    }
}
