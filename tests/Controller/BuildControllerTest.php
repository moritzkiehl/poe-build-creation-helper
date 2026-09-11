<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Build;
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
        preg_match('#/b/([0-9a-zA-Z]{22})#', $location, $m);

        return $m[1];
    }
}
