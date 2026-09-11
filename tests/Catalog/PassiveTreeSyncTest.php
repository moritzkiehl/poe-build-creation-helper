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
        $this->db->executeStatement('DELETE FROM catalog_passive_edge');
        $this->db->executeStatement('DELETE FROM catalog_passive');
        $this->db->executeStatement('DELETE FROM catalog_sync');
    }

    public function testASuccessfulRunStoresNodesEdgesAndALogEntry(): void
    {
        $result = $this->sync($this->fixture())->run();

        self::assertTrue($result->ok);
        self::assertSame(4, $this->rowCount('SELECT COUNT(*) FROM catalog_passive'));
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM catalog_passive_edge'));

        $log = self::getContainer()->get(EntityManagerInterface::class)->getRepository(CatalogSync::class)->findOneBy(['source' => 'passive_tree']);
        self::assertNotNull($log);
        self::assertSame('ok', $log->getStatus());
        self::assertSame(4, $log->getCount());
    }

    public function testARerunReplacesRatherThanDuplicates(): void
    {
        $this->sync($this->fixture())->run();
        $this->sync($this->fixture())->run();

        self::assertSame(4, $this->rowCount('SELECT COUNT(*) FROM catalog_passive'));
    }

    public function testAnUnchangedUpstreamIsNotRebuilt(): void
    {
        $this->sync($this->fixture())->run();

        $result = $this->sync(new MockResponse('', ['http_code' => 304]))->run();

        self::assertTrue($result->ok);
        self::assertSame('unchanged', $result->status);
        self::assertSame(4, $this->rowCount('SELECT COUNT(*) FROM catalog_passive'));
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
        self::assertSame(4, $this->rowCount('SELECT COUNT(*) FROM catalog_passive'), 'a failed sync must not empty the catalog');
        self::assertSame('failed', $this->columnValue('SELECT status FROM catalog_sync ORDER BY id DESC LIMIT 1'));
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
            'https://example.test/data.json',
        );
    }
}
