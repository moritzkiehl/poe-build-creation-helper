<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\Command\AllocatePassive;
use App\Build\Edit\Command\SetHeaderField;
use App\Entity\Build;
use App\Entity\BuildEvent;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildEventRepository;
use App\Repository\BuildRepository;
use App\Tests\Support\SeedsALegalPassiveTree;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class BuildEditorTest extends KernelTestCase
{
    use SeedsALegalPassiveTree;

    private EntityManagerInterface $entityManager;
    private BuildRepository $builds;
    private BuildEventRepository $events;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->builds = self::getContainer()->get(BuildRepository::class);
        $this->events = self::getContainer()->get(BuildEventRepository::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $this->entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    public function testAllocatingAPassiveChangesTheDocumentAndLeavesAnEvent(): void
    {
        // 'strength89' would not do here any more: it is already allocated by
        // the fixture, and Task 8 refuses re-allocating an already-allocated
        // id. This id is connected to the seeded class's start node and is
        // absent from valid-full.build, so allocating it is a genuine change.
        $build = $this->buildWithLegalTree(['test_target_node']);

        $this->bus->dispatch(new AllocatePassive((int) $build->getId(), 'test_target_node'));
        $this->entityManager->clear();

        $reloaded = $this->builds->findOneByShareSlug($build->getShareSlug());
        self::assertNotNull($reloaded);
        self::assertContains('test_target_node', array_column($reloaded->toDocument()->passives, 'id'));

        $timeline = $this->events->timeline($reloaded);
        self::assertSame('passive.allocate', $timeline[0]->getAction());
        self::assertSame('test_target_node', $timeline[0]->getPayload()['id']);
    }

    public function testAHeaderFieldFromTheFormatReachesTheExportedDocument(): void
    {
        $build = $this->build();

        $this->bus->dispatch(new SetHeaderField((int) $build->getId(), 'ascendancy', 'Sorceress3'));
        $this->entityManager->clear();

        $reloaded = $this->builds->findOneByShareSlug($build->getShareSlug());
        self::assertNotNull($reloaded);
        self::assertSame('Sorceress3', $reloaded->toDocument()->ascendancy);
    }

    public function testAPlanningFieldStaysOutOfTheExportedDocument(): void
    {
        $build = $this->build();
        $before = $build->toDocument();

        $this->bus->dispatch(new SetHeaderField((int) $build->getId(), 'target_level', '92'));
        $this->entityManager->clear();

        $reloaded = $this->builds->findOneByShareSlug($build->getShareSlug());
        self::assertNotNull($reloaded);
        self::assertSame(92, $reloaded->getTargetLevel());
        self::assertEquals($before, $reloaded->toDocument());
    }

    public function testAnUnknownHeaderFieldIsRefused(): void
    {
        $build = $this->build();

        $this->expectException(\Throwable::class);

        $this->bus->dispatch(new SetHeaderField((int) $build->getId(), 'secret_flag', 'yes'));
    }

    private function build(): Build
    {
        $json = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($json);

        $build = $this->builds->create(new BuildDocumentReader()->read($json), '0.5.5');
        $this->entityManager->flush();

        return $build;
    }

    /**
     * A build assigned to a class whose start node reaches every given id
     * directly, so allocating them is legal. Only a test that dispatches
     * `AllocatePassive` needs this over the plain `build()` above.
     *
     * @param list<string> $legalPassiveIds
     */
    private function buildWithLegalTree(array $legalPassiveIds): Build
    {
        $build = $this->build();
        $db = self::getContainer()->get(Connection::class);
        $classId = $this->seedALegalPassiveTree($db, $legalPassiveIds);

        $build->setClassKey($classId);
        $this->entityManager->flush();

        return $build;
    }
}
