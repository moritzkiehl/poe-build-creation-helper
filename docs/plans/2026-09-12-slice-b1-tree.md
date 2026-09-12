# Slice B1 — The Tree Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the passive tree editable only from the canvas, with every allocation checked against four legality rules on the server, deallocation cascading as one undoable event, and weapon-set passives allocated, coloured and summed as three separate groups.

**Architecture:** A new `src/Build/Tree/` namespace holds the domain: `PassiveGraph` (adjacency and legality data, read once per request), `Allocation` (the build's passives as id → `WeaponSet`), and `AllocationRules` (the four rules and the cascade). The rules are pure and unit-tested against hand-built graphs; the command handler calls them and refuses illegal commands with the existing `InvalidEditCommand`. The canvas receives two extra tuple fields and renders three allocation colours; view state (allocation mode, summary view) rides the query-parameter mechanism already used by the interval toggle.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine DBAL/ORM, MariaDB 11.4, Twig, symfony/messenger, AssetMapper + Stimulus + Turbo, PHPUnit 13, vitest, Playwright.

**Spec:** `docs/specs/2026-09-09-poe2-build-helper-design.md`, section "Iteration 3 refinements (2026-09-12)" — subsections "The tree is edited from the canvas only", "Allocation is checked, not merely drawn", "Deallocation cascades, as one event", "Weapon sets are allocated, coloured and summed separately". Read those four before Task 1.

## Global Constraints

- **Everything runs inside DDEV.** There is no PHP, Composer, MariaDB or usable Node on the host — the host `npm` is a broken Windows shim. Every command in this plan is prefixed `ddev`. Never run a bare `php`, `composer` or `npm`.
- **The repository is public.** Real upstream/GGG catalog data must never be committed. Every test fixture is invented. `var/catalog/` and `var/sample/` are gitignored and stay that way.
- **PHPStan runs at level max.** Forbidden escapes: `treatPhpDocTypesAsCertain: false`, a baseline, `@phpstan-ignore`, loosening a production type to satisfy the analyser, or deleting a test.
- **Never change production markup to make a test assertion match.** Fix the assertion. (`.claude/mistakes.md`, repeated ×2.)
- **Every test you add or touch must be one you have seen fail.** Revert the production line, run with `--filter`, see red, restore, see green. A test whose failure you did not witness does not count as written.
- **App-only fields never reach the exported file.** `class_key`, `target_level`, `note`, `archetype_key`, `instilled_passives` must never appear in `BuildDocument`, the `document` column, or export output. `weapon_set` is the opposite — it is a real `.build` field and must round-trip.
- **`weapon_set` on the wire:** absent means shared. The value `0` never appears in any real file and the app never writes it. Only `1` and `2` are written.
- **Prefer PSR-compliant and already-installed Symfony packages** over a hand-rolled equivalent (`CLAUDE.md`).
- **Comments and identifiers in English.**
- The gate is `ddev composer gate` (php-cs-fixer, PHPStan, PHPUnit, vitest, Playwright). It must be green at every commit.

---

## File Structure

**Created:**
- `src/Build/Tree/WeaponSet.php` — the `0|1|2` enum, plus reading/writing the wire form.
- `src/Build/Tree/Allocation.php` — the build's passives as id → `WeaponSet`, built from a `BuildDocument`.
- `src/Build/Tree/TreeContext.php` — start node, ascendancy, declared jewel keystone.
- `src/Build/Tree/PassiveGraph.php` — adjacency, keystone radii, unlock constraints, keystone set. One query batch per request.
- `src/Build/Tree/AllocationRules.php` — the four rules, the legal-target set, and the cascade.
- `migrations/Version20260912100000.php` — two columns on `catalog_passive`, one on `catalog_sync`.
- `tests/Build/Tree/AllocationRulesTest.php`, `tests/Build/Tree/AllocationTest.php`, `tests/Build/Tree/PassiveGraphTest.php`.
- `tests/js/tree_renderer.test.js`.

**Modified:**
- `src/Catalog/PassiveTreeNormalizer.php`, `src/Catalog/NormalizedTree.php` — keep two discarded fields.
- `src/Catalog/PassiveTreeSync.php` — write them; stop skipping re-import when the row shape changed.
- `src/Catalog/TreeExport.php` — tuple 8 → 10.
- `src/Entity/CatalogPassive.php`, `src/Entity/CatalogSync.php` — new columns.
- `src/Build/Edit/DocumentEditor.php` — weapon set on allocate, cascade on deallocate.
- `src/Build/Edit/Command/AllocatePassive.php`, `.../DeallocatePassive.php`, `src/Build/Edit/CommandFactory.php`, `src/Build/Edit/Handler/PassiveEditHandler.php`.
- `src/Controller/EditorContext.php`, `src/Controller/EditorSearches.php`, `src/Controller/BuildEditorController.php`.
- `templates/build/_state.html.twig`, `_nodes.html.twig`, `_tree.html.twig`, `_search_state.html.twig`, `_history.html.twig`.
- `assets/lib/tree_renderer.js`, `assets/controllers/tree_controller.js`.
- `tests/e2e/editor.spec.js`.

---

### Task 1: The normalizer keeps the two legality fields

**Files:**
- Modify: `src/Catalog/PassiveTreeNormalizer.php`
- Modify: `src/Catalog/NormalizedTree.php`
- Test: `tests/Catalog/PassiveTreeNormalizerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PassiveNode` gains `keystones_in_radius: list<string>` and `unlock_constraint: array{nodes: list<string>, ascendancy: string|null}|null`. Both are resolved from upstream **tree-keys** (integers, the `nodes` object's keys) to **stored string ids** through the `$allocatable` map the normalizer already builds. A tree-key that does not resolve is dropped.

Upstream shapes, measured against the real export 2026-09-12 (5153 nodes): `keystonesInRadius` is a list of integer tree-keys and appears on 1573 nodes. `unlockConstraint` appears on 200 nodes and is always `{"nodes": [<int>, ...], "ascendancy": "<string>"}` or `{"nodes": [<int>, ...]}` — 197 with `ascendancy: "Druid1"` and one gate node, 3 with no ascendancy and two or three gate nodes.

- [ ] **Step 1: Write the failing test**

Add to `tests/Catalog/PassiveTreeNormalizerTest.php`:

```php
public function testItResolvesLegalityFieldsFromTreeKeysToStoredIds(): void
{
    $json = json_encode([
        'nodes' => [
            'root' => ['id' => 'root'],
            '100' => ['id' => 'keystone_a', 'name' => 'Keystone A', 'isKeystone' => true, 'x' => 0, 'y' => 0],
            '200' => ['id' => 'gate_a', 'name' => 'Gate A', 'isNotable' => true, 'x' => 1, 'y' => 1],
            '300' => [
                'id' => 'covered_a',
                'name' => 'Covered A',
                'x' => 2,
                'y' => 2,
                'keystonesInRadius' => [100, 999],
                'unlockConstraint' => ['nodes' => [200, 999], 'ascendancy' => 'Druid1'],
            ],
        ],
    ], \JSON_THROW_ON_ERROR);

    $tree = new PassiveTreeNormalizer()->normalize($json);
    $byId = array_column($tree->nodes, null, 'id');

    // 999 is not an allocatable node, so it is dropped rather than carried
    // through as an unresolvable key.
    self::assertSame(['keystone_a'], $byId['covered_a']['keystones_in_radius']);
    self::assertSame(['gate_a'], $byId['covered_a']['unlock_constraint']['nodes']);
    self::assertSame('Druid1', $byId['covered_a']['unlock_constraint']['ascendancy']);
    self::assertSame([], $byId['keystone_a']['keystones_in_radius']);
    self::assertNull($byId['keystone_a']['unlock_constraint']);
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit --filter testItResolvesLegalityFieldsFromTreeKeysToStoredIds`
Expected: FAIL — `Undefined array key "keystones_in_radius"`.

- [ ] **Step 3: Resolve the fields after the allocatable map is complete**

The map is only complete after the node loop, so resolution is a second pass. In `normalize()`, replace the single loop's node-building with a loop that also stashes the raw fields, then resolve:

```php
$nodes[] = [
    'id' => $id,
    'name' => \is_string($node['name'] ?? null) ? $node['name'] : '',
    'kind' => $this->kind($node),
    'ascendancy_key' => \is_string($node['ascendancyId'] ?? null) ? $node['ascendancyId'] : null,
    'pos_x' => is_numeric($node['x'] ?? null) ? (float) $node['x'] : 0.0,
    'pos_y' => is_numeric($node['y'] ?? null) ? (float) $node['y'] : 0.0,
    'stats' => array_values(array_filter((array) ($node['stats'] ?? []), is_string(...))),
    'recipe' => array_values(array_filter((array) ($node['recipe'] ?? []), is_string(...))),
    'keystones_in_radius' => [],
    'unlock_constraint' => null,
];
```

After the loop, before `return new NormalizedTree(...)`:

```php
foreach ($nodes as $index => $node) {
    $raw = $rawNodes[array_search($node['id'], $allocatable, true)] ?? null;
    if (!\is_array($raw)) {
        continue;
    }

    $nodes[$index]['keystones_in_radius'] = $this->resolveKeys($raw['keystonesInRadius'] ?? null, $allocatable);
    $nodes[$index]['unlock_constraint'] = $this->unlockConstraint($raw['unlockConstraint'] ?? null, $allocatable);
}
```

`array_search` over 5153 entries per node is quadratic and unacceptable. Build the reverse index once, immediately after the node loop, and use it instead:

```php
/** @var array<string, string|int> $keyById */
$keyById = array_flip($allocatable);
```

then `$raw = $rawNodes[$keyById[$node['id']] ?? ''] ?? null;`.

Add the two private helpers:

```php
/**
 * @param array<string, string> $allocatable tree-key => stored id
 *
 * @return list<string>
 */
private function resolveKeys(mixed $keys, array $allocatable): array
{
    $resolved = [];

    foreach ((array) (\is_array($keys) ? $keys : []) as $key) {
        $id = $allocatable[(string) (\is_scalar($key) ? $key : '')] ?? null;
        if (\is_string($id)) {
            $resolved[] = $id;
        }
    }

    return $resolved;
}

/**
 * @param array<string, string> $allocatable
 *
 * @return array{nodes: list<string>, ascendancy: string|null}|null
 */
private function unlockConstraint(mixed $raw, array $allocatable): ?array
{
    if (!\is_array($raw)) {
        return null;
    }

    $nodes = $this->resolveKeys($raw['nodes'] ?? null, $allocatable);

    if ([] === $nodes) {
        return null;
    }

    return [
        'nodes' => $nodes,
        'ascendancy' => \is_string($raw['ascendancy'] ?? null) ? $raw['ascendancy'] : null,
    ];
}
```

Extend the `@phpstan-type PassiveNode` line in `src/Catalog/NormalizedTree.php`:

```php
 * @phpstan-type PassiveNode array{id: string, name: string, kind: string, ascendancy_key: string|null, pos_x: float, pos_y: float, stats: list<string>, recipe: list<string>, keystones_in_radius: list<string>, unlock_constraint: array{nodes: list<string>, ascendancy: string|null}|null}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `ddev php bin/phpunit --filter PassiveTreeNormalizerTest` then `ddev composer stan`
Expected: PASS, PHPStan clean.

- [ ] **Step 5: Commit**

```bash
git add src/Catalog/PassiveTreeNormalizer.php src/Catalog/NormalizedTree.php tests/Catalog/PassiveTreeNormalizerTest.php
git commit -m "feat(catalog): keep the tree's connectivity and unlock constraints"
```

---

### Task 2: The columns exist, get written, and get backfilled on a deployed instance

**Files:**
- Create: `migrations/Version20260912100000.php`
- Modify: `src/Entity/CatalogPassive.php`, `src/Entity/CatalogSync.php`, `src/Catalog/PassiveTreeSync.php`
- Test: `tests/Catalog/PassiveTreeSyncTest.php`

**Interfaces:**
- Consumes: Task 1's `keystones_in_radius` / `unlock_constraint` on each node.
- Produces: `catalog_passive.keystones_in_radius` (JSON, list of ids) and `catalog_passive.unlock_constraint` (JSON, the object or `null`); `PassiveTreeSync::SHAPE_REVISION` (string) stored in `catalog_sync.shape_revision`.

**Why the sync change is in this task and not a follow-up.** `PassiveTreeSync::run()` skips the whole re-import when upstream's revision has not moved and the table is non-empty. Adding a column therefore leaves it **permanently empty on any instance that already synced** — the columns only fill when GGG next ships a tree. That has now nearly bitten this project three times (stats, recipe, and these two). The fix is a shape revision the application controls: bump the constant whenever the written row shape changes, and the fast path stops applying until a re-import has run.

**Migration content is load-bearing.** `JSON NOT NULL` with no `DEFAULT` backfills existing rows with `''`, which fails `JSON_VALID` and makes every later query against the column error. This exact line has already been got wrong once in this project. `keystones_in_radius` is `JSON NOT NULL DEFAULT '[]'`; `unlock_constraint` is `JSON DEFAULT NULL` (nullable, because "no constraint" is a real state and `null` says it better than `{}`).

- [ ] **Step 1: Write the failing test**

Add to `tests/Catalog/PassiveTreeSyncTest.php`:

```php
public function testItReimportsWhenTheRowShapeChangedEvenThoughUpstreamDidNot(): void
{
    $db = self::getContainer()->get(Connection::class);
    self::assertInstanceOf(Connection::class, $db);

    $db->executeStatement('DELETE FROM catalog_passive');
    $db->executeStatement('DELETE FROM catalog_sync');
    $db->executeStatement(
        "INSERT INTO catalog_passive (id, name, kind, pos_x, pos_y, stats, recipe, keystones_in_radius, unlock_constraint) VALUES ('stale', 'Stale', 'small', 0, 0, '[]', '[]', '[]', NULL)"
    );
    // A previous run at an older shape, with upstream sitting at the same revision.
    $db->executeStatement(
        "INSERT INTO catalog_sync (source, ran_at, upstream_revision, shape_revision, status, count) VALUES ('passive_tree', NOW(), 'rev-1', 'shape-0', 'ok', 1)"
    );

    $result = $this->syncWithUnchangedUpstream('rev-1');

    self::assertSame('ok', $result->status, 'a changed row shape must force the re-import the revision check would skip');
    self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM catalog_passive WHERE id = 'stale'"));
}
```

`syncWithUnchangedUpstream()` is a private helper in this test class that builds a `PassiveTreeSync` against a stub `SourceFetcher` returning `new FetchResult(ok: true, changed: false, body: $this->treeJson(), revision: $revision)`. If the existing test class has no such helper or stub, write one following whatever stubbing pattern the class already uses for the fetcher; do not add a mocking library.

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit --filter testItReimportsWhenTheRowShapeChangedEvenThoughUpstreamDidNot`
Expected: FAIL — unknown column `shape_revision` (the migration does not exist yet).

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tree legality data on catalog_passive, and the shape revision that forces a re-import.';
    }

    public function up(Schema $schema): void
    {
        // JSON NOT NULL without a DEFAULT backfills existing rows with '',
        // which is not valid JSON. The default is what keeps the 4912 rows
        // already in this table queryable.
        $this->addSql("ALTER TABLE catalog_passive ADD keystones_in_radius JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE catalog_passive ADD unlock_constraint JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_sync ADD shape_revision VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_passive DROP keystones_in_radius');
        $this->addSql('ALTER TABLE catalog_passive DROP unlock_constraint');
        $this->addSql('ALTER TABLE catalog_sync DROP shape_revision');
    }
}
```

- [ ] **Step 4: Run the migration on both databases**

Run: `ddev composer db:migrate`
Expected: both the dev and the test database report the migration applied.

Then verify the backfill is valid JSON rather than assuming it:

Run: `ddev mysql -e "SELECT COUNT(*) AS invalid FROM catalog_passive WHERE JSON_VALID(keystones_in_radius) = 0"`
Expected: `invalid` is `0`.

- [ ] **Step 5: Add the entity columns**

In `src/Entity/CatalogPassive.php`, after `$recipe`:

```php
    /** @var list<string> */
    #[ORM\Column(name: 'keystones_in_radius', type: Types::JSON)]
    private array $keystonesInRadius = [];

    /** @var array{nodes: list<string>, ascendancy: string|null}|null */
    #[ORM\Column(name: 'unlock_constraint', type: Types::JSON, nullable: true)]
    private ?array $unlockConstraint = null;
```

with getters following the file's existing style:

```php
    /** @return list<string> */
    public function getKeystonesInRadius(): array
    {
        return $this->keystonesInRadius;
    }

    /** @return array{nodes: list<string>, ascendancy: string|null}|null */
    public function getUnlockConstraint(): ?array
    {
        return $this->unlockConstraint;
    }
```

In `src/Entity/CatalogSync.php`, after `$upstreamRevision`:

```php
    #[ORM\Column(name: 'shape_revision', length: 32, nullable: true)]
    private ?string $shapeRevision = null;

    public function getShapeRevision(): ?string
    {
        return $this->shapeRevision;
    }
```

- [ ] **Step 6: Write the two columns and gate the fast path**

In `src/Catalog/PassiveTreeSync.php` add the constant at the top of the class:

```php
    /**
     * Bumped whenever the shape of a written row changes. The revision check
     * below skips a re-import when upstream has not moved, which would
     * otherwise leave a newly added column empty forever on an instance that
     * had already synced.
     */
    public const string SHAPE_REVISION = 'tree-2026-09-12-legality';
```

Change the fast path:

```php
        if (!$fetch->changed && $this->alreadyPopulated() && $this->shapeIsCurrent()) {
            return $this->record(new SyncResult(true, 'unchanged', $this->storedCount()), $fetch->revision);
        }
```

```php
    private function shapeIsCurrent(): bool
    {
        $value = $this->db->fetchOne(
            "SELECT shape_revision FROM catalog_sync WHERE source = 'passive_tree' AND status = 'ok' ORDER BY id DESC LIMIT 1"
        );

        return $value === self::SHAPE_REVISION;
    }
```

In `replace()`, widen the node insert to ten columns:

```php
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                    array_push(
                        $params,
                        $node['id'], $node['name'], $node['kind'], $node['ascendancy_key'],
                        $node['pos_x'], $node['pos_y'],
                        json_encode($node['stats'], \JSON_THROW_ON_ERROR),
                        json_encode($node['recipe'], \JSON_THROW_ON_ERROR),
                        json_encode($node['keystones_in_radius'], \JSON_THROW_ON_ERROR),
                        null === $node['unlock_constraint'] ? null : json_encode($node['unlock_constraint'], \JSON_THROW_ON_ERROR),
                    );
                }
                $db->executeStatement('INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe, keystones_in_radius, unlock_constraint) VALUES '.implode(',', $values), $params);
```

And in `record()`, persist the shape revision alongside the upstream one — follow whatever insert that method already performs, adding `shape_revision` with `self::SHAPE_REVISION` as its value.

- [ ] **Step 7: Run the tests and watch them pass**

Run: `ddev php bin/phpunit --filter PassiveTreeSyncTest` then `ddev composer stan`
Expected: PASS, PHPStan clean.

- [ ] **Step 8: Re-sync the real tree and verify the counts match the measurement**

Run: `ddev php bin/console app:catalog:sync passive_tree`
Then: `ddev mysql -e "SELECT COUNT(*) AS total, SUM(JSON_LENGTH(keystones_in_radius) > 0) AS with_radius, SUM(unlock_constraint IS NOT NULL) AS with_constraint FROM catalog_passive"`
Expected: `with_radius` is **1573** and `with_constraint` is **200**. These are the figures measured from the real export on 2026-09-12. A different number means the resolution in Task 1 dropped keys it should have kept — stop and investigate rather than continuing.

- [ ] **Step 9: Commit**

```bash
git add migrations/Version20260912100000.php src/Entity/CatalogPassive.php src/Entity/CatalogSync.php src/Catalog/PassiveTreeSync.php tests/Catalog/PassiveTreeSyncTest.php
git commit -m "feat(catalog): store tree legality data and re-import when the shape changes"
```

---

### Task 3: The canvas payload carries the legality fields

**Files:**
- Modify: `src/Catalog/TreeExport.php`
- Test: `tests/Catalog/TreeExportTest.php`

**Interfaces:**
- Consumes: the two new columns from Task 2.
- Produces: the node tuple grows from 8 to **10** entries, in this fixed order: `id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe, keystones_in_radius, unlock_constraint`. Index 8 is `list<string>`; index 9 is `array{nodes: list<string>, ascendancy: string|null}|null`. `assets/controllers/tree_controller.js` reads these positionally in Task 8.

- [ ] **Step 1: Write the failing test**

Add to `tests/Catalog/TreeExportTest.php`, following the positional style the existing node-tuple test uses:

```php
public function testTheNodeTupleCarriesLegalityDataAtItsAgreedPositions(): void
{
    $db = self::getContainer()->get(Connection::class);
    self::assertInstanceOf(Connection::class, $db);

    $db->executeStatement('DELETE FROM catalog_passive');
    $db->executeStatement(
        "INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe, keystones_in_radius, unlock_constraint)
         VALUES ('covered', 'Covered', 'small', NULL, 1.5, -2.5, '[]', '[]', '[\"keystone_a\"]', '{\"nodes\":[\"gate_a\"],\"ascendancy\":\"Druid1\"}')"
    );

    $payload = self::getContainer()->get(TreeExport::class)->payload();
    $node = $payload['nodes'][0];

    self::assertCount(10, $node);
    self::assertSame(['keystone_a'], $node[8]);
    self::assertSame(['nodes' => ['gate_a'], 'ascendancy' => 'Druid1'], $node[9]);
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit --filter testTheNodeTupleCarriesLegalityDataAtItsAgreedPositions`
Expected: FAIL — `Failed asserting that actual size 8 matches expected size 10`.

- [ ] **Step 3: Widen the tuple**

In `payload()`, add the two columns to the `SELECT` and two entries to the tuple:

```php
        foreach ($this->db->fetchAllAssociative('SELECT id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe, keystones_in_radius, unlock_constraint FROM catalog_passive') as $row) {
            $nodes[] = [
                Row::str($row, 'id'),
                Row::str($row, 'name'),
                Row::str($row, 'kind'),
                Row::nullableStr($row, 'ascendancy_key'),
                Row::float($row, 'pos_x'),
                Row::float($row, 'pos_y'),
                array_map(StatText::plain(...), Row::jsonStrings($row, 'stats')),
                array_map(StatText::emotion(...), Row::jsonStrings($row, 'recipe')),
                Row::jsonStrings($row, 'keystones_in_radius'),
                $this->unlockConstraint($row),
            ];
        }
```

Add the private decoder, which must tolerate a null column:

```php
    /**
     * @param array<string, mixed> $row
     *
     * @return array{nodes: list<string>, ascendancy: string|null}|null
     */
    private function unlockConstraint(array $row): ?array
    {
        $raw = $row['unlock_constraint'] ?? null;

        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $nodes = array_values(array_filter((array) ($decoded['nodes'] ?? []), is_string(...)));

        return [] === $nodes ? null : [
            'nodes' => $nodes,
            'ascendancy' => \is_string($decoded['ascendancy'] ?? null) ? $decoded['ascendancy'] : null,
        ];
    }
```

Update the class docblock — it currently says "eight named keys" and names the order — to say ten and list the two new ones. Update the `@return` annotation on `payload()` to `array{0: string, 1: string, 2: string, 3: string|null, 4: float, 5: float, 6: list<string>, 7: list<string>, 8: list<string>, 9: array{nodes: list<string>, ascendancy: string|null}|null}`.

- [ ] **Step 4: Run it and watch it pass**

Run: `ddev php bin/phpunit --filter TreeExportTest` then `ddev composer stan`
Expected: PASS, PHPStan clean.

- [ ] **Step 5: Commit**

```bash
git add src/Catalog/TreeExport.php tests/Catalog/TreeExportTest.php
git commit -m "feat(catalog): ship legality data with the tree payload"
```

---

### Task 4: WeaponSet, Allocation and TreeContext

**Files:**
- Create: `src/Build/Tree/WeaponSet.php`, `src/Build/Tree/Allocation.php`, `src/Build/Tree/TreeContext.php`
- Test: `tests/Build/Tree/AllocationTest.php`

**Interfaces:**
- Consumes: `App\Interchange\BuildDocument` (`->passives` is `list<array<string, mixed>>`).
- Produces:
  - `WeaponSet` — a backed enum: `Shared = 0`, `One = 1`, `Two = 2`. `WeaponSet::fromWire(mixed $raw): self` maps absent/null/`0` to `Shared`. `->toWire(): ?int` returns `null` for `Shared`, else the int — `null` means "omit the key".
  - `Allocation` — `readonly`, wrapping `array<string, WeaponSet>`. `Allocation::of(BuildDocument $document): self`; `->setOf(string $id): ?WeaponSet`; `->has(string $id): bool`; `->ids(): list<string>` (every allocated id); `->idsVisibleTo(WeaponSet $set): list<string>` — for `Shared`, only shared ids; for `One`/`Two`, **shared plus that set**. `->without(string $id): self`.
  - `TreeContext` — `readonly`, `public function __construct(public ?string $startNodeId, public ?string $ascendancyKey, public ?string $jewelKeystoneId = null)`. The third argument is always `null` in B1; slice B2 supplies it from the declared jewel pseudo-slot.

`idsVisibleTo()` is the whole per-set connectivity rule in one method: a set 1 node may route through shared or set 1 nodes, never through set 2.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build\Tree;

use App\Build\Tree\Allocation;
use App\Build\Tree\WeaponSet;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class AllocationTest extends TestCase
{
    public function testAbsentWeaponSetMeansShared(): void
    {
        self::assertSame(WeaponSet::Shared, WeaponSet::fromWire(null));
        self::assertSame(WeaponSet::Shared, WeaponSet::fromWire(0));
        self::assertSame(WeaponSet::One, WeaponSet::fromWire(1));
        self::assertSame(WeaponSet::Two, WeaponSet::fromWire('2'));
    }

    public function testSharedIsWrittenAsAnAbsentKeyNotAsZero(): void
    {
        // The value 0 appears in no file the game has ever written; absence is
        // what shared looks like on the wire.
        self::assertNull(WeaponSet::Shared->toWire());
        self::assertSame(1, WeaponSet::One->toWire());
    }

    public function testASetSeesItsOwnNodesAndTheSharedOnesButNotTheOtherSet(): void
    {
        $allocation = Allocation::of($this->document());

        self::assertSame(['trunk'], $allocation->idsVisibleTo(WeaponSet::Shared));
        self::assertSame(['trunk', 'ailment'], $allocation->idsVisibleTo(WeaponSet::One));
        self::assertSame(['trunk', 'cooldown'], $allocation->idsVisibleTo(WeaponSet::Two));
    }

    public function testItReadsTheSetOfEachAllocatedNode(): void
    {
        $allocation = Allocation::of($this->document());

        self::assertSame(WeaponSet::Shared, $allocation->setOf('trunk'));
        self::assertSame(WeaponSet::One, $allocation->setOf('ailment'));
        self::assertNull($allocation->setOf('never_allocated'));
    }

    private function document(): BuildDocument
    {
        return new BuildDocument(
            name: 'test',
            passives: [
                ['id' => 'trunk', 'level_interval' => [1, 100]],
                ['id' => 'ailment', 'level_interval' => [1, 100], 'weapon_set' => 1],
                ['id' => 'cooldown', 'level_interval' => [1, 100], 'weapon_set' => 2],
            ],
        );
    }
}
```

Check `BuildDocument`'s real constructor before writing `document()` — use its actual named parameters. If `name` and `passives` are not both constructor arguments, build the document the way the existing `tests/Build/DocumentEditorPassivesTest.php` builds one and keep the same three passives.

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit --filter AllocationTest`
Expected: FAIL — `Class "App\Build\Tree\WeaponSet" not found`.

- [ ] **Step 3: Write the three classes**

`src/Build/Tree/WeaponSet.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Tree;

/**
 * Which weapon set a passive belongs to.
 *
 * The `.build` format writes 1 or 2 and omits the key entirely for a passive
 * that applies to both. The value 0 is documented as valid but appears in no
 * file the game has written, so it is read and never produced.
 */
enum WeaponSet: int
{
    case Shared = 0;
    case One = 1;
    case Two = 2;

    public static function fromWire(mixed $raw): self
    {
        return match (true) {
            1 === $raw, '1' === $raw => self::One,
            2 === $raw, '2' === $raw => self::Two,
            default => self::Shared,
        };
    }

    /**
     * Null means "write no key at all", which is what shared looks like.
     */
    public function toWire(): ?int
    {
        return self::Shared === $this ? null : $this->value;
    }
}
```

`src/Build/Tree/Allocation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Tree;

use App\Interchange\BuildDocument;

/**
 * The build's allocated passives, each with the weapon set it belongs to.
 *
 * A node id appears at most once — measured across the real corpus, no file
 * allocates the same id twice — so a map keyed by id loses nothing.
 */
final readonly class Allocation
{
    /**
     * @param array<string, WeaponSet> $byId
     */
    public function __construct(private array $byId)
    {
    }

    public static function of(BuildDocument $document): self
    {
        $byId = [];

        foreach ($document->passives as $passive) {
            $id = $passive['id'] ?? null;

            if (\is_string($id) && '' !== $id) {
                $byId[$id] = WeaponSet::fromWire($passive['weapon_set'] ?? null);
            }
        }

        return new self($byId);
    }

    public function has(string $id): bool
    {
        return isset($this->byId[$id]);
    }

    public function setOf(string $id): ?WeaponSet
    {
        return $this->byId[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->byId);
    }

    /**
     * What a node in `$set` may route through: its own set plus the shared
     * nodes, never the other set. This is the per-set connectivity rule.
     *
     * @return list<string>
     */
    public function idsVisibleTo(WeaponSet $set): array
    {
        $visible = [];

        foreach ($this->byId as $id => $nodeSet) {
            if (WeaponSet::Shared === $nodeSet || $nodeSet === $set) {
                $visible[] = $id;
            }
        }

        return $visible;
    }

    public function without(string $id): self
    {
        $byId = $this->byId;
        unset($byId[$id]);

        return new self($byId);
    }
}
```

`src/Build/Tree/TreeContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Tree;

/**
 * What legality depends on besides the tree itself.
 *
 * `jewelKeystoneId` is always null in slice B1: the mechanism is here because
 * rules 3 and 4 differ only in what enables a keystone, but the control that
 * declares it belongs to slice B2.
 */
final readonly class TreeContext
{
    public function __construct(
        public ?string $startNodeId,
        public ?string $ascendancyKey,
        public ?string $jewelKeystoneId = null,
    ) {
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `ddev php bin/phpunit --filter AllocationTest` then `ddev composer stan`
Expected: PASS, PHPStan clean.

- [ ] **Step 5: Commit**

```bash
git add src/Build/Tree tests/Build/Tree/AllocationTest.php
git commit -m "feat(build): model a passive's weapon set and the build's allocation"
```

---

### Task 5: PassiveGraph reads the tree once

**Files:**
- Create: `src/Build/Tree/PassiveGraph.php`
- Test: `tests/Build/Tree/PassiveGraphTest.php`

**Interfaces:**
- Consumes: `Doctrine\DBAL\Connection`, the columns from Task 2.
- Produces: `PassiveGraph` with `->neighbours(string $id): list<string>`, `->keystonesCovering(string $id): list<string>`, `->unlockConstraintOf(string $id): ?array{nodes: list<string>, ascendancy: string|null}`, `->isKeystone(string $id): bool`, `->exists(string $id): bool`. Everything is loaded lazily on first call and cached for the life of the instance; the service is not shared between requests.

A rules evaluation touches thousands of nodes, so per-node queries are not an option: three `fetchAllAssociative` calls fill the whole structure.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build\Tree;

use App\Build\Tree\PassiveGraph;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PassiveGraphTest extends KernelTestCase
{
    public function testItReadsAdjacencyInBothDirections(): void
    {
        $graph = $this->graphOver(
            nodes: [['a', 'small'], ['b', 'small']],
            edges: [['a', 'b']],
        );

        self::assertSame(['b'], $graph->neighbours('a'));
        self::assertSame(['a'], $graph->neighbours('b'), 'an edge stored one way must still connect both ways');
        self::assertSame([], $graph->neighbours('nowhere'));
    }

    public function testItReadsKeystoneRadiiAndUnlockConstraints(): void
    {
        $graph = $this->graphOver(
            nodes: [['covered', 'small'], ['stone', 'keystone']],
            edges: [],
            radius: ['covered' => ['stone']],
            constraints: ['covered' => ['nodes' => ['gate'], 'ascendancy' => 'Druid1']],
        );

        self::assertSame(['stone'], $graph->keystonesCovering('covered'));
        self::assertSame(['nodes' => ['gate'], 'ascendancy' => 'Druid1'], $graph->unlockConstraintOf('covered'));
        self::assertNull($graph->unlockConstraintOf('stone'));
        self::assertTrue($graph->isKeystone('stone'));
        self::assertFalse($graph->isKeystone('covered'));
    }

    /**
     * @param list<array{0: string, 1: string}>                                     $nodes
     * @param list<array{0: string, 1: string}>                                     $edges
     * @param array<string, list<string>>                                           $radius
     * @param array<string, array{nodes: list<string>, ascendancy: string|null}>    $constraints
     */
    private function graphOver(array $nodes, array $edges, array $radius = [], array $constraints = []): PassiveGraph
    {
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);

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

        return new PassiveGraph($db);
    }
}
```

Note the first test's intent: `catalog_passive_edge` may or may not already store both directions. The assertion says the graph must answer both ways **regardless**, so `PassiveGraph` symmetrises what it reads. If the sync already writes both directions this costs nothing; if it does not, this is what makes connectivity correct.

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit --filter PassiveGraphTest`
Expected: FAIL — `Class "App\Build\Tree\PassiveGraph" not found`.

- [ ] **Step 3: Write the graph**

```php
<?php

declare(strict_types=1);

namespace App\Build\Tree;

use App\Catalog\Row;
use Doctrine\DBAL\Connection;

/**
 * The passive tree as the legality rules need it: who touches whom, which
 * keystones cover a node, and what gates it.
 *
 * Loaded in three queries on first use. A rules evaluation walks thousands of
 * nodes, so a query per node is not an option.
 */
final class PassiveGraph
{
    /** @var array<string, list<string>>|null */
    private ?array $neighbours = null;

    /** @var array<string, list<string>> */
    private array $radius = [];

    /** @var array<string, array{nodes: list<string>, ascendancy: string|null}> */
    private array $constraints = [];

    /** @var array<string, string> */
    private array $kinds = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<string>
     */
    public function neighbours(string $id): array
    {
        $this->load();

        return $this->neighbours[$id] ?? [];
    }

    /**
     * @return list<string>
     */
    public function keystonesCovering(string $id): array
    {
        $this->load();

        return $this->radius[$id] ?? [];
    }

    /**
     * @return array{nodes: list<string>, ascendancy: string|null}|null
     */
    public function unlockConstraintOf(string $id): ?array
    {
        $this->load();

        return $this->constraints[$id] ?? null;
    }

    public function isKeystone(string $id): bool
    {
        $this->load();

        return 'keystone' === ($this->kinds[$id] ?? null);
    }

    public function exists(string $id): bool
    {
        $this->load();

        return isset($this->kinds[$id]);
    }

    private function load(): void
    {
        if (null !== $this->neighbours) {
            return;
        }

        $neighbours = [];

        // Stored edges are directed. Connectivity is not, so both directions
        // are recorded whatever the table happens to hold.
        foreach ($this->db->fetchAllAssociative('SELECT from_id, to_id FROM catalog_passive_edge') as $row) {
            $from = Row::str($row, 'from_id');
            $to = Row::str($row, 'to_id');

            $neighbours[$from][$to] = true;
            $neighbours[$to][$from] = true;
        }

        foreach ($this->db->fetchAllAssociative('SELECT id, kind, keystones_in_radius, unlock_constraint FROM catalog_passive') as $row) {
            $id = Row::str($row, 'id');
            $this->kinds[$id] = Row::str($row, 'kind');

            $covering = Row::jsonStrings($row, 'keystones_in_radius');
            if ([] !== $covering) {
                $this->radius[$id] = $covering;
            }

            $constraint = $this->constraint($row);
            if (null !== $constraint) {
                $this->constraints[$id] = $constraint;
            }
        }

        $this->neighbours = array_map(array_keys(...), $neighbours);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{nodes: list<string>, ascendancy: string|null}|null
     */
    private function constraint(array $row): ?array
    {
        $raw = $row['unlock_constraint'] ?? null;

        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $nodes = array_values(array_filter((array) ($decoded['nodes'] ?? []), is_string(...)));

        return [] === $nodes ? null : [
            'nodes' => $nodes,
            'ascendancy' => \is_string($decoded['ascendancy'] ?? null) ? $decoded['ascendancy'] : null,
        ];
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `ddev php bin/phpunit --filter PassiveGraphTest` then `ddev composer stan`
Expected: PASS, PHPStan clean.

- [ ] **Step 5: Commit**

```bash
git add src/Build/Tree/PassiveGraph.php tests/Build/Tree/PassiveGraphTest.php
git commit -m "feat(build): read the tree's shape and gates in one place"
```

---

### Task 6: The four rules

**Files:**
- Create: `src/Build/Tree/AllocationRules.php`
- Test: `tests/Build/Tree/AllocationRulesTest.php`

**Interfaces:**
- Consumes: `PassiveGraph`, `Allocation`, `WeaponSet`, `TreeContext` (Tasks 4-5).
- Produces:
  - `->mayAllocate(Allocation $allocation, TreeContext $context, string $id, WeaponSet $set): bool`
  - `->isLegal(Allocation $allocation, TreeContext $context, string $id): bool` — re-checks an **already allocated** node against the rules, using the set it is allocated into. This is what the cascade in Task 7 calls.
  - `->illegalAfter(Allocation $allocation, TreeContext $context): list<string>` — every allocated id that `isLegal()` now rejects, iterated to a fixed point so that a node made illegal by an earlier removal is itself removed.

**The rules, in evaluation order.** All four come from the spec section "Allocation is checked, not merely drawn"; the weapon-set clause comes from "Weapon sets are allocated, coloured and summed separately".

1. The node must exist in the catalog.
2. **Unlock constraint.** If the node carries one, *all* of its gate nodes must be allocated, and if it names an ascendancy the build's ascendancy must match. (All-of is the conservative reading — see spec proof 8. The data cannot distinguish all-of from any-of.)
3. **Connectivity**, per weapon set: the node must be adjacent to some node in the connected component of `allocation->idsVisibleTo($set)` rooted at `context->startNodeId`. The start node itself is always legal.
4. **The radius exception**, which excuses rule 3 only: the node is not itself a keystone, and at least one keystone covering it is *enabled*. A keystone is enabled when it is allocated **and** `AscendancyDruid1Notable1` (Entwined Realities) is allocated, or when it is the declared `jewelKeystoneId`.

- [ ] **Step 1: Write the failing test**

```php
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

        $both = new Allocation(['start' => WeaponSet::Shared, 'AscendancyDruid1Notable1' => WeaponSet::Shared, 'stone' => WeaponSet::Shared]);
        self::assertTrue($rules->mayAllocate($both, $context, 'floating', WeaponSet::Shared));
    }

    public function testAKeystoneIsNeverExcusedByItsOwnRadius(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['AscendancyDruid1Notable1', 'notable'], ['stone', 'keystone'], ['other_stone', 'keystone']],
            edges: [['start', 'AscendancyDruid1Notable1']],
            radius: ['other_stone' => ['stone']],
        );

        $both = new Allocation(['start' => WeaponSet::Shared, 'AscendancyDruid1Notable1' => WeaponSet::Shared, 'stone' => WeaponSet::Shared]);

        self::assertFalse(
            $rules->mayAllocate($both, new TreeContext('start', 'Druid1'), 'other_stone', WeaponSet::Shared),
            'the stat reads "Non-Keystone Passive Skills"',
        );
    }

    public function testIllegalAfterFindsWhatAnEarlierRemovalStranded(): void
    {
        $rules = $this->rulesOver(
            nodes: [['start', 'small'], ['a', 'small'], ['b', 'small'], ['c', 'small']],
            edges: [['start', 'a'], ['a', 'b'], ['b', 'c']],
        );

        // `a` is gone. `b` loses its connection, and `c` only loses its own
        // once `b` has been taken out too — which needs a second pass.
        $stranded = new Allocation(['start' => WeaponSet::Shared, 'b' => WeaponSet::Shared, 'c' => WeaponSet::Shared]);

        self::assertSame(['b', 'c'], $rules->illegalAfter($stranded, $this->context()));
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
     * @param list<array{0: string, 1: string}>                                  $nodes
     * @param list<array{0: string, 1: string}>                                  $edges
     * @param array<string, list<string>>                                        $radius
     * @param array<string, array{nodes: list<string>, ascendancy: string|null}> $constraints
     */
    private function rulesOver(array $nodes, array $edges, array $radius = [], array $constraints = []): AllocationRules
    {
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);

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
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit --filter AllocationRulesTest`
Expected: FAIL — `Class "App\Build\Tree\AllocationRules" not found`.

- [ ] **Step 3: Write the rules**

```php
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
     * @return list<string>
     */
    public function illegalAfter(Allocation $allocation, TreeContext $context): array
    {
        $removed = [];

        do {
            $stranded = [];

            foreach ($allocation->ids() as $id) {
                if (!$this->isLegal($allocation, $context, $id)) {
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

        if (!$this->unlocked($without, $context, $id)) {
            return false;
        }

        return $this->connected($without, $context, $id, $set) || $this->excusedByRadius($without, $context, $id);
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
```

- [ ] **Step 4: Run it and watch it pass**

Run: `ddev php bin/phpunit --filter AllocationRulesTest` then `ddev composer stan`
Expected: PASS (8 tests), PHPStan clean.

- [ ] **Step 5: Commit**

```bash
git add src/Build/Tree/AllocationRules.php tests/Build/Tree/AllocationRulesTest.php
git commit -m "feat(build): check a passive against the four legality rules"
```

---

### Task 7: The document allocates with a weapon set and deallocates with a cascade

**Files:**
- Modify: `src/Build/Edit/DocumentEditor.php`
- Test: `tests/Build/DocumentEditorPassivesTest.php`

**Interfaces:**
- Consumes: `WeaponSet` (Task 4).
- Produces:
  - `allocatePassive(BuildDocument $document, string $id, WeaponSet $set = WeaponSet::Shared): BuildDocument` — writes `weapon_set` only when the set is not `Shared`.
  - `deallocatePassives(BuildDocument $document, array $ids): BuildDocument` — removes every listed id in one pass. The cascade's *membership* is decided by `AllocationRules` in the handler (Task 8), not here: `DocumentEditor` stays free of catalog access, which is what makes it unit-testable without a database.

`deallocatePassive()` keeps its single-id signature and delegates to the plural form, because `Revert` replays historical single-id events.

- [ ] **Step 1: Write the failing test**

Add to `tests/Build/DocumentEditorPassivesTest.php`:

```php
public function testAllocatingIntoASetWritesTheKeyAndAllocatingSharedOmitsIt(): void
{
    $editor = $this->editor();
    $document = $editor->allocatePassive($this->emptyDocument(), 'shared_one');
    $document = $editor->allocatePassive($document, 'set_one', WeaponSet::One);

    self::assertArrayNotHasKey('weapon_set', $document->passives[0], 'shared is an absent key, never 0');
    self::assertSame(1, $document->passives[1]['weapon_set']);
}

public function testDeallocatingManyRemovesThemAllAndLeavesTheRestUntouched(): void
{
    $editor = $this->editor();
    $document = new BuildDocument(name: 'test', passives: [
        ['id' => 'keep', 'level_interval' => [1, 100], 'additional_text' => 'mine'],
        ['id' => 'drop_a', 'level_interval' => [1, 100]],
        ['id' => 'drop_b', 'level_interval' => [1, 100], 'weapon_set' => 2],
    ]);

    $changed = $editor->deallocatePassives($document, ['drop_a', 'drop_b']);

    self::assertCount(1, $changed->passives);
    self::assertSame('keep', $changed->passives[0]['id']);
    self::assertSame('mine', $changed->passives[0]['additional_text']);
}
```

Use whatever `editor()` / `emptyDocument()` helpers the test class already has; if it builds its `DocumentEditor` inline, follow that.

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit --filter "testAllocatingIntoASetWritesTheKeyAndAllocatingSharedOmitsIt|testDeallocatingManyRemovesThemAllAndLeavesTheRestUntouched"`
Expected: FAIL — too few arguments / `Call to undefined method ...::deallocatePassives()`.

- [ ] **Step 3: Change the two methods**

```php
    public function allocatePassive(BuildDocument $document, string $id, WeaponSet $set = WeaponSet::Shared): BuildDocument
    {
        if (null !== $this->indexOfPassive($document, $id)) {
            return $document;
        }

        $passive = ['id' => $id, 'level_interval' => [1, self::MAX_LEVEL], 'additional_text' => ''];
        $wire = $set->toWire();

        if (null !== $wire) {
            $passive['weapon_set'] = $wire;
        }

        $passives = $document->passives;
        $passives[] = $passive;

        return $this->withPassives($document, $passives);
    }

    public function deallocatePassive(BuildDocument $document, string $id): BuildDocument
    {
        return $this->deallocatePassives($document, [$id]);
    }

    /**
     * @param list<string> $ids
     */
    public function deallocatePassives(BuildDocument $document, array $ids): BuildDocument
    {
        if ([] === $ids) {
            return $document;
        }

        $drop = array_fill_keys($ids, true);

        $passives = array_values(array_filter(
            $document->passives,
            static fn (array $passive): bool => !isset($drop[\is_string($passive['id'] ?? null) ? $passive['id'] : '']),
        ));

        return $this->withPassives($document, $passives);
    }
```

Add `use App\Build\Tree\WeaponSet;` to the imports.

- [ ] **Step 4: Run it and watch it pass**

Run: `ddev php bin/phpunit --filter DocumentEditor` then `ddev composer stan`
Expected: PASS — every existing `DocumentEditor` test still green, because `allocatePassive`'s third argument is defaulted and `deallocatePassive` keeps its behaviour.

- [ ] **Step 5: Commit**

```bash
git add src/Build/Edit/DocumentEditor.php tests/Build/DocumentEditorPassivesTest.php
git commit -m "feat(build): allocate into a weapon set and deallocate in bulk"
```

---

### Task 8: The command refuses an illegal allocation and cascades a removal

**Files:**
- Modify: `src/Build/Edit/Command/AllocatePassive.php`, `src/Build/Edit/CommandFactory.php`, `src/Build/Edit/Handler/PassiveEditHandler.php`
- Modify: `templates/build/_history.html.twig`
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: `AllocationRules`, `Allocation`, `TreeContext`, `WeaponSet` (Tasks 4-6); `DocumentEditor::deallocatePassives()` (Task 7).
- Produces: `AllocatePassive` gains `public WeaponSet $set`. The `passive.allocate` payload gains `set` (the wire value, `null` for shared). The `passive.deallocate` payload gains `also` — `list<string>`, the cascaded ids, empty when nothing else fell out. Both history sentences read from these.

The handler is where legality is enforced, because the spec says the endpoint enforces and the canvas only previews.

- [ ] **Step 1: Write the failing test**

Add to `tests/Controller/BuildEditorControllerTest.php`, following the class's existing pattern for seeding a build and posting to `/act`:

```php
public function testAllocatingANodeThatTouchesNothingIsRefused(): void
{
    $client = static::createClient();
    $build = $this->seedBuildWithTree($client);

    $client->request('POST', $this->actUrl($build), ['action' => 'passive.allocate', 'id' => 'far']);

    self::assertResponseStatusCodeSame(422);
    self::assertStringContainsString('not connected', (string) $client->getResponse()->getContent());
}

public function testDeallocatingAJunctionTakesWhatHungOffIt(): void
{
    $client = static::createClient();
    $build = $this->seedBuildWithTree($client);

    $client->request('POST', $this->actUrl($build), ['action' => 'passive.allocate', 'id' => 'near']);
    $client->request('POST', $this->actUrl($build), ['action' => 'passive.allocate', 'id' => 'leaf']);
    $client->request('POST', $this->actUrl($build), ['action' => 'passive.deallocate', 'id' => 'near']);

    $crawler = $client->request('GET', $this->editUrl($build));

    self::assertStringNotContainsString('leaf', $crawler->filter('#build-nodes')->text(), 'the leaf lost its only route to the start');
    self::assertStringContainsString('and 1 more', $crawler->filter('#build-history')->text());
}
```

`seedBuildWithTree()` is a new private helper in this class: it seeds `catalog_passive` / `catalog_passive_edge` with `start — near — leaf` plus an unconnected `far`, seeds a `catalog_class` whose `start_node_id` is `start`, creates a build through the existing test-build route, and sets its class. Reuse the raw-DBAL seeding the Catalog tests already do.

- [ ] **Step 2: Run them and watch them fail**

Run: `ddev php bin/phpunit --filter "testAllocatingANodeThatTouchesNothingIsRefused|testDeallocatingAJunctionTakesWhatHungOffIt"`
Expected: FAIL — the first returns 302/200 instead of 422; the second still shows `leaf`.

- [ ] **Step 3: Carry the weapon set on the command**

`src/Build/Edit/Command/AllocatePassive.php`:

```php
final readonly class AllocatePassive implements EditCommand
{
    public function __construct(public int $buildId, public string $id, public WeaponSet $set = WeaponSet::Shared)
    {
    }
}
```

In `CommandFactory::fromRequest()`, change the `passive.allocate` arm to read the optional `set` field:

```php
            'passive.allocate' => new AllocatePassive($buildId, $this->string($payload, 'id'), WeaponSet::fromWire($this->optionalString($payload, 'set'))),
```

- [ ] **Step 4: Enforce in the handler and record the cascade**

Inject the rules and the graph's context sources into `PassiveEditHandler`:

```php
    public function __construct(
        private readonly BuildEditor $builds,
        private readonly DocumentEditor $documents,
        private readonly AllocationRules $rules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }
```

```php
    #[AsMessageHandler]
    public function allocate(AllocatePassive $command): void
    {
        $payload = ['id' => $command->id, 'set' => $command->set->toWire()];

        $this->builds->apply($command->buildId, 'passive.allocate', $payload, function (Build $build) use ($command): void {
            $document = $build->toDocument();
            $context = $this->contextOf($build);

            if (!$this->rules->mayAllocate(Allocation::of($document), $context, $command->id, $command->set)) {
                throw InvalidEditCommand::illegalAllocation($command->id);
            }

            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->allocatePassive($d, $command->id, $command->set));
        });
    }

    #[AsMessageHandler]
    public function deallocate(DeallocatePassive $command): void
    {
        $this->builds->apply($command->buildId, 'passive.deallocate', ['id' => $command->id, 'also' => []], function (Build $build) use ($command): void {
            $document = $build->toDocument();
            $context = $this->contextOf($build);

            $remaining = Allocation::of($document)->without($command->id);
            $also = $this->rules->illegalAfter($remaining, $context);

            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->deallocatePassives($d, [$command->id, ...$also]));
        });
    }
```

The `also` list has to reach the recorded payload, and the closure above computes it after `apply()` has already been handed its payload. Resolve that by computing the cascade **before** calling `apply()`:

```php
    #[AsMessageHandler]
    public function deallocate(DeallocatePassive $command): void
    {
        $build = $this->builds->find($command->buildId);
        $context = $this->contextOf($build);
        $also = $this->rules->illegalAfter(Allocation::of($build->toDocument())->without($command->id), $context);

        $this->builds->apply($command->buildId, 'passive.deallocate', ['id' => $command->id, 'also' => $also], function (Build $build) use ($command, $also): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->deallocatePassives($d, [$command->id, ...$also]));
        });
    }
```

Check `BuildEditor` for an existing "load this build" method before adding `find()`. If none exists, add one that throws `InvalidEditCommand::noSuchEntry('build')` when the id is unknown, matching how `apply()` already resolves a build.

`contextOf()` reads the class's start node:

```php
    private function contextOf(Build $build): TreeContext
    {
        $classKey = $build->getClassKey();
        $class = null === $classKey ? null : $this->entityManager->getRepository(CatalogClass::class)->find($classKey);

        return new TreeContext($class?->getStartNodeId(), $build->getAscendancyKey());
    }
```

Add to `src/Build/Edit/InvalidEditCommand.php`, matching its existing named-constructor style:

```php
    public static function illegalAllocation(string $id): self
    {
        return new self('“'.$id.'” is not connected to your tree, or something it needs is not allocated yet.');
    }
```

- [ ] **Step 5: Add the two history sentences**

In `templates/build/_history.html.twig`, in the same sentence map the other actions use:

```twig
{% elseif event.action == 'passive.deallocate' %}
    Removed {{ event.payload.id }}{% if event.payload.also is defined and event.payload.also|length %} and {{ event.payload.also|length }} more{% endif %}
```

and extend the existing `passive.allocate` arm so a weapon-set allocation says which set:

```twig
{% elseif event.action == 'passive.allocate' %}
    Allocated {{ event.payload.id }}{% if event.payload.set is defined and event.payload.set %} for weapon set {{ event.payload.set }}{% endif %}
```

Historical events carry neither key, which is why both are guarded with `is defined` — `strict_variables` is on in the test environment and an unguarded read would throw on every build made before this task.

- [ ] **Step 6: Run the tests and watch them pass**

Run: `ddev php bin/phpunit --filter BuildEditorControllerTest` then `ddev composer stan` then `ddev php bin/console lint:twig templates/`
Expected: PASS, PHPStan clean, Twig clean.

- [ ] **Step 7: Commit**

```bash
git add src/Build/Edit templates/build/_history.html.twig tests/Controller/BuildEditorControllerTest.php
git commit -m "feat(editor): refuse an illegal allocation and cascade a removal"
```

---

### Task 9: The editor hands the canvas its allocation groups and legality inputs

**Files:**
- Modify: `templates/build/_state.html.twig`, `src/Controller/EditorContext.php`
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: `Allocation`, `WeaponSet` (Task 4).
- Produces: `#build-state` JSON gains `allocatedBySet` — `{"shared": [...], "1": [...], "2": [...]}` — and `startNodeId`. `allocated` (the flat list) stays, because the renderer's edge-highlighting uses it and Task 10 keeps that. `EditorContext::of()` gains `allocationBySet` (the same three lists) and `startNodeId`.

- [ ] **Step 1: Write the failing test**

```php
public function testTheCanvasStateSeparatesTheThreeAllocationGroups(): void
{
    $client = static::createClient();
    $build = $this->seedBuildWithTree($client);

    $client->request('POST', $this->actUrl($build), ['action' => 'passive.allocate', 'id' => 'near']);
    $client->request('POST', $this->actUrl($build), ['action' => 'passive.allocate', 'id' => 'leaf', 'set' => '1']);

    $crawler = $client->request('GET', $this->editUrl($build));
    $state = json_decode($crawler->filter('#build-state')->text(), true, flags: \JSON_THROW_ON_ERROR);

    self::assertContains('near', $state['allocatedBySet']['shared']);
    self::assertContains('leaf', $state['allocatedBySet']['1']);
    self::assertSame([], $state['allocatedBySet']['2']);
    self::assertSame('start', $state['startNodeId']);
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit --filter testTheCanvasStateSeparatesTheThreeAllocationGroups`
Expected: FAIL — `Undefined array key "allocatedBySet"`.

- [ ] **Step 3: Build the groups in EditorContext**

In `EditorContext::of()`, after `$document` is read:

```php
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
```

and add `'allocationBySet' => $allocationBySet` plus `'startNodeId' => $startNodeId` to the returned array, where `$startNodeId` comes from the build's class the same way Task 8's `contextOf()` does. If that lookup is wanted in both places, put it on a small shared service rather than duplicating the repository call — this is the seam the final review of slice A complained about, so do not copy it.

- [ ] **Step 4: Emit them in the state partial**

```twig
{% set allocated = document.passives|map(p => p.id)|filter(id => id is not null) %}
<script type="application/json" id="build-state" data-tree-target="state">
    {{ {
        allocated: allocated|merge([]),
        allocatedBySet: allocationBySet,
        startNodeId: startNodeId,
        ascendancy: build.ascendancyKey,
        classKey: build.classKey,
    }|json_encode|raw }}
</script>
```

- [ ] **Step 5: Run it and watch it pass**

Run: `ddev php bin/phpunit --filter BuildEditorControllerTest` then `ddev composer stan` then `ddev php bin/console lint:twig templates/`
Expected: PASS, PHPStan clean, Twig clean.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/EditorContext.php templates/build/_state.html.twig tests/Controller/BuildEditorControllerTest.php
git commit -m "feat(editor): hand the canvas its three allocation groups"
```

---

### Task 10: Three colours on the canvas

**Files:**
- Modify: `assets/lib/tree_renderer.js`
- Create: `tests/js/tree_renderer.test.js`

**Interfaces:**
- Consumes: nothing from PHP directly.
- Produces: `drawTree(context, { nodes, edges, allocatedBySet, startNodeId, hovered, highlighted }, camera)`. `allocatedBySet` is `{ shared: Set, one: Set, two: Set }`. `highlighted` is a `Set` of ids the search found (Task 12 fills it; an empty set until then). The old `allocated` parameter is gone — Task 11 updates the only caller.

Colours, chosen to stay distinguishable against the existing `#101014` ground and the existing `#e8c56a` allocated gold: shared keeps the gold, set 1 is `#6aa9e8` (blue), set 2 is `#7ad67a` (green). Highlighted nodes get a white ring, the same stroke the hover already uses, so the search result is visible without a new visual language.

- [ ] **Step 1: Write the failing test**

```js
import { describe, expect, it, vi } from 'vitest';
import { drawTree } from '../../assets/lib/tree_renderer.js';

function fakeContext() {
    const fills = [];
    return {
        canvas: { width: 400, height: 300 },
        get fillStyle() { return this._fill; },
        set fillStyle(value) { this._fill = value; },
        fills,
        clearRect: vi.fn(),
        beginPath: vi.fn(),
        moveTo: vi.fn(),
        lineTo: vi.fn(),
        stroke: vi.fn(),
        arc: vi.fn(),
        fill: vi.fn(function () { fills.push(this._fill); }),
    };
}

const camera = { scale: 1, x: 0, y: 0 };

function nodesAt(ids) {
    return new Map(ids.map((id, index) => [id, { id, name: id, kind: 'small', x: index * 10, y: 0 }]));
}

describe('drawTree', () => {
    it('paints the three allocation groups in three different colours', () => {
        const context = fakeContext();

        drawTree(context, {
            nodes: nodesAt(['shared_node', 'set_one_node', 'set_two_node']),
            edges: [],
            allocatedBySet: { shared: new Set(['shared_node']), one: new Set(['set_one_node']), two: new Set(['set_two_node']) },
            startNodeId: null,
            hovered: null,
            highlighted: new Set(),
        }, camera);

        expect(new Set(context.fills).size).toBe(3);
    });

    it('paints an unallocated node in neither allocation colour', () => {
        const context = fakeContext();

        drawTree(context, {
            nodes: nodesAt(['lonely']),
            edges: [],
            allocatedBySet: { shared: new Set(), one: new Set(), two: new Set() },
            startNodeId: null,
            hovered: null,
            highlighted: new Set(),
        }, camera);

        expect(context.fills).toEqual(['#6a6a7a']);
    });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev npm run test:js`
Expected: FAIL — all three nodes paint the same unallocated grey, so `new Set(context.fills).size` is 1.

- [ ] **Step 3: Colour by group**

```js
const COLOURS = {
    edge: '#3a3a46',
    edgeAllocated: '#c7a54a',
    small: '#6a6a7a',
    notable: '#9a8ad6',
    keystone: '#d67a7a',
    allocated: '#e8c56a',
    allocatedSetOne: '#6aa9e8',
    allocatedSetTwo: '#7ad67a',
    start: '#ffffff',
};
```

Replace the signature and the two places that consult `allocated`:

```js
export function drawTree(context, { nodes, edges, allocatedBySet, startNodeId, hovered, highlighted }, camera) {
    const { width, height } = context.canvas;
    const isAllocated = (id) => allocatedBySet.shared.has(id) || allocatedBySet.one.has(id) || allocatedBySet.two.has(id);
```

the edge loop becomes `context.strokeStyle = isAllocated(from) && isAllocated(to) ? COLOURS.edgeAllocated : COLOURS.edge;`, and the node fill becomes:

```js
        context.fillStyle = node.id === startNodeId
            ? COLOURS.start
            : allocatedBySet.one.has(node.id) ? COLOURS.allocatedSetOne
            : allocatedBySet.two.has(node.id) ? COLOURS.allocatedSetTwo
            : allocatedBySet.shared.has(node.id) ? COLOURS.allocated
            : (COLOURS[node.kind] ?? COLOURS.small);
```

After the existing hover stroke, add the highlight ring:

```js
        if (highlighted.has(node.id)) {
            context.strokeStyle = COLOURS.start;
            context.lineWidth = 3;
            context.stroke();
            context.lineWidth = 1.5;
        }
```

- [ ] **Step 4: Run it and watch it pass**

Run: `ddev npm run test:js`
Expected: PASS — 13 tests (11 existing + 2 new).

- [ ] **Step 5: Commit**

```bash
git add assets/lib/tree_renderer.js tests/js/tree_renderer.test.js
git commit -m "feat(editor): colour the two weapon sets apart from the shared tree"
```

---

### Task 11: The allocation mode above the canvas

**Files:**
- Modify: `assets/controllers/tree_controller.js`, `templates/build/_tree.html.twig`
- Test: `tests/e2e/editor.spec.js`

**Interfaces:**
- Consumes: Task 9's `allocatedBySet` / `startNodeId` in `#build-state`; Task 10's `drawTree` signature; Task 3's ten-field tuple.
- Produces: a `weaponSet` value on the Stimulus controller (`'shared' | '1' | '2'`, default `'shared'`) sent as the `set` field on every allocate request. The control is three radio inputs in `_tree.html.twig` with `data-action="change->tree#setWeaponSet"`, each labelled with its colour swatch.

This is plain client state, not view state that must survive an edit: a Turbo Stream replaces the tree section's *contents*, and the radios live in the section header above the canvas. Confirm that by running the e2e test below, which allocates and then checks the radio is still selected.

- [ ] **Step 1: Write the failing test**

Add to `tests/e2e/editor.spec.js`, following the existing spec's setup:

```js
test('the weapon set chosen above the canvas is the one a click allocates into', async ({ page }) => {
    await openEditor(page);

    await page.getByRole('radio', { name: 'Weapon set 1' }).check();
    await clickNodeOnCanvas(page, 'near');

    await expect(page.locator('#build-nodes')).toContainText('weapon set 1');
    await expect(page.getByRole('radio', { name: 'Weapon set 1' })).toBeChecked();
});
```

`openEditor` and `clickNodeOnCanvas` are the existing spec's helpers — reuse them rather than writing new ones. If `clickNodeOnCanvas` does not take a node id, extend it the way the existing canvas-click test locates its node.

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev npm run test:e2e`
Expected: FAIL — no radio named "Weapon set 1".

- [ ] **Step 3: Add the control**

In `templates/build/_tree.html.twig`, between the `<h2>` and the `<canvas>`:

```twig
    <fieldset class="weapon-set-mode">
        <legend>Clicks allocate into</legend>
        {% for value, label in {'shared': 'Both weapon sets', '1': 'Weapon set 1', '2': 'Weapon set 2'} %}
            <label>
                <input type="radio" name="weapon-set-mode" value="{{ value }}"
                       data-action="change->tree#setWeaponSet"
                       {{ value == 'shared' ? 'checked' : '' }}>
                <span class="swatch swatch-{{ value }}" aria-hidden="true"></span>
                {{ label }}
            </label>
        {% endfor %}
    </fieldset>
```

In `assets/styles/app.css`, the three swatches, using the same values as the renderer:

```css
.weapon-set-mode { border: 1px solid var(--line); display: flex; flex-wrap: wrap; gap: var(--gap); margin-bottom: 0.5rem; padding: 0.5rem; }
.swatch { border-radius: 50%; display: inline-block; height: 0.7rem; vertical-align: middle; width: 0.7rem; }
.swatch-shared { background: #e8c56a; }
.swatch-1 { background: #6aa9e8; }
.swatch-2 { background: #7ad67a; }
```

- [ ] **Step 4: Read the groups and send the set**

In `assets/controllers/tree_controller.js`:

```js
    connect() {
        this.weaponSet = 'shared';
        // ...whatever connect already does
    }

    setWeaponSet(event) {
        this.weaponSet = event.target.value;
    }
```

In `readState()`, replace the flat set with the three groups:

```js
    readState() {
        const state = JSON.parse(this.stateTarget.textContent);
        const bySet = state.allocatedBySet ?? { shared: [], 1: [], 2: [] };

        this.allocatedBySet = {
            shared: new Set(bySet.shared ?? []),
            one: new Set(bySet['1'] ?? []),
            two: new Set(bySet['2'] ?? []),
        };
        this.startNodeId = state.startNodeId ?? null;
        this.highlighted = new Set(state.highlighted ?? []);
    }
```

Everywhere the controller previously did `this.allocated.has(id)`, `.add(id)`, `.delete(id)` or copied it for the optimistic-update rollback, operate on the group instead. The allocate/deallocate decision becomes:

```js
    groupFor(weaponSet) {
        return weaponSet === '1' ? this.allocatedBySet.one : weaponSet === '2' ? this.allocatedBySet.two : this.allocatedBySet.shared;
    }

    isAllocated(id) {
        return this.allocatedBySet.shared.has(id) || this.allocatedBySet.one.has(id) || this.allocatedBySet.two.has(id);
    }
```

and the rollback snapshot copies all three sets rather than one. Send the set with the allocate request, alongside whatever fields the request already carries: `body.set = this.weaponSet === 'shared' ? '' : this.weaponSet;`.

Update the `drawTree` call in `redraw()` to pass `allocatedBySet`, `startNodeId` and `highlighted` instead of `allocated`.

Update the node-tuple destructuring where the payload is read: it now has ten fields. Keep reading positionally and name the two new ones so the next reader knows they exist, even though B1's canvas does not draw from them:

```js
            const [id, name, kind, ascendancyKey, x, y, stats, recipe, keystonesInRadius, unlockConstraint] = tuple;
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `ddev npm run test:e2e` then `ddev npm run test:js`
Expected: PASS — 3 Playwright tests, 13 vitest.

- [ ] **Step 6: Commit**

```bash
git add assets/controllers/tree_controller.js assets/styles/app.css templates/build/_tree.html.twig tests/e2e/editor.spec.js
git commit -m "feat(editor): choose which weapon set a canvas click allocates into"
```

---

### Task 12: Search finds and highlights instead of allocating

**Files:**
- Modify: `templates/build/_nodes.html.twig`, `templates/build/_state.html.twig`, `src/Controller/EditorContext.php`
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: Task 10's `highlighted` set; Task 11's `readState()`.
- Produces: `#build-state` gains `highlighted` — the ids the current passive search matched, `[]` when the box is empty. `_nodes.html.twig` renders no allocate form and no `passive.allocate` action anywhere.

From the spec: "The search box keeps its place but loses its allocate buttons: it now finds a node and highlights it on the canvas. Nothing in the DOM allocates or deallocates a passive any more." The justification is that the game's own tree is not keyboard or screen-reader operable, so a parallel text path invents a requirement the game does not meet.

- [ ] **Step 1: Write the failing test**

```php
public function testTheSearchHighlightsInsteadOfOfferingToAllocate(): void
{
    $client = static::createClient();
    $build = $this->seedBuildWithTree($client);

    $crawler = $client->request('GET', $this->editUrl($build).'?q=near');

    self::assertSame(0, $crawler->filter('input[value="passive.allocate"]')->count(), 'the tree is canvas-only now');
    self::assertStringContainsString('Near', $crawler->filter('#build-nodes')->text());

    $state = json_decode($crawler->filter('#build-state')->text(), true, flags: \JSON_THROW_ON_ERROR);
    self::assertSame(['near'], $state['highlighted']);
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit --filter testTheSearchHighlightsInsteadOfOfferingToAllocate`
Expected: FAIL — one `passive.allocate` input found, and `highlighted` is undefined.

- [ ] **Step 3: Strip the allocate form and publish the matches**

In `templates/build/_nodes.html.twig`, the result `<li>` loses its form entirely:

```twig
            {% for node in passiveResults %}
                <li>
                    <strong>{{ node.name }}</strong> <code>{{ node.id }}</code>
                    {% if node.stats %}<span>{{ node.stats|join(', ') }}</span>{% endif %}
                    {% if node.id in allocatedIds %}<span>allocated</span>{% endif %}
                </li>
            {% else %}
                <li>Nothing matches “{{ passiveQuery }}”.</li>
            {% endfor %}
```

and the list gains a line above it saying what the search now does, replacing any copy that told the reader to allocate from here:

```twig
        <p>Matches are ringed on the tree. Allocate by clicking them there.</p>
```

In `EditorContext::of()`, add `'highlightedIds' => array_column($passiveResults, 'id')` using whatever variable already holds the passive search results. In `_state.html.twig`, add `highlighted: highlightedIds`.

- [ ] **Step 4: Run it and watch it pass**

Run: `ddev php bin/phpunit --filter BuildEditorControllerTest` then `ddev php bin/console lint:twig templates/`
Expected: PASS, Twig clean.

- [ ] **Step 5: Check no other template still allocates a passive**

Run: `grep -rn "passive.allocate\|passive.deallocate" templates/`
Expected: no results. If anything remains, remove it — the spec's claim is that nothing in the DOM does this any more, and a leftover control makes that false.

- [ ] **Step 6: Commit**

```bash
git add templates/build src/Controller/EditorContext.php tests/Controller/BuildEditorControllerTest.php
git commit -m "feat(editor): let the search find a node instead of allocating it"
```

---

### Task 13: The stats overview splits three ways

**Files:**
- Modify: `src/Controller/EditorSearches.php`, `src/Controller/BuildEditorController.php`, `templates/build/_search_state.html.twig`, `templates/build/_nodes.html.twig`, `src/Controller/EditorContext.php`
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: `Allocation::idsVisibleTo()` (Task 4); the existing `StatSummary::of()`.
- Produces: a `stats` query parameter with values `shared` (default), `1`, `2`. `EditorSearches::all()` gains `statsView`. The two set views sum **shared plus that set** — the question being asked is what the build gives while that weapon set is equipped.

**The duplication this must not repeat.** A query key that survives an edit has to be registered in *two* places: rendered as a hidden field by `templates/build/_search_state.html.twig`, and listed in `BuildEditorController::searchParams()`, which forwards it on the non-Turbo redirect. That second registration has been missed twice in this project — for three of the five search terms and again for the interval toggle — each time producing a parameter that silently vanishes after a plain form POST. Register `stats` in both, and add the test below, which fails if only one is done.

- [ ] **Step 1: Write the failing test**

```php
public function testTheStatsViewSurvivesAnEditAndSumsSharedPlusTheChosenSet(): void
{
    $client = static::createClient();
    $build = $this->seedBuildWithStats($client);

    $crawler = $client->request('GET', $this->editUrl($build).'?stats=1');
    self::assertStringContainsString('10% increased Damage', $crawler->filter('#build-nodes')->text(), 'the shared node counts toward the set 1 view');
    self::assertStringContainsString('5% increased Damage', $crawler->filter('#build-nodes')->text());

    // A plain form POST redirects; the view must come back with it.
    $client->request('POST', $this->actUrl($build), [
        'action' => 'passive.interval_all',
        'from' => '1',
        'to' => '90',
        'stats' => '1',
    ]);
    $client->followRedirect();

    self::assertStringContainsString('stats=1', (string) $client->getHistory()->current()->getUri());
}
```

`seedBuildWithStats()` seeds two connected nodes carrying distinct stat text — a shared one with `10% increased Damage` and a set 1 one with `5% increased Damage` — and allocates both.

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit --filter testTheStatsViewSurvivesAnEditAndSumsSharedPlusTheChosenSet`
Expected: FAIL — the set 1 stat is absent, because the overview sums everything and knows nothing about `stats`.

- [ ] **Step 3: Read the parameter**

In `EditorSearches`, add `statsView` alongside the existing five terms and the two interval overrides, read the same nullsafe way, defaulting to `'shared'` when absent or when the value is not one of `shared`, `1`, `2`.

In `BuildEditorController::searchParams()`, add `'stats'` to the forwarded whitelist.

In `templates/build/_search_state.html.twig`, add the hidden field beside the others:

```twig
<input type="hidden" name="stats" value="{{ statsView }}">
```

- [ ] **Step 4: Sum the chosen view**

In `EditorContext::of()`, build the summary from the ids the view covers rather than from every allocated id:

```php
        $view = match ($searches['statsView']) {
            '1' => WeaponSet::One,
            '2' => WeaponSet::Two,
            default => WeaponSet::Shared,
        };

        $statsById = [];
        foreach ($allocation->idsVisibleTo($view) as $id) {
            $statsById[$id] = $detail[$id]['stats'] ?? [];
        }
```

replacing the existing loop that walked `$allocatedIds`.

- [ ] **Step 5: Render the control**

In `templates/build/_nodes.html.twig`, above the "What the tree gives" heading:

```twig
    <p class="stats-view">
        {% for value, label in {'shared': 'Both sets only', '1': 'With weapon set 1', '2': 'With weapon set 2'} %}
            {% if value == statsView %}
                <strong>{{ label }}</strong>
            {% else %}
                <a href="{{ path('app_build_edit', {slug: build.shareSlug, token: token, stats: value, q: passiveQuery}) }}">{{ label }}</a>
            {% endif %}
        {% endfor %}
    </p>
```

and change the heading so it says which view is showing:

```twig
    <h3>What the tree gives{% if statsView != 'shared' %} with weapon set {{ statsView }}{% endif %} ({{ document.passives|length }} passives)</h3>
```

- [ ] **Step 6: Run it and watch it pass**

Run: `ddev php bin/phpunit --filter BuildEditorControllerTest` then `ddev composer stan` then `ddev php bin/console lint:twig templates/`
Expected: PASS, PHPStan clean, Twig clean.

- [ ] **Step 7: Commit**

```bash
git add src/Controller templates/build tests/Controller/BuildEditorControllerTest.php
git commit -m "feat(editor): read the tree's stats per weapon set"
```

---

### Task 14: The whole path, end to end

**Files:**
- Modify: `tests/e2e/editor.spec.js`
- Modify: `docs/specs/2026-09-09-poe2-build-helper-design.md`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing new; this task proves the feature in a browser and records what was measured.

The tooltip defect in slice A shipped green because the only automated check asserted text and never position. The lesson generalises: a canvas feature needs at least one assertion about what the canvas *did*, not only about what the DOM says afterwards.

- [ ] **Step 1: Write the failing test**

```js
test('an illegal node cannot be allocated from the canvas', async ({ page }) => {
    await openEditor(page);

    const before = await page.locator('#build-nodes').textContent();
    await clickNodeOnCanvas(page, 'far');

    await expect(page.locator('.error')).toContainText('not connected');
    await expect(page.locator('#build-nodes')).toHaveText(before ?? '');
});

test('removing a junction takes its branch with it', async ({ page }) => {
    await openEditor(page);

    await clickNodeOnCanvas(page, 'near');
    await clickNodeOnCanvas(page, 'leaf');
    await expect(page.locator('#build-nodes')).toContainText('2 passives');

    await clickNodeOnCanvas(page, 'near');

    await expect(page.locator('#build-nodes')).toContainText('0 passives');
    await expect(page.locator('#build-history')).toContainText('and 1 more');
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `ddev npm run test:e2e`
Expected: both FAIL if any wiring from Tasks 8-12 is incomplete. If they pass immediately, check that `clickNodeOnCanvas` is really hitting the node — a click that misses produces the same "nothing happened" as a correct refusal, and that ambiguity is what the `before`/`after` comparison in the first test is there to break.

- [ ] **Step 3: Fix whatever they catch**

No new production code is planned here. If these tests fail, the defect is in Tasks 8-12; fix it there and note what was wrong in the task's report.

- [ ] **Step 4: Run the whole gate**

Run: `ddev composer gate`
Expected: green — PHPUnit, vitest, Playwright, PHPStan, php-cs-fixer all pass.

- [ ] **Step 5: Record what was measured**

In the spec's "Iteration 3 refinements (2026-09-12)" section, under "Weapon sets are allocated, coloured and summed separately", append one line stating the figures this slice verified against the real catalog: the node count, how many carry `keystones_in_radius`, and how many carry `unlock_constraint`, with the date. Use the numbers Task 2 Step 8 actually printed, not the ones quoted in this plan — if they differ, the difference is the finding.

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/editor.spec.js docs/specs/2026-09-09-poe2-build-helper-design.md
git commit -m "test(editor): prove legality and the cascade in a browser"
```

---

## Self-Review

**Spec coverage.** Every slice-B1 requirement maps to a task: canvas-only editing → 12; the four rules → 6, enforced in 8; `keystones_in_radius` / `unlock_constraint` synced → 1, 2, 3; cascading deallocation → 6 (`illegalAfter`), 7 (`deallocatePassives`), 8 (the event and its sentence); weapon sets → 4 (model), 9 (state), 10 (colours), 11 (mode), 13 (summary), with per-set connectivity in 6. The spec's "Carried into slice B: one place to register view state" is **deliberately not** collapsed into one declaration here — Task 13 adds the third consumer and guards it with a test instead. That is a smaller change than the refactor the spec asks for, and it leaves the duplication in place; if the reviewer rates that a defect, the fix is a single `ViewState` class listing the keys that both the template and `searchParams()` read.

**Placeholder scan.** No "TBD", no "handle edge cases", no "similar to Task N". Three tasks tell the implementer to follow an existing helper pattern rather than quoting it (Task 2's fetcher stub, Task 8's `seedBuildWithTree`, Task 11's Playwright helpers) — in each case because the pattern already exists in that file and quoting a guess at it would be worse than pointing at it.

**Type consistency.** `WeaponSet` is the enum everywhere; `'shared' | '1' | '2'` strings appear only at the HTTP and JSON boundaries (Tasks 9, 11, 13) and are mapped to the enum immediately. `allocatedBySet` uses the keys `shared`/`1`/`2` in JSON and `shared`/`one`/`two` in JavaScript — deliberate, because JS object keys `1` and `2` invite numeric coercion; Task 11's `readState()` is the single place that translates. `AllocationRules` takes `(Allocation, TreeContext, string, WeaponSet)` in that order in both public methods.

**One known risk.** Task 8 computes the cascade before `apply()` and again inside it via `deallocatePassives`; if `BuildEditor::apply()` turns out to re-read the build in a way that makes the pre-computed `$also` stale, the handler needs the payload written after the mutation instead. The task says to check `BuildEditor` for an existing loader first, which is where that would surface.
