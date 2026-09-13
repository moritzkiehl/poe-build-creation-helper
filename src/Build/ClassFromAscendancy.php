<?php

declare(strict_types=1);

namespace App\Build;

use App\Entity\Build;
use App\Entity\CatalogClass;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fills in a build's class from its ascendancy.
 *
 * A `.build` file never carries a class — the class is app-only — but every
 * ascendancy belongs to exactly one class (measured 2026-09-13: 23 ascendancy
 * ids across 12 classes, none shared). Without a class a build has no start
 * node, and the passive tree refuses every allocation, so an imported build
 * would otherwise open with a tree nothing can be taken on.
 *
 * A class already set is left alone: the player's choice outranks the file.
 */
final class ClassFromAscendancy
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function fillIn(Build $build): void
    {
        $ascendancy = $build->getAscendancyKey();

        if (null !== $build->getClassKey() || null === $ascendancy) {
            return;
        }

        foreach ($this->entityManager->getRepository(CatalogClass::class)->findAll() as $class) {
            foreach ($class->getAscendancies() as $candidate) {
                if ($candidate['id'] === $ascendancy) {
                    $build->setClassKey($class->getId());

                    return;
                }
            }
        }
    }
}
