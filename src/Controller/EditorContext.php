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

        $searches = $this->searches->all();

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
            // An id the catalog no longer resolves — the build outlived a
            // catalog re-sync — stays visible under its bare id rather than
            // silently vanishing, and keeps a working Remove control: a
            // player must be able to get rid of an entry they cannot read.
            'instilledNodes' => array_map(
                static fn (string $id): array => $detail[$id] ?? ['id' => $id, 'name' => $id, 'kind' => null, 'stats' => [], 'recipe' => []],
                $instilledIds,
            ),
            // Which mode a build opens in is derived from its document and
            // never stored — a stored mode could disagree with the document
            // it claims to describe. The `intervals`/`supports` query
            // parameters let a player override that derived mode for the
            // current view only; they are never written back, so toggling
            // never recreates the disagreement the derivation exists to
            // prevent.
            'passivesUniform' => match ($searches['intervalsOverride']) {
                'per-passive' => false,
                'flat' => true,
                default => IntervalMode::passivesAreUniform($document),
            },
            'passiveSpan' => IntervalMode::passiveSpan($document),
            'supportsFollow' => match ($searches['supportsOverride']) {
                'per-passive' => false,
                'flat' => true,
                default => IntervalMode::supportsFollowTheirSkills($document),
            },
        ], $searches);
    }
}
