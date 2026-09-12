<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\PassiveTreeSync;
use App\Catalog\SourceFetcher;
use App\Entity\CatalogSync;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PassiveTreeSyncTest extends KernelTestCase
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

    public function testASuccessfulRunStoresNodesEdgesAndALogEntry(): void
    {
        $result = $this->sync($this->fixture())->run();

        self::assertTrue($result->ok);
        self::assertSame(5, $this->rowCount('SELECT COUNT(*) FROM catalog_passive'));
        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM catalog_passive_edge'));

        $log = self::getContainer()->get(EntityManagerInterface::class)->getRepository(CatalogSync::class)->findOneBy(['source' => 'passive_tree']);
        self::assertNotNull($log);
        self::assertSame('ok', $log->getStatus());
        self::assertSame(5, $log->getCount());
    }

    public function testARerunReplacesRatherThanDuplicates(): void
    {
        $this->sync($this->fixture())->run();
        $this->sync($this->fixture())->run();

        self::assertSame(5, $this->rowCount('SELECT COUNT(*) FROM catalog_passive'));
    }

    public function testAnUnchangedUpstreamIsNotRebuilt(): void
    {
        $this->sync($this->fixture())->run();

        $result = $this->sync(new MockResponse('', ['http_code' => 304]))->run();

        self::assertTrue($result->ok);
        self::assertSame('unchanged', $result->status);
        self::assertSame(5, $this->rowCount('SELECT COUNT(*) FROM catalog_passive'));
    }

    public function testTheRevisionUpstreamGaveIsRecorded(): void
    {
        $this->sync($this->fixture())->run();

        self::assertSame('"synthetic-etag"', $this->columnValue('SELECT upstream_revision FROM catalog_sync ORDER BY id DESC LIMIT 1'));
    }

    public function testABrokenUpstreamLeavesTheCatalogAloneAndIsLogged(): void
    {
        $this->sync($this->fixture())->run();

        $result = $this->sync(new MockResponse('', ['http_code' => 503]))->run();

        self::assertFalse($result->ok);
        self::assertSame(5, $this->rowCount('SELECT COUNT(*) FROM catalog_passive'), 'a failed sync must not empty the catalog');
        self::assertSame('failed', $this->columnValue('SELECT status FROM catalog_sync ORDER BY id DESC LIMIT 1'));
    }

    public function testTheSyncStoresTheClassesFromTheExport(): void
    {
        $this->sync($this->fixture())->run();

        $rows = $this->db->fetchAllAssociative('SELECT id, start_node_id FROM catalog_class ORDER BY id');

        self::assertSame(
            [['id' => 'Sorceress', 'start_node_id' => 'syntheticstart1'], ['id' => 'Warrior', 'start_node_id' => 'syntheticstart1']],
            $rows,
        );
    }

    public function testARerunReplacesClassesRatherThanAccumulating(): void
    {
        $this->sync($this->fixture())->run();
        $this->sync($this->fixture())->run();

        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM catalog_class'));
    }

    public function testItReimportsWhenTheRowShapeChangedEvenThoughUpstreamDidNot(): void
    {
        $this->db->executeStatement('DELETE FROM catalog_passive');
        $this->db->executeStatement('DELETE FROM catalog_sync');
        $this->db->executeStatement(
            "INSERT INTO catalog_passive (id, name, kind, pos_x, pos_y, stats, recipe, keystones_in_radius, unlock_constraint) VALUES ('stale', 'Stale', 'small', 0, 0, '[]', '[]', '[]', NULL)"
        );
        // A previous run at an older shape, with upstream sitting at the same revision.
        $this->db->executeStatement(
            "INSERT INTO catalog_sync (source, ran_at, upstream_revision, shape_revision, status, count) VALUES ('passive_tree', NOW(), 'rev-1', 'shape-0', 'ok', 1)"
        );

        $result = $this->syncWithUnchangedUpstream('rev-1');

        self::assertSame('ok', $result->status, 'a changed row shape must force the re-import the revision check would skip');
        self::assertSame(0, $this->rowCount("SELECT COUNT(*) FROM catalog_passive WHERE id = 'stale'"));
    }

    private function rowCount(string $sql): int
    {
        $value = $this->db->fetchOne($sql);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function columnValue(string $sql): string
    {
        $value = $this->db->fetchOne($sql);
        self::assertIsString($value);

        return $value;
    }

    private function fixture(): MockResponse
    {
        return new MockResponse($this->treeJson(), ['response_headers' => ['ETag' => '"synthetic-etag"']]);
    }

    private function treeJson(): string
    {
        $json = file_get_contents(__DIR__.'/../fixtures/catalog/tree-shape.json');
        self::assertIsString($json);

        return $json;
    }

    /**
     * There is no fetcher interface to substitute a test double against —
     * `SourceFetcher` is final and every consumer depends on the concrete
     * class — so "stubbing" it means driving the real one with a
     * `MockHttpClient` the way every other test in this class does. A 304
     * response makes it report `changed: false` while still returning the
     * body cached from an earlier fetch, which is what upstream not having
     * moved actually looks like from `PassiveTreeSync`'s point of view.
     */
    private function syncWithUnchangedUpstream(string $revision): \App\Catalog\SyncResult
    {
        $dir = sys_get_temp_dir().'/catalog-test';
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        file_put_contents($dir.'/passive_tree.json', $this->treeJson());

        return $this->sync(new MockResponse('', ['http_code' => 304, 'response_headers' => ['ETag' => $revision]]))->run();
    }

    private function sync(MockResponse $response): PassiveTreeSync
    {
        $container = self::getContainer();

        return new PassiveTreeSync(
            new SourceFetcher(new MockHttpClient($response), sys_get_temp_dir().'/catalog-test', 'test/0.1 (contact: test@example.test)'),
            new \App\Catalog\PassiveTreeNormalizer(),
            $this->db,
            $container->get(EntityManagerInterface::class),
            '0.5.5',
            'https://example.test/data.json',
        );
    }
}
