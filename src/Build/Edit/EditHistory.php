<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Entity\Build;
use App\Entity\BuildEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Appends one event per edit. Never updates or deletes: pruning is a separate,
 * time-based decision, and reverting appends rather than rewrites.
 */
final class EditHistory
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public function record(Build $build, string $action, array $payload, ?string $snapshotName = null): BuildEvent
    {
        $event = new BuildEvent($build, $action, $payload, BuildSnapshot::capture($build), $snapshotName);
        $this->entityManager->persist($event);

        return $event;
    }
}
