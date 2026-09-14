<?php

declare(strict_types=1);

namespace App\Tests\Build\Tree;

use App\Build\Tree\Allocation;
use App\Build\Tree\AllocationRules;
use App\Build\Tree\PassiveGraph;
use App\Build\Tree\TreeContext;
use App\Build\Tree\WeaponSet;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AllocationRulesTest extends KernelTestCase
{
    public function testANodeTouchingTheAllocatedSetIsLegalAndOneFloatingFreeIsNot(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['near', 'small'], ['far', 'small']],
            edges: [['start', 'near']],
        );
        $allocation = new Allocation(['start' => WeaponSet::Shared]);

        self::assertTrue($rules->mayAllocate($allocation, $this->context(), 'near', WeaponSet::Shared));
        self::assertFalse($rules->mayAllocate($allocation, $this->context(), 'far', WeaponSet::Shared));
    }

    public function testMayAllocateRejectsAnUnknownNodeAndOneAlreadyAllocated(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['near', 'small']],
            edges: [['start', 'near']],
        );
        $allocation = new Allocation(['start' => WeaponSet::Shared, 'near' => WeaponSet::Shared]);

        self::assertFalse(
            $rules->mayAllocate($allocation, $this->context(), 'nowhere', WeaponSet::Shared),
            'a node absent from the catalog is never allocatable',
        );
        self::assertFalse(
            $rules->mayAllocate($allocation, $this->context(), 'near', WeaponSet::Shared),
            'a node already allocated is not allocatable a second time',
        );
    }

    public function testANeighbourOfTheStartIsAllocatableEvenWhenTheStartIsNotItselfAllocated(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['near', 'small']],
            edges: [['start', 'near']],
        );

        self::assertTrue(
            $rules->mayAllocate(new Allocation([]), $this->context(), 'near', WeaponSet::Shared),
            'the start node seeds the connected component whether or not it is allocated — load-bearing for an empty build',
        );
    }

    public function testASetOneNodeMayNotRouteThroughSetTwo(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['bridge', 'small'], ['leaf', 'small']],
            edges: [['start', 'bridge'], ['bridge', 'leaf']],
        );

        $throughSetTwo = new Allocation(['start' => WeaponSet::Shared, 'bridge' => WeaponSet::Two]);
        self::assertFalse(
            $rules->mayAllocate($throughSetTwo, $this->context(), 'leaf', WeaponSet::One),
            'set 1 must not reach the start through a set 2 node',
        );

        $throughShared = new Allocation(['start' => WeaponSet::Shared, 'bridge' => WeaponSet::Shared]);
        self::assertTrue($rules->mayAllocate($throughShared, $this->context(), 'leaf', WeaponSet::One));

        $throughSetOne = new Allocation(['start' => WeaponSet::Shared, 'bridge' => WeaponSet::One]);
        self::assertTrue($rules->mayAllocate($throughSetOne, $this->context(), 'leaf', WeaponSet::One));
    }

    public function testAnUnlockConstraintNeedsEveryGateNodeAndTheNamedAscendancy(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['gate_a', 'notable'], ['gate_b', 'notable'], ['locked', 'small']],
            edges: [['start', 'gate_a'], ['gate_a', 'gate_b'], ['gate_b', 'locked']],
            constraints: ['locked' => ['nodes' => ['gate_a', 'gate_b'], 'ascendancy' => 'Druid1']],
        );

        $onlyOneGate = new Allocation(['start' => WeaponSet::Shared, 'gate_a' => WeaponSet::Shared, 'gate_b' => WeaponSet::Shared]);

        self::assertFalse(
            $rules->mayAllocate($onlyOneGate, new TreeContext('start', null), 'locked', WeaponSet::Shared),
            'the gates are allocated but the ascendancy does not match',
        );
        self::assertTrue($rules->mayAllocate($onlyOneGate, new TreeContext('start', 'Druid1'), 'locked', WeaponSet::Shared));

        $missingAGate = new Allocation(['start' => WeaponSet::Shared, 'gate_a' => WeaponSet::Shared]);
        self::assertFalse(
            $rules->mayAllocate($missingAGate, new TreeContext('start', 'Druid1'), 'locked', WeaponSet::Shared),
            'all listed gate nodes are required, not just one',
        );
    }

    /**
     * Real data has three notables whose unlock_constraint carries a `nodes`
     * list and no `ascendancy` key at all (invented equivalents here — the
     * shape, not the fixture, is what is under test). Naming no ascendancy
     * must not be confused with naming one the build lacks: it must pass
     * regardless of the build's ascendancy, including a build with none.
     */
    public function testAnUnlockConstraintWithNoAscendancyKeyIgnoresTheBuildsAscendancy(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['gate_a', 'notable'], ['gate_b', 'notable'], ['locked', 'small']],
            edges: [['start', 'gate_a'], ['gate_a', 'gate_b'], ['gate_b', 'locked']],
            constraints: ['locked' => ['nodes' => ['gate_a', 'gate_b']]],
        );

        $bothGates = new Allocation(['start' => WeaponSet::Shared, 'gate_a' => WeaponSet::Shared, 'gate_b' => WeaponSet::Shared]);

        self::assertTrue(
            $rules->mayAllocate($bothGates, new TreeContext('start', null), 'locked', WeaponSet::Shared),
            'a constraint naming no ascendancy must not fail closed for a build with none',
        );
        self::assertTrue(
            $rules->mayAllocate($bothGates, new TreeContext('start', 'Druid1'), 'locked', WeaponSet::Shared),
            'nor for a build that has one — the constraint names none, so none is required',
        );
    }

    public function testAKeystoneRadiusExcusesConnectivityOnlyWhenBothEnablersAreAllocated(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['AscendancyDruid1Notable1', 'notable'], ['stone', 'keystone'], ['floating', 'small']],
            edges: [['start', 'AscendancyDruid1Notable1']],
            radius: ['floating' => ['stone']],
        );
        $context = new TreeContext('start', 'Druid1');

        $notableOnly = new Allocation(['start' => WeaponSet::Shared, 'AscendancyDruid1Notable1' => WeaponSet::Shared]);
        self::assertFalse(
            $rules->mayAllocate($notableOnly, $context, 'floating', WeaponSet::Shared),
            'Entwined Realities arms the mechanism; the keystone switches on its own neighbourhood',
        );

        $keystoneOnly = new Allocation(['start' => WeaponSet::Shared, 'stone' => WeaponSet::Shared]);
        self::assertFalse(
            $rules->mayAllocate($keystoneOnly, $context, 'floating', WeaponSet::Shared),
            'the keystone alone unlocks nothing until Entwined Realities arms it',
        );

        $both = new Allocation(['start' => WeaponSet::Shared, 'AscendancyDruid1Notable1' => WeaponSet::Shared, 'stone' => WeaponSet::Shared]);
        self::assertTrue($rules->mayAllocate($both, $context, 'floating', WeaponSet::Shared));
    }

    public function testAKeystoneIsNeverExcusedByItsOwnRadius(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['AscendancyDruid1Notable1', 'notable'], ['stone', 'keystone'], ['other_stone', 'keystone']],
            edges: [['start', 'AscendancyDruid1Notable1']],
            radius: ['stone' => ['stone']],
        );

        $both = new Allocation(['start' => WeaponSet::Shared, 'AscendancyDruid1Notable1' => WeaponSet::Shared, 'stone' => WeaponSet::Shared]);

        self::assertFalse(
            $rules->mayAllocate($both, new TreeContext('start', 'Druid1'), 'stone', WeaponSet::Shared),
            'the stat reads "Non-Keystone Passive Skills"',
        );
    }

    /**
     * Enablers are scoped to the weapon set they are allocated in (owner,
     * 0.5.5, spec proof 10): a keystone or *Entwined Realities* taken at set
     * Two does nothing for a node taken at set One — the rule connectivity
     * already follows. A shared enabler counts for every set.
     */
    public function testAnEnablerCountsOnlyInItsOwnWeaponSet(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['AscendancyDruid1Notable1', 'notable'], ['stone', 'keystone'], ['floating', 'small']],
            edges: [['start', 'AscendancyDruid1Notable1']],
            radius: ['floating' => ['stone']],
        );
        $context = new TreeContext('start', 'Druid1');

        $enablersInSetTwo = new Allocation([
            'start' => WeaponSet::Shared,
            'AscendancyDruid1Notable1' => WeaponSet::Two,
            'stone' => WeaponSet::Two,
        ]);

        self::assertFalse($rules->mayAllocate($enablersInSetTwo, $context, 'floating', WeaponSet::One), 'set Two enablers must not open the radius for set One');
        self::assertTrue($rules->mayAllocate($enablersInSetTwo, $context, 'floating', WeaponSet::Two));

        $sharedEnablers = new Allocation([
            'start' => WeaponSet::Shared,
            'AscendancyDruid1Notable1' => WeaponSet::Shared,
            'stone' => WeaponSet::Shared,
        ]);

        self::assertTrue($rules->mayAllocate($sharedEnablers, $context, 'floating', WeaponSet::One), 'a shared enabler counts for every set');
    }

    public function testAnUnlockGateCountsOnlyInItsOwnWeaponSet(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['gate', 'notable'], ['locked', 'small']],
            edges: [['start', 'gate'], ['start', 'locked']],
            constraints: ['locked' => ['nodes' => ['gate'], 'ascendancy' => null]],
        );
        $context = new TreeContext('start', null);
        $gateInSetTwo = new Allocation(['start' => WeaponSet::Shared, 'gate' => WeaponSet::Two]);

        self::assertFalse($rules->mayAllocate($gateInSetTwo, $context, 'locked', WeaponSet::One), 'a set Two gate must not unlock a set One node');
        self::assertTrue($rules->mayAllocate($gateInSetTwo, $context, 'locked', WeaponSet::Two));
    }

    public function testIllegalAfterFindsWhatAnEarlierRemovalStranded(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['a', 'small'], ['b', 'small'], ['c', 'small']],
            edges: [['start', 'a'], ['a', 'b'], ['b', 'c']],
        );

        // `a` was never allocated. `isLegal()` excludes only the node under
        // test before checking, so both `b` and `c` are found unreachable
        // from `start` in the very first pass — `b` because its one route
        // runs through the unallocated `a`, `c` because its one route runs
        // through `b`, which is likewise absent from the reduced allocation
        // `c`'s own check builds. Neither needs the other removed first.
        $stranded = new Allocation(['start' => WeaponSet::Shared, 'b' => WeaponSet::Shared, 'c' => WeaponSet::Shared]);

        self::assertSame(['b', 'c'], $rules->illegalAfter($stranded, $this->context()));
    }

    /**
     * The one case that genuinely needs the `do/while` to run twice: `gate`
     * fails connectivity in pass one (its only route runs through `filler`,
     * which is not allocated). `locked`'s unlock constraint names `gate` as a
     * gate node, and `gate` is still allocated for the whole of pass one, so
     * `locked` passes then — it only fails once `gate` has actually left the
     * allocation, in pass two.
     */
    public function testTheCascadeNeedsASecondPassWhenAGateNodeIsStranded(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['filler', 'small'], ['gate', 'notable'], ['locked', 'small']],
            edges: [['start', 'filler'], ['filler', 'gate'], ['start', 'locked']],
            constraints: ['locked' => ['nodes' => ['gate']]],
        );

        // `filler` is not allocated, so `gate` is unreachable from the start
        // from the very first check; `locked` reaches the start directly and
        // does not depend on `gate` for connectivity, only for its unlock.
        $stranded = new Allocation(['start' => WeaponSet::Shared, 'gate' => WeaponSet::Shared, 'locked' => WeaponSet::Shared]);

        self::assertSame(['gate', 'locked'], $rules->illegalAfter($stranded, $this->context()));
    }

    /**
     * `v` reaches the start through `x` and through `z`. `z` is connected but
     * gated by `gate`, which is not allocated, so `z` is already illegal —
     * and a removal keeps an already-illegal passive rather than sweeping
     * it. A node that stays must stay routable: removing `x` still leaves
     * `v` a route through `z`, the same route `mayAllocate()` would accept
     * `v` back over. Unpinned, `z` goes in the first pass and strands `v` in
     * the second.
     */
    public function testAPinnedNodeStaysRoutableThroughTheCascade(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['x', 'small'], ['z', 'small'], ['v', 'small'], ['gate', 'notable']],
            edges: [['start', 'x'], ['x', 'v'], ['start', 'z'], ['z', 'v'], ['start', 'gate']],
            constraints: ['z' => ['nodes' => ['gate']]],
        );
        $allocation = new Allocation(['start' => WeaponSet::Shared, 'x' => WeaponSet::Shared, 'z' => WeaponSet::Shared, 'v' => WeaponSet::Shared]);

        self::assertSame(['z'], $rules->illegalAfter($allocation, $this->context()), 'z is illegal before anything is removed');
        self::assertTrue(
            $rules->mayAllocate($allocation->without('x')->without('v'), $this->context(), 'v', WeaponSet::Shared),
            'with x gone, v may still be allocated over z',
        );

        self::assertSame([], $rules->illegalAfter($allocation->without('x'), $this->context(), ['z']), 'pinned, z keeps v routable');
        self::assertSame(['v', 'z'], $rules->illegalAfter($allocation->without('x'), $this->context()), 'unpinned, z goes and takes v with it');
    }

    public function testTheStartNodeItselfIsAlwaysLegal(): void
    {
        $rules = $this->rulesOver(nodes: [['start', 'small']], edges: []);

        self::assertSame([], $rules->illegalAfter(new Allocation(['start' => WeaponSet::Shared]), $this->context()));
    }

    private function context(): TreeContext
    {
        return new TreeContext('start', null);
    }

    /**
     * @param list<array{0: string, 1: string}>                                   $nodes
     * @param list<array{0: string, 1: string}>                                   $edges
     * @param array<string, list<string>>                                         $radius
     * @param array<string, array{nodes: list<string>, ascendancy?: string|null}> $constraints
     */
    private function rulesOver(array $nodes, array $edges, array $radius = [], array $constraints = []): AllocationRules
    {
        $db = self::getContainer()->get(Connection::class);

        $db->executeStatement('DELETE FROM catalog_passive_edge');
        $db->executeStatement('DELETE FROM catalog_passive');

        foreach ($nodes as [$id, $kind]) {
            $db->executeStatement(
                'INSERT INTO catalog_passive (id, name, kind, pos_x, pos_y, stats, recipe, keystones_in_radius, unlock_constraint) VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?)',
                [$id, ucfirst($id), $kind, '[]', '[]', json_encode($radius[$id] ?? [], \JSON_THROW_ON_ERROR), isset($constraints[$id]) ? json_encode($constraints[$id], \JSON_THROW_ON_ERROR) : null],
            );
        }

        foreach ($edges as [$from, $to]) {
            $db->executeStatement('INSERT INTO catalog_passive_edge (from_id, to_id) VALUES (?, ?)', [$from, $to]);
        }

        return new AllocationRules(new PassiveGraph($db));
    }
}
