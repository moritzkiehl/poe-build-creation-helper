<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildEditor;
use App\Build\Edit\Command\AddInstilled;
use App\Build\Edit\Command\RemoveInstilled;
use App\Entity\Build;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An Instilled Modifier is granted by the amulet, not allocated on the tree, so
 * these edits touch only the build's own record and never its document.
 */
final class InstilledEditHandler
{
    public function __construct(private readonly BuildEditor $builds)
    {
    }

    #[AsMessageHandler]
    public function add(AddInstilled $command): void
    {
        $this->builds->apply($command->buildId, 'instilled.add', ['id' => $command->id], static function (Build $build) use ($command): void {
            $build->addInstilledPassive($command->id);
        });
    }

    #[AsMessageHandler]
    public function remove(RemoveInstilled $command): void
    {
        $this->builds->apply($command->buildId, 'instilled.remove', ['id' => $command->id], static function (Build $build) use ($command): void {
            $build->removeInstilledPassive($command->id);
        });
    }
}
