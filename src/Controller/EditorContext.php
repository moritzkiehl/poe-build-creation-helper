<?php

declare(strict_types=1);

namespace App\Controller;

use App\Build\Edit\IntervalMode;
use App\Build\InventorySlots;
use App\Build\StatSummary;
use App\Catalog\CatalogSearch;
use App\Entity\Build;
use App\Entity\CatalogClass;
use App\Repository\BuildEventRepository;
use Doctrine\ORM\EntityManagerInterface;

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
        private readonly EditorSearches $searches,
        private readonly CatalogSearch $catalog,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(Build $build, string $token, ?string $error = null): array
    {
        $document = $build->toDocument();
        $allocatedIds = [];
        foreach ($document->passives as $passive) {
            if (\is_string($passive['id'] ?? null)) {
                $allocatedIds[] = $passive['id'];
            }
        }

        $instilledIds = $build->getInstilledPassives();
        $detail = array_column($this->catalog->passivesByIds(array_values(array_unique([...$allocatedIds, ...$instilledIds]))), null, 'id');

        $statsById = [];
        foreach ($allocatedIds as $id) {
            $statsById[$id] = $detail[$id]['stats'] ?? [];
        }

        $allocatedByFamily = [];
        foreach ($document->passives as $passive) {
            if (\is_string($passive['id'] ?? null)) {
                $allocatedByFamily[StatSummary::family($passive['id'])][] = $passive;
            }
        }

        return array_merge([
            'build' => $build,
            'token' => $token,
            'document' => $document,
            'classes' => $this->entityManager->getRepository(CatalogClass::class)->findBy([], ['id' => 'ASC']),
            'slots' => $this->slots->all(),
            'events' => $this->events->timeline($build),
            'error' => $error,
            'treeSummary' => StatSummary::of($statsById),
            'allocatedByFamily' => $allocatedByFamily,
            'instilledNodes' => array_values(array_filter(array_map(static fn (string $id): ?array => $detail[$id] ?? null, $instilledIds))),
            'passivesUniform' => IntervalMode::passivesAreUniform($document),
            'passiveSpan' => IntervalMode::passiveSpan($document),
            'supportsFollow' => IntervalMode::supportsFollowTheirSkills($document),
        ], $this->searches->all());
    }
}
