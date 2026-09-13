<?php

declare(strict_types=1);

namespace App\Controller;

use App\Build\Edit\IntervalMode;
use App\Build\InventorySlots;
use App\Build\StatSummary;
use App\Build\Tree\Allocation;
use App\Build\Tree\TreeContextFactory;
use App\Build\Tree\WeaponSet;
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
        private readonly TreeContextFactory $treeContexts,
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

        $allocation = Allocation::of($document);
        $allocationBySet = [
            'shared' => [],
            '1' => [],
            '2' => [],
        ];

        foreach ($allocation->ids() as $id) {
            $allocationBySet[match ($allocation->setOf($id)) {
                WeaponSet::One => '1',
                WeaponSet::Two => '2',
                default => 'shared',
            }][] = $id;
        }

        $instilledIds = $build->getInstilledPassives();
        $detail = array_column($this->catalog->passivesByIds(array_values(array_unique([...$allocatedIds, ...$instilledIds]))), null, 'id');

        $searches = $this->searches->all();

        // The stats overview answers "what does this build give while set N
        // is equipped" — never a set's nodes in isolation — so it sums the
        // shared nodes plus the chosen set, exactly what idsVisibleTo() already
        // groups. `statsOverride` is the raw, possibly-empty query term (see
        // `passivesUniform` below for why); `statsView` is the resolved label
        // the templates compare against and the one control that isn't shown
        // for the current view links away from.
        $view = match ($searches['statsOverride']) {
            '1' => WeaponSet::One,
            '2' => WeaponSet::Two,
            default => WeaponSet::Shared,
        };
        $statsView = match ($view) {
            WeaponSet::One => '1',
            WeaponSet::Two => '2',
            WeaponSet::Shared => 'shared',
        };

        $statsById = [];
        foreach ($allocation->idsVisibleTo($view) as $id) {
            $statsById[$id] = $detail[$id]['stats'] ?? [];
        }

        $allocatedByFamily = [];
        foreach ($document->passives as $passive) {
            if (\is_string($passive['id'] ?? null)) {
                $allocatedByFamily[StatSummary::family($passive['id'])][] = $passive;
            }
        }

        $highlightedIds = array_column($searches['passiveResults'], 'id');

        return array_merge([
            'build' => $build,
            'highlightedIds' => $highlightedIds,
            'token' => $token,
            'document' => $document,
            'classes' => $this->entityManager->getRepository(CatalogClass::class)->findBy([], ['id' => 'ASC']),
            'slots' => $this->slots->all(),
            'events' => $this->events->timeline($build),
            'error' => $error,
            'treeSummary' => StatSummary::of($statsById),
            'statsView' => $statsView,
            'allocatedByFamily' => $allocatedByFamily,
            'allocationBySet' => $allocationBySet,
            'startNodeId' => $this->treeContexts->of($build)->startNodeId,
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
