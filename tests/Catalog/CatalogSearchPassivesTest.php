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

    public function testPassivesFormatsStatsLikeItsNeighboursRatherThanPrintingRawMarkup(): void
    {
        $this->seedPassives([
            ['id' => 'criticals1', 'name' => 'Critical Damage', 'kind' => 'small', 'stats' => ['15% increased [CriticalDamageBonus|Critical Damage Bonus]']],
        ]);

        $found = array_column($this->search->passives('Critical'), null, 'id');

        self::assertSame(['15% increased Critical Damage Bonus'], $found['criticals1']['stats']);
    }

    public function testOnlyNodesWithARecipeAreInstillable(): void
    {
        $this->seedPassives([
            ['id' => 'ignite_mitigation13', 'name' => 'Self Immolation', 'kind' => 'notable', 'recipe' => ['LiquidA', 'LiquidB', 'LiquidC']],
            ['id' => 'strength89', 'name' => 'Attribute', 'kind' => 'small', 'recipe' => []],
        ]);

        $results = $this->search->instillablePassives('a');

        self::assertSame(['ignite_mitigation13'], array_column($results, 'id'), 'a node with no recipe cannot be instilled');
        self::assertSame(['Liquid A', 'Liquid B', 'Liquid C'], $results[0]['recipe'], 'the cost is readable, like everywhere else');
    }

    public function testAnEmptyInstillableQueryAnswersNothing(): void
    {
        self::assertSame([], $this->search->instillablePassives(''));
    }

    public function testLookingUpNodesByIdReturnsReadableDetail(): void
    {
        $this->seedPassives([
            ['id' => 'criticals1', 'name' => 'Critical Damage', 'kind' => 'small', 'stats' => ['15% increased [CriticalDamageBonus|Critical Damage Bonus]'], 'recipe' => []],
            ['id' => 'ignite_mitigation13', 'name' => 'Self Immolation', 'kind' => 'notable', 'stats' => [], 'recipe' => ['ConcentratedLiquidSuffering']],
        ]);

        $found = array_column($this->search->passivesByIds(['ignite_mitigation13', 'criticals1']), null, 'id');

        self::assertSame(['15% increased Critical Damage Bonus'], $found['criticals1']['stats']);
        self::assertSame(['Concentrated Liquid Suffering'], $found['ignite_mitigation13']['recipe']);
    }

    public function testLookingUpNothingQueriesNothing(): void
    {
        self::assertSame([], $this->search->passivesByIds([]));
    }

    /**
     * @param list<array{id: string, name: string, kind: string, stats?: list<string>, recipe?: list<string>}> $passives
     */
    private function seedPassives(array $passives): void
    {
        foreach ($passives as $passive) {
            $this->db->executeStatement(
                'INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES (?, ?, ?, NULL, 0, 0, ?, ?)',
                [$passive['id'], $passive['name'], $passive['kind'], json_encode($passive['stats'] ?? []), json_encode($passive['recipe'] ?? [])],
            );
        }
    }
}
