<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\TreeExport;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Nothing else reads `catalog_passive` through raw DBAL rather than the ORM:
 * the driver hands FLOAT columns back as native PHP floats here, not strings,
 * which is exactly the distinction {@see \App\Catalog\Row::float()} exists
 * for. This once read every node's coordinates as 0.0 regardless of what was
 * stored, discovered only by the one test that puts a real node in front of a
 * real browser (tests/e2e/editor.spec.js) — nothing PHP-side ever gave the
 * canvas real coordinates to get wrong.
 */
final class TreeExportTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->executeStatement('DELETE FROM catalog_class');
        $this->db->executeStatement('DELETE FROM catalog_passive_edge');
        $this->db->executeStatement('DELETE FROM catalog_passive');
        $this->db->executeStatement('DELETE FROM catalog_sync');
    }

    public function testNodeCoordinatesSurviveTheRoundTripThroughRawDbal(): void
    {
        $this->seedPassive(id: 'n1', name: 'Node One', posX: 1234.5, posY: -678.0);

        $node = $this->nodeById('n1');

        self::assertSame(['n1', 'Node One', 'small', null, 1234.5, -678.0], \array_slice($node, 0, 6));
    }

    public function testANodeShipsReadableStatsAndRecipe(): void
    {
        $this->seedPassive(
            id: 'e2e_detail',
            name: 'Detail Node',
            kind: 'notable',
            ascendancyKey: 'gemling',
            posX: 12.5,
            posY: -34.5,
            stats: ['40% reduced [BuffMagnitude|Magnitude] of [Ignite|Ignite] on you'],
            recipe: ['ConcentratedLiquidSuffering', 'LiquidDespair'],
        );

        $node = $this->nodeById('e2e_detail');

        // Checked by position, not by count: the tuple is a contract with a
        // fixed order, and a count check cannot catch two adjacent strings
        // (name, kind) swapped — only the value at each index can.
        self::assertSame('e2e_detail', $node[0]);
        self::assertSame('Detail Node', $node[1]);
        self::assertSame('notable', $node[2]);
        self::assertSame('gemling', $node[3]);
        self::assertSame(12.5, $node[4]);
        self::assertSame(-34.5, $node[5]);
        self::assertSame(['40% reduced Magnitude of Ignite on you'], $node[6]);
        self::assertSame(['Concentrated Liquid Suffering', 'Liquid Despair'], $node[7]);
    }

    public function testTheNodeTupleCarriesLegalityDataAtItsAgreedPositions(): void
    {
        $this->db->executeStatement('DELETE FROM catalog_passive');
        $this->db->executeStatement(
            "INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe, keystones_in_radius, unlock_constraint)
             VALUES ('covered', 'Covered', 'small', NULL, 1.5, -2.5, '[]', '[]', '[\"keystone_a\"]', '{\"nodes\":[\"gate_a\"],\"ascendancy\":\"Druid1\"}')"
        );

        $payload = self::getContainer()->get(TreeExport::class)->payload();
        $node = $payload['nodes'][0];

        // Tuple length is enforced by the @return shape on payload(), verified by PHPStan at max level.
        // Positional index assertions below catch mutations to the tuple structure.
        self::assertSame(['keystone_a'], $node[8]);
        self::assertSame(['nodes' => ['gate_a'], 'ascendancy' => 'Druid1'], $node[9]);
    }

    public function testUnlockConstraintIsNullWhenNotSet(): void
    {
        $this->seedPassive(id: 'null_constraint');

        $node = $this->nodeById('null_constraint');

        self::assertSame([], $node[8]);
        self::assertNull($node[9]);
    }

    /**
     * @param list<string> $stats
     * @param list<string> $recipe
     */
    private function seedPassive(
        string $id,
        string $name = 'Node',
        string $kind = 'small',
        ?string $ascendancyKey = null,
        float $posX = 0.0,
        float $posY = 0.0,
        array $stats = [],
        array $recipe = [],
    ): void {
        $this->db->executeStatement(
            'INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $name, $kind, $ascendancyKey, $posX, $posY, json_encode($stats), json_encode($recipe)],
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string|null, 4: float, 5: float, 6: list<string>, 7: list<string>, 8: list<string>, 9: array{nodes: list<string>, ascendancy: string|null}|null}
     */
    private function nodeById(string $id): array
    {
        $export = new TreeExport($this->db);
        foreach ($export->payload()['nodes'] as $node) {
            if ($id === $node[0]) {
                return $node;
            }
        }

        self::fail(\sprintf('no node with id "%s" in the payload', $id));
    }
}
