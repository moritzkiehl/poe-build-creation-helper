<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Build;
use App\Entity\BuildEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BuildEditorControllerTest extends WebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    public function testAPlainFormPostChangesTheBuildAndRedirectsBack(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'Respec at 60']);

        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorExists('input[value="Respec at 60"]');
    }

    public function testATurboRequestGetsStreamsInsteadOfARedirect(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'From Turbo'], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        $body = (string) $this->client->getResponse()->getContent();
        foreach (['build-header', 'build-nodes', 'build-history', 'build-state', 'build-error'] as $target) {
            self::assertStringContainsString('target="'.$target.'"', $body);
        }
    }

    public function testARefusedEditAnswers422AndSaysWhyRatherThanFailing(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'target_level', 'value' => '900'], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('target level', (string) $this->client->getResponse()->getContent());
    }

    public function testEditingWithoutTheEditTokenIsRefused(): void
    {
        $slug = $this->slugOf($this->createBuild());

        $this->client->request('POST', '/b/'.$slug.'/edit/'.str_repeat('0', 64).'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'Hijacked']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAPassiveCanBeAllocatedAndRemovedWithoutJavascript(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'melee99_']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-nodes', 'melee99_');

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.deallocate', 'id' => 'melee99_']);
        $this->client->followRedirect();

        self::assertSelectorTextNotContains('#build-nodes', 'melee99_');
    }

    public function testTheEditorStillOffersTheWholeFileReplacement(): void
    {
        $this->client->request('GET', $this->createBuild());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/update"]');
        self::assertSelectorExists('#build-findings');
    }

    /**
     * @return string the edit URL, without a trailing slash
     */
    private function createBuild(): string
    {
        $source = __DIR__.'/../fixtures/build/valid-full.build';
        $copy = sys_get_temp_dir().'/'.uniqid('upload', true).'.build';
        copy($source, $copy);

        $this->client->request('POST', '/builds', files: ['build' => new UploadedFile($copy, 'valid-full.build', 'application/json', test: true)]);

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    private function slugOf(string $editUrl): string
    {
        self::assertSame(1, preg_match('#/b/([0-9a-zA-Z]{22})#', $editUrl, $m));

        return $m[1];
    }
}
