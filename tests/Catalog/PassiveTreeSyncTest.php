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
        $json = file_get_contents(__DIR__.'/../fixtures/catalog/tree-shape.json');
        self::assertIsString($json);

        return new MockResponse($json, ['response_headers' => ['ETag' => '"synthetic-etag"']]);
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
