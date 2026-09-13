<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Build;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BuildControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    protected function tearDown(): void
    {
        // A leftover class owning "Warrior2" would hand every later test's
        // imported build a class it never asked for.
        $this->db()->executeStatement('DELETE FROM catalog_class WHERE id IN (?, ?)', ['test_import_class', 'test_decoy_class']);
        parent::tearDown();
    }

    public function testUploadingABuildFileCreatesAShareableBuild(): void
    {
        $this->client->request('POST', '/builds', files: ['build' => $this->fixtureUpload()]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Titan Earthquake Slam');
    }

    public function testPastedJsonCreatesABuildToo(): void
    {
        $this->client->request('POST', '/builds', ['json' => '{"name":"Pasted Build"}']);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Pasted Build');
    }

    public function testABrokenFileIsRejectedWithAReadableReason(): void
    {
        $this->client->request('POST', '/builds', ['json' => '{"name": ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'not valid JSON');
    }

    public function testTheSharedPageIsReadOnly(): void
    {
        $slug = $this->createBuild();

        $this->client->request('GET', '/b/'.$slug);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Titan Earthquake Slam');
        self::assertSelectorNotExists('form[action$="/update"]');
    }

    public function testExportHandsBackTheDocumentUnchanged(): void
    {
        $slug = $this->createBuild();

        $this->client->request('GET', '/b/'.$slug.'/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $original = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($original);
        self::assertSame(
            json_decode($original, true),
            json_decode((string) $this->client->getResponse()->getContent(), true),
        );
    }

    public function testTheEditPageOpensWithTheRightToken(): void
    {
        $this->client->request('POST', '/builds', files: ['build' => $this->fixtureUpload()]);
        $editUrl = $this->client->getResponse()->headers->get('Location');
        self::assertIsString($editUrl);

        $this->client->request('GET', $editUrl);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/update"]');
    }

    public function testTheEditTokenPermitsReplacingTheStoredDocument(): void
    {
        $this->client->request('POST', '/builds', files: ['build' => $this->fixtureUpload()]);
        $editUrl = (string) $this->client->getResponse()->headers->get('Location');

        $this->client->request('POST', $editUrl.'/update', ['json' => '{"name":"Replaced Build"}']);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Replaced Build');
    }

    public function testReplacingWithoutTheEditTokenIsRefused(): void
    {
        $slug = $this->createBuild();

        $this->client->request('POST', '/b/'.$slug.'/edit/'.str_repeat('0', 64).'/update', ['json' => '{"name":"Hijacked"}']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAWrongEditTokenIsRefused(): void
    {
        $slug = $this->createBuild();

        $this->client->request('GET', '/b/'.$slug.'/edit/'.str_repeat('0', 64));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownSlugIsNotFound(): void
    {
        $this->client->request('GET', '/b/aaaaaaaaaaaaaaaaaaaaaa');

        self::assertResponseStatusCodeSame(404);
    }

    public function testImportingABuildTakesItsClassFromItsAscendancy(): void
    {
        $this->seedClassOwningTheFixtureAscendancy('test_import_class');

        $slug = $this->createBuild();

        // valid-full.build carries ascendancy "Warrior2" and, like every file
        // the game writes, no class — class is app-only. Without one there is
        // no start node, so nothing on the tree could be allocated.
        self::assertSame('test_import_class', $this->classKeyOf($slug));
    }

    public function testAnAscendancyTheCatalogDoesNotKnowLeavesTheClassUnset(): void
    {
        $this->forgetEveryClassOwningTheFixtureAscendancy();
        // A class that owns a different ascendancy, so that falling back to
        // "some class" would put a value here instead of leaving it empty.
        $this->seedClass('test_decoy_class', 'Decoy1');

        $slug = $this->createBuild();

        self::assertNull($this->classKeyOf($slug), 'an ascendancy the catalog does not know must not fall back to another class');
    }

    public function testReplacingTheStoredFileFillsInAMissingClass(): void
    {
        $this->forgetEveryClassOwningTheFixtureAscendancy();
        [$slug, $editUrl] = $this->createBuildAndEditUrl();
        self::assertNull($this->classKeyOf($slug), 'precondition: the catalog did not know the ascendancy at import');

        $this->seedClassOwningTheFixtureAscendancy('test_import_class');
        $this->client->request('POST', $editUrl.'/update', ['json' => $this->fixtureJson()]);

        self::assertResponseRedirects();
        self::assertSame('test_import_class', $this->classKeyOf($slug));
    }

    public function testReplacingTheStoredFileNeverOverridesAChosenClass(): void
    {
        $this->seedClassOwningTheFixtureAscendancy('test_import_class');
        [$slug, $editUrl] = $this->createBuildAndEditUrl();
        $this->db()->executeStatement('UPDATE build SET class_key = ? WHERE share_slug = ?', ['test_chosen_class', $slug]);

        $this->client->request('POST', $editUrl.'/update', ['json' => $this->fixtureJson()]);

        self::assertResponseRedirects();
        self::assertSame('test_chosen_class', $this->classKeyOf($slug), 'a class the player chose outranks one derived from the file');
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function seedClass(string $classId, string $ascendancyId): void
    {
        $this->db()->executeStatement('DELETE FROM catalog_class WHERE id = ?', [$classId]);
        $this->db()->executeStatement(
            'INSERT INTO catalog_class (id, start_node_id, base_str, base_dex, base_int, ascendancies) VALUES (?, ?, 0, 0, 0, ?)',
            [$classId, $classId.'_start', json_encode([['id' => $ascendancyId, 'name' => $ascendancyId]], \JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * Leaves exactly one class owning the fixture's "Warrior2", so the
     * derived class is unambiguous whatever earlier tests left behind.
     */
    private function seedClassOwningTheFixtureAscendancy(string $classId): void
    {
        $this->forgetEveryClassOwningTheFixtureAscendancy();
        $this->seedClass($classId, 'Warrior2');
    }

    private function forgetEveryClassOwningTheFixtureAscendancy(): void
    {
        $this->db()->executeStatement('DELETE FROM catalog_class WHERE ascendancies LIKE ?', ['%"Warrior2"%']);
    }

    private function classKeyOf(string $slug): ?string
    {
        $value = $this->db()->fetchOne('SELECT class_key FROM build WHERE share_slug = ?', [$slug]);

        return \is_string($value) ? $value : null;
    }

    /**
     * @return array{0: string, 1: string} the share slug and the edit URL
     */
    private function createBuildAndEditUrl(): array
    {
        $this->client->request('POST', '/builds', files: ['build' => $this->fixtureUpload()]);
        $editUrl = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame(1, preg_match('#/b/([0-9a-zA-Z]{22})/edit/#', $editUrl, $m), 'creating a build redirects to its edit link');

        return [$m[1], $editUrl];
    }

    private function fixtureJson(): string
    {
        return (string) file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
    }

    private function fixtureUpload(): UploadedFile
    {
        $source = __DIR__.'/../fixtures/build/valid-full.build';
        $copy = sys_get_temp_dir().'/'.uniqid('upload', true).'.build';
        copy($source, $copy);

        return new UploadedFile($copy, 'valid-full.build', 'application/json', test: true);
    }

    private function createBuild(): string
    {
        $this->client->request('POST', '/builds', files: ['build' => $this->fixtureUpload()]);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame(1, preg_match('#/b/([0-9a-zA-Z]{22})#', $location, $m), 'creating a build redirects to its edit link');

        return $m[1];
    }
}
