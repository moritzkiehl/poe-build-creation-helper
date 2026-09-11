<?php

declare(strict_types=1);

namespace App\Repository;

use App\Build\TokenGenerator;
use App\Entity\Build;
use App\Interchange\BuildDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Build>
 */
class BuildRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly TokenGenerator $tokens)
    {
        parent::__construct($registry, Build::class);
    }

    public function create(BuildDocument $document, string $gameVersion): Build
    {
        $token = $this->tokens->editToken();

        $build = new Build(
            document: $document,
            gameVersion: $gameVersion,
            shareSlug: $this->tokens->shareSlug(),
            editTokenHash: $this->tokens->hash($token),
            editToken: $token,
        );

        $this->getEntityManager()->persist($build);

        return $build;
    }

    public function findOneByShareSlug(string $shareSlug): ?Build
    {
        return $this->findOneBy(['shareSlug' => $shareSlug]);
    }

    public function isEditableWith(Build $build, string $editToken): bool
    {
        return $this->tokens->verify($editToken, $build->getEditTokenHash());
    }
}
