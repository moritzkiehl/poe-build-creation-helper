<?php

declare(strict_types=1);

namespace App\Build\Tree;

/**
 * Whether a passive may be allocated, and what stops being legal when one goes.
 *
 * Every rule is derived from data the catalog already carries. The two that are
 * not — that both legality exceptions need their enabling node *allocated*, and
 * that an unlock constraint needs all of its gate nodes — are owner-confirmed
 * for 0.5.5 and recorded in the spec's proof list.
 */
final class AllocationRules
{
    /**
     * Entwined Realities. Allocating it arms the radius mechanism; each
     * keystone then switches on its own neighbourhood as it is taken.
     */
    private const string ENTWINED_REALITIES = 'AscendancyDruid1Notable1';

    public function __construct(private readonly PassiveGraph $graph)
    {
    }

    public function mayAllocate(Allocation $allocation, TreeContext $context, string $id, WeaponSet $set): bool
    {
        if ($allocation->has($id) || !$this->graph->exists($id)) {
            return false;
        }

        return $this->passes($allocation, $context, $id, $set);
    }

    /**
     * Re-checks a node that is already allocated, in the set it sits in.
     */
    public function isLegal(Allocation $allocation, TreeContext $context, string $id): bool
    {
        $set = $allocation->setOf($id);

        if (null === $set || !$this->graph->exists($id)) {
            return false;
        }

        return $this->passes($allocation->without($id), $context, $id, $set);
    }

    /**
     * Every allocated node the rules now reject, to a fixed point: removing one
     * can strand the next, and that one the one after it.
     *
     * A pinned id is never reported and never removed. It stays in the
     * allocation throughout, so a route through it still counts and it still
     * opens whatever it gates — the same allocation `mayAllocate()` would
     * judge a node against. Each pass still removes at least one unpinned
     * node or ends the loop, so it terminates.
     *
     * @param list<string> $pinned
     *
     * @return list<string>
     */
    public function illegalAfter(Allocation $allocation, TreeContext $context, array $pinned = []): array
    {
        $kept = array_fill_keys($pinned, true);
        $removed = [];

        do {
            $stranded = [];

            foreach ($allocation->ids() as $id) {
                if (!isset($kept[$id]) && !$this->isLegal($allocation, $context, $id)) {
                    $stranded[] = $id;
                }
            }

            foreach ($stranded as $id) {
                $allocation = $allocation->without($id);
                $removed[] = $id;
            }
        } while ([] !== $stranded);

        sort($removed);

        return $removed;
    }

    private function passes(Allocation $without, TreeContext $context, string $id, WeaponSet $set): bool
    {
        if ($id === $context->startNodeId) {
            return true;
        }

        // Enablers count only within the set the node is taken in — its own
        // set plus the shared nodes (owner, 0.5.5, spec proof 10).
        $visible = $without->visibleTo($set);

        if (!$this->unlocked($visible, $context, $id)) {
            return false;
        }

        return $this->connected($without, $context, $id, $set) || $this->excusedByRadius($visible, $context, $id);
    }

    private function unlocked(Allocation $allocation, TreeContext $context, string $id): bool
    {
        $constraint = $this->graph->unlockConstraintOf($id);

        if (null === $constraint) {
            return true;
        }

        if (null !== $constraint['ascendancy'] && $constraint['ascendancy'] !== $context->ascendancyKey) {
            return false;
        }

        foreach ($constraint['nodes'] as $gate) {
            if (!$allocation->has($gate)) {
                return false;
            }
        }

        return true;
    }

    private function connected(Allocation $allocation, TreeContext $context, string $id, WeaponSet $set): bool
    {
        $start = $context->startNodeId;

        if (null === $start) {
            return false;
        }

        $reachable = $this->componentFrom($start, $allocation->idsVisibleTo($set));

        foreach ($this->graph->neighbours($id) as $neighbour) {
            if (isset($reachable[$neighbour])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $visible
     *
     * @return array<string, true>
     */
    private function componentFrom(string $start, array $visible): array
    {
        $allowed = array_fill_keys($visible, true);
        $allowed[$start] = true;

        $seen = [$start => true];
        $queue = [$start];

        while ([] !== $queue) {
            $current = array_pop($queue);

            foreach ($this->graph->neighbours($current) as $neighbour) {
                if (isset($allowed[$neighbour]) && !isset($seen[$neighbour])) {
                    $seen[$neighbour] = true;
                    $queue[] = $neighbour;
                }
            }
        }

        return $seen;
    }

    private function excusedByRadius(Allocation $allocation, TreeContext $context, string $id): bool
    {
        // "Non-Keystone Passive Skills": a keystone still has to be reached the
        // ordinary way before it can enable anything.
        if ($this->graph->isKeystone($id)) {
            return false;
        }

        $armed = $allocation->has(self::ENTWINED_REALITIES);

        foreach ($this->graph->keystonesCovering($id) as $keystone) {
            if ($keystone === $context->jewelKeystoneId) {
                return true;
            }

            if ($armed && $allocation->has($keystone)) {
                return true;
            }
        }

        return false;
    }
}
