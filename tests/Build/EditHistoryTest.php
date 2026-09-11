<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\BuildSnapshot;
use App\Build\Edit\EditHistory;
use App\Entity\Build;
use App\Entity\BuildEvent;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildEventRepository;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EditHistoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BuildRepository $builds;
    private BuildEventRepository $events;
    private EditHistory $history;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->builds = self::getContainer()->get(BuildRepository::class);
        $this->events = self::getContainer()->get(BuildEventRepository::class);
        $this->history = self::getContainer()->get(EditHistory::class);
        $this->entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $this->entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    public function testAnEventKeepsTheWholeEditableStateAtThatMoment(): void
    {
        $build = $this->build();
        $build->setNote('before');

        $this->history->record($build, 'header.set', ['field' => 'note']);
        $build->setNote('after');
        $this->entityManager->flush();

        $timeline = $this->events->timeline($build);

        self::assertCount(1, $timeline);
        self::assertSame('header.set', $timeline[0]->getAction());
        self::assertSame('before', $timeline[0]->getSnapshot()['header']['note'], 'the snapshot is the state at record time');
        self::assertSame('Titan Earthquake Slam', $timeline[0]->getSnapshot()['document']['name']);
    }

    public function testRestoringASnapshotPutsBothDocumentAndPlanningFieldsBack(): void
    {
        $build = $this->build();
        $build->setTargetLevel(90);
        $snapshot = BuildSnapshot::capture($build);

        $build->setTargetLevel(12);
        $build->setName('Renamed');
        BuildSnapshot::restore($build, $snapshot);

        self::assertSame(90, $build->getTargetLevel());
        self::assertSame('Titan Earthquake Slam', $build->getName());
    }

    public function testTheTimelineIsNewestFirst(): void
    {
        $build = $this->build();
        $this->history->record($build, 'passive.allocate', ['id' => 'strength89']);
        $this->history->record($build, 'passive.deallocate', ['id' => 'strength89']);
        $this->entityManager->flush();

        self::assertSame(
            ['passive.deallocate', 'passive.allocate'],
            array_map(static fn (BuildEvent $e): string => $e->getAction(), $this->events->timeline($build)),
        );
    }

    public function testPruningKeepsNamedSnapshotsAndDropsOrdinaryEventsPastTheCutoff(): void
    {
        $build = $this->build();
        $old = $this->history->record($build, 'passive.allocate', ['id' => 'strength89']);
        $kept = $this->history->record($build, 'snapshot', [], 'Before the respec');
        $this->entityManager->flush();

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE build_event SET created_at = ? WHERE id IN (?, ?)',
            ['2020-01-01 00:00:00', $old->getId(), $kept->getId()],
        );
        $this->entityManager->clear();

        $pruned = $this->events->prune(new \DateTimeImmutable('2026-01-01'));

        self::assertSame(1, $pruned);
        $remaining = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM build_event');
        self::assertSame(1, is_numeric($remaining) ? (int) $remaining : 0);
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
