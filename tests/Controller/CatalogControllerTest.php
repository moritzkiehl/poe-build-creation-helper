<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->db = self::getContainer()->get(Connection::class);

        foreach (['catalog_gem_tag', 'catalog_gem', 'catalog_unique', 'catalog_sync'] as $table) {
            $this->db->executeStatement('DELETE FROM '.$table);
        }
    }

    public function testAnEmptyCatalogExplainsItselfInsteadOfShowingNothing(): void
    {
        $this->client->request('GET', '/catalog');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No catalog data yet');
    }

    public function testItListsGemsAndSaysWhatDataItRanOn(): void
    {
        $this->seed();

        $this->client->request('GET', '/catalog');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Earthquake');
        self::assertSelectorTextContains('body', '0.5.5');
    }

    public function testSearchingNarrowsTheList(): void
    {
        $this->seed();

        $this->client->request('GET', '/catalog', ['q' => 'earthqu']);

        self::assertSelectorTextContains('body', 'Earthquake');
        self::assertSelectorTextNotContains('body', 'Abiding Hex');
    }

    public function testTheIdentifierIsSearchableToo(): void
    {
        $this->seed();

        $this->client->request('GET', '/catalog', ['q' => 'SupportGemAbidingHex']);

        self::assertSelectorTextContains('body', 'Abiding Hex');
    }

    public function testFilteringByKindKeepsOnlyThatKind(): void
    {
        $this->seed();

        $this->client->request('GET', '/catalog', ['kind' => 'support']);

        self::assertSelectorTextContains('body', 'Abiding Hex');
        self::assertSelectorTextNotContains('body', 'Earthquake');
    }

    public function testUniquesAreBrowsableAndAnAmbiguousNameIsMarked(): void
    {
        $this->seed();

        $this->client->request('GET', '/catalog', ['kind' => 'unique']);

        self::assertSelectorTextContains('body', 'Grand Spectrum');
        self::assertSelectorTextContains('body', 'name shared');
    }

    public function testAQueryThatMatchesNothingSaysSo(): void
    {
        $this->seed();

        $this->client->request('GET', '/catalog', ['q' => 'zzzznothing']);

        self::assertSelectorTextContains('body', 'Nothing matches');
    }

    public function testTheTreePayloadIsEmptyButValidWithoutCatalogData(): void
    {
        $this->client->request('GET', '/catalog/tree.json');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['nodes' => [], 'edges' => [], 'classes' => []], $payload);
    }

    private function seed(): void
    {
        foreach ([
            ['Metadata/Items/Gems/SkillGemEarthquake', 'Earthquake', 'active', 'strength'],
            ['Metadata/Items/Gem/SupportGemAbidingHex', 'Abiding Hex', 'support', 'intelligence'],
        ] as [$id, $name, $kind, $attribute]) {
            $this->db->executeStatement('INSERT INTO catalog_gem (id, name, kind, primary_attribute, icon) VALUES (?, ?, ?, ?, NULL)', [$id, $name, $kind, $attribute]);
        }
        foreach ([['Metadata/Items/Gems/SkillGemEarthquake', 'melee'], ['Metadata/Items/Gem/SupportGemAbidingHex', 'curse']] as [$gem, $tag]) {
            $this->db->executeStatement('INSERT INTO catalog_gem_tag (gem_id, tag) VALUES (?, ?)', [$gem, $tag]);
        }
        foreach ([['1', 'Astramentis', 'Amulet'], ['2', 'Grand Spectrum', 'Jewel'], ['3', 'Grand Spectrum', 'Jewel']] as [$id, $name, $class]) {
            $this->db->executeStatement('INSERT INTO catalog_unique (id, name, item_class, inventory_width, inventory_height, icon) VALUES (?, ?, ?, 1, 1, NULL)', [$id, $name, $class]);
        }
        $this->db->executeStatement("INSERT INTO catalog_sync (source, ran_at, upstream_revision, game_version, status, count, error) VALUES ('items', ?, NULL, '0.5.5', 'ok', 3, NULL)", ['2026-09-11 12:00:00']);
    }
}
