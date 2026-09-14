<?php

declare(strict_types=1);

namespace App\Build;

use App\Entity\Build;
use App\Entity\CatalogClass;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Works out a build's class from its ascendancy.
 *
 * A `.build` file never carries a class — the class is app-only — but every
 * ascendancy belongs to exactly one class (measured 2026-09-13: 23 ascendancy
 * ids across 12 classes, none shared). Without a class a build has no start
 * node, and the passive tree refuses every allocation.
 *
 * The class is filled in on import, and looked up again at runtime for a build
 * that has none stored — one imported before the catalog knew its ascendancy.
 * A class the player chose always outranks the file.
 */
final class ClassFromAscendancy
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function fillIn(Build $build): void
    {
        if (null !== $build->getClassKey()) {
            return;
        }

        $classKey = $this->classFor($build->getAscendancyKey());

        if (null !== $classKey) {
            $build->setClassKey($classKey);
        }
    }

    /**
     * The class an ascendancy belongs to, or null when there is no ascendancy
     * or the catalog does not know it.
     */
    public function classFor(?string $ascendancy): ?string
    {
        if (null === $ascendancy) {
            return null;
        }

        foreach ($this->entityManager->getRepository(CatalogClass::class)->findAll() as $class) {
            foreach ($class->getAscendancies() as $candidate) {
                if ($candidate['id'] === $ascendancy) {
                    return $class->getId();
                }
            }
        }

        return null;
    }
}
