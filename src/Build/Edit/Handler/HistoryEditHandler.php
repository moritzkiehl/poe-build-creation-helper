<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildSnapshot;
use App\Build\Edit\Command\CreateSnapshot;
use App\Build\Edit\Command\Revert;
use App\Build\Edit\EditHistory;
use App\Build\Edit\InvalidEditCommand;
use App\Repository\BuildEventRepository;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The two edits that act on the history itself.
 *
 * Neither goes through BuildEditor: a snapshot changes nothing, and a revert
 * needs the event it is reverting to before it can change anything.
 */
final class HistoryEditHandler
{
    public function __construct(
        private readonly BuildRepository $builds,
        private readonly BuildEventRepository $events,
        private readonly EditHistory $history,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[AsMessageHandler]
    public function snapshot(CreateSnapshot $command): void
    {
        $build = $this->builds->find($command->buildId) ?? throw InvalidEditCommand::noSuchEntry('build');
        $name = trim($command->name);

        if ('' === $name) {
            throw InvalidEditCommand::noSuchEntry('name for this snapshot');
        }

        $this->history->record($build, 'snapshot', ['name' => $name], $name);
        $this->entityManager->flush();
    }

    #[AsMessageHandler]
    public function revert(Revert $command): void
    {
        $build = $this->builds->find($command->buildId) ?? throw InvalidEditCommand::noSuchEntry('build');
        $event = $this->events->findForBuild($build, $command->eventId) ?? throw InvalidEditCommand::noSuchEntry('history entry');

        BuildSnapshot::restore($build, $event->getSnapshot());

        $this->history->record($build, 'revert', ['event_id' => $command->eventId]);
        $this->entityManager->flush();
    }
}
