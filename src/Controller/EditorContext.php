<?php

declare(strict_types=1);

namespace App\Controller;

use App\Build\InventorySlots;
use App\Catalog\CatalogSearch;
use App\Entity\Build;
use App\Entity\CatalogClass;
use App\Repository\BuildEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What every partial of the editor renders from.
 *
 * The page and the Turbo Streams answer with the same partials, so they have
 * to be handed the same variables — a key present in one and missing in the
 * other would show up only as an area that silently empties after an edit.
 */
final class EditorContext
{
    public function __construct(
        private readonly BuildEventRepository $events,
        private readonly InventorySlots $slots,
        private readonly EntityManagerInterface $entityManager,
        private readonly CatalogSearch $search,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(Build $build, string $token, ?string $error = null): array
    {
        $query = trim((string) ($this->requests->getCurrentRequest()?->query->get('q') ?? ''));

        return [
            'build' => $build,
            'token' => $token,
            'document' => $build->toDocument(),
            'classes' => $this->entityManager->getRepository(CatalogClass::class)->findBy([], ['id' => 'ASC']),
            'slots' => $this->slots->all(),
            'events' => $this->events->timeline($build),
            'error' => $error,
            'passiveQuery' => $query,
            'passiveResults' => $this->search->passives($query),
        ];
    }
}
