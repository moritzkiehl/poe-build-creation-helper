# Editor Refinements, Slice A — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the editor show what the tree actually gives you — hover a node to see its effect and what it costs to instil, read the allocated tree as summed stats rather than a list of ids, and set level ranges once instead of per node.

**Architecture:** The catalog starts keeping the `recipe` field it currently discards, and `tree.json` ships stats and recipes alongside the coordinates so hover is instant. Two new pure services carry the thinking: one derives whether a build's intervals are uniform (which picks the editing mode, never stored), the other groups allocated passives by id family and sums their stat lines by a rule that holds no game knowledge. Everything else is templates and command wiring on patterns iteration 3 already established.

**Tech Stack:** PHP 8.5 / Symfony 8.1, Doctrine + MariaDB, Twig, symfony/messenger, Stimulus + Turbo over AssetMapper, vitest, Playwright — all inside DDEV.

**Spec:** `docs/specs/2026-09-09-poe2-build-helper-design.md`, section **"Iteration 3 refinements"** (committed in `fd53426`). Read it before starting. This plan implements **slice A only**; slice B (canvas-only editing and the four allocation-legality rules) is designed there but deliberately not built yet.

## Global Constraints

- **Everything runs inside DDEV.** There is no PHP, Composer, MariaDB or usable Node on the host — the host `npm` is a broken Windows shim. Use `ddev composer …`, `ddev php …`, `ddev npm …` from the repository root `/home/moritz/PhpstormProjects/poe-creation-helper`. Site: `https://poe-build-helper.ddev.site` (self-signed — `curl -sk`).
- **Gate:** `ddev composer gate` = php-cs-fixer, PHPStan at **level max** over `src` and `tests`, PHPUnit. Plus `ddev npm run test:js` (vitest) and the Playwright run. Baseline: 165 PHPUnit (1 expected skip — an optional corpus needing personal files absent here), 11 vitest, 1 Playwright.
- **FORBIDDEN to make static analysis pass:** `treatPhpDocTypesAsCertain: false`, a baseline file, `@phpstan-ignore`, loosening a production type to `array`/`mixed`, deleting a test.
- **Twig traps this project has hit:** there is no `|values` filter (throws at render, which PHPUnit does NOT catch) and inline `{% for … if … %}` was removed in Twig 3 — use `|filter(…)`. Run `ddev php bin/console lint:twig templates/` before every commit.
- **Never change production markup to satisfy a test assertion.** This happened twice in iteration 3 and was reverted both times. If an assertion cannot see an `<input value>` attribute, assert on the attribute (`input[name="x"][value="y"]`), ideally scoped by a unique id.
- **`.build` format fidelity, measured from real game files:** `level_interval` is `[from, to]` and is required on **every** passive, skill, support and slot; levels run **0–100**. `additional_text` is on passives and slots, **never** skills. `support_skills` is absent until a skill has one and absent again when emptied. `unique_name` only when a unique is actually named. Passive ids and gem ids are stored **verbatim** — 527 passive ids end in an underscore, and three gem path prefixes coexist.
- **App-only fields never reach the export.** `class_key`, `target_level`, `note`, `archetype_key` and (new here) `instilled_passives` live on the `build` row only, never in `BuildDocument`, the `document` column, or `BuildDocumentWriter` output. The byte-exact round-trip of fourteen real game files is the evidence the game accepts our output; `tests/Interchange/` guards it.
- **Game facts are curated and version-stamped, never inferred.** An Instilled Modifier costs no passive point (owner-confirmed, 0.5.5). The catalog carries Distilled Emotion recipes but nothing about their cost.
- **Vocabulary:** the currency is a **Distilled Emotion**; applying it yields an **Instilled Modifier**. "Anoint" is legacy wording — use it only when quoting a legacy mod name.
- **The catalog may be empty.** Every page must render with no game data synced. On this machine it IS synced (4912 passives, 1120 gems, 5408 items), so tests must not depend on either state unless they seed it themselves.

---

## File Structure

**Catalog — keeping and shipping node detail**
- `src/Catalog/PassiveTreeNormalizer.php` — also read `recipe`
- `src/Catalog/NormalizedTree.php` — node shape gains `recipe`
- `src/Catalog/PassiveTreeSync.php` — write the new column
- `src/Entity/CatalogPassive.php` — `recipe` field
- `src/Catalog/View/StatText.php` *(new)* — the two display transforms, in one place because both are "catalog text made readable"
- `src/Catalog/TreeExport.php` — tuple 6 → 8, shipping already-readable text

**The thinking, as pure services**
- `src/Build/StatSummary.php` *(new)* — allocated ids + their stats → families with summed and listed lines
- `src/Build/Edit/IntervalMode.php` *(new)* — derives uniformity and the widest span from a document

**Editing**
- `src/Build/Edit/DocumentEditor.php` — two cascading interval methods
- `src/Build/Edit/Command/` *(new DTOs)* — `SetAllPassiveIntervals`, `SetSkillIntervalCascading`, `AddInstilled`, `RemoveInstilled`
- `src/Build/Edit/CommandFactory.php` — four new actions
- `src/Build/Edit/Handler/PassiveEditHandler.php`, `SkillEditHandler.php`, `HeaderEditHandler.php` — new methods
- `src/Build/Edit/Handler/InstilledEditHandler.php` *(new)*
- `src/Entity/Build.php` — `instilledPassives` column, `setGameVersion`

**Reading for the page**
- `src/Catalog/CatalogSearch.php` — `instillablePassives()`
- `src/Controller/EditorSearches.php` *(new)* — all five search terms and their results, extracted so `EditorContext` stays a context builder rather than a search coordinator
- `src/Controller/EditorContext.php` — delegates searching, adds the new keys

**Templates**
- `templates/build/_nodes.html.twig` — becomes the stats overview
- `templates/build/_skills.html.twig` — support autocomplete, interval mode
- `templates/build/_slots.html.twig` — unique autocomplete, Instilled Modifier search
- `templates/build/_header.html.twig` — editable `game_version`
- `templates/build/_tree.html.twig` — tooltip element

**Client**
- `assets/controllers/tree_controller.js` — populate the tooltip on hover

---

### Task 1: Keep the `recipe` the normaliser throws away

The tree export carries, on 875 nodes, the three Distilled Emotions that instil that node. Nothing keeps it today.

**Files:**
- Modify: `src/Catalog/NormalizedTree.php`, `src/Catalog/PassiveTreeNormalizer.php`, `src/Catalog/PassiveTreeSync.php`, `src/Entity/CatalogPassive.php`
- Create: `migrations/Version20260912090000.php`
- Modify: `tests/fixtures/catalog/tree-shape.json`
- Test: `tests/Catalog/PassiveTreeNormalizerTest.php`

**Interfaces:**
- Consumes: `PassiveTreeNormalizer::normalize(string $json): NormalizedTree`.
- Produces: the `PassiveNode` shape gains `recipe: list<string>`; `catalog_passive.recipe` (JSON, defaults to `[]`); `CatalogPassive::getRecipe(): list<string>`.

- [ ] **Step 1: Add a recipe to the synthetic fixture**

In `tests/fixtures/catalog/tree-shape.json`, add a `recipe` to the notable `1002` (`synthetic13_`) and leave the others without one. Invent the ingredient names — real upstream content must never enter this repository:

```json
        "1002": {
            "id": "synthetic13_",
            "skill": 1002,
            "name": "Synthetic Notable",
            "isNotable": true,
            "recipe": ["LiquidSyntheticA", "LiquidSyntheticB", "LiquidSyntheticA"],
            "stats": ["15% increased Melee Damage"],
            "group": 1, "orbit": 2, "orbitIndex": 6,
            "x": 264.0, "y": 200.0,
            "out": [], "in": ["1001"], "edges": [1]
        },
```

Note the repeated ingredient — real recipes do repeat, and the shape must survive it.

- [ ] **Step 2: Write the failing test**

Add to `tests/Catalog/PassiveTreeNormalizerTest.php`:

```php
    public function testANodeKeepsTheDistilledEmotionsThatInstilIt(): void
    {
        $byId = array_column($this->normalize()->nodes, null, 'id');

        self::assertSame(
            ['LiquidSyntheticA', 'LiquidSyntheticB', 'LiquidSyntheticA'],
            $byId['synthetic13_']['recipe'],
            'a repeated ingredient is real and must survive',
        );
    }

    public function testANodeWithNoRecipeGetsAnEmptyList(): void
    {
        $byId = array_column($this->normalize()->nodes, null, 'id');

        self::assertSame([], $byId['synthetic12']['recipe']);
    }
```

- [ ] **Step 3: Run it and watch it fail**

Run: `ddev php bin/phpunit tests/Catalog/PassiveTreeNormalizerTest.php`
Expected: FAIL — undefined array key `recipe`.

- [ ] **Step 4: Widen the node shape**

In `src/Catalog/NormalizedTree.php`, extend the type alias:

```php
 * @phpstan-type PassiveNode array{id: string, name: string, kind: string, ascendancy_key: string|null, pos_x: float, pos_y: float, stats: list<string>, recipe: list<string>}
```

- [ ] **Step 5: Read it in the normaliser**

In `src/Catalog/PassiveTreeNormalizer.php`, add to the node array built in `normalize()`:

```php
                'recipe' => array_values(array_filter((array) ($node['recipe'] ?? []), is_string(...))),
```

- [ ] **Step 6: Add the column**

Create `migrations/Version20260912090000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the Distilled Emotions that instil a passive: 875 nodes carry a three-ingredient recipe the normaliser used to discard.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_passive ADD recipe JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_passive DROP recipe');
    }
}
```

Add to `src/Entity/CatalogPassive.php`, beside `$stats`:

```php
    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $recipe = [];

    /** @return list<string> */
    public function getRecipe(): array
    {
        return $this->recipe;
    }
```

Run: `ddev composer db:migrate`

- [ ] **Step 7: Write it in the sync**

In `src/Catalog/PassiveTreeSync.php`, extend the `catalog_passive` insert — the column list, the placeholder tuple and the pushed parameters must stay in step:

```php
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                    array_push($params, $node['id'], $node['name'], $node['kind'], $node['ascendancy_key'], $node['pos_x'], $node['pos_y'], json_encode($node['stats'], \JSON_THROW_ON_ERROR), json_encode($node['recipe'], \JSON_THROW_ON_ERROR));
                }
                $db->executeStatement('INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES '.implode(',', $values), $params);
```

- [ ] **Step 8: Run the tests and the gate**

Run: `ddev php bin/phpunit tests/Catalog` then `ddev composer gate`
Expected: PASS, gate green.

- [ ] **Step 9: Re-sync and confirm against real data**

Run: `ddev php bin/console app:catalog:sync`
Then: `ddev php bin/console dbal:run-sql "SELECT COUNT(*) FROM catalog_passive WHERE JSON_LENGTH(recipe) > 0"`
Expected: **875**. If it is 0, the sync wrote nothing; if it is 4912, the empty case is wrong.

- [ ] **Step 10: Commit**

```bash
git add src/Catalog src/Entity/CatalogPassive.php migrations/Version20260912090000.php tests/
git commit -m "$(cat <<'EOF'
feat(catalog): keep the Distilled Emotions that instil a passive

875 nodes carry a three-ingredient recipe the normaliser discarded. The
editor needs it to show what a node costs to instil.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 2: Readable text, and shipping it with the tree

Stat lines carry game markup (`[Ignite|Ignites]`) and emotion ids are camel case. Both become readable once, server-side, so the canvas and the overview share one implementation instead of duplicating it in JavaScript.

**Files:**
- Create: `src/Catalog/View/StatText.php`, `tests/Catalog/StatTextTest.php`
- Modify: `src/Catalog/TreeExport.php`
- Test: `tests/Catalog/TreeExportTest.php`

**Interfaces:**
- Consumes: `CatalogPassive` rows via DBAL; `Row::str()`, `Row::nullableStr()`, `Row::float()`.
- Produces:
  - `StatText::plain(string $stat): string`
  - `StatText::emotion(string $id): string`
  - `TreeExport::payload()` node tuples become **8** elements: `[id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe]`, where `stats` is `list<string>` already plain and `recipe` is `list<string>` already readable.

- [ ] **Step 1: Write the failing test**

Create `tests/Catalog/StatTextTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\View\StatText;
use PHPUnit\Framework\TestCase;

final class StatTextTest extends TestCase
{
    public function testAPipedLinkKeepsOnlyWhatThePlayerReads(): void
    {
        self::assertSame(
            '40% reduced Magnitude of Ignite on you',
            StatText::plain('40% reduced [BuffMagnitude|Magnitude] of [Ignite|Ignite] on you'),
        );
    }

    public function testALinkWithoutAPipeKeepsItsOnlyWord(): void
    {
        self::assertSame('50% increased Armour while Ignited', StatText::plain('50% increased [Armour] while [Ignite|Ignited]'));
    }

    public function testTextWithNoMarkupIsUntouched(): void
    {
        self::assertSame('You cannot be interrupted', StatText::plain('You cannot be interrupted'));
    }

    public function testAnUnclosedBracketIsLeftAloneRatherThanEaten(): void
    {
        self::assertSame('broken [Ignite', StatText::plain('broken [Ignite'));
    }

    public function testAnEmotionIdBecomesWords(): void
    {
        self::assertSame('Concentrated Liquid Suffering', StatText::emotion('ConcentratedLiquidSuffering'));
        self::assertSame('Liquid Despair', StatText::emotion('LiquidDespair'));
    }

    public function testAnEmotionIdThatIsAlreadyWordsIsUnchanged(): void
    {
        self::assertSame('Liquid Envy', StatText::emotion('Liquid Envy'));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit tests/Catalog/StatTextTest.php`
Expected: FAIL — `App\Catalog\View\StatText` does not exist.

- [ ] **Step 3: Write it**

Create `src/Catalog/View/StatText.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\View;

/**
 * Catalog text made readable.
 *
 * Both transforms happen server-side and once, so the canvas tooltip and the
 * server-rendered stats overview share an implementation rather than keeping
 * one each in PHP and JavaScript. The stored text keeps its markup: the link
 * targets may yet be useful, and discarding them at sync time would be harder
 * to undo than formatting at read time.
 */
final class StatText
{
    /**
     * `[Key|Display]` reads as `Display`, `[Key]` as `Key`. Anything that is
     * not a closed pair is left exactly as it is — a malformed line should look
     * wrong rather than quietly lose a word.
     */
    public static function plain(string $stat): string
    {
        return preg_replace_callback(
            '/\[([^\[\]|]+)(?:\|([^\[\]|]+))?\]/',
            static fn (array $m): string => '' !== ($m[2] ?? '') ? $m[2] : $m[1],
            $stat,
        ) ?? $stat;
    }

    /**
     * `ConcentratedLiquidSuffering` reads as `Concentrated Liquid Suffering`.
     *
     * This is a mechanical camel-case split, not a claim about GGG's own
     * wording. If their display names turn out to differ, this becomes a
     * curated map like `inventory_slots.yaml`.
     */
    public static function emotion(string $id): string
    {
        return trim((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $id));
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `ddev php bin/phpunit tests/Catalog/StatTextTest.php`
Expected: PASS, six tests.

- [ ] **Step 5: Write the failing payload test**

Add to `tests/Catalog/TreeExportTest.php` (read its existing setup first and reuse how it seeds `catalog_passive`):

```php
    public function testANodeShipsReadableStatsAndRecipe(): void
    {
        $this->seedPassive(
            id: 'e2e_detail',
            stats: ['40% reduced [BuffMagnitude|Magnitude] of [Ignite|Ignite] on you'],
            recipe: ['ConcentratedLiquidSuffering', 'LiquidDespair'],
        );

        $node = $this->nodeById('e2e_detail');

        self::assertCount(8, $node, 'the tuple is a contract: id, name, kind, ascendancy, x, y, stats, recipe');
        self::assertSame(['40% reduced Magnitude of Ignite on you'], $node[6]);
        self::assertSame(['Concentrated Liquid Suffering', 'Liquid Despair'], $node[7]);
    }
```

Adapt `seedPassive()`/`nodeById()` to the helpers that file already has; add them if it has none, keeping its existing style.

- [ ] **Step 6: Run it and watch it fail**

Run: `ddev php bin/phpunit tests/Catalog/TreeExportTest.php`
Expected: FAIL — the tuple has 6 elements.

- [ ] **Step 7: Widen the payload**

In `src/Catalog/TreeExport.php`, extend the docblock shape and select the two new columns, decoding and formatting each:

```php
     * @return array{nodes: list<array{0: string, 1: string, 2: string, 3: string|null, 4: float, 5: float, 6: list<string>, 7: list<string>}>, edges: list<array{0: string, 1: string}>, classes: list<array{id: string, start_node_id: string, ascendancies: list<array{id: string, name: string}>}>}
```

```php
        foreach ($this->db->fetchAllAssociative('SELECT id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe FROM catalog_passive') as $row) {
            $nodes[] = [
                Row::str($row, 'id'),
                Row::str($row, 'name'),
                Row::str($row, 'kind'),
                Row::nullableStr($row, 'ascendancy_key'),
                Row::float($row, 'pos_x'),
                Row::float($row, 'pos_y'),
                array_map(StatText::plain(...), self::strings($row, 'stats')),
                array_map(StatText::emotion(...), self::strings($row, 'recipe')),
            ];
        }
```

A JSON column of strings is read in three places by the end of this plan, so the reader belongs on `Row` — the existing "typed reads off a DBAL row" helper — rather than being copied into each caller. Add to `src/Catalog/Row.php`:

```php
    /**
     * A JSON column holding a list of strings. Anything that is not a string —
     * a malformed row, a schema that moved — is dropped rather than coerced,
     * matching how the other readers here behave.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    public static function jsonStrings(array $row, string $key): array
    {
        $decoded = json_decode(self::str($row, $key, '[]'), true);

        return array_values(array_filter(\is_array($decoded) ? $decoded : [], is_string(...)));
    }
```

and add a case to `tests/Catalog/` wherever `Row` is already covered — if it has no test, create `tests/Catalog/RowTest.php` with a case for a valid list, a malformed value, and a missing key.

In `TreeExport`, call `Row::jsonStrings($row, 'stats')` and `Row::jsonStrings($row, 'recipe')` in place of the two `self::strings(...)` calls shown above.

- [ ] **Step 8: Run the tests and the gate**

Run: `ddev php bin/phpunit tests/Catalog` then `ddev composer gate`
Expected: PASS, gate green.

- [ ] **Step 9: Confirm against the real catalog**

```bash
curl -sk https://poe-build-helper.ddev.site/catalog/tree.json | python3 -c "
import json,sys
n=json.load(sys.stdin)['nodes']
print('tuple len:', len(n[0]))
withr=[r for r in n if r[7]]
print('nodes with a recipe:', len(withr))
print('sample:', withr[0][0], withr[0][6][:1], withr[0][7])
print('any markup left?', any('[' in s for r in n for s in r[6]))
"
```
Expected: tuple len 8, **875** nodes with a recipe, readable emotion names, and `any markup left? False`.

- [ ] **Step 10: Commit**

```bash
git add src/Catalog tests/Catalog
git commit -m "$(cat <<'EOF'
feat(catalog): ship readable stats and recipes with the tree

Markup and camel case are resolved once, server-side, so the canvas
tooltip and the stats overview share one implementation instead of
keeping a copy each in PHP and JavaScript. Stored text keeps its markup.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 3: The stat summary

The rule that turns a pile of allocated ids into readable totals. Pure, no database, no framework — this is where the one piece of real thinking in slice A lives.

**Files:**
- Create: `src/Build/StatSummary.php`, `tests/Build/StatSummaryTest.php`

**Interfaces:**
- Consumes: nothing — plain arrays in, plain arrays out.
- Produces:
  - `StatSummary::family(string $passiveId): string` — the id with trailing digits and any trailing underscore removed
  - `StatSummary::of(array $statsById): list<array{key: string, nodes: int, summed: list<string>, listed: list<array{text: string, count: int}>}>` where `$statsById` is `array<string, list<string>>` mapping an allocated passive id to its already-plain stat lines, and the result is ordered by family key

- [ ] **Step 1: Write the failing tests**

Create `tests/Build/StatSummaryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\StatSummary;
use PHPUnit\Framework\TestCase;

final class StatSummaryTest extends TestCase
{
    public function testAFamilyIsTheIdWithoutItsNumber(): void
    {
        self::assertSame('area_attacks', StatSummary::family('area_attacks38'));
        self::assertSame('criticals', StatSummary::family('criticals7'));
    }

    public function testATrailingUnderscoreGoesWithTheNumber(): void
    {
        self::assertSame('melee', StatSummary::family('melee22_'));
    }

    public function testAnIdWithNoNumberIsItsOwnFamily(): void
    {
        self::assertSame('strength', StatSummary::family('strength'));
    }

    public function testRepeatedLinesAreSummed(): void
    {
        $summary = StatSummary::of([
            'criticals1' => ['10% increased Critical Hit Chance'],
            'criticals2' => ['10% increased Critical Hit Chance'],
            'criticals3' => ['10% increased Critical Hit Chance'],
        ]);

        self::assertCount(1, $summary);
        self::assertSame('criticals', $summary[0]['key']);
        self::assertSame(3, $summary[0]['nodes']);
        self::assertSame(['30% increased Critical Hit Chance'], $summary[0]['summed']);
        self::assertSame([], $summary[0]['listed']);
    }

    public function testDifferentNumbersOnTheSameLineStillSum(): void
    {
        $summary = StatSummary::of([
            'criticals1' => ['10% increased Critical Hit Chance'],
            'criticals2' => ['25% increased Critical Hit Chance'],
        ]);

        self::assertSame(['35% increased Critical Hit Chance'], $summary[0]['summed']);
    }

    public function testASignedValueKeepsItsSign(): void
    {
        $summary = StatSummary::of([
            'fire1' => ['+2% to Maximum Fire Resistance'],
            'fire2' => ['+1% to Maximum Fire Resistance'],
        ]);

        self::assertSame(['+3% to Maximum Fire Resistance'], $summary[0]['summed']);
    }

    public function testADecimalSums(): void
    {
        $summary = StatSummary::of([
            'regen1' => ['0.5% of Life Regenerated per second'],
            'regen2' => ['0.25% of Life Regenerated per second'],
        ]);

        self::assertSame(['0.75% of Life Regenerated per second'], $summary[0]['summed']);
    }

    public function testALineWithTwoNumbersIsListedNotSummed(): void
    {
        $summary = StatSummary::of([
            'hybrid1' => ['Adds 3 to 7 Physical Damage'],
            'hybrid2' => ['Adds 3 to 7 Physical Damage'],
        ]);

        self::assertSame([], $summary[0]['summed']);
        self::assertSame([['text' => 'Adds 3 to 7 Physical Damage', 'count' => 2]], $summary[0]['listed']);
    }

    public function testALineWithNoNumberIsListedNotSummed(): void
    {
        $summary = StatSummary::of([
            'keystone1' => ['You cannot be interrupted'],
        ]);

        self::assertSame([], $summary[0]['summed']);
        self::assertSame([['text' => 'You cannot be interrupted', 'count' => 1]], $summary[0]['listed']);
    }

    public function testOneNodeCanContributeSummableAndUnsummableLines(): void
    {
        $summary = StatSummary::of([
            'mixed1' => ['10% increased Armour', 'You cannot be interrupted'],
            'mixed2' => ['15% increased Armour'],
        ]);

        self::assertSame(['25% increased Armour'], $summary[0]['summed']);
        self::assertSame([['text' => 'You cannot be interrupted', 'count' => 1]], $summary[0]['listed']);
    }

    public function testFamiliesAreSeparateAndOrdered(): void
    {
        $summary = StatSummary::of([
            'zeal1' => ['10% increased Zeal'],
            'armour1' => ['10% increased Armour'],
        ]);

        self::assertSame(['armour', 'zeal'], array_column($summary, 'key'));
    }

    public function testNothingAllocatedIsAnEmptySummary(): void
    {
        self::assertSame([], StatSummary::of([]));
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `ddev php bin/phpunit tests/Build/StatSummaryTest.php`
Expected: FAIL — `App\Build\StatSummary` does not exist.

- [ ] **Step 3: Write it**

Create `src/Build/StatSummary.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build;

/**
 * What an allocated tree adds up to.
 *
 * Nodes group by id family — the id without its number — because the tree
 * names them that way: `criticals7` and `criticals38` are two of the ninety-nine
 * `criticals` nodes.
 *
 * Lines are summed by a rule holding no game knowledge at all. Replace every
 * number in a line with a placeholder; lines sharing that shape, and carrying
 * exactly one number, are the same statement at different magnitudes, so their
 * numbers add. Everything else is listed verbatim with a count.
 *
 * The rule can therefore never be wrong about a mechanic — it can only decline
 * to sum, and a declined line is shown in full. That visible failure is why
 * this needs no accuracy measurement: nothing it cannot read disappears.
 */
final class StatSummary
{
    private const string NUMBER = '[+-]?\d+(?:\.\d+)?';

    public static function family(string $passiveId): string
    {
        return (string) preg_replace('/\d+_?$/', '', $passiveId);
    }

    /**
     * @param array<string, list<string>> $statsById allocated passive id => its plain stat lines
     *
     * @return list<array{key: string, nodes: int, summed: list<string>, listed: list<array{text: string, count: int}>}>
     */
    public static function of(array $statsById): array
    {
        /** @var array<string, array{nodes: int, lines: list<string>}> $families */
        $families = [];

        foreach ($statsById as $id => $stats) {
            $key = self::family($id);
            $families[$key] ??= ['nodes' => 0, 'lines' => []];
            ++$families[$key]['nodes'];

            foreach ($stats as $line) {
                $families[$key]['lines'][] = $line;
            }
        }

        ksort($families);

        $summary = [];

        foreach ($families as $key => $family) {
            ['summed' => $summed, 'listed' => $listed] = self::fold($family['lines']);
            $summary[] = ['key' => $key, 'nodes' => $family['nodes'], 'summed' => $summed, 'listed' => $listed];
        }

        return $summary;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{summed: list<string>, listed: list<array{text: string, count: int}>}
     */
    private static function fold(array $lines): array
    {
        /** @var array<string, array{total: float, template: string, decimals: int, signed: bool}> $summable */
        $summable = [];
        /** @var array<string, int> $plain */
        $plain = [];

        foreach ($lines as $line) {
            $numbers = [];
            preg_match_all('/'.self::NUMBER.'/', $line, $numbers);

            if (1 !== \count($numbers[0])) {
                $plain[$line] = ($plain[$line] ?? 0) + 1;
                continue;
            }

            $value = $numbers[0][0];
            $shape = (string) preg_replace('/'.self::NUMBER.'/', '%s', $line);

            $summable[$shape] ??= [
                'total' => 0.0,
                'template' => $shape,
                'decimals' => 0,
                'signed' => str_starts_with($value, '+'),
            ];
            $summable[$shape]['total'] += (float) $value;
            $summable[$shape]['decimals'] = max(
                $summable[$shape]['decimals'],
                \strlen(substr((string) strrchr($value.'.', '.'), 1)),
            );
        }

        $summed = [];

        foreach ($summable as $entry) {
            $number = number_format($entry['total'], $entry['decimals'], '.', '');

            if ($entry['signed'] && !str_starts_with($number, '-')) {
                $number = '+'.$number;
            }

            $summed[] = sprintf($entry['template'], $number);
        }

        $listed = [];

        foreach ($plain as $text => $count) {
            $listed[] = ['text' => (string) $text, 'count' => $count];
        }

        return ['summed' => $summed, 'listed' => $listed];
    }
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `ddev php bin/phpunit tests/Build/StatSummaryTest.php`
Expected: PASS, twelve tests. If the decimal case fails, the decimal-width tracking is the thing to fix — do not relax the assertion.

- [ ] **Step 5: Run the gate and commit**

```bash
ddev composer gate
git add src/Build/StatSummary.php tests/Build/StatSummaryTest.php
git commit -m "$(cat <<'EOF'
feat(build): sum what an allocated tree adds up to

Nodes group by id family; lines sum only when they are the same statement
at different magnitudes, judged by shape rather than by any knowledge of
the game. A line it cannot read is listed in full rather than dropped, so
the rule can decline but never lie.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 4: Instilled Modifiers — storage, commands and search

The app models no items except uniques by name, so it does not describe the amulet. It records the result: which passives the player declared instilled.

**Files:**
- Modify: `src/Entity/Build.php`, `src/Catalog/CatalogSearch.php`, `src/Build/Edit/CommandFactory.php`
- Create: `migrations/Version20260912090100.php`, `src/Build/Edit/Command/AddInstilled.php`, `src/Build/Edit/Command/RemoveInstilled.php`, `src/Build/Edit/Handler/InstilledEditHandler.php`
- Test: `tests/Build/InstilledTest.php`, add to `tests/Catalog/CatalogSearchPassivesTest.php`

**Interfaces:**
- Consumes: `BuildEditor::apply(int $buildId, string $action, array $payload, callable $mutate): void`; `Build` accessors; `CatalogSearch`.
- Produces:
  - `Build::getInstilledPassives(): list<string>`, `Build::addInstilledPassive(string $id): void`, `Build::removeInstilledPassive(string $id): void`
  - actions `instilled.add` and `instilled.remove`, both with payload field `id`
  - `CatalogSearch::instillablePassives(string $query): list<array{id: string, name: string, kind: string, stats: string, recipe: list<string>}>`

- [ ] **Step 1: Write the failing test**

Create `tests/Build/InstilledTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\Command\AddInstilled;
use App\Build\Edit\Command\RemoveInstilled;
use App\Entity\Build;
use App\Entity\BuildEvent;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class InstilledTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BuildRepository $builds;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->builds = self::getContainer()->get(BuildRepository::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $this->entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    public function testDeclaringAndRemovingAnInstilledPassive(): void
    {
        $build = $this->build();
        $id = (int) $build->getId();

        $this->bus->dispatch(new AddInstilled($id, 'ignite_mitigation13'));
        $this->entityManager->clear();
        self::assertSame(['ignite_mitigation13'], $this->reload($build)->getInstilledPassives());

        $this->bus->dispatch(new RemoveInstilled($id, 'ignite_mitigation13'));
        $this->entityManager->clear();
        self::assertSame([], $this->reload($build)->getInstilledPassives());
    }

    public function testDeclaringTheSamePassiveTwiceKeepsOneEntry(): void
    {
        $build = $this->build();
        $id = (int) $build->getId();

        $this->bus->dispatch(new AddInstilled($id, 'ignite_mitigation13'));
        $this->bus->dispatch(new AddInstilled($id, 'ignite_mitigation13'));
        $this->entityManager->clear();

        self::assertSame(['ignite_mitigation13'], $this->reload($build)->getInstilledPassives());
    }

    public function testMoreThanOneMayBeDeclared(): void
    {
        $build = $this->build();
        $id = (int) $build->getId();

        $this->bus->dispatch(new AddInstilled($id, 'first_node'));
        $this->bus->dispatch(new AddInstilled($id, 'second_node'));
        $this->entityManager->clear();

        self::assertSame(['first_node', 'second_node'], $this->reload($build)->getInstilledPassives(), 'a unique amulet can carry more than one; the app does not police a limit it cannot verify');
    }

    public function testAnInstilledDeclarationNeverReachesTheExportedDocument(): void
    {
        $build = $this->build();
        $before = $build->toDocument();

        $this->bus->dispatch(new AddInstilled((int) $build->getId(), 'ignite_mitigation13'));
        $this->entityManager->clear();

        self::assertEquals($before, $this->reload($build)->toDocument());
    }

    private function reload(Build $build): Build
    {
        $reloaded = $this->builds->findOneByShareSlug($build->getShareSlug());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function build(): Build
    {
        $json = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($json);

        $build = $this->builds->create(new BuildDocumentReader()->read($json), '0.5.5');
        $this->entityManager->flush();

        return $build;
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit tests/Build/InstilledTest.php`
Expected: FAIL — `App\Build\Edit\Command\AddInstilled` does not exist.

- [ ] **Step 3: Add the column and its accessors**

Create `migrations/Version20260912090100.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which passives the player declared instilled. App-only: the game reconstructs the modifier from the amulet, so it never enters the exported document.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE build ADD instilled_passives JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE build DROP instilled_passives');
    }
}
```

In `src/Entity/Build.php`, beside the other planning fields:

```php
    /**
     * Passives the player declared as carrying an Instilled Modifier. App-only,
     * like the planning fields above: the `.build` format has no way to say a
     * passive was granted by an amulet rather than allocated, and the game does
     * not need telling — it reads the amulet.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $instilledPassives = [];

    /** @return list<string> */
    public function getInstilledPassives(): array
    {
        return $this->instilledPassives;
    }

    public function addInstilledPassive(string $id): void
    {
        if (\in_array($id, $this->instilledPassives, true)) {
            return;
        }

        $this->instilledPassives[] = $id;
        $this->touch();
    }

    public function removeInstilledPassive(string $id): void
    {
        $remaining = array_values(array_filter($this->instilledPassives, static fn (string $each): bool => $each !== $id));

        if ($remaining === $this->instilledPassives) {
            return;
        }

        $this->instilledPassives = $remaining;
        $this->touch();
    }
```

Run: `ddev composer db:migrate`

- [ ] **Step 4: Add the commands and their handler**

`src/Build/Edit/Command/AddInstilled.php` and `RemoveInstilled.php`, both `final readonly`, both `implements EditCommand`, each `public function __construct(public int $buildId, public string $id)`.

Create `src/Build/Edit/Handler/InstilledEditHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildEditor;
use App\Build\Edit\Command\AddInstilled;
use App\Build\Edit\Command\RemoveInstilled;
use App\Entity\Build;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An Instilled Modifier is granted by the amulet, not allocated on the tree, so
 * these edits touch only the build's own record and never its document.
 */
final class InstilledEditHandler
{
    public function __construct(private readonly BuildEditor $builds)
    {
    }

    #[AsMessageHandler]
    public function add(AddInstilled $command): void
    {
        $this->builds->apply($command->buildId, 'instilled.add', ['id' => $command->id], static function (Build $build) use ($command): void {
            $build->addInstilledPassive($command->id);
        });
    }

    #[AsMessageHandler]
    public function remove(RemoveInstilled $command): void
    {
        $this->builds->apply($command->buildId, 'instilled.remove', ['id' => $command->id], static function (Build $build) use ($command): void {
            $build->removeInstilledPassive($command->id);
        });
    }
}
```

In `src/Build/Edit/CommandFactory.php`, add to the match:

```php
            'instilled.add' => new AddInstilled($buildId, $this->string($payload, 'id')),
            'instilled.remove' => new RemoveInstilled($buildId, $this->string($payload, 'id')),
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `ddev php bin/phpunit tests/Build/InstilledTest.php`
Expected: PASS, four tests.

- [ ] **Step 6: Add the search, with its failing test first**

Add to `tests/Catalog/CatalogSearchPassivesTest.php`, reusing its existing seeding helper:

```php
    public function testOnlyNodesWithARecipeAreInstillable(): void
    {
        $this->seedPassives([
            ['id' => 'ignite_mitigation13', 'name' => 'Self Immolation', 'kind' => 'notable', 'recipe' => ['LiquidA', 'LiquidB', 'LiquidC']],
            ['id' => 'strength89', 'name' => 'Attribute', 'kind' => 'small', 'recipe' => []],
        ]);

        $results = $this->search->instillablePassives('a');

        self::assertSame(['ignite_mitigation13'], array_column($results, 'id'), 'a node with no recipe cannot be instilled');
        self::assertSame(['Liquid A', 'Liquid B', 'Liquid C'], $results[0]['recipe'], 'the cost is readable, like everywhere else');
    }

    public function testAnEmptyInstillableQueryAnswersNothing(): void
    {
        self::assertSame([], $this->search->instillablePassives(''));
    }
```

Extend that file's seeding helper to accept a `recipe` key, defaulting to `[]`, so the older tests keep working.

Run it, watch it fail, then add to `src/Catalog/CatalogSearch.php`:

```php
    /**
     * Passives that can carry an Instilled Modifier — the 875 that have a
     * Distilled Emotion recipe. Searching the other four thousand would offer
     * the player nodes the mechanic cannot reach.
     *
     * @return list<array{id: string, name: string, kind: string, stats: string, recipe: list<string>}>
     */
    public function instillablePassives(string $query): array
    {
        if ('' === trim($query)) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, kind, stats, recipe FROM catalog_passive
             WHERE JSON_LENGTH(recipe) > 0 AND (name LIKE ? OR id LIKE ?)
             ORDER BY name LIMIT 50',
            ['%'.$query.'%', '%'.$query.'%'],
        );

        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'id' => Row::str($row, 'id'),
                'name' => Row::str($row, 'name'),
                'kind' => Row::str($row, 'kind'),
                'stats' => implode(', ', array_map(StatText::plain(...), Row::jsonStrings($row, 'stats'))),
                'recipe' => array_map(StatText::emotion(...), Row::jsonStrings($row, 'recipe')),
            ];
        }

        return $results;
    }

```

Import `App\Catalog\View\StatText`. `Row::jsonStrings()` is added in Task 2; if `passives()` still decodes stats inline, switch it to that too rather than keeping two copies.

- [ ] **Step 7: Run everything and confirm against real data**

```bash
ddev php bin/phpunit tests/Build tests/Catalog
ddev composer gate
ddev php bin/console dbal:run-sql "SELECT COUNT(*) FROM catalog_passive WHERE JSON_LENGTH(recipe) > 0 AND name LIKE '%Molten%'"
```
Expected: tests pass, gate green, and the last query returns a small non-zero count — real instillable nodes exist to search.

- [ ] **Step 8: Commit**

```bash
git add src/Entity/Build.php src/Build/Edit src/Catalog/CatalogSearch.php migrations/Version20260912090100.php tests/
git commit -m "$(cat <<'EOF'
feat(build): declare which passives carry an Instilled Modifier

The app models no items beyond uniques by name, so it records the result
rather than the amulet. App-only, like the planning fields: the game reads
the modifier off the item, so it never enters the exported document.

Search is limited to the 875 nodes that have a Distilled Emotion recipe —
offering the other four thousand would suggest something the mechanic
cannot do.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 5: Interval modes

Two modes each for passives and skills, derived from the document and never stored — a stored mode could disagree with the document it describes.

**Files:**
- Create: `src/Build/Edit/IntervalMode.php`, `tests/Build/IntervalModeTest.php`, `src/Build/Edit/Command/SetAllPassiveIntervals.php`, `src/Build/Edit/Command/SetSkillIntervalCascading.php`
- Modify: `src/Build/Edit/DocumentEditor.php`, `src/Build/Edit/CommandFactory.php`, `src/Build/Edit/Handler/PassiveEditHandler.php`, `src/Build/Edit/Handler/SkillEditHandler.php`
- Test: `tests/Build/DocumentEditorIntervalsTest.php`

**Interfaces:**
- Consumes: `BuildDocument`; `DocumentEditor`'s existing private `levelInterval(int $from, int $to): array{int, int}`.
- Produces:
  - `IntervalMode::passivesAreUniform(BuildDocument $d): bool`
  - `IntervalMode::passiveSpan(BuildDocument $d): array{int, int}` — lowest `from`, highest `to`; `[1, 100]` when there are no passives
  - `IntervalMode::supportsFollowTheirSkills(BuildDocument $d): bool`
  - `DocumentEditor::setAllPassiveLevelIntervals(BuildDocument $d, int $from, int $to): BuildDocument`
  - `DocumentEditor::setSkillLevelIntervalCascading(BuildDocument $d, int $index, int $from, int $to): BuildDocument`
  - actions `passive.interval_all` (`from`, `to`) and `skill.interval_cascade` (`index`, `from`, `to`)

- [ ] **Step 1: Write the failing mode tests**

Create `tests/Build/IntervalModeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\IntervalMode;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class IntervalModeTest extends TestCase
{
    public function testPassivesSharingOneIntervalAreUniform(): void
    {
        self::assertTrue(IntervalMode::passivesAreUniform($this->passives([[1, 100], [1, 100]])));
    }

    public function testPassivesWithDifferentIntervalsAreNot(): void
    {
        self::assertFalse(IntervalMode::passivesAreUniform($this->passives([[1, 100], [34, 60]])));
    }

    public function testNoPassivesCountsAsUniform(): void
    {
        self::assertTrue(IntervalMode::passivesAreUniform(new BuildDocument(name: 'Build')), 'an empty tree opens in the simple mode');
    }

    public function testTheSpanIsTheWidest(): void
    {
        self::assertSame([12, 90], IntervalMode::passiveSpan($this->passives([[34, 60], [12, 55], [40, 90]])));
    }

    public function testTheSpanOfNothingIsTheWholeGame(): void
    {
        self::assertSame([1, 100], IntervalMode::passiveSpan(new BuildDocument(name: 'Build')));
    }

    public function testSupportsMatchingTheirSkillFollowIt(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [
            ['id' => 'a', 'level_interval' => [12, 100], 'support_skills' => [
                ['id' => 's1', 'level_interval' => [12, 100]],
            ]],
        ]);

        self::assertTrue(IntervalMode::supportsFollowTheirSkills($document));
    }

    public function testOneStaggeredSupportBreaksIt(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [
            ['id' => 'a', 'level_interval' => [12, 100], 'support_skills' => [
                ['id' => 's1', 'level_interval' => [12, 100]],
                ['id' => 's2', 'level_interval' => [34, 100]],
            ]],
        ]);

        self::assertFalse(IntervalMode::supportsFollowTheirSkills($document), 'a support slotted later is real planning and must keep its own control');
    }

    public function testSkillsMayDifferFromEachOther(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [
            ['id' => 'a', 'level_interval' => [12, 100], 'support_skills' => [['id' => 's1', 'level_interval' => [12, 100]]]],
            ['id' => 'b', 'level_interval' => [34, 100], 'support_skills' => [['id' => 's2', 'level_interval' => [34, 100]]]],
        ]);

        self::assertTrue(IntervalMode::supportsFollowTheirSkills($document), 'only supports collapse into their skill; skills stay independent');
    }

    public function testASkillWithNoSupportsCannotBreakIt(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [['id' => 'a', 'level_interval' => [12, 100]]]);

        self::assertTrue(IntervalMode::supportsFollowTheirSkills($document));
    }

    /**
     * @param list<array{int, int}> $intervals
     */
    private function passives(array $intervals): BuildDocument
    {
        return new BuildDocument(
            name: 'Build',
            passives: array_map(
                static fn (array $i): array => ['id' => 'n'.$i[0], 'level_interval' => $i, 'additional_text' => ''],
                $intervals,
            ),
        );
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `ddev php bin/phpunit tests/Build/IntervalModeTest.php`
Expected: FAIL — `App\Build\Edit\IntervalMode` does not exist.

- [ ] **Step 3: Write it**

Create `src/Build/Edit/IntervalMode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Interchange\BuildDocument;

/**
 * Which interval editor a build opens in.
 *
 * Derived from the document every time, never stored. A stored mode could
 * disagree with the document it claims to describe; a derived one cannot. The
 * cost is one harmless quirk — a build edited per-passive into uniform values
 * reopens flattened — and the data is identical either way.
 *
 * A game-exported file may carry genuinely different intervals per node, and
 * flattening those on import would destroy what the round-trip guarantee exists
 * to protect. So the file's own shape chooses the mode.
 */
final class IntervalMode
{
    private const int MIN_LEVEL = 0;
    private const int MAX_LEVEL = 100;

    public static function passivesAreUniform(BuildDocument $document): bool
    {
        $seen = null;

        foreach ($document->passives as $passive) {
            $interval = self::interval($passive);

            if (null === $seen) {
                $seen = $interval;
                continue;
            }

            if ($interval !== $seen) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{int, int} lowest from, highest to
     */
    public static function passiveSpan(BuildDocument $document): array
    {
        $from = null;
        $to = null;

        foreach ($document->passives as $passive) {
            [$start, $end] = self::interval($passive);
            $from = null === $from ? $start : min($from, $start);
            $to = null === $to ? $end : max($to, $end);
        }

        return [$from ?? 1, $to ?? self::MAX_LEVEL];
    }

    public static function supportsFollowTheirSkills(BuildDocument $document): bool
    {
        foreach ($document->skills as $skill) {
            $skillInterval = self::interval($skill);

            foreach ((array) ($skill['support_skills'] ?? []) as $support) {
                if (!\is_array($support)) {
                    continue;
                }

                /** @var array<string, mixed> $support */
                if (self::interval($support) !== $skillInterval) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{int, int}
     */
    private static function interval(array $entry): array
    {
        $interval = $entry['level_interval'] ?? null;

        if (!\is_array($interval) || 2 !== \count($interval)) {
            return [1, self::MAX_LEVEL];
        }

        $from = is_numeric($interval[0] ?? null) ? (int) $interval[0] : 1;
        $to = is_numeric($interval[1] ?? null) ? (int) $interval[1] : self::MAX_LEVEL;

        return [max(self::MIN_LEVEL, $from), min(self::MAX_LEVEL, $to)];
    }
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `ddev php bin/phpunit tests/Build/IntervalModeTest.php`
Expected: PASS, nine tests.

- [ ] **Step 5: Write the failing editor tests**

Create `tests/Build/DocumentEditorIntervalsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\DocumentEditor;
use App\Build\Edit\InvalidEditCommand;
use App\Build\InventorySlots;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class DocumentEditorIntervalsTest extends TestCase
{
    private DocumentEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new DocumentEditor(new InventorySlots(__DIR__.'/../../config/inventory_slots.yaml'));
    }

    public function testSettingAllPassiveIntervalsTouchesEveryOneAndKeepsOtherFields(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [
            ['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => 'keep me', 'weapon_set' => 2],
            ['id' => 'b', 'level_interval' => [34, 60], 'additional_text' => ''],
        ]);

        $changed = $this->editor->setAllPassiveLevelIntervals($document, 12, 90);

        self::assertSame([[12, 90], [12, 90]], array_column($changed->passives, 'level_interval'));
        self::assertSame('keep me', $changed->passives[0]['additional_text']);
        self::assertSame(2, $changed->passives[0]['weapon_set']);
    }

    public function testSettingAllPassiveIntervalsIsRangeChecked(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setAllPassiveLevelIntervals(new BuildDocument(name: 'Build', passives: [['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => '']]), 60, 34);
    }

    public function testCascadingASkillIntervalCarriesItsSupportsWithIt(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [
            ['id' => 'a', 'level_interval' => [1, 100], 'support_skills' => [
                ['id' => 's1', 'level_interval' => [1, 100]],
                ['id' => 's2', 'level_interval' => [34, 100]],
            ]],
            ['id' => 'b', 'level_interval' => [1, 100]],
        ]);

        $changed = $this->editor->setSkillLevelIntervalCascading($document, 0, 12, 90);

        self::assertSame([12, 90], $changed->skills[0]['level_interval']);
        self::assertSame([[12, 90], [12, 90]], array_column($changed->skills[0]['support_skills'], 'level_interval'));
        self::assertSame([1, 100], $changed->skills[1]['level_interval'], 'other skills are independent');
    }

    public function testCascadingASkillWithNoSupportsJustSetsTheSkill(): void
    {
        $document = new BuildDocument(name: 'Build', skills: [['id' => 'a', 'level_interval' => [1, 100]]]);

        $changed = $this->editor->setSkillLevelIntervalCascading($document, 0, 12, 90);

        self::assertSame(['id' => 'a', 'level_interval' => [12, 90]], $changed->skills[0], 'no empty support_skills key appears');
    }

    public function testCascadingAnAbsentSkillIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setSkillLevelIntervalCascading(new BuildDocument(name: 'Build'), 3, 12, 90);
    }
}
```

- [ ] **Step 6: Run them, watch them fail, then implement**

Run: `ddev php bin/phpunit tests/Build/DocumentEditorIntervalsTest.php` — expect FAIL on the undefined method.

Add to `src/Build/Edit/DocumentEditor.php`:

```php
    public function setAllPassiveLevelIntervals(BuildDocument $document, int $from, int $to): BuildDocument
    {
        $interval = $this->levelInterval($from, $to);
        $passives = $document->passives;

        foreach ($passives as $index => $passive) {
            $passives[$index]['level_interval'] = $interval;
        }

        return $this->withPassives($document, $passives);
    }

    /**
     * Sets a skill's interval and carries its supports with it. A skill setup
     * comes online together, which is what the flattened mode means; different
     * skills stay independent of one another.
     */
    public function setSkillLevelIntervalCascading(BuildDocument $document, int $index, int $from, int $to): BuildDocument
    {
        $skills = $document->skills;
        $this->mustHaveSkill($skills, $index);
        $interval = $this->levelInterval($from, $to);

        $skills[$index]['level_interval'] = $interval;
        $supports = $this->supportsOf($skills[$index]);

        if ([] !== $supports) {
            foreach ($supports as $position => $support) {
                $supports[$position]['level_interval'] = $interval;
            }

            $skills[$index]['support_skills'] = $supports;
        }

        return $this->withSkills($document, $skills);
    }
```

Run again: expect PASS, five tests.

- [ ] **Step 7: Wire the commands**

Create `src/Build/Edit/Command/SetAllPassiveIntervals.php` (`public int $buildId, public int $from, public int $to`) and `SetSkillIntervalCascading.php` (`public int $buildId, public int $index, public int $from, public int $to`), both `final readonly implements EditCommand`.

In `CommandFactory`:

```php
            'passive.interval_all' => new SetAllPassiveIntervals($buildId, $this->int($payload, 'from'), $this->int($payload, 'to')),
            'skill.interval_cascade' => new SetSkillIntervalCascading($buildId, $this->int($payload, 'index'), $this->int($payload, 'from'), $this->int($payload, 'to')),
```

In `PassiveEditHandler`, following the shape of its existing methods:

```php
    #[AsMessageHandler]
    public function setAllIntervals(SetAllPassiveIntervals $command): void
    {
        $payload = ['from' => $command->from, 'to' => $command->to];

        $this->builds->apply($command->buildId, 'passive.interval_all', $payload, function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->setAllPassiveLevelIntervals($d, $command->from, $command->to));
        });
    }
```

In `SkillEditHandler`, the same shape for `SetSkillIntervalCascading` → action `skill.interval_cascade`, payload `['index' => …, 'from' => …, 'to' => …]`, calling `setSkillLevelIntervalCascading`.

- [ ] **Step 8: Run the gate and commit**

```bash
ddev composer gate
git add src/Build/Edit tests/Build
git commit -m "$(cat <<'EOF'
feat(build): set level intervals once, for the tree or a skill setup

The mode is derived from the document and never stored, so it cannot
disagree with what it describes, and an imported file carrying genuinely
staggered intervals keeps its per-node editor instead of being flattened.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 6: Extract the searching, and add the three new searches

`EditorContext` is about to carry five search terms. Extracting them keeps it a context builder rather than a search coordinator, and gives the new searches one obvious home.

**Files:**
- Create: `src/Controller/EditorSearches.php`, `tests/Controller/EditorSearchesTest.php`
- Modify: `src/Controller/EditorContext.php`

**Interfaces:**
- Consumes: `CatalogSearch::search(?string $query, string $kind)`, `::passives(string $query)`, `::instillablePassives(string $query)`; `RequestStack`.
- Produces: `EditorSearches::all(): array{passiveQuery: string, passiveResults: list<array{id: string, name: string, kind: string, stats: string}>, gemQuery: string, gemResults: list<array<string, mixed>>, supportQuery: string, supportResults: list<array<string, mixed>>, uniqueQuery: string, uniqueResults: list<array<string, mixed>>, instilledQuery: string, instilledResults: list<array{id: string, name: string, kind: string, stats: string, recipe: list<string>}>}`. Query parameter names: `q`, `gem`, `support`, `unique`, `instilled`.

- [ ] **Step 1: Write the failing test**

Create `tests/Controller/EditorSearchesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\EditorSearches;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class EditorSearchesTest extends KernelTestCase
{
    public function testEveryTermIsEmptyWithoutARequest(): void
    {
        self::bootKernel();
        $searches = self::getContainer()->get(EditorSearches::class);

        $all = $searches->all();

        foreach (['passiveQuery', 'gemQuery', 'supportQuery', 'uniqueQuery', 'instilledQuery'] as $key) {
            self::assertSame('', $all[$key], $key.' must not fatal without a request');
        }
        foreach (['passiveResults', 'gemResults', 'supportResults', 'uniqueResults', 'instilledResults'] as $key) {
            self::assertSame([], $all[$key], $key.' must not query on an empty term');
        }
    }

    public function testATermIsReadFromTheQueryStringAndTrimmed(): void
    {
        self::bootKernel();
        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push(Request::create('/b/x/edit/y?support=  Fast Forward  '));

        self::assertSame('Fast Forward', self::getContainer()->get(EditorSearches::class)->all()['supportQuery']);
    }

    public function testATermFallsBackToTheRequestBodyForAnEditPost(): void
    {
        self::bootKernel();
        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push(Request::create('/b/x/edit/y/act', 'POST', ['instilled' => 'Molten']));

        self::assertSame('Molten', self::getContainer()->get(EditorSearches::class)->all()['instilledQuery']);
    }

    public function testAnEmptyQueryStringTermBeatsAStaleBodyTerm(): void
    {
        self::bootKernel();
        $stack = self::getContainer()->get(RequestStack::class);
        $request = Request::create('/b/x/edit/y?q=', 'POST', ['q' => 'stale']);
        $stack->push($request);

        self::assertSame('', self::getContainer()->get(EditorSearches::class)->all()['passiveQuery'], 'clearing the box must clear the term');
    }
}
```

`EditorSearches` must be reachable from the container in tests; if the compiler prunes it, note that its first real consumer arrives in this same task (`EditorContext`), which should keep it referenced — do **not** add `public: true` to `config/services.yaml`, that idiom was removed from this project deliberately.

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit tests/Controller/EditorSearchesTest.php`
Expected: FAIL — `App\Controller\EditorSearches` does not exist.

- [ ] **Step 3: Write it**

Create `src/Controller/EditorSearches.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogSearch;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Every search the editor offers, and the terms that drive them.
 *
 * Extracted from EditorContext once there were five: a context builder that
 * also coordinates searching is doing two jobs, and the searches share one
 * awkward rule about where a term comes from.
 */
final class EditorSearches
{
    public function __construct(
        private readonly CatalogSearch $search,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $request = $this->requests->getCurrentRequest();

        $passive = $this->term($request, 'q');
        $gem = $this->term($request, 'gem');
        $support = $this->term($request, 'support');
        $unique = $this->term($request, 'unique');
        $instilled = $this->term($request, 'instilled');

        return [
            'passiveQuery' => $passive,
            'passiveResults' => '' !== $passive ? $this->search->passives($passive) : [],
            'gemQuery' => $gem,
            'gemResults' => '' !== $gem ? $this->search->search($gem, 'all') : [],
            'supportQuery' => $support,
            'supportResults' => '' !== $support ? $this->search->search($support, 'support') : [],
            'uniqueQuery' => $unique,
            'uniqueResults' => '' !== $unique ? $this->search->search($unique, 'unique') : [],
            'instilledQuery' => $instilled,
            'instilledResults' => '' !== $instilled ? $this->search->instillablePassives($instilled) : [],
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
```

- [ ] **Step 4: Delegate from EditorContext**

In `src/Controller/EditorContext.php`, replace the `CatalogSearch` and `RequestStack` constructor arguments with `EditorSearches $searches`, delete the private `term()` method and the five search keys, and merge instead:

```php
        return array_merge([
            'build' => $build,
            'token' => $token,
            'document' => $build->toDocument(),
            'classes' => $this->entityManager->getRepository(CatalogClass::class)->findBy([], ['id' => 'ASC']),
            'slots' => $this->slots->all(),
            'events' => $this->events->timeline($build),
            'error' => $error,
        ], $this->searches->all());
    }
```

- [ ] **Step 5: Run the whole suite — this touches every editor template's variables**

Run: `ddev php bin/phpunit` then `ddev composer gate`
Expected: PASS. A failure in `BuildEditorControllerTest` means a template lost a variable; fix the wiring, not the test.

- [ ] **Step 6: Commit**

```bash
git add src/Controller tests/Controller/EditorSearchesTest.php
git commit -m "$(cat <<'EOF'
refactor(editor): give the editor's five searches one home

EditorContext was becoming a search coordinator as well as a context
builder. Extracting the searches also gives the support, unique and
instillable lookups somewhere obvious to live.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 7: The stats overview replaces the allocated list

The right-hand column stops being a list of ids and becomes what the tree actually gives you. This is also where the passive interval toggle lives, so both modes ship together in one template.

**Files:**
- Modify: `src/Catalog/CatalogSearch.php`, `src/Controller/EditorContext.php`, `templates/build/_nodes.html.twig`
- Test: `tests/Catalog/CatalogSearchPassivesTest.php`, `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: `StatSummary::of()`, `StatSummary::family()`, `IntervalMode::passivesAreUniform()`, `IntervalMode::passiveSpan()`, `Build::getInstilledPassives()`, actions `passive.interval`, `passive.interval_all`, `passive.deallocate`, `instilled.remove`.
- Produces:
  - `CatalogSearch::passivesByIds(array $ids): list<array{id: string, name: string, kind: string, stats: list<string>, recipe: list<string>}>` — plain stats, readable recipe, empty list for an empty input
  - `EditorContext::of()` gains `treeSummary`, `instilledNodes`, `allocatedByFamily`, `passivesUniform`, `passiveSpan`

- [ ] **Step 1: Write the failing lookup test**

Add to `tests/Catalog/CatalogSearchPassivesTest.php`:

```php
    public function testLookingUpNodesByIdReturnsReadableDetail(): void
    {
        $this->seedPassives([
            ['id' => 'criticals1', 'name' => 'Critical Damage', 'kind' => 'small', 'stats' => ['15% increased [CriticalDamageBonus|Critical Damage Bonus]'], 'recipe' => []],
            ['id' => 'ignite_mitigation13', 'name' => 'Self Immolation', 'kind' => 'notable', 'stats' => [], 'recipe' => ['ConcentratedLiquidSuffering']],
        ]);

        $found = array_column($this->search->passivesByIds(['ignite_mitigation13', 'criticals1']), null, 'id');

        self::assertSame(['15% increased Critical Damage Bonus'], $found['criticals1']['stats']);
        self::assertSame(['Concentrated Liquid Suffering'], $found['ignite_mitigation13']['recipe']);
    }

    public function testLookingUpNothingQueriesNothing(): void
    {
        self::assertSame([], $this->search->passivesByIds([]));
    }
```

Extend the seeding helper to accept `stats` as well, defaulting to `[]`.

- [ ] **Step 2: Run it, watch it fail, implement**

Run: `ddev php bin/phpunit tests/Catalog/CatalogSearchPassivesTest.php` — expect FAIL.

Add to `src/Catalog/CatalogSearch.php`:

```php
    /**
     * Detail for a known set of ids — what the stats overview and the instilled
     * list both need. Ids come from a build document, so the set is small.
     *
     * @param list<string> $ids
     *
     * @return list<array{id: string, name: string, kind: string, stats: list<string>, recipe: list<string>}>
     */
    public function passivesByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, kind, stats, recipe FROM catalog_passive WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::STRING],
        );

        $found = [];

        foreach ($rows as $row) {
            $found[] = [
                'id' => Row::str($row, 'id'),
                'name' => Row::str($row, 'name'),
                'kind' => Row::str($row, 'kind'),
                'stats' => array_map(StatText::plain(...), Row::jsonStrings($row, 'stats')),
                'recipe' => array_map(StatText::emotion(...), Row::jsonStrings($row, 'recipe')),
            ];
        }

        return $found;
    }
```

Import `Doctrine\DBAL\ArrayParameterType`. Run again: expect PASS.

- [ ] **Step 3: Feed the template**

In `src/Controller/EditorContext.php`, inject `CatalogSearch $catalog` and add to the returned array, before the merge:

```php
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
```

and the keys:

```php
            'treeSummary' => StatSummary::of($statsById),
            'allocatedByFamily' => $allocatedByFamily,
            'instilledNodes' => array_values(array_filter(array_map(static fn (string $id): ?array => $detail[$id] ?? null, $instilledIds))),
            'passivesUniform' => IntervalMode::passivesAreUniform($document),
            'passiveSpan' => IntervalMode::passiveSpan($document),
```

Reuse the `$document` local for the existing `'document'` key rather than calling `toDocument()` twice.

- [ ] **Step 4: Write the failing template tests**

Add to `tests/Controller/BuildEditorControllerTest.php`:

```php
    public function testTheOverviewGroupsAllocatedPassivesByFamily(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'criticals7']);
        $this->client->followRedirect();
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'criticals38']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-nodes', 'criticals');
        self::assertSelectorTextNotContains('#build-nodes', 'criticals7', 'the family is shown, not each id');
    }

    public function testTheOverviewOffersOneIntervalWhenTheyAreUniform(): void
    {
        $this->client->request('GET', $this->createBuild());

        self::assertSelectorExists('#build-nodes form[data-interval="all"]');
        self::assertSelectorNotExists('#build-nodes form[data-interval="one"]');
    }

    public function testStaggeredIntervalsOpenThePerPassiveEditor(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.interval', 'id' => 'strength89', 'from' => '34', 'to' => '60']);
        $this->client->followRedirect();

        self::assertSelectorExists('#build-nodes form[data-interval="one"]');
    }

    public function testSettingTheTreeWideIntervalTouchesEveryPassive(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.interval_all', 'from' => '12', 'to' => '90']);
        $this->client->followRedirect();

        self::assertSelectorExists('#build-nodes input[name="from"][value="12"]');
    }

    public function testInstilledNodesListSeparatelyFromTheTree(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'instilled.add', 'id' => 'strength89']);
        $this->client->followRedirect();

        self::assertSelectorExists('#build-instilled');
    }
```

The fixture build carries `strength89`, `melee22_` and `attributes70`, all with `[1, 100]` except `attributes70` at `[0, 100]` — check `tests/fixtures/build/valid-full.build` before relying on uniformity, and if the fixture is already staggered, allocate onto a build created from `{"name":"Uniform"}` instead.

- [ ] **Step 5: Run them, watch them fail, write the template**

Replace `templates/build/_nodes.html.twig`:

```twig
<section id="build-nodes">
    <h2>Passives</h2>

    <form action="{{ path('app_build_edit', {slug: build.shareSlug, token: token}) }}" method="get">
        <label for="passive-q">Find a node</label>
        <input type="search" id="passive-q" name="q" value="{{ passiveQuery }}" placeholder="name or id">
        <button type="submit">Search</button>
    </form>

    {% if passiveQuery is not empty %}
        {% set allocatedIds = document.passives|map(p => p.id) %}
        <ul>
            {% for node in passiveResults %}
                <li>
                    <strong>{{ node.name }}</strong> <code>{{ node.id }}</code>
                    {% if node.stats %}<span>{{ node.stats }}</span>{% endif %}
                    {% if node.id in allocatedIds %}
                        <span>allocated</span>
                    {% else %}
                        <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                            <input type="hidden" name="action" value="passive.allocate">
                            <input type="hidden" name="id" value="{{ node.id }}">
                            <input type="hidden" name="q" value="{{ passiveQuery }}">
                            <button type="submit">Allocate</button>
                        </form>
                    {% endif %}
                </li>
            {% else %}
                <li>Nothing matches “{{ passiveQuery }}”.</li>
            {% endfor %}
        </ul>
    {% endif %}

    <h3>Levels</h3>
    {% if passivesUniform %}
        <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post" data-interval="all">
            <input type="hidden" name="action" value="passive.interval_all">
            <input type="hidden" name="q" value="{{ passiveQuery }}">
            <label for="tree-from">The whole tree, from</label>
            <input type="number" id="tree-from" name="from" min="0" max="100" value="{{ passiveSpan[0] }}">
            <label for="tree-to">to</label>
            <input type="number" id="tree-to" name="to" min="0" max="100" value="{{ passiveSpan[1] }}">
            <button type="submit">Set</button>
        </form>
        <p>Every passive shares one range. Give one of them its own below to plan them separately.</p>
    {% else %}
        <p>These passives have different ranges, so each is set on its own. Give them all the same range to go back to one control.</p>
    {% endif %}

    <h3>What the tree gives ({{ document.passives|length }} passives)</h3>
    <ul>
        {% for family in treeSummary %}
            <li>
                <strong>{{ family.key }}</strong> <span>{{ family.nodes }} node{{ family.nodes == 1 ? '' : 's' }}</span>
                <ul>
                    {% for line in family.summed %}<li>{{ line }}</li>{% endfor %}
                    {% for line in family.listed %}<li>{{ line.text }}{% if line.count > 1 %} <span>×{{ line.count }}</span>{% endif %}</li>{% endfor %}
                </ul>

                {% if not passivesUniform %}
                    {% for passive in allocatedByFamily[family.key]|default([]) %}
                        <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post" data-interval="one">
                            <input type="hidden" name="action" value="passive.interval">
                            <input type="hidden" name="id" value="{{ passive.id }}">
                            <input type="hidden" name="q" value="{{ passiveQuery }}">
                            <label for="from-{{ passive.id }}"><code>{{ passive.id }}</code> from</label>
                            <input type="number" id="from-{{ passive.id }}" name="from" min="0" max="100" value="{{ passive.level_interval[0] ?? 1 }}">
                            <label for="to-{{ passive.id }}">to</label>
                            <input type="number" id="to-{{ passive.id }}" name="to" min="0" max="100" value="{{ passive.level_interval[1] ?? 100 }}">
                            <button type="submit">Set</button>
                        </form>
                    {% endfor %}
                {% endif %}
            </li>
        {% else %}
            <li>No passives yet. Search above, or click the tree.</li>
        {% endfor %}
    </ul>

    <h3 id="build-instilled">Instilled ({{ instilledNodes|length }})</h3>
    <p>Granted by the amulet. These cost no passive point.</p>
    <ul>
        {% for node in instilledNodes %}
            <li>
                <strong>{{ node.name }}</strong> <code>{{ node.id }}</code>
                <ul>{% for line in node.stats %}<li>{{ line }}</li>{% endfor %}</ul>
                {% if node.recipe %}<span>{{ node.recipe|join(', ') }}</span>{% endif %}
                <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                    <input type="hidden" name="action" value="instilled.remove">
                    <input type="hidden" name="id" value="{{ node.id }}">
                    <button type="submit">Remove</button>
                </form>
            </li>
        {% else %}
            <li>None declared. Add one beside the amulet.</li>
        {% endfor %}
    </ul>
</section>
```

Note the per-node ids use the passive id, not a loop index, so they stay unique across families.

- [ ] **Step 6: Lint, test, verify over HTTP**

```bash
ddev php bin/console lint:twig templates/
ddev php bin/phpunit tests/Controller tests/Catalog
ddev composer gate
```

Then create a build, allocate two `criticals` nodes through `/act`, and confirm by curl that `#build-nodes` shows one `criticals` group with a summed line rather than two ids.

- [ ] **Step 7: Commit**

```bash
git add src/Catalog/CatalogSearch.php src/Controller/EditorContext.php templates/build/_nodes.html.twig tests/
git commit -m "$(cat <<'EOF'
feat(editor): show what the tree gives instead of a list of ids

Allocated passives group by id family with their stat lines summed where
the lines are the same statement at different magnitudes. Instilled
modifiers list separately because they cost no passive point.

The level-interval control follows the document: one range while they are
uniform, per-node once they are not.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 8: Skills — support autocomplete and the cascading interval

Closes one of iteration 3's gaps against the spec (supports are typed by hand today) and adds the skills half of the interval modes.

**Files:**
- Modify: `templates/build/_skills.html.twig`
- Modify: `src/Controller/EditorContext.php` (one key)
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: `supportQuery`/`supportResults` from `EditorSearches`; `IntervalMode::supportsFollowTheirSkills()`; actions `support.add`, `skill.interval_cascade`, `skill.interval`, `support.interval`.
- Produces: `EditorContext::of()` gains `supportsFollow`.

- [ ] **Step 1: Add the mode key**

In `EditorContext::of()`, beside the other derived keys:

```php
            'supportsFollow' => IntervalMode::supportsFollowTheirSkills($document),
```

- [ ] **Step 2: Write the failing tests**

```php
    public function testASupportCanBeFoundBySearchRatherThanTyped(): void
    {
        $edit = $this->createBuild();

        $this->client->request('GET', $edit.'?support=Fast');

        self::assertSelectorExists('#build-skills input[name="support"]');
        self::assertSelectorExists('#build-skills form input[name="action"][value="support.add"]');
    }

    public function testASkillIntervalCascadesToItsSupportsWhenTheyFollow(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'skill.interval_cascade', 'index' => '0', 'from' => '12', 'to' => '90']);
        $this->client->followRedirect();

        self::assertSelectorExists('#build-skills input[name="from"][value="12"]');
    }
```

The fixture's first skill has two supports at `[12, 100]` and `[34, 100]`, so it opens in per-support mode; the cascade test still applies because the action does not depend on the mode.

- [ ] **Step 3: Run them, watch them fail, edit the template**

In `templates/build/_skills.html.twig`:

1. Replace the hand-typed support input with a search, mirroring the gem search already in that file — a GET form on `app_build_edit` with `name="support"`, then results each posting `support.add` with `skill_index` and `support_id`, carrying hidden `q`, `gem` and `support` terms.
2. Where a skill's interval form is rendered, switch the action when supports follow their skill:

```twig
    {% set skillAction = supportsFollow ? 'skill.interval_cascade' : 'skill.interval' %}
```

and use `{{ skillAction }}` in that form's hidden `action`. When `supportsFollow` is true, render each support's interval as text rather than number inputs, with a line saying the supports follow the skill's range; when false, keep the existing per-support forms.

Keep every `id` unique — supports are nested, so compose both indices as that file already does.

- [ ] **Step 4: Lint, test, commit**

```bash
ddev php bin/console lint:twig templates/
ddev php bin/phpunit tests/Controller
ddev composer gate
git add templates/build/_skills.html.twig src/Controller/EditorContext.php tests/Controller/BuildEditorControllerTest.php
git commit -m "$(cat <<'EOF'
feat(editor): search for support gems, and set a skill setup's range at once

Supports were typed as full Metadata paths by hand, which the spec never
asked for. When every support already matches its skill, the skill's range
carries them with it; a staggered plan keeps its per-support controls.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 9: Equipment — unique autocomplete and the Instilled Modifier field

Closes the second gap, and gives the Instilled declaration its home beside the amulet, where a Distilled Emotion is actually applied.

**Files:**
- Modify: `templates/build/_slots.html.twig`
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: `uniqueQuery`/`uniqueResults` and `instilledQuery`/`instilledResults` from `EditorSearches`; actions `slot.set`, `instilled.add`.
- Produces: nothing new.

- [ ] **Step 1: Write the failing tests**

```php
    public function testAUniqueCanBeFoundBySearch(): void
    {
        $this->client->request('GET', $this->createBuild().'?unique=Astra');

        self::assertSelectorExists('#build-slots input[name="unique"]');
    }

    public function testTheInstilledFieldSitsWithTheAmulet(): void
    {
        $this->client->request('GET', $this->createBuild());

        self::assertSelectorExists('#build-slots input[name="instilled"]');
    }

    public function testAnInstillableNodeCanBeDeclaredFromTheAmulet(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'instilled.add', 'id' => 'ignite_mitigation13']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-instilled', 'Instilled');
    }
```

- [ ] **Step 2: Run them, watch them fail, edit the template**

In `templates/build/_slots.html.twig`:

1. Add a unique search — a GET form with `name="unique"` — whose results fill the slot's `unique_name` input. Simplest honest version: each result posts `slot.set` for a chosen `inventory_id`. Render the search once above the slot list and let each result offer the fourteen slots via a `<select name="inventory_id">` populated from `slots`, so no JavaScript is needed.
2. Beneath the `Amulet1` row, add the Instilled Modifier search: a GET form with `name="instilled"`, and results each posting `instilled.add` with `id`. Show each result's name, id, plain stats and its readable recipe, since the recipe is what the player has to go and collect.

Carry hidden `q`, `gem`, `support`, `unique` and `instilled` fields on every `/act` form in this file, as the other editor forms do, so a search survives an edit.

- [ ] **Step 3: Lint, test, verify over HTTP, commit**

```bash
ddev php bin/console lint:twig templates/
ddev php bin/phpunit tests/Controller
ddev composer gate
```

Then by curl: search `?instilled=Molten`, confirm a result appears with its three emotions; declare it; confirm it appears under `#build-instilled` in the overview.

```bash
git add templates/build/_slots.html.twig tests/Controller/BuildEditorControllerTest.php
git commit -m "$(cat <<'EOF'
feat(editor): search uniques, and declare Instilled Modifiers at the amulet

A unique name was free text, so a typo reached the exported file. The
Instilled field sits with the amulet because that is where a Distilled
Emotion is applied, and shows each node's recipe — the emotions are what
the player has to go and collect.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 10: `game_version` becomes editable

The last of iteration 3's three gaps. The spec lists it as a header field; it renders as prose today and has no setter.

**Files:**
- Modify: `src/Entity/Build.php`, `src/Build/Edit/Handler/HeaderEditHandler.php`, `templates/build/_header.html.twig`
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: action `header.set`.
- Produces: `Build::setGameVersion(string $gameVersion): void`; `header.set` accepts field `game_version`.

- [ ] **Step 1: Write the failing test**

```php
    public function testTheGameVersionCanBeChanged(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'game_version', 'value' => '0.6.0']);
        $this->client->followRedirect();

        self::assertSelectorExists('input[name="value"][value="0.6.0"]');
    }

    public function testAnEmptyGameVersionIsRefused(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'game_version', 'value' => ''], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(422);
    }
```

A build must always claim a version — rules and catalog data are version-bound, so an empty one would make every later check meaningless.

- [ ] **Step 2: Run them, watch them fail, implement**

In `src/Entity/Build.php`:

```php
    public function setGameVersion(string $gameVersion): void
    {
        $this->gameVersion = $gameVersion;
        $this->touch();
    }
```

In `HeaderEditHandler::set()`, add a match arm — note it uses `$value`, not `$optional`, because this field may not be emptied:

```php
                'game_version' => $build->setGameVersion('' === $value ? throw InvalidEditCommand::noSuchEntry('game version') : $value),
```

In `templates/build/_header.html.twig`, replace the `<p>Game version …</p>` line with a `header.field(...)` call for `game_version`.

- [ ] **Step 3: Lint, test, commit**

```bash
ddev php bin/console lint:twig templates/
ddev php bin/phpunit tests/Controller
ddev composer gate
git add src/Entity/Build.php src/Build/Edit/Handler/HeaderEditHandler.php templates/build/_header.html.twig tests/Controller/BuildEditorControllerTest.php
git commit -m "$(cat <<'EOF'
feat(editor): let a build say which game version it targets

The spec lists game_version as a header field; it was display-only. It
cannot be cleared — rules and catalog data are version-bound, so a build
with no version makes every later check meaningless.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 11: The node hover

The last piece, and the one that needs a browser to judge. The canvas already has the data — Task 2 put stats and recipes in the payload.

**Files:**
- Modify: `assets/controllers/tree_controller.js`, `templates/build/_tree.html.twig`, `assets/styles/app.css`
- Test: `tests/e2e/editor.spec.js`

**Interfaces:**
- Consumes: the 8-element node tuple from `/catalog/tree.json`; `nearestNode()` and the camera helpers already imported by the controller.
- Produces: a `tooltip` Stimulus target in `_tree.html.twig`.

- [ ] **Step 1: Add the tooltip element**

In `templates/build/_tree.html.twig`, inside the section, after the canvas:

```twig
    <div class="tree-tooltip" data-tree-target="tooltip" hidden></div>
```

and in `assets/styles/app.css`:

```css
.tree-tooltip { background: #17171d; border: 1px solid var(--line); color: #e8e8ea; font-size: 0.85rem; max-width: 22rem; padding: 0.5rem 0.6rem; pointer-events: none; position: absolute; z-index: 2; }
.tree-tooltip h3 { font-size: 0.9rem; margin: 0 0 0.25rem; }
.tree-tooltip ul { margin: 0; padding-left: 1rem; }
.tree-tooltip .cost { color: #c7a54a; display: block; margin-top: 0.35rem; }
```

The section needs `position: relative` for the absolute placement to be relative to the canvas.

- [ ] **Step 2: Keep the detail when loading the tree**

In `tree_controller.js`, the node loop currently discards the last two tuple elements. Keep them:

```js
        for (const [id, name, kind, ascendancy, x, y, stats, recipe] of tree.nodes) {
            if (ascendancy && ascendancy !== this.ascendancy) {
                continue;
            }

            this.nodes.set(id, { id, name, kind, x, y, stats: stats ?? [], recipe: recipe ?? [] });
        }
```

- [ ] **Step 3: Show it on hover, instead of the `title` attribute**

Replace the `title`-setting branch of `pointermove` with a tooltip render, and hide it when nothing is under the cursor:

```js
    showTooltip(node, offsetX, offsetY) {
        const tip = this.tooltipTarget;

        if (!node) {
            tip.hidden = true;

            return;
        }

        const stats = node.stats.map((line) => `<li>${escapeHtml(line)}</li>`).join('');
        const cost = node.recipe.length
            ? `<span class="cost">Instil with ${node.recipe.map(escapeHtml).join(', ')}</span>`
            : '';

        tip.innerHTML = `<h3>${escapeHtml(node.name)}</h3><ul>${stats}</ul>${cost}`;
        tip.style.left = `${offsetX + 14}px`;
        tip.style.top = `${offsetY + 14}px`;
        tip.hidden = false;
    }
```

with, at the bottom of the module:

```js
function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
```

Node names and stats come from the catalog, not from a user, but this is HTML being built from data — escaping it costs four lines and removes the question entirely.

Call `this.showTooltip(node, event.offsetX, event.offsetY)` where the hover branch resolves a node, add `'tooltip'` to `static targets`, and hide the tooltip on `pointerdown` so it does not sit over a drag.

- [ ] **Step 4: Extend the smoke test**

In `tests/e2e/editor.spec.js`, add a second test that seeds a node with stats and a recipe, hovers it, and asserts the tooltip shows the node's name and its emotions. The existing test's seeding command and click arithmetic show how to place a node at a known screen position — hover the same point rather than clicking it.

- [ ] **Step 5: Run everything**

```bash
ddev php bin/console lint:twig templates/
ddev npm run test:js
ddev composer gate
```

- [ ] **Step 6: Look at it in a browser — this task is not done without it**

Start from a build with a class selected and a synced catalog. Check: hovering a small node shows its name and stat lines; hovering a notable with a recipe also shows its three emotions; hovering nothing hides the tooltip; the tooltip does not flicker while panning; it does not fall off the right edge of the canvas at the far side of the tree.

Report what you saw, and say plainly if the last two are wrong — a tooltip that escapes its container is a real defect, not a polish item.

- [ ] **Step 7: Commit**

```bash
git add assets templates/build/_tree.html.twig tests/e2e/editor.spec.js
git commit -m "$(cat <<'EOF'
feat(editor): show a node's effect and instil cost on hover

Replaces the title attribute, which has a browser-imposed delay of about a
second and cannot format several lines. The data already ships with the
tree, so the tooltip costs no request.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

## Notes for the executor

**Spec coverage.** Interval modes → Tasks 5, 7, 8. Node hover → Tasks 1, 2, 11. Stats overview → Tasks 3, 7. Instilled Modifiers → Tasks 4, 7, 9. The three iteration-3 gaps → Tasks 8 (supports), 9 (uniques), 10 (`game_version`). Catalog and payload → Tasks 1, 2.

**Deliberately not here.** Slice B: canvas-only editing, search-becomes-highlight, and the four allocation-legality rules. Until slice B lands the search box keeps its allocate buttons — only the per-node remove controls go, with the list they lived on. `keystones_in_radius` and `unlock_constraint` are not added, and the tuple stops at eight.

**Ordering constraints that are not obvious:**
- Task 2 depends on Task 1's column existing; Task 7 depends on Task 4's `instilled_passives` column and on Task 3's `StatSummary`.
- Task 6 changes `EditorContext`'s constructor and touches every editor template's variables. Run the **whole** suite after it, not just the controller tests.
- Tasks 7–10 all add hidden search-term fields to `/act` forms. If a search empties after an edit in one area, that area's forms are missing a hidden field.

**Two things only a person can judge**, both in Task 11: whether the tooltip reads well, and whether it stays inside the canvas at the tree's edges. Everything else in this plan is covered by the gate.

**A standing trap.** Three times in iteration 3 an implementer answered a failing assertion by changing production markup. If an assertion cannot see an `<input value>`, assert on the attribute.
