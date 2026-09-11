<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Entity\Build;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BuildPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BuildRepository $builds;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->builds = self::getContainer()->get(BuildRepository::class);
        $this->entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    public function testAStoredBuildComesBackAsTheSameDocument(): void
    {
        $json = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($json);
        $document = new BuildDocumentReader()->read($json);

        $build = $this->builds->create($document, '0.5.5');
        $slug = $build->getShareSlug();
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->builds->findOneByShareSlug($slug);

        self::assertNotNull($reloaded);
        self::assertEquals($document, $reloaded->toDocument());
    }

    public function testTheEditTokenIsHandedOutOnceAndStoredOnlyAsAHash(): void
    {
        $document = new BuildDocumentReader()->read('{"name":"Titan Earthquake Slam"}');

        $build = $this->builds->create($document, '0.5.5');
        $token = $build->getEditToken();

        self::assertNotNull($token, 'a freshly created build hands out its token once');
        self::assertNotSame($token, $build->getEditTokenHash());
        self::assertTrue($this->builds->isEditableWith($build, $token));
        self::assertFalse($this->builds->isEditableWith($build, 'not-the-token'));
    }

    public function testTwoBuildsNeverShareAShareSlug(): void
    {
        $document = new BuildDocumentReader()->read('{"name":"Titan Earthquake Slam"}');

        $first = $this->builds->create($document, '0.5.5');
        $second = $this->builds->create($document, '0.5.5');

        self::assertNotSame($first->getShareSlug(), $second->getShareSlug());
    }

    public function testPlanningFieldsSurvivePersistenceAndStayOutOfTheExport(): void
    {
        $json = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($json);
        $document = new BuildDocumentReader()->read($json);

        $build = $this->builds->create($document, '0.5.5');
        $build->setClassKey('Warrior');
        $build->setTargetLevel(92);
        $build->setNote('Swap to the second weapon set at 60.');
        $build->setArchetypeKey('slam');
        $slug = $build->getShareSlug();
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->builds->findOneByShareSlug($slug);

        self::assertNotNull($reloaded);
        self::assertSame('Warrior', $reloaded->getClassKey());
        self::assertSame(92, $reloaded->getTargetLevel());
        self::assertSame('slam', $reloaded->getArchetypeKey());
        self::assertEquals($document, $reloaded->toDocument(), 'planning fields never reach the exported document');
    }
}
