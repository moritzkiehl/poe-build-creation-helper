<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Entity\Build;
use App\Interchange\BuildDocument;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What every edit does regardless of what it changes: find the build, change
 * it, leave an event behind, flush. Handlers supply only the change.
 */
final class BuildEditor
{
    public function __construct(
        private readonly BuildRepository $builds,
        private readonly EditHistory $history,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, scalar|list<string>|null> $payload
     * @param callable(Build): void                    $mutate
     */
    public function apply(int $buildId, string $action, array $payload, callable $mutate): void
    {
        $build = $this->find($buildId);

        $mutate($build);

        $this->history->record($build, $action, $payload);
        $this->entityManager->flush();
    }

    public function find(int $buildId): Build
    {
        return $this->builds->find($buildId) ?? throw InvalidEditCommand::noSuchEntry('build');
    }

    /**
     * @param callable(BuildDocument): BuildDocument $change
     */
    public function document(Build $build, callable $change): void
    {
        $build->applyDocument($change($build->toDocument()));
    }
}
