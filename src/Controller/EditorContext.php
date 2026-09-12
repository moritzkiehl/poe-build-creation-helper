<?php

declare(strict_types=1);

namespace App\Controller;

use App\Build\InventorySlots;
use App\Catalog\CatalogSearch;
use App\Entity\Build;
use App\Entity\CatalogClass;
use App\Repository\BuildEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
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
        $request = $this->requests->getCurrentRequest();
        $query = $this->term($request, 'q');
        $gemQuery = $this->term($request, 'gem');

        return [
            'build' => $build,
            'token' => $token,
            'document' => $build->toDocument(),
            'classes' => $this->entityManager->getRepository(CatalogClass::class)->findBy([], ['id' => 'ASC']),
            'slots' => $this->slots->all(),
            'events' => $this->events->timeline($build),
            'error' => $error,
            'passiveQuery' => $query,
            'passiveResults' => '' !== $query ? $this->search->passives($query) : [],
            'gemQuery' => $gemQuery,
            'gemResults' => '' !== $gemQuery ? $this->search->search($gemQuery, 'all') : [],
        ];
    }

    /**
     * A search term normally arrives in the query string — the search forms
     * submit via GET. An edit is a POST carrying neither, so the redirect and
     * the Turbo response that follow it fall back to the request body, which
     * the `/act` forms carry the term in as a hidden field. The query string
     * wins when present, even empty, so clearing the search box still clears
     * the term instead of resurrecting it from a stale hidden field.
     */
    private function term(?Request $request, string $key): string
    {
        if (null === $request) {
            return '';
        }

        $value = $request->query->get($key);

        if (!\is_string($value)) {
            $value = $request->request->get($key);
        }

        return trim(\is_string($value) ? $value : '');
    }
}
