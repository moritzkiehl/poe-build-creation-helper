<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Build;
use App\Entity\BuildEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BuildEvent>
 */
class BuildEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuildEvent::class);
    }

    /**
     * @return list<BuildEvent>
     */
    public function timeline(Build $build, int $limit = 50): array
    {
        return $this->findBy(['build' => $build], ['id' => 'DESC'], $limit);
    }

    public function findForBuild(Build $build, int $id): ?BuildEvent
    {
        return $this->findOneBy(['id' => $id, 'build' => $build]);
    }

    /**
     * Ordinary events expire after the retention window; named snapshots are
     * what the user asked to keep, so they never expire.
     */
    public function prune(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM '.BuildEvent::class.' e WHERE e.createdAt < :cutoff AND e.isNamedSnapshot = false')
            ->setParameter('cutoff', $cutoff)
            ->execute();
    }
}
