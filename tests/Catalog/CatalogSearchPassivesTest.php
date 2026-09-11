<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogSearch;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogSearchPassivesTest extends KernelTestCase
{
    private Connection $db;
    private CatalogSearch $search;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->executeStatement('DELETE FROM catalog_passive_edge');
        $this->db->executeStatement('DELETE FROM catalog_passive');

        $this->search = new CatalogSearch($this->db);
    }

    public function testSearchingMatchesNameOrIdAndAnsweringNothingForAnEmptyQuery(): void
    {
        $this->seedPassives([
            ['id' => 'strength89', 'name' => 'Attribute', 'kind' => 'small'],
            ['id' => 'melee22_', 'name' => 'Brutal Strikes', 'kind' => 'notable'],
        ]);

        self::assertSame(['melee22_'], array_column($this->search->passives('Brutal'), 'id'));
        self::assertSame(['strength89'], array_column($this->search->passives('strength8'), 'id'), 'ids are searchable because findings name them');
        self::assertSame([], $this->search->passives(''));
    }

    /**
     * @param list<array{id: string, name: string, kind: string}> $passives
     */
    private function seedPassives(array $passives): void
    {
        foreach ($passives as $passive) {
            $this->db->executeStatement(
                'INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats) VALUES (?, ?, ?, NULL, 0, 0, ?)',
                [$passive['id'], $passive['name'], $passive['kind'], '[]'],
            );
        }
    }
}
