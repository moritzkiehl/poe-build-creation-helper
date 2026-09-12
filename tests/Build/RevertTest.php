<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\Command\AllocatePassive;
use App\Build\Edit\Command\CreateSnapshot;
use App\Build\Edit\Command\Revert;
use App\Entity\Build;
use App\Entity\BuildEvent;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildEventRepository;
use App\Repository\BuildRepository;
use App\Tests\Support\SeedsALegalPassiveTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RevertTest extends KernelTestCase
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

    public function testRevertingPutsTheBuildBackAndKeepsEveryEventThatFollowed(): void
    {
        $build = $this->buildWithLegalTree($this->build(), $this->entityManager, ['first', 'second']);
        $id = (int) $build->getId();

        $this->bus->dispatch(new AllocatePassive($id, 'first'));
        $afterFirst = $this->events->timeline($this->reload($build))[0];
        $this->bus->dispatch(new AllocatePassive($id, 'second'));

        $this->bus->dispatch(new Revert($id, (int) $afterFirst->getId()));
        $this->entityManager->clear();

        $reverted = $this->reload($build);
        $ids = array_column($reverted->toDocument()->passives, 'id');

        self::assertContains('first', $ids);
        self::assertNotContains('second', $ids);
        self::assertSame(
            ['revert', 'passive.allocate', 'passive.allocate'],
            array_map(static fn (BuildEvent $e): string => $e->getAction(), $this->events->timeline($reverted)),
            'reverting appends; nothing is deleted',
        );
    }

    public function testRevertingTwiceWalksForwardAgain(): void
    {
        $build = $this->buildWithLegalTree($this->build(), $this->entityManager, ['first', 'second']);
        $id = (int) $build->getId();

        $this->bus->dispatch(new AllocatePassive($id, 'first'));
        $afterFirst = $this->events->timeline($this->reload($build))[0];
        $this->bus->dispatch(new AllocatePassive($id, 'second'));
        $afterSecond = $this->events->timeline($this->reload($build))[0];

        $this->bus->dispatch(new Revert($id, (int) $afterFirst->getId()));
        $this->bus->dispatch(new Revert($id, (int) $afterSecond->getId()));
        $this->entityManager->clear();

        self::assertContains('second', array_column($this->reload($build)->toDocument()->passives, 'id'));
    }

    public function testANamedSnapshotRecordsTheStateWithoutChangingIt(): void
    {
        $build = $this->build();
        $id = (int) $build->getId();
        $before = $build->toDocument();

        $this->bus->dispatch(new CreateSnapshot($id, 'Before the respec'));
        $this->entityManager->clear();

        $reloaded = $this->reload($build);
        $event = $this->events->timeline($reloaded)[0];

        self::assertTrue($event->isNamedSnapshot());
        self::assertSame('Before the respec', $event->getSnapshotName());
        self::assertEquals($before, $reloaded->toDocument());
    }

    public function testAnEventBelongingToAnotherBuildCannotBeRevertedTo(): void
    {
        $mine = $this->build();
        $theirs = $this->buildWithLegalTree($this->build(), $this->entityManager, ['theirs']);
        $this->bus->dispatch(new AllocatePassive((int) $theirs->getId(), 'theirs'));
        $theirEvent = $this->events->timeline($this->reload($theirs))[0];

        $this->expectException(\Throwable::class);

        $this->bus->dispatch(new Revert((int) $mine->getId(), (int) $theirEvent->getId()));
    }

    private function reload(Build $build): Build
    {
        $reloaded = $this->builds->findOneByShareSlug($build->getShareSlug());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function build(): Build
    {
        $json = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($json);

        $build = $this->builds->create(new BuildDocumentReader()->read($json), '0.5.5');
        $this->entityManager->flush();

        return $build;
    }
}
