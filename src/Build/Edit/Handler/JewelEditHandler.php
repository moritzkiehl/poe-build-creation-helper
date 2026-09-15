<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildEditor;
use App\Build\Edit\Command\SetJewelKeystone;
use App\Build\Edit\InvalidEditCommand;
use App\Build\Tree\PassiveGraph;
use App\Entity\Build;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The keystone a declared keystone-radius jewel works around — rule 4 of the
 * tree's legality. The format has no jewels, so this is app-only.
 */
final class JewelEditHandler
{
    public function __construct(
        private readonly BuildEditor $builds,
        private readonly PassiveGraph $graph,
    ) {
    }

    #[AsMessageHandler]
    public function set(SetJewelKeystone $command): void
    {
        $keystone = $command->keystoneId;

        if (null !== $keystone && !$this->graph->isKeystone($keystone)) {
            throw InvalidEditCommand::noSuchEntry('keystone "'.$keystone.'"');
        }

        // Clearing or changing the jewel keeps the passives it made legal
        // (owner, 2026-09-13) — the same as a class or ascendancy change.
        $this->builds->apply($command->buildId, 'jewel.set', ['keystone_id' => $keystone], static function (Build $build) use ($keystone): void {
            $build->setJewelKeystone($keystone);
        });
    }
}
