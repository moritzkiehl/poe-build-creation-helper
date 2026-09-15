# Canvas Feedback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The passive-tree canvas greys out nodes the rules would refuse, and the whole app gets a light and a dark colour set that follow the OS theme, with hue kept for the three allocation groups.

**Architecture:** The server computes, per weapon set, every node `AllocationRules::mayAllocate()` would accept, using one bulk method that reuses the rules' own private checks. That is not a JavaScript port. The build state ships the three sets to the canvas. Colours become CSS custom properties in `app.css`; the canvas reads the same properties through a small `palette.js`, and redraws when the weapon-set mode or the OS theme changes.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine DBAL, Twig, Stimulus, canvas 2D, PHPUnit, vitest, Playwright, all inside DDEV.

**Spec:** `docs/specs/2026-09-09-poe2-build-helper-design.md`, section "Canvas feedback, decided 2026-09-14". Game rules: `docs/rules.md` T1–T7.

## Global Constraints

- Everything runs inside DDEV. The host has no php, composer, node or npm. PHP: `ddev php bin/phpunit …`; JS: `ddev exec npx vitest run …`; the gate: `ddev composer gate`.
- Before every commit, run the full gate, `ddev composer gate` (php-cs-fixer, PHPStan at max, PHPUnit, vitest, Playwright). It must be green.
- PHPStan escapes are forbidden: no baseline, no `@phpstan-ignore`, no loosened production types, no deleted tests.
- TDD with witnessed failure. Run every new test red before writing the code that makes it green, and quote the failure message in the report.
- No real GGG data in the repository. Test fixtures are invented ids, like those already in `AllocationRulesTest`.
- Comments are English prose explaining *why*, in the density of the surrounding code.
- Colour values are exactly the spec's. Light: surface `#fcfcfb`, ink `#0b0b0b`, secondary ink `#52514e`, muted ink `#898781`, baseline `#c3c2b7`, shared `#2a78d6`, set 1 `#eb6834`, set 2 `#1baf7a`. Dark: surface `#1a1a19`, ink `#ffffff`, secondary ink `#c3c2b7`, muted ink `#898781`, baseline `#383835`, shared `#3987e5`, set 1 `#d95926`, set 2 `#199e70`.
- The server stays the authority. Greying is feedback only; a click on a greyed node still goes to the server, which refuses it with its message.
- Commit messages end with the two attribution lines the session supplies.

## File map

| File | Change |
|---|---|
| `src/Build/Tree/PassiveGraph.php` | + `ids(): list<string>` |
| `src/Build/Tree/AllocationRules.php` | + `allocatable()`; `connected()` split so both share one component walk |
| `tests/Build/Tree/AllocationRulesTest.php` | + two tests |
| `src/Controller/EditorContext.php` | + `allocatable` variable |
| `templates/build/_state.html.twig` | + `allocatable` key |
| `tests/Controller/BuildEditorControllerTest.php` | + one test |
| `assets/styles/app.css` | colour tokens, light and dark |
| `assets/lib/palette.js` | new: reads the canvas palette from CSS tokens |
| `tests/js/palette.test.js` | new |
| `assets/lib/tree_renderer.js` | palette argument, greying, keystone ring |
| `tests/js/tree_renderer.test.js` | rewritten against palette roles |
| `assets/controllers/tree_controller.js` | reads `allocatable`, palette, theme listener, redraw on mode change, group in tooltip |
| `tests/e2e/editor.spec.js` | + two pixel tests |

---

### Task 1: `allocatable()`, one bulk answer per weapon set

**Files:**
- Modify: `src/Build/Tree/PassiveGraph.php`
- Modify: `src/Build/Tree/AllocationRules.php`
- Test: `tests/Build/Tree/AllocationRulesTest.php`

**Interfaces:**
- Produces: `PassiveGraph::ids(): list<string>`, every catalog node id.
- Produces: `AllocationRules::allocatable(Allocation $allocation, TreeContext $context, WeaponSet $set): list<string>`. Sorted ascending. Exactly the ids for which `mayAllocate($allocation, $context, $id, $set)` is true, including the start node while it is unallocated, because `mayAllocate()` accepts it too.

- [ ] **Step 1: Write the two failing tests**

Add both to `AllocationRulesTest`, before `context()`:

```php
    /**
     * One graph exercising every rule: a plain neighbour (`c`), a set 2
     * route (`bridge` → `beyond`), a set 2 gate (`gate` → `locked`), the
     * Entwined radius armed by a shared notable and a set 1 keystone
     * (`floating`), a keystone covering itself (`stone`), a jewel keystone
     * (`jewelled`) and an island.
     */
    public function testAllocatableIsExactlyWhatMayAllocateAccepts(): void
    {
        [$rules, $ids, $allocation] = $this->everyRuleGraph();

        $contexts = [
            'class, no jewel' => new TreeContext('start', 'Druid1'),
            'class and jewel' => new TreeContext('start', 'Druid1', 'jewel_stone'),
            'no class' => new TreeContext(null, null),
        ];
        // The empty build is the one where the start node is itself
        // unallocated — and `mayAllocate()` accepts it then.
        $allocations = ['built' => $allocation, 'empty' => new Allocation([])];

        foreach ($allocations as $built => $current) {
            foreach ($contexts as $label => $context) {
                foreach (WeaponSet::cases() as $set) {
                    $expected = array_values(array_filter(
                        $ids,
                        static fn (string $id): bool => $rules->mayAllocate($current, $context, $id, $set),
                    ));
                    sort($expected);

                    self::assertSame($expected, $rules->allocatable($current, $context, $set), "{$built}, {$label}, {$set->name}");
                }
            }
        }
    }

    /**
     * The equality above would also hold if both sides were empty; this pins
     * what the answer actually is, so it cannot pass vacuously.
     */
    public function testAllocatableFollowsEachWeaponSetsOwnRoutesAndEnablers(): void
    {
        [$rules, , $allocation] = $this->everyRuleGraph();
        $context = new TreeContext('start', 'Druid1');

        self::assertSame(['c'], $rules->allocatable($allocation, $context, WeaponSet::Shared));
        self::assertSame(['c', 'floating'], $rules->allocatable($allocation, $context, WeaponSet::One), 'the set 1 keystone opens its radius for set 1');
        self::assertSame(['beyond', 'c', 'locked'], $rules->allocatable($allocation, $context, WeaponSet::Two), 'the set 2 bridge and gate count for set 2');
    }
```

Add the shared fixture beside `rulesOver()`:

```php
    /**
     * @return array{0: AllocationRules, 1: list<string>, 2: Allocation}
     */
    private function everyRuleGraph(): array
    {
        $nodes = [
            ['start', 'small'], ['a', 'small'], ['b', 'small'], ['c', 'small'],
            ['bridge', 'small'], ['beyond', 'small'],
            ['gate', 'notable'], ['locked', 'small'],
            ['AscendancyDruid1Notable1', 'notable'], ['stone', 'keystone'], ['floating', 'small'],
            ['jewel_stone', 'keystone'], ['jewelled', 'small'],
            ['island', 'small'],
        ];

        $rules = $this->rulesOver(
            nodes: $nodes,
            edges: [
                ['start', 'a'], ['a', 'b'], ['a', 'c'], ['b', 'stone'],
                ['start', 'bridge'], ['bridge', 'beyond'],
                ['start', 'gate'], ['start', 'locked'],
                ['start', 'AscendancyDruid1Notable1'],
            ],
            radius: ['floating' => ['stone'], 'stone' => ['stone'], 'jewelled' => ['jewel_stone']],
            constraints: ['locked' => ['nodes' => ['gate'], 'ascendancy' => null]],
        );

        $allocation = new Allocation([
            'start' => WeaponSet::Shared,
            'a' => WeaponSet::Shared,
            'AscendancyDruid1Notable1' => WeaponSet::Shared,
            'b' => WeaponSet::One,
            'stone' => WeaponSet::One,
            'bridge' => WeaponSet::Two,
            'gate' => WeaponSet::Two,
        ]);

        return [$rules, array_column($nodes, 0), $allocation];
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `ddev php bin/phpunit --filter 'testAllocatable'`
Expected: 2 errors, `Call to undefined method App\Build\Tree\AllocationRules::allocatable()`.

- [ ] **Step 3: Add `PassiveGraph::ids()`**

In `PassiveGraph`, after `exists()`:

```php
    /**
     * @return list<string>
     */
    public function ids(): array
    {
        $this->load();

        return array_keys($this->kinds);
    }
```

`array_keys()` of a string-keyed array can still yield `int` keys for numeric strings. If PHPStan reports `list<int|string>`, map it through `array_map(strval(...), array_keys($this->kinds))`. No real id is numeric, but the type must say `string`.

- [ ] **Step 4: Add `allocatable()` and share the component walk**

In `AllocationRules`, after `mayAllocate()`:

```php
    /**
     * Every node `mayAllocate()` would accept for `$set`, computed once for
     * the whole tree rather than node by node: the connected component is
     * walked once and each node then costs only its own neighbours, gates
     * and radius keystones. It reuses the very checks `mayAllocate()` runs,
     * so the canvas's greying cannot drift from what the server enforces.
     *
     * @return list<string>
     */
    public function allocatable(Allocation $allocation, TreeContext $context, WeaponSet $set): array
    {
        $visible = $allocation->visibleTo($set);
        $reachable = $this->reachable($allocation, $context, $set);
        $ids = [];

        foreach ($this->graph->ids() as $id) {
            if ($allocation->has($id)) {
                continue;
            }

            if ($id === $context->startNodeId
                || ($this->unlocked($visible, $context, $id)
                    && ($this->touches($reachable, $id) || $this->excusedByRadius($visible, $context, $id)))) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return $ids;
    }
```

Replace `connected()` with these two methods, so `passes()` and `allocatable()` share them:

```php
    /**
     * @return array<string, true>
     */
    private function reachable(Allocation $allocation, TreeContext $context, WeaponSet $set): array
    {
        $start = $context->startNodeId;

        return null === $start ? [] : $this->componentFrom($start, $allocation->idsVisibleTo($set));
    }

    /**
     * @param array<string, true> $reachable
     */
    private function touches(array $reachable, string $id): bool
    {
        foreach ($this->graph->neighbours($id) as $neighbour) {
            if (isset($reachable[$neighbour])) {
                return true;
            }
        }

        return false;
    }
```

In `passes()`, replace `$this->connected($without, $context, $id, $set)` with `$this->touches($this->reachable($without, $context, $set), $id)`.

- [ ] **Step 5: Run the rules tests, all green**

Run: `ddev php bin/phpunit tests/Build/Tree`
Expected: OK. The existing connectivity tests prove the `connected()` split changed nothing.

- [ ] **Step 6: Mutation check**

Temporarily delete the `$id === $context->startNodeId ||` clause from `allocatable()`, with a `trap` that restores the file, and run `--filter testAllocatableIsExactly`. Expected: FAIL on an `empty, class …` case, the only one where the start node is unallocated, with `'start'` missing from the actual list. Restore, confirm with `git diff --stat` that only the intended files changed, and rerun green.

- [ ] **Step 7: Gate and commit**

```bash
ddev composer gate
git add src/Build/Tree/PassiveGraph.php src/Build/Tree/AllocationRules.php tests/Build/Tree/AllocationRulesTest.php
git commit -m "feat(build): answer which nodes are allocatable per weapon set in one pass"
```

---

### Task 2: The build state carries the allocatable sets

**Files:**
- Modify: `src/Controller/EditorContext.php`
- Modify: `templates/build/_state.html.twig`
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: `AllocationRules::allocatable()` from Task 1.
- Produces: `#build-state` JSON gains `allocatable: {"shared": [ids], "1": [ids], "2": [ids]}`. The keys match `allocatedBySet`.

- [ ] **Step 1: Write the failing test**

Add after `testTheCanvasStateSeparatesTheThreeAllocationGroups()`. `seedBuildWithTree()` is the start — near — leaf chain plus an unconnected `far`. The fixture's own passives are unknown to this catalog, so they reach nothing:

```php
    public function testTheCanvasStateListsWhatEachWeaponSetCanAllocateNext(): void
    {
        $edit = $this->seedBuildWithTree();

        $allocatable = $this->allocatableIn($edit);
        self::assertContains('near', $allocatable['shared']);
        self::assertNotContains('leaf', $allocatable['shared'], 'leaf is two steps out');
        self::assertNotContains('far', $allocatable['shared'], 'far touches nothing');

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'near', 'set' => '1']);

        $allocatable = $this->allocatableIn($edit);
        self::assertContains('leaf', $allocatable['1'], 'near opened leaf for set 1');
        self::assertNotContains('leaf', $allocatable['2'], 'a set 1 node opens nothing for set 2');
        self::assertNotContains('near', $allocatable['1'], 'an allocated node is not allocatable again');
    }

    /**
     * @return array{shared: list<string>, 1: list<string>, 2: list<string>}
     */
    private function allocatableIn(string $edit): array
    {
        $crawler = $this->client->request('GET', $edit);
        $state = json_decode($crawler->filter('#build-state')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $allocatable = $state['allocatable'] ?? null;
        self::assertIsArray($allocatable);

        $ids = static function (mixed $list): array {
            self::assertIsArray($list);

            return array_values(array_filter($list, is_string(...)));
        };

        return ['shared' => $ids($allocatable['shared'] ?? null), '1' => $ids($allocatable['1'] ?? null), '2' => $ids($allocatable['2'] ?? null)];
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit --filter testTheCanvasStateListsWhatEachWeaponSetCanAllocateNext`
Expected: FAIL at `self::assertIsArray($allocatable)`, "Failed asserting that null is of type array".

- [ ] **Step 3: Compute the sets in `EditorContext`**

Add `use App\Build\Tree\AllocationRules;` and a constructor argument `private readonly AllocationRules $rules,` after `$treeContexts`; `src/` is autowired, so no service config is needed. In `of()`, after `$allocationBySet` is filled:

```php
        // What a click could add next, per weapon set, so the canvas can
        // grey the rest. The same rules the allocate command enforces.
        $treeContext = $this->treeContexts->of($build);
        $allocatable = [
            'shared' => $this->rules->allocatable($allocation, $treeContext, WeaponSet::Shared),
            '1' => $this->rules->allocatable($allocation, $treeContext, WeaponSet::One),
            '2' => $this->rules->allocatable($allocation, $treeContext, WeaponSet::Two),
        ];
```

In the returned array, add `'allocatable' => $allocatable,` after `'allocationBySet'`. Change `'startNodeId' => $this->treeContexts->of($build)->startNodeId,` to `'startNodeId' => $treeContext->startNodeId,`.

- [ ] **Step 4: Ship them in the state**

In `templates/build/_state.html.twig`, add after `allocatedBySet: allocationBySet,`:

```twig
            allocatable: allocatable,
```

- [ ] **Step 5: Run green, then the controller suite**

Run: `ddev php bin/phpunit --filter testTheCanvasStateListsWhatEachWeaponSetCanAllocateNext`, then `ddev php bin/phpunit tests/Controller`.
Expected: OK both times. The page and the Turbo Stream render the same `_state` partial from the same `EditorContext`, so each edit's stream refreshes the sets without further work.

- [ ] **Step 6: Measure the cost on the development database**

The page view now loads the passive graph, 4912 nodes, and walks it three times. Time an editor page with a real imported build, before (`git stash`) and after:

```bash
ddev exec curl -s -o /dev/null -w '%{time_total}\n' http://localhost/b/<slug>/edit/<token>
```

Run it three times each way. Record the medians in the report. If "after" adds more than 150 ms, report DONE_WITH_CONCERNS with the figures rather than optimising.

- [ ] **Step 7: Gate and commit**

```bash
ddev composer gate
git add src/Controller/EditorContext.php templates/build/_state.html.twig tests/Controller/BuildEditorControllerTest.php
git commit -m "feat(editor): tell the canvas what each weapon set can allocate next"
```

---

### Task 3: Colour tokens, light and dark, for the whole app

**Files:**
- Modify: `assets/styles/app.css`

**Interfaces:**
- Produces: custom properties on `:root`, which Task 4 reads by these exact names: `--surface`, `--ink`, `--ink-secondary`, `--ink-muted`, `--baseline`, `--group-shared`, `--group-1`, `--group-2`, `--error`.

This task has no automated test of its own: vitest runs without a DOM, and Task 5's Playwright tests prove the tokens reach the canvas in both modes. Its check is the gate plus a manual look.

- [ ] **Step 1: Replace the colour declarations in `app.css`**

Replace the first line (`:root { --gap: 1rem; --line: #d5d5d5; }`) with:

```css
/* The whole app follows the OS theme (owner, 2026-09-14). Values are the
   spec's "Canvas feedback" palette. Hue is reserved for the three allocation
   groups, and tree_controller.js reads these same tokens for the canvas, so
   the palette lives here and only here. */
:root {
    color-scheme: light dark;
    --gap: 1rem;
    --surface: #fcfcfb;
    --ink: #0b0b0b;
    --ink-secondary: #52514e;
    --ink-muted: #898781;
    --baseline: #c3c2b7;
    --group-shared: #2a78d6;
    --group-1: #eb6834;
    --group-2: #1baf7a;
    /* 6.4:1 on the light surface. */
    --error: #b3261e;
}

@media (prefers-color-scheme: dark) {
    :root {
        --surface: #1a1a19;
        --ink: #ffffff;
        --ink-secondary: #c3c2b7;
        --baseline: #383835;
        --group-shared: #3987e5;
        --group-1: #d95926;
        --group-2: #199e70;
        /* 10.2:1 on the dark surface. */
        --error: #f2b8b5;
    }
}
```

`--ink-muted` is the same in both modes, so the dark block doesn't repeat it.

- [ ] **Step 2: Point every hardcoded colour at a token**

Apply these replacements in `app.css`:

- `body { … }` gains `background: var(--surface); color: var(--ink);`.
- Every `var(--line)` becomes `var(--baseline)`.
- `.tree-canvas { background: #101014; … }` becomes `background: var(--surface);`.
- `.swatch-shared`, `.swatch-1`, `.swatch-2` use `var(--group-shared)`, `var(--group-1)`, `var(--group-2)`.
- `.tree-tooltip` uses `background: var(--surface); color: var(--ink);` and keeps `border: 1px solid var(--baseline)`.
- `.tree-tooltip .cost` uses `color: var(--ink-secondary)`.
- `.error:not(:empty)` becomes `border: 1px solid var(--error); color: var(--error); margin-bottom: var(--gap); padding: 0.5rem;`, with no background.

Then confirm nothing hardcoded is left:

```bash
grep -n '#[0-9a-fA-F]\{3,6\}' assets/styles/app.css
```

Expected: hex values only inside the two token blocks.

- [ ] **Step 3: Look at it in both modes**

Run `ddev launch` and open an editor page. Switch the OS or browser between light and dark (Chrome DevTools, Rendering, "Emulate CSS media feature prefers-color-scheme"). The page, form controls, legend swatches, tooltip and error box follow in both modes. The canvas still uses its old colours until Task 4. Note what you saw in the report.

- [ ] **Step 4: Gate and commit**

```bash
ddev composer gate
git add assets/styles/app.css
git commit -m "feat(ui): light and dark colour tokens that follow the OS theme"
```

---

### Task 4: The canvas greys, draws kind by size, and follows the theme

**Files:**
- Create: `assets/lib/palette.js`
- Create: `tests/js/palette.test.js`
- Modify: `assets/lib/tree_renderer.js`
- Modify: `tests/js/tree_renderer.test.js`
- Modify: `assets/controllers/tree_controller.js`

**Interfaces:**
- Consumes: the `allocatable` state key from Task 2 and the CSS tokens from Task 3.
- Produces: `readPalette(style)`, taking any object with `getPropertyValue(name)` such as a `CSSStyleDeclaration` and returning `{ surface, ink, inkSecondary, inkMuted, baseline, shared, setOne, setTwo }`.
- Produces: `drawTree(context, { nodes, edges, allocatedBySet, allocatable, startNodeId, hovered, highlighted }, camera, palette)`, where `allocatable` is a `Set` of ids for the selected weapon-set mode.

- [ ] **Step 1: Write the failing palette test**

`tests/js/palette.test.js`:

```js
import { describe, expect, it } from 'vitest';
import { readPalette } from '../../assets/lib/palette.js';

function styleOf(tokens) {
    return { getPropertyValue: (name) => tokens[name] ?? '' };
}

describe('readPalette', () => {
    it('reads each role from its CSS token, trimmed', () => {
        const palette = readPalette(styleOf({
            '--surface': ' #1a1a19', '--ink': '#ffffff', '--ink-secondary': '#c3c2b7', '--ink-muted': '#898781',
            '--baseline': '#383835', '--group-shared': '#3987e5', '--group-1': '#d95926', '--group-2': '#199e70',
        }));

        expect(palette).toEqual({
            surface: '#1a1a19', ink: '#ffffff', inkSecondary: '#c3c2b7', inkMuted: '#898781',
            baseline: '#383835', shared: '#3987e5', setOne: '#d95926', setTwo: '#199e70',
        });
    });

    it('falls back to the light palette for a token the stylesheet did not define', () => {
        expect(readPalette(styleOf({})).shared).toBe('#2a78d6');
    });
});
```

Run: `ddev exec npx vitest run tests/js/palette.test.js`
Expected: FAIL, the module `../../assets/lib/palette.js` cannot be resolved.

- [ ] **Step 2: Write `palette.js`**

```js
// The canvas can't use CSS variables directly, so it reads the same tokens
// app.css defines. A missing token falls back to the light value rather
// than to an empty string, which a canvas would silently ignore, leaving
// the previous colour in place.
const TOKENS = {
    surface: ['--surface', '#fcfcfb'],
    ink: ['--ink', '#0b0b0b'],
    inkSecondary: ['--ink-secondary', '#52514e'],
    inkMuted: ['--ink-muted', '#898781'],
    baseline: ['--baseline', '#c3c2b7'],
    shared: ['--group-shared', '#2a78d6'],
    setOne: ['--group-1', '#eb6834'],
    setTwo: ['--group-2', '#1baf7a'],
};

export function readPalette(style) {
    return Object.fromEntries(Object.entries(TOKENS).map(([role, [token, fallback]]) => [
        role,
        style.getPropertyValue(token).trim() || fallback,
    ]));
}
```

Run: `ddev exec npx vitest run tests/js/palette.test.js`
Expected: PASS, 2 tests.

- [ ] **Step 3: Rewrite the renderer tests against palette roles**

Replace `tests/js/tree_renderer.test.js` after `fakeContext()` (keep that function as it is). The palette values are role names, so each test pins which role paints what, not a hex value:

```js
const camera = { scale: 1, x: 0, y: 0 };

const palette = {
    surface: 'surface', ink: 'ink', inkSecondary: 'ink-secondary', inkMuted: 'ink-muted',
    baseline: 'baseline', shared: 'shared', setOne: 'set-one', setTwo: 'set-two',
};

function nodesAt(ids, kind = 'small') {
    return new Map(ids.map((id, index) => [id, { id, name: id, kind, x: index * 10, y: 0 }]));
}

const none = () => ({ shared: new Set(), one: new Set(), two: new Set() });

function draw(context, overrides) {
    drawTree(context, {
        nodes: new Map(),
        edges: [],
        allocatedBySet: none(),
        allocatable: new Set(),
        startNodeId: null,
        hovered: null,
        highlighted: new Set(),
        ...overrides,
    }, camera, palette);
}

describe('drawTree', () => {
    it('paints the three allocation groups in their three group colours', () => {
        const context = fakeContext();

        draw(context, {
            nodes: nodesAt(['shared_node', 'set_one_node', 'set_two_node']),
            allocatedBySet: { shared: new Set(['shared_node']), one: new Set(['set_one_node']), two: new Set(['set_two_node']) },
        });

        expect(context.fills).toEqual(['shared', 'set-one', 'set-two']);
    });

    it('paints an allocatable node in secondary ink and greys one that is not', () => {
        const context = fakeContext();

        draw(context, { nodes: nodesAt(['open', 'refused']), allocatable: new Set(['open']) });

        expect(context.fills).toEqual(['ink-secondary', 'ink-muted']);
    });

    it('never greys the start node, allocatable or not', () => {
        const context = fakeContext();

        draw(context, { nodes: nodesAt(['start']), startNodeId: 'start' });

        expect(context.fills).toEqual(['ink']);
    });

    it('rings a node the search matched, and not one it did not', () => {
        const context = fakeContext();

        draw(context, { nodes: nodesAt(['found', 'not_found']), highlighted: new Set(['found']) });

        const rings = context.strokes.filter((stroke) => 3 === stroke.width);
        expect(rings).toEqual([{ style: 'ink', width: 3 }]);
    });

    it('rings a keystone in its own fill colour, and a notable not at all', () => {
        const context = fakeContext();

        draw(context, { nodes: new Map([
            ['stone', { id: 'stone', name: 'stone', kind: 'keystone', x: 0, y: 0 }],
            ['notable', { id: 'notable', name: 'notable', kind: 'notable', x: 10, y: 0 }],
        ]), allocatable: new Set(['stone', 'notable']) });

        expect(context.strokes).toEqual([{ style: 'ink-secondary', width: 1.5 }]);
    });
});
```

Run: `ddev exec npx vitest run tests/js/tree_renderer.test.js`
Expected: FAIL. The old renderer ignores `palette` and still paints `#e8c56a` and the other old hex values.

- [ ] **Step 4: Rewrite `tree_renderer.js`**

Remove the `COLOURS` constant. Keep `RADIUS` and the edge loop's geometry. Change the signature and the colour logic:

```js
// Kind is carried by size, and a keystone by a ring as well. Hue means
// allocation and nothing else (spec, "Canvas feedback").
const RADIUS = { small: 4, notable: 7, keystone: 9 };

// Edges are straight lines. In the game they follow the orbit they were laid
// out on; matching that is a visual nicety and is deliberately not done here.
export function drawTree(context, { nodes, edges, allocatedBySet, allocatable, startNodeId, hovered, highlighted }, camera, palette) {
    const { width, height } = context.canvas;
    const isAllocated = (id) => allocatedBySet.shared.has(id) || allocatedBySet.one.has(id) || allocatedBySet.two.has(id);

    context.clearRect(0, 0, width, height);

    for (const [from, to] of edges) {
        const a = nodes.get(from);
        const b = nodes.get(to);

        if (!a || !b) {
            continue;
        }

        const start = worldToScreen(camera, width, height, a.x, a.y);
        const end = worldToScreen(camera, width, height, b.x, b.y);
        const taken = isAllocated(from) && isAllocated(to);

        context.strokeStyle = taken ? palette.inkSecondary : palette.baseline;
        context.lineWidth = taken ? 3 : 1.5;
        context.beginPath();
        context.moveTo(start.x, start.y);
        context.lineTo(end.x, end.y);
        context.stroke();
    }

    context.lineWidth = 1.5;

    for (const node of nodes.values()) {
        const point = worldToScreen(camera, width, height, node.x, node.y);

        if (point.x < -20 || point.y < -20 || point.x > width + 20 || point.y > height + 20) {
            continue;
        }

        const radius = (RADIUS[node.kind] ?? RADIUS.small) * Math.max(0.6, Math.min(1.6, camera.scale * 6));

        // Greying is feedback only: the server still judges every click.
        const fill = node.id === startNodeId ? palette.ink
            : allocatedBySet.one.has(node.id) ? palette.setOne
            : allocatedBySet.two.has(node.id) ? palette.setTwo
            : allocatedBySet.shared.has(node.id) ? palette.shared
            : allocatable.has(node.id) ? palette.inkSecondary
            : palette.inkMuted;

        context.fillStyle = fill;
        context.beginPath();
        context.arc(point.x, point.y, radius, 0, Math.PI * 2);
        context.fill();

        if ('keystone' === node.kind) {
            context.strokeStyle = fill;
            context.beginPath();
            context.arc(point.x, point.y, radius + 3, 0, Math.PI * 2);
            context.stroke();
        }

        if (node.id === hovered) {
            context.strokeStyle = palette.ink;
            context.beginPath();
            context.arc(point.x, point.y, radius, 0, Math.PI * 2);
            context.stroke();
        }

        if (highlighted.has(node.id)) {
            context.strokeStyle = palette.ink;
            context.lineWidth = 3;
            context.beginPath();
            context.arc(point.x, point.y, radius, 0, Math.PI * 2);
            context.stroke();
            context.lineWidth = 1.5;
        }
    }
}
```

The hover and search rings now start their own path. The keystone ring would otherwise be the path they stroke.

Run: `ddev exec npx vitest run`
Expected: PASS, all files.

- [ ] **Step 5: Wire the controller**

In `assets/controllers/tree_controller.js`:

1. `import { readPalette } from '../lib/palette.js';`
2. In `connect()`, before `this.readState();`:

```js
        this.allocatableBySet = { shared: new Set(), one: new Set(), two: new Set() };
        this.palette = readPalette(getComputedStyle(this.element));

        // The canvas can't restyle itself the way CSS does, so a change of
        // OS theme re-reads the tokens and repaints.
        this.theme = window.matchMedia('(prefers-color-scheme: dark)');
        this.onTheme = () => { this.palette = readPalette(getComputedStyle(this.element)); this.redraw(); };
        this.theme.addEventListener('change', this.onTheme);
```

3. In `disconnect()`, add `this.theme?.removeEventListener('change', this.onTheme);`.
4. In `readState()`, after `this.allocatedBySet = …;`:

```js
        const allocatable = state.allocatable ?? { shared: [], 1: [], 2: [] };
        this.allocatableBySet = {
            shared: new Set(allocatable.shared ?? []),
            one: new Set(allocatable['1'] ?? []),
            two: new Set(allocatable['2'] ?? []),
        };
```

5. In `redraw()`, pass `allocatable: this.allocatableFor(this.weaponSet),` after `allocatedBySet`, and `this.palette` as the fourth argument to `drawTree`.
6. Add beside `groupFor()`:

```js
    allocatableFor(weaponSet) {
        return weaponSet === '1' ? this.allocatableBySet.one : weaponSet === '2' ? this.allocatableBySet.two : this.allocatableBySet.shared;
    }
```

7. `setWeaponSet()` redraws, since the greying depends on the mode:

```js
    setWeaponSet(event) {
        this.weaponSet = event.target.value;
        this.redraw();
    }
```

8. In `showTooltip()`, name the node's group. The light aqua is 2.74:1, below 3:1, so colour alone may not carry it (spec). Before `tip.innerHTML = …`:

```js
        const group = this.allocatedBySet.one.has(node.id) ? 'Weapon set 1'
            : this.allocatedBySet.two.has(node.id) ? 'Weapon set 2'
            : this.allocatedBySet.shared.has(node.id) ? 'Both weapon sets'
            : null;
        const allocatedIn = group ? `<span class="group">Allocated: ${group}</span>` : '';
```

and make the markup `` `<h3>${escapeHtml(node.name)}</h3>${allocatedIn}<ul>${stats}</ul>${cost}` ``. In `app.css`, add `.tree-tooltip .group { color: var(--ink-secondary); display: block; margin-bottom: 0.25rem; }`.

The labels match the legend's in `templates/build/_tree.html.twig`.

- [ ] **Step 6: Gate and commit**

The existing Playwright tests click the canvas and hover the tooltip, so they cover the wiring. Task 5 adds the pixel proof.

```bash
ddev composer gate
git add assets/lib/palette.js assets/lib/tree_renderer.js assets/controllers/tree_controller.js assets/styles/app.css tests/js/palette.test.js tests/js/tree_renderer.test.js
git commit -m "feat(tree): grey what cannot be allocated and draw from the theme's tokens"
```

---

### Task 5: Prove the greying in a real browser, light and dark

**Files:**
- Modify: `tests/e2e/editor.spec.js`

**Interfaces:**
- Consumes: the seeded tree from `CreateTestBuildCommand`. `e2e_target` is one edge from the start at +1000 world units, `e2e_leaf` hangs off the target at +2000, and `e2e_island` touches nothing at −1000.

At the fixed initial scale 0.12, a small node's radius is 4 × max(0.6, min(1.6, 0.72)) ≈ 2.9 device pixels. Its centre pixel is fully covered and so reads the exact fill, without anti-aliasing.

- [ ] **Step 1: Add the helpers and two tests**

After `clickCanvasAt()`:

```js
// Token values from app.css, as the canvas reports them.
const RGB = {
    light: { secondary: [82, 81, 78], muted: [137, 135, 129] },
    dark: { secondary: [195, 194, 183], muted: [137, 135, 129] },
};

/**
 * The colour of the pixel at the centre of the node `worldOffsetX` world
 * units right of the start, read from the canvas's own drawing buffer.
 */
async function pixelAt(canvas, worldOffsetX) {
    return canvas.evaluate((el, offset) => {
        const x = Math.round(el.width / 2 + offset);
        const y = Math.round(el.height / 2);

        return Array.from(el.getContext('2d').getImageData(x, y, 1, 1).data.slice(0, 3));
    }, worldOffsetX * SCALE);
}

for (const scheme of ['light', 'dark']) {
    test(`an unreachable node is greyed and a reachable one is not (${scheme})`, async ({ page }) => {
        await page.emulateMedia({ colorScheme: scheme });
        await page.goto(createBuild());

        const canvas = page.locator('canvas.tree-canvas');
        await expect.poll(() => canvas.evaluate((el) => el.width)).toBeGreaterThan(0);

        await expect.poll(() => pixelAt(canvas, TARGET_OFFSET_X)).toEqual(RGB[scheme].secondary);
        expect(await pixelAt(canvas, ISLAND_OFFSET_X)).toEqual(RGB[scheme].muted);
    });
}

test('the greying follows the weapon set chosen above the canvas', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto(createBuild());

    const canvas = page.locator('canvas.tree-canvas');
    await expect.poll(() => canvas.evaluate((el) => el.width)).toBeGreaterThan(0);
    await canvas.scrollIntoViewIfNeeded();

    // Allocating the target into set 1 opens the leaf behind it for set 1
    // only. Shared mode must grey it again: a set 1 node routes nothing for
    // the shared group.
    await page.locator('input[name="weapon-set-mode"][value="1"]').check();
    await clickCanvasAt(page, canvas, TARGET_OFFSET_X);
    await expect.poll(async () => {
        const state = JSON.parse(await page.locator('#build-state').textContent());

        return state.allocatable['1'].includes('e2e_leaf');
    }).toBe(true);

    await expect.poll(() => pixelAt(canvas, LEAF_OFFSET_X)).toEqual(RGB.light.secondary);

    await page.locator('input[name="weapon-set-mode"][value="shared"]').check();
    await expect.poll(() => pixelAt(canvas, LEAF_OFFSET_X)).toEqual(RGB.light.muted);
});
```

- [ ] **Step 2: Watch the mode test fail without the redraw**

Temporarily remove `this.redraw();` from `setWeaponSet()` in `tree_controller.js`, with a `trap` that restores it, and run:

`ddev exec npx playwright test -g "follows the weapon set"`

Expected: FAIL. The leaf pixel stays `[82, 81, 78]` after switching to shared. Restore the line and confirm with `git diff --stat` that only `tests/e2e/editor.spec.js` is modified.

- [ ] **Step 3: Watch a scheme test fail on the wrong palette**

Temporarily change the dark `--ink-secondary` in `app.css` to `#52514e`, with a `trap` restore, and run `-g "(dark)"`. Expected: FAIL, the pixel reads `[82, 81, 78]` instead of `[195, 194, 183]`. This proves the dark test reads the dark tokens rather than passing on the light ones. Restore.

- [ ] **Step 4: Run all three green**

Run: `ddev exec npx playwright test -g "greyed|follows the weapon set"`
Expected: 3 passed.

- [ ] **Step 5: Gate and commit**

```bash
ddev composer gate
git add tests/e2e/editor.spec.js
git commit -m "test(e2e): prove the canvas greying in light, dark and per weapon set"
```

---

## After the last task

- Update `docs/rules.md`: T1–T7 note that the canvas greys by the same rules (`AllocationRules::allocatable()`).
- Tick the canvas-feedback section in the spec as built, with the commit range.
