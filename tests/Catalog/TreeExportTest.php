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
        $this->db->executeStatement(
            'INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['n1', 'Node One', 'small', null, 1234.5, -678.0, '[]'],
        );

        $export = new TreeExport($this->db);
        $payload = $export->payload();

        self::assertSame(['n1', 'Node One', 'small', null, 1234.5, -678.0], $payload['nodes'][0]);
    }
}
