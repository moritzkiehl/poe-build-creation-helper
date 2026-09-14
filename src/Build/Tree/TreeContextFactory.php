<?php

declare(strict_types=1);

namespace App\Build\Tree;

use App\Build\ClassFromAscendancy;
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
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClassFromAscendancy $classes,
    ) {
    }

    public function of(Build $build): TreeContext
    {
        $classKey = $this->classKeyOf($build);
        $class = null === $classKey ? null : $this->entityManager->getRepository(CatalogClass::class)->find($classKey);

        return new TreeContext($class?->getStartNodeId(), $build->getAscendancyKey());
    }

    /**
     * The class this build's tree grows from: the one the player chose, or else
     * the one its ascendancy belongs to. A build imported before the catalog
     * knew its ascendancy has no stored class; working it out here, on read,
     * makes its tree usable without writing anything during a page view.
     */
    public function classKeyOf(Build $build): ?string
    {
        return $build->getClassKey() ?? $this->classes->classFor($build->getAscendancyKey());
    }
}
