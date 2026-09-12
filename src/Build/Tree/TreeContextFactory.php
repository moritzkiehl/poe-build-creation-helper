<?php

declare(strict_types=1);

namespace App\Build\Tree;

use App\Entity\Build;
use App\Entity\CatalogClass;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the `TreeContext` a build's own legality checks run against.
 *
 * The lookup — a build's class key to that class's start node — is needed by
 * every handler that touches allocation, so it lives here once rather than as
 * a private method repeated in each of them.
 */
final class TreeContextFactory
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function of(Build $build): TreeContext
    {
        $classKey = $build->getClassKey();
        $class = null === $classKey ? null : $this->entityManager->getRepository(CatalogClass::class)->find($classKey);

        return new TreeContext($class?->getStartNodeId(), $build->getAscendancyKey());
    }
}
