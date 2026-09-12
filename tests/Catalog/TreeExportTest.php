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
            stats: ['40% reduced [BuffMagnitude|Magnitude] of [Ignite|Ignite] on you'],
            recipe: ['ConcentratedLiquidSuffering', 'LiquidDespair'],
        );

        $node = $this->nodeById('e2e_detail');

        self::assertCount(8, $node, 'the tuple is a contract: id, name, kind, ascendancy, x, y, stats, recipe');
        self::assertSame(['40% reduced Magnitude of Ignite on you'], $node[6]);
        self::assertSame(['Concentrated Liquid Suffering', 'Liquid Despair'], $node[7]);
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
     * Untyped on purpose: the node tuple's length is exactly what this test
     * fixture asserts, and a return type precise enough to describe it would
     * tell PHPStan the length before the assertion runs, making the assertion
     * a tautology instead of a check.
     *
     * @return list<string|float|list<string>|null>
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
