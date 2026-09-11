# Iteration 3 — Editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the build page from an upload-and-replace form into a real editor: header fields, a canvas-rendered passive tree with an accessible node list beside it, skills with supports, equipment slots, and a revertible edit history.

**Architecture:** Every edit is one command posted to a single endpoint (`POST /b/{slug}/edit/{token}/act`), dispatched over Symfony Messenger's synchronous bus to a handler that mutates the build, appends a `build_event` row, and flushes. The endpoint answers with Turbo Streams that re-render every editor area — including a `<script type="application/json">` state tag that the canvas Stimulus controller reads, because nothing in a canvas is DOM. Plain HTML forms post the same body and get a redirect instead, so the editor works without JavaScript apart from the canvas itself.

**Tech Stack:** PHP 8.4 / Symfony 8.1, Doctrine ORM + MariaDB, Twig, symfony/messenger, AssetMapper + Stimulus + Turbo (no bundler), vitest for pure JS logic, Playwright for one canvas smoke test.

**Spec:** `docs/specs/2026-09-09-poe2-build-helper-design.md`, section "Iteration 3 design — editor" (committed in `4a77b22`). Read that section before starting; this plan implements it and does not repeat its reasoning.

## Global Constraints

- **Quality gate:** `composer gate` = `@cs` (php-cs-fixer, dry run), `@stan` (PHPStan **level max**, paths `src` and `tests`), `@test` (PHPUnit). Every task ends green.
- **PHP/Symfony versions:** PHP 8.4, all `symfony/*` packages pinned `8.1.*`. Any new Symfony package is required as `8.1.*`.
- **Database:** MariaDB only, in dev and in test. No SQLite shortcut — JSON and generated columns behave differently. Apply migrations to both with `composer db:migrate`.
- **Language:** English throughout — code, comments, commit messages, UI copy.
- **Dependencies:** prefer PSR-compliant and already-installed Symfony packages over a hand-rolled equivalent (see `CLAUDE.md`).
- **`.build` format fidelity (measured, not assumed):**
  - `level_interval` is a two-element array `[from, to]`; level values run **0 to 100**.
  - `weapon_set` is `1` or `2` only.
  - Passive ids are stored **verbatim** — 527 of them end in an underscore (`strength65_`).
  - Gem ids are stored **verbatim** — three path prefixes coexist (`Metadata/Items/Gem/`, `Metadata/Items/Gems/`, `Metadata/items/Gems/`). Never normalise them.
  - `additional_text` is present on passives and inventory slots, **never on skills**.
  - The `Inventories` vocabulary is exactly 14 values: `Weapon1`, `Weapon2`, `Offhand1`, `Offhand2`, `Helm1`, `BodyArmour1`, `Gloves1`, `Boots1`, `Belt1`, `Amulet1`, `Ring1`, `Ring2`, `Trinket1`, `Flask1`.
  - `ascendancy` is an identifier like `Sorceress3` — the same key space as the tree export's `ascendancyId`.
- **App-only fields never enter the export.** `class_key`, `target_level`, `note` and `archetype_key` are columns on `build` only. They must never reach `BuildDocument`, the `document` JSON column, or `BuildDocumentWriter` output — an export must contain only fields GGG documents for format version 1.
- **No upstream data in the repository.** Test fixtures mimic the *shape* of upstream exports with invented content. Never commit real RePoE or skill-tree excerpts (measure 5).
- **Defaults for newly created entries** (taken from the real-file corpus):
  - passive: `{"id": …, "level_interval": [1, 100], "additional_text": ""}`
  - skill: `{"id": …, "level_interval": [1, 100]}` — no `support_skills` key until a support is added
  - support: `{"id": …, "level_interval": [1, 100]}`
  - inventory slot: `{"inventory_id": …, "slot_x": 0, "slot_y": 0, "level_interval": [1, 100], "additional_text": ""}`

---

### Task 1: Classes and start nodes in the passive tree catalog

The tree export's `classes[]` array is currently thrown away. The editor needs it: a class dropdown, and the start node the camera centres on. Each start node carries `classStartIndex: [a, b]` — a pair of indices into `classes[]` — so six physical nodes serve all twelve classes.

**Files:**
- Modify: `src/Catalog/NormalizedTree.php`
- Modify: `src/Catalog/PassiveTreeNormalizer.php`
- Modify: `src/Catalog/PassiveTreeSync.php`
- Create: `src/Entity/CatalogClass.php`
- Create: `migrations/Version20260912080000.php`
- Modify: `tests/fixtures/catalog/tree-shape.json`
- Test: `tests/Catalog/PassiveTreeNormalizerTest.php` (modify), `tests/Catalog/PassiveTreeSyncTest.php` (modify)

**Interfaces:**
- Consumes: `PassiveTreeNormalizer::normalize(string $json): NormalizedTree`, `NormalizedTree::$nodes`, `NormalizedTree::$edges` (unchanged).
- Produces:
  - `NormalizedTree::$classes` — `list<array{id: string, start_node_id: string, base_str: int, base_dex: int, base_int: int, ascendancies: list<array{id: string, name: string}>}>`
  - Table `catalog_class` and entity `App\Entity\CatalogClass` with `getId(): string`, `getStartNodeId(): string`, `getBaseStr/Dex/Int(): int`, `getAscendancies(): list<array{id: string, name: string}>`

- [ ] **Step 1: Extend the synthetic fixture with class data**

Add a `classes` array and a start node to `tests/fixtures/catalog/tree-shape.json`. Replace the existing `"classes"` line and add one node. The fixture stays entirely invented — no upstream content.

```json
    "classes": [
        {"name": "Warrior", "base_str": 15, "base_dex": 7, "base_int": 7, "ascendancies": [{"id": "Synthetic1", "name": "Synthetic Ascendant"}]},
        {"name": "Sorceress", "base_str": 7, "base_dex": 7, "base_int": 15, "ascendancies": []}
    ],
```

And inside `"nodes"`, add a start node plus point `root` at it:

```json
        "root": {"group": 0, "orbit": 0, "orbitIndex": 0, "out": ["1000", "1001"], "in": [], "edges": [0]},
        "1000": {
            "id": "syntheticstart1",
            "skill": 1000,
            "name": "WARRIOR",
            "classStartIndex": [0, 1],
            "group": 1, "orbit": 0, "orbitIndex": 0,
            "x": 0.0, "y": 0.0,
            "out": ["1001"], "in": ["root"], "edges": [0]
        },
```

- [ ] **Step 2: Write the failing normalizer tests**

Add to `tests/Catalog/PassiveTreeNormalizerTest.php`:

```php
    public function testEveryClassIsReadFromTheExportWithItsStartNode(): void
    {
        $classes = array_column($this->normalize()->classes, null, 'id');

        self::assertCount(2, $classes);
        self::assertSame('syntheticstart1', $classes['Warrior']['start_node_id']);
        self::assertSame('syntheticstart1', $classes['Sorceress']['start_node_id'], 'two classes share one physical start node');
        self::assertSame(15, $classes['Warrior']['base_str']);
    }

    public function testAClassCarriesItsAscendancies(): void
    {
        $classes = array_column($this->normalize()->classes, null, 'id');

        self::assertSame([['id' => 'Synthetic1', 'name' => 'Synthetic Ascendant']], $classes['Warrior']['ascendancies']);
        self::assertSame([], $classes['Sorceress']['ascendancies']);
    }
```

- [ ] **Step 3: Run them and watch them fail**

Run: `php bin/phpunit tests/Catalog/PassiveTreeNormalizerTest.php`
Expected: FAIL — `Undefined property: App\Catalog\NormalizedTree::$classes`.

- [ ] **Step 4: Carry classes on NormalizedTree**

In `src/Catalog/NormalizedTree.php`, add the type alias and the property:

```php
 * @phpstan-type PassiveNode array{id: string, name: string, kind: string, ascendancy_key: string|null, pos_x: float, pos_y: float, stats: list<string>}
 * @phpstan-type PassiveClass array{id: string, start_node_id: string, base_str: int, base_dex: int, base_int: int, ascendancies: list<array{id: string, name: string}>}
 */
final readonly class NormalizedTree
{
    /**
     * @param list<PassiveNode>           $nodes
     * @param list<array{string, string}> $edges
     * @param list<PassiveClass>          $classes
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public array $classes = [],
        public ?string $treeVariant = null,
    ) {
    }
}
```

- [ ] **Step 5: Read classes in the normalizer**

In `src/Catalog/PassiveTreeNormalizer.php`, pass the new argument in `normalize()` (note `classes:` is named, so the existing `treeVariant:` call stays correct):

```php
        return new NormalizedTree(
            nodes: $nodes,
            edges: $this->edges($rawNodes, $allocatable),
            classes: $this->classes($data, $rawNodes),
            treeVariant: \is_string($data['tree'] ?? null) ? $data['tree'] : null,
        );
```

And add the two private methods. `classStartIndex` is the only link between a class and its position on the tree, so a class without one is dropped rather than guessed at:

```php
    /**
     * The export names classes in one array and marks start nodes with the
     * indices they serve. Six nodes cover twelve classes, in pairs.
     *
     * @param array<string, mixed>                    $data
     * @param array<string, array<string, mixed>>     $rawNodes
     *
     * @return list<array{id: string, start_node_id: string, base_str: int, base_dex: int, base_int: int, ascendancies: list<array{id: string, name: string}>}>
     */
    private function classes(array $data, array $rawNodes): array
    {
        $startNodes = $this->startNodesByClassIndex($rawNodes);
        $classes = [];

        foreach (array_values((array) ($data['classes'] ?? [])) as $index => $class) {
            if (!\is_array($class) || !\is_string($class['name'] ?? null) || !isset($startNodes[$index])) {
                continue;
            }

            $classes[] = [
                'id' => $class['name'],
                'start_node_id' => $startNodes[$index],
                'base_str' => is_numeric($class['base_str'] ?? null) ? (int) $class['base_str'] : 0,
                'base_dex' => is_numeric($class['base_dex'] ?? null) ? (int) $class['base_dex'] : 0,
                'base_int' => is_numeric($class['base_int'] ?? null) ? (int) $class['base_int'] : 0,
                'ascendancies' => $this->ascendancies($class['ascendancies'] ?? []),
            ];
        }

        return $classes;
    }

    /**
     * @param array<string, array<string, mixed>> $rawNodes
     *
     * @return array<int, string> class index => passive id of its start node
     */
    private function startNodesByClassIndex(array $rawNodes): array
    {
        $starts = [];

        foreach ($rawNodes as $node) {
            $id = $node['id'] ?? null;
            if (!\is_string($id) || !\is_array($node['classStartIndex'] ?? null)) {
                continue;
            }

            foreach ($node['classStartIndex'] as $index) {
                if (\is_int($index)) {
                    $starts[$index] = $id;
                }
            }
        }

        return $starts;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function ascendancies(mixed $raw): array
    {
        $ascendancies = [];

        foreach ((array) $raw as $ascendancy) {
            if (\is_array($ascendancy) && \is_string($ascendancy['id'] ?? null) && \is_string($ascendancy['name'] ?? null)) {
                $ascendancies[] = ['id' => $ascendancy['id'], 'name' => $ascendancy['name']];
            }
        }

        return $ascendancies;
    }
```

- [ ] **Step 6: Run the normalizer tests to verify they pass**

Run: `php bin/phpunit tests/Catalog/PassiveTreeNormalizerTest.php`
Expected: PASS, all tests.

- [ ] **Step 7: Add the entity**

Create `src/Entity/CatalogClass.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One of the game's twelve classes, keyed by the name the tree export writes.
 *
 * `startNodeId` is what the editor centres the tree on. Six nodes serve all
 * twelve classes — two classes always share one physical start position — so
 * this is not a unique column.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_class')]
class CatalogClass
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(length: 128)]
    private string $startNodeId;

    #[ORM\Column]
    private int $baseStr = 0;

    #[ORM\Column]
    private int $baseDex = 0;

    #[ORM\Column]
    private int $baseInt = 0;

    /** @var list<array{id: string, name: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $ascendancies = [];

    public function getId(): string
    {
        return $this->id;
    }

    public function getStartNodeId(): string
    {
        return $this->startNodeId;
    }

    public function getBaseStr(): int
    {
        return $this->baseStr;
    }

    public function getBaseDex(): int
    {
        return $this->baseDex;
    }

    public function getBaseInt(): int
    {
        return $this->baseInt;
    }

    /** @return list<array{id: string, name: string}> */
    public function getAscendancies(): array
    {
        return $this->ascendancies;
    }
}
```

- [ ] **Step 8: Add the migration**

Create `migrations/Version20260912080000.php` (the timestamp must sort after `Version20260911113302`):

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog_class: the twelve classes, their attribute bases, their ascendancies and the tree node each one starts on.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_class (id VARCHAR(64) NOT NULL, start_node_id VARCHAR(128) NOT NULL, base_str INT NOT NULL, base_dex INT NOT NULL, base_int INT NOT NULL, ascendancies JSON NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_class');
    }
}
```

Run: `composer db:migrate`

- [ ] **Step 9: Write the failing sync test**

In `tests/Catalog/PassiveTreeSyncTest.php`, follow the existing test's arrangement (read it first) and add:

```php
    public function testTheSyncStoresTheClassesFromTheExport(): void
    {
        $this->sync()->run();

        $rows = $this->connection()->fetchAllAssociative('SELECT id, start_node_id FROM catalog_class ORDER BY id');

        self::assertSame(
            [['id' => 'Sorceress', 'start_node_id' => 'syntheticstart1'], ['id' => 'Warrior', 'start_node_id' => 'syntheticstart1']],
            $rows,
        );
    }

    public function testARerunReplacesClassesRatherThanAccumulating(): void
    {
        $this->sync()->run();
        $this->sync()->run();

        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM catalog_class'));
    }
```

Adapt `$this->sync()` / `$this->connection()` to whatever helpers that test class already uses.

- [ ] **Step 10: Run it and watch it fail**

Run: `php bin/phpunit tests/Catalog/PassiveTreeSyncTest.php`
Expected: FAIL — `catalog_class` is empty.

- [ ] **Step 11: Write classes in the sync**

In `src/Catalog/PassiveTreeSync.php`, inside the `replace()` transaction, delete and re-insert classes alongside nodes and edges. Classes are deleted **first** and written **last** so a start node it points at always exists:

```php
            $db->executeStatement('DELETE FROM catalog_class');
            $db->executeStatement('DELETE FROM catalog_passive_edge');
            $db->executeStatement('DELETE FROM catalog_passive');
```

and after the edge loop:

```php
            foreach ($tree->classes as $class) {
                $db->executeStatement(
                    'INSERT INTO catalog_class (id, start_node_id, base_str, base_dex, base_int, ascendancies) VALUES (?, ?, ?, ?, ?, ?)',
                    [$class['id'], $class['start_node_id'], $class['base_str'], $class['base_dex'], $class['base_int'], json_encode($class['ascendancies'], \JSON_THROW_ON_ERROR)],
                );
            }
```

- [ ] **Step 12: Run the gate**

Run: `composer gate`
Expected: green.

- [ ] **Step 13: Commit**

```bash
git add src/Catalog src/Entity/CatalogClass.php migrations/Version20260912080000.php tests/
git commit -m "$(cat <<'EOF'
feat(catalog): sync the twelve classes and their tree start nodes

The export links a class to the tree only through classStartIndex on six
shared start nodes. The editor needs that link for the class dropdown and
for centring the tree.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 2: `/catalog/tree.json` — the tree payload for the browser

The canvas needs all 4912 nodes and 6076 edges in one request. It changes only when the catalog is re-synced, so it is cached against `catalog_sync`.

**Files:**
- Create: `src/Catalog/TreeExport.php`
- Modify: `src/Controller/CatalogController.php`
- Test: `tests/Controller/CatalogControllerTest.php` (modify)

**Interfaces:**
- Consumes: table `catalog_passive`, `catalog_passive_edge`, `catalog_class` (Task 1), `catalog_sync`.
- Produces:
  - `TreeExport::payload(): array{nodes: list<array{0: string, 1: string, 2: string, 3: string|null, 4: float, 5: float}>, edges: list<array{0: string, 1: string}>, classes: list<array{id: string, start_node_id: string, ascendancies: list<array{id: string, name: string}>}>}`
  - `TreeExport::revision(): ?\DateTimeImmutable`
  - Route `app_catalog_tree` at `GET /catalog/tree.json`
  - **Node tuple order, relied on by the JS in Tasks 16–17: `[id, name, kind, ascendancy_key, x, y]`.**

- [ ] **Step 1: Write the failing controller test**

Add to `tests/Controller/CatalogControllerTest.php` (read its setup first and reuse it):

```php
    public function testTheTreePayloadIsEmptyButValidWithoutCatalogData(): void
    {
        $this->client->request('GET', '/catalog/tree.json');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['nodes' => [], 'edges' => [], 'classes' => []], $payload);
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php bin/phpunit tests/Controller/CatalogControllerTest.php`
Expected: FAIL — 404, no such route.

- [ ] **Step 3: Write the export service**

Create `src/Catalog/TreeExport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * The whole passive tree in one payload, for the canvas renderer.
 *
 * Nodes are tuples rather than objects: five thousand of them with six named
 * keys each is a payload several times larger than it needs to be, and the
 * only consumer is our own Stimulus controller. The order is fixed by
 * contract: id, name, kind, ascendancy key, x, y.
 */
final class TreeExport
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{nodes: list<array{0: string, 1: string, 2: string, 3: string|null, 4: float, 5: float}>, edges: list<array{0: string, 1: string}>, classes: list<array{id: string, start_node_id: string, ascendancies: list<array{id: string, name: string}>}>}
     */
    public function payload(): array
    {
        $nodes = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, name, kind, ascendancy_key, pos_x, pos_y FROM catalog_passive') as $row) {
            $nodes[] = [
                Row::str($row, 'id'),
                Row::str($row, 'name'),
                Row::str($row, 'kind'),
                Row::nullableStr($row, 'ascendancy_key'),
                (float) Row::str($row, 'pos_x', '0'),
                (float) Row::str($row, 'pos_y', '0'),
            ];
        }

        $edges = [];
        foreach ($this->db->fetchAllAssociative('SELECT from_id, to_id FROM catalog_passive_edge') as $row) {
            $edges[] = [Row::str($row, 'from_id'), Row::str($row, 'to_id')];
        }

        $classes = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, start_node_id, ascendancies FROM catalog_class ORDER BY id') as $row) {
            $decoded = json_decode(Row::str($row, 'ascendancies', '[]'), true);
            $ascendancies = [];
            foreach (\is_array($decoded) ? $decoded : [] as $ascendancy) {
                if (\is_array($ascendancy) && \is_string($ascendancy['id'] ?? null) && \is_string($ascendancy['name'] ?? null)) {
                    $ascendancies[] = ['id' => $ascendancy['id'], 'name' => $ascendancy['name']];
                }
            }

            $classes[] = [
                'id' => Row::str($row, 'id'),
                'start_node_id' => Row::str($row, 'start_node_id'),
                'ascendancies' => $ascendancies,
            ];
        }

        return ['nodes' => $nodes, 'edges' => $edges, 'classes' => $classes];
    }

    /**
     * When the stored tree last changed. Null means nothing has been synced.
     */
    public function revision(): ?\DateTimeImmutable
    {
        $value = $this->db->fetchOne("SELECT MAX(ran_at) FROM catalog_sync WHERE source = 'passive_tree' AND status = 'ok'");

        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }
}
```

`Row::str($row, 'pos_x', '0')` reads the float back as a string from DBAL; the cast that follows is the deliberate conversion.

- [ ] **Step 4: Add the route**

In `src/Controller/CatalogController.php`, inject `TreeExport $tree` into the constructor and add:

```php
    /**
     * Cached against the sync rather than a fixed lifetime: the tree changes
     * when we adopt a patch and at no other time.
     */
    #[Route('/catalog/tree.json', name: 'app_catalog_tree', methods: ['GET'])]
    public function treeData(Request $request): Response
    {
        $revision = $this->tree->revision();
        $response = new Response();
        $response->setPublic();

        if (null !== $revision) {
            $response->setLastModified($revision);
            $response->setEtag(md5($revision->format(\DATE_ATOM)));

            if ($response->isNotModified($request)) {
                return $response;
            }
        }

        $payload = null === $revision ? ['nodes' => [], 'edges' => [], 'classes' => []] : $this->tree->payload();
        $response->setContent(json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php bin/phpunit tests/Controller/CatalogControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Run the gate and commit**

```bash
composer gate
git add src/Catalog/TreeExport.php src/Controller/CatalogController.php tests/Controller/CatalogControllerTest.php
git commit -m "$(cat <<'EOF'
feat(catalog): serve the passive tree as one cached JSON payload

Nodes as tuples, cached against the passive_tree sync: the canvas needs
all 4912 nodes at once, and they change only when a patch is adopted.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 3: Build — the app-only planning columns

`class_key`, `target_level`, `note` and `archetype_key` are planning metadata the `.build` format has no room for. The load-bearing test is that they change nothing about the export.

**Files:**
- Modify: `src/Entity/Build.php`
- Create: `migrations/Version20260912080100.php`
- Test: `tests/Build/BuildPersistenceTest.php` (modify)

**Interfaces:**
- Consumes: `Build::toDocument()`, `Build::applyDocument()`.
- Produces, all on `App\Entity\Build`:
  - `getClassKey(): ?string` / `setClassKey(?string $classKey): void`
  - `getTargetLevel(): ?int` / `setTargetLevel(?int $targetLevel): void`
  - `getNote(): ?string` / `setNote(?string $note): void`
  - `getArchetypeKey(): ?string` / `setArchetypeKey(?string $archetypeKey): void`
  - `getAuthor(): ?string` / `setAuthor(?string $author): void`
  - `getLink(): ?string` / `setLink(?string $link): void`
  - `getDescription(): ?string` / `setDescription(?string $description): void`
  - `getAscendancyKey(): ?string` / `setAscendancyKey(?string $ascendancyKey): void`
  - `setName(string $name): void`

- [ ] **Step 1: Write the failing test**

Add to `tests/Build/BuildPersistenceTest.php`:

```php
    public function testPlanningFieldsSurvivePersistenceAndStayOutOfTheExport(): void
    {
        $json = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($json);
        $document = new BuildDocumentReader()->read($json);

        $build = $this->builds->create($document, '0.5.5');
        $build->setClassKey('Warrior');
        $build->setTargetLevel(92);
        $build->setNote('Swap to the second weapon set at 60.');
        $build->setArchetypeKey('slam');
        $slug = $build->getShareSlug();
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->builds->findOneByShareSlug($slug);

        self::assertNotNull($reloaded);
        self::assertSame('Warrior', $reloaded->getClassKey());
        self::assertSame(92, $reloaded->getTargetLevel());
        self::assertSame('slam', $reloaded->getArchetypeKey());
        self::assertEquals($document, $reloaded->toDocument(), 'planning fields never reach the exported document');
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php bin/phpunit tests/Build/BuildPersistenceTest.php`
Expected: FAIL — `Call to undefined method App\Entity\Build::setClassKey()`.

- [ ] **Step 3: Add the columns, getters and setters**

In `src/Entity/Build.php`, after `$gameVersion`, add:

```php
    /**
     * Planning fields. The Build Planner format has no room for them, so they
     * are columns here and never enter `document` or `BuildDocument` — an
     * export must carry only fields GGG documents for version 1.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $classKey = null;

    #[ORM\Column(nullable: true)]
    private ?int $targetLevel = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $archetypeKey = null;
```

Then add the accessors. Every setter touches `updatedAt`, because a header edit is an edit:

```php
    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): void
    {
        $this->author = $author;
        $this->touch();
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): void
    {
        $this->link = $link;
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function getAscendancyKey(): ?string
    {
        return $this->ascendancyKey;
    }

    public function setAscendancyKey(?string $ascendancyKey): void
    {
        $this->ascendancyKey = $ascendancyKey;
        $this->touch();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getClassKey(): ?string
    {
        return $this->classKey;
    }

    public function setClassKey(?string $classKey): void
    {
        $this->classKey = $classKey;
        $this->touch();
    }

    public function getTargetLevel(): ?int
    {
        return $this->targetLevel;
    }

    public function setTargetLevel(?int $targetLevel): void
    {
        $this->targetLevel = $targetLevel;
        $this->touch();
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
        $this->touch();
    }

    public function getArchetypeKey(): ?string
    {
        return $this->archetypeKey;
    }

    public function setArchetypeKey(?string $archetypeKey): void
    {
        $this->archetypeKey = $archetypeKey;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
```

Replace the assignment at the end of `applyDocument()` with `$this->touch();`.

- [ ] **Step 4: Add the migration**

Create `migrations/Version20260912080100.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912080100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the planning fields to build: class, target level, note and archetype. They never enter the exported document.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE build ADD class_key VARCHAR(64) DEFAULT NULL, ADD target_level INT DEFAULT NULL, ADD note LONGTEXT DEFAULT NULL, ADD archetype_key VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE build DROP class_key, DROP target_level, DROP note, DROP archetype_key');
    }
}
```

Run: `composer db:migrate`

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Build tests/Interchange`
Expected: PASS — including the round-trip tests, which prove the export is unchanged.

- [ ] **Step 6: Run the gate and commit**

```bash
composer gate
git add src/Entity/Build.php migrations/Version20260912080100.php tests/Build/BuildPersistenceTest.php
git commit -m "$(cat <<'EOF'
feat(build): add the planning fields the .build format has no room for

Class, target level, note and archetype are columns on build only. The
round-trip tests stand as the proof that the export is unchanged.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 4: `build_event` — snapshots and the history record

Autosave means nothing is ever explicitly saved, so undoing needs its own mechanism. Every command appends one row carrying the full editable state after it.

**Files:**
- Create: `src/Entity/BuildEvent.php`
- Create: `src/Repository/BuildEventRepository.php`
- Create: `src/Build/Edit/BuildSnapshot.php`
- Create: `src/Build/Edit/EditHistory.php`
- Create: `migrations/Version20260912080200.php`
- Test: `tests/Build/EditHistoryTest.php`

**Interfaces:**
- Consumes: `Build` accessors from Task 3, `BuildDocument`.
- Produces:
  - `BuildSnapshot::capture(Build $build): array{document: array{name: string, author: string|null, link: string|null, description: string|null, ascendancy: string|null, passives: list<array<string, mixed>>, skills: list<array<string, mixed>>, inventory_slots: list<array<string, mixed>>}, header: array{class_key: string|null, target_level: int|null, note: string|null, archetype_key: string|null}}`
  - `BuildSnapshot::restore(Build $build, array $snapshot): void`
  - `EditHistory::record(Build $build, string $action, array $payload, ?string $snapshotName = null): BuildEvent` — `$payload` is `array<string, scalar|null>`
  - `BuildEventRepository::timeline(Build $build, int $limit = 50): list<BuildEvent>`
  - `BuildEventRepository::findForBuild(Build $build, int $id): ?BuildEvent`
  - `BuildEventRepository::prune(\DateTimeImmutable $cutoff): int`
  - `BuildEvent` getters: `getId(): ?int`, `getAction(): string`, `getCreatedAt(): \DateTimeImmutable`, `getPayload(): array<string, scalar|null>`, `getSnapshot(): array`, `isNamedSnapshot(): bool`, `getSnapshotName(): ?string`

- [ ] **Step 1: Write the failing test**

Create `tests/Build/EditHistoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\BuildSnapshot;
use App\Build\Edit\EditHistory;
use App\Entity\Build;
use App\Entity\BuildEvent;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildEventRepository;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EditHistoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BuildRepository $builds;
    private BuildEventRepository $events;
    private EditHistory $history;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->builds = self::getContainer()->get(BuildRepository::class);
        $this->events = self::getContainer()->get(BuildEventRepository::class);
        $this->history = self::getContainer()->get(EditHistory::class);
        $this->entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $this->entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    public function testAnEventKeepsTheWholeEditableStateAtThatMoment(): void
    {
        $build = $this->build();
        $build->setNote('before');

        $this->history->record($build, 'header.set', ['field' => 'note']);
        $build->setNote('after');
        $this->entityManager->flush();

        $timeline = $this->events->timeline($build);

        self::assertCount(1, $timeline);
        self::assertSame('header.set', $timeline[0]->getAction());
        self::assertSame('before', $timeline[0]->getSnapshot()['header']['note'], 'the snapshot is the state at record time');
        self::assertSame('Titan Earthquake Slam', $timeline[0]->getSnapshot()['document']['name']);
    }

    public function testRestoringASnapshotPutsBothDocumentAndPlanningFieldsBack(): void
    {
        $build = $this->build();
        $build->setTargetLevel(90);
        $snapshot = BuildSnapshot::capture($build);

        $build->setTargetLevel(12);
        $build->setName('Renamed');
        BuildSnapshot::restore($build, $snapshot);

        self::assertSame(90, $build->getTargetLevel());
        self::assertSame('Titan Earthquake Slam', $build->getName());
    }

    public function testTheTimelineIsNewestFirst(): void
    {
        $build = $this->build();
        $this->history->record($build, 'passive.allocate', ['id' => 'strength89']);
        $this->history->record($build, 'passive.deallocate', ['id' => 'strength89']);
        $this->entityManager->flush();

        self::assertSame(
            ['passive.deallocate', 'passive.allocate'],
            array_map(static fn (BuildEvent $e): string => $e->getAction(), $this->events->timeline($build)),
        );
    }

    public function testPruningKeepsNamedSnapshotsAndDropsOrdinaryEventsPastTheCutoff(): void
    {
        $build = $this->build();
        $old = $this->history->record($build, 'passive.allocate', ['id' => 'strength89']);
        $kept = $this->history->record($build, 'snapshot', [], 'Before the respec');
        $this->entityManager->flush();

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE build_event SET created_at = ? WHERE id IN (?, ?)',
            ['2020-01-01 00:00:00', $old->getId(), $kept->getId()],
        );
        $this->entityManager->clear();

        $pruned = $this->events->prune(new \DateTimeImmutable('2026-01-01'));

        self::assertSame(1, $pruned);
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM build_event'));
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

Run: `php bin/phpunit tests/Build/EditHistoryTest.php`
Expected: FAIL — `App\Entity\BuildEvent` does not exist.

- [ ] **Step 3: Write the entity**

Create `src/Entity/BuildEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BuildEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One edit, with the whole editable state as it stood afterwards.
 *
 * The snapshot is stored rather than replayed. A build document is small, and
 * storing it whole means reverting is a copy instead of a replay engine.
 * Reverting appends a new event of its own: the log is never rewritten.
 *
 * @phpstan-type Snapshot array{document: array<string, mixed>, header: array{class_key: string|null, target_level: int|null, note: string|null, archetype_key: string|null}}
 */
#[ORM\Entity(repositoryClass: BuildEventRepository::class)]
#[ORM\Table(name: 'build_event')]
#[ORM\Index(name: 'idx_build_event_prune', columns: ['created_at', 'is_named_snapshot'])]
class BuildEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Build $build;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 32)]
    private string $action;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    /** @var Snapshot */
    #[ORM\Column(type: Types::JSON)]
    private array $snapshot;

    #[ORM\Column]
    private bool $isNamedSnapshot = false;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $snapshotName = null;

    /**
     * @param array<string, scalar|null> $payload
     * @param Snapshot                   $snapshot
     */
    public function __construct(Build $build, string $action, array $payload, array $snapshot, ?string $snapshotName = null)
    {
        $this->build = $build;
        $this->action = $action;
        $this->payload = $payload;
        $this->snapshot = $snapshot;
        $this->snapshotName = $snapshotName;
        $this->isNamedSnapshot = null !== $snapshotName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBuild(): Build
    {
        return $this->build;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /** @return array<string, scalar|null> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @return Snapshot */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    public function isNamedSnapshot(): bool
    {
        return $this->isNamedSnapshot;
    }

    public function getSnapshotName(): ?string
    {
        return $this->snapshotName;
    }
}
```

- [ ] **Step 4: Write the snapshot helper**

Create `src/Build/Edit/BuildSnapshot.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Entity\Build;
use App\Interchange\BuildDocument;

/**
 * The editable state of a build, in one array and back again.
 *
 * Both halves travel together on purpose: reverting a build that restored the
 * passives but kept a later note would be a state the user never had.
 *
 * @phpstan-type Snapshot array{document: array<string, mixed>, header: array{class_key: string|null, target_level: int|null, note: string|null, archetype_key: string|null}}
 */
final class BuildSnapshot
{
    /**
     * @return Snapshot
     */
    public static function capture(Build $build): array
    {
        $document = $build->toDocument();

        return [
            'document' => [
                'name' => $document->name,
                'author' => $document->author,
                'link' => $document->link,
                'description' => $document->description,
                'ascendancy' => $document->ascendancy,
                'passives' => $document->passives,
                'skills' => $document->skills,
                'inventory_slots' => $document->inventorySlots,
            ],
            'header' => [
                'class_key' => $build->getClassKey(),
                'target_level' => $build->getTargetLevel(),
                'note' => $build->getNote(),
                'archetype_key' => $build->getArchetypeKey(),
            ],
        ];
    }

    /**
     * @param Snapshot $snapshot
     */
    public static function restore(Build $build, array $snapshot): void
    {
        $document = $snapshot['document'];

        $build->applyDocument(new BuildDocument(
            name: \is_string($document['name'] ?? null) ? $document['name'] : $build->getName(),
            author: self::nullableString($document, 'author'),
            link: self::nullableString($document, 'link'),
            description: self::nullableString($document, 'description'),
            ascendancy: self::nullableString($document, 'ascendancy'),
            passives: self::entries($document, 'passives'),
            skills: self::entries($document, 'skills'),
            inventorySlots: self::entries($document, 'inventory_slots'),
        ));

        $build->setClassKey($snapshot['header']['class_key']);
        $build->setTargetLevel($snapshot['header']['target_level']);
        $build->setNote($snapshot['header']['note']);
        $build->setArchetypeKey($snapshot['header']['archetype_key']);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function nullableString(array $document, string $field): ?string
    {
        $value = $document[$field] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(array $document, string $field): array
    {
        $entries = [];

        foreach ((array) ($document[$field] ?? []) as $entry) {
            if (\is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
```

- [ ] **Step 5: Write the history recorder and the repository**

Create `src/Build/Edit/EditHistory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Entity\Build;
use App\Entity\BuildEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Appends one event per edit. Never updates or deletes: pruning is a separate,
 * time-based decision, and reverting appends rather than rewrites.
 */
final class EditHistory
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public function record(Build $build, string $action, array $payload, ?string $snapshotName = null): BuildEvent
    {
        $event = new BuildEvent($build, $action, $payload, BuildSnapshot::capture($build), $snapshotName);
        $this->entityManager->persist($event);

        return $event;
    }
}
```

Create `src/Repository/BuildEventRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Build;
use App\Entity\BuildEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BuildEvent>
 */
class BuildEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuildEvent::class);
    }

    /**
     * @return list<BuildEvent>
     */
    public function timeline(Build $build, int $limit = 50): array
    {
        return array_values($this->findBy(['build' => $build], ['id' => 'DESC'], $limit));
    }

    public function findForBuild(Build $build, int $id): ?BuildEvent
    {
        return $this->findOneBy(['id' => $id, 'build' => $build]);
    }

    /**
     * Ordinary events expire after the retention window; named snapshots are
     * what the user asked to keep, so they never expire.
     */
    public function prune(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM '.BuildEvent::class.' e WHERE e.createdAt < :cutoff AND e.isNamedSnapshot = false')
            ->setParameter('cutoff', $cutoff)
            ->execute();
    }
}
```

- [ ] **Step 6: Add the migration**

Create `migrations/Version20260912080200.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912080200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create build_event: one row per edit, carrying the whole editable state afterwards, so a build can be reverted to any point.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE build_event (id INT AUTO_INCREMENT NOT NULL, build_id INT NOT NULL, created_at DATETIME NOT NULL, action VARCHAR(32) NOT NULL, payload JSON NOT NULL, snapshot JSON NOT NULL, is_named_snapshot TINYINT(1) NOT NULL, snapshot_name VARCHAR(120) DEFAULT NULL, INDEX IDX_BUILD_EVENT_BUILD (build_id), INDEX idx_build_event_prune (created_at, is_named_snapshot), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE build_event ADD CONSTRAINT FK_BUILD_EVENT_BUILD FOREIGN KEY (build_id) REFERENCES build (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE build_event');
    }
}
```

Run: `composer db:migrate`

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Build/EditHistoryTest.php`
Expected: PASS, four tests.

- [ ] **Step 8: Run the gate and commit**

```bash
composer gate
git add src/Entity/BuildEvent.php src/Repository/BuildEventRepository.php src/Build/Edit migrations/Version20260912080200.php tests/Build/EditHistoryTest.php
git commit -m "$(cat <<'EOF'
feat(build): record every edit as a revertible event

Each event carries the whole editable state afterwards, so reverting is a
copy rather than a replay. Ordinary events expire after the retention
window; named snapshots do not.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 5: DocumentEditor — passives

The pure half of editing: a `BuildDocument` in, a changed `BuildDocument` out. No database, no framework, so the rules about the format are cheap to test.

**Files:**
- Create: `src/Build/Edit/DocumentEditor.php`
- Create: `src/Build/Edit/InvalidEditCommand.php`
- Test: `tests/Build/DocumentEditorPassivesTest.php`

**Interfaces:**
- Consumes: `App\Interchange\BuildDocument`.
- Produces:
  - `DocumentEditor::allocatePassive(BuildDocument $document, string $id): BuildDocument`
  - `DocumentEditor::deallocatePassive(BuildDocument $document, string $id): BuildDocument`
  - `DocumentEditor::setPassiveLevelInterval(BuildDocument $document, string $id, int $from, int $to): BuildDocument`
  - `InvalidEditCommand extends \RuntimeException` with static constructors `unknownAction(string $action)`, `outOfRange(string $field)`, `noSuchEntry(string $what)`

- [ ] **Step 1: Write the failing tests**

Create `tests/Build/DocumentEditorPassivesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\DocumentEditor;
use App\Build\Edit\InvalidEditCommand;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class DocumentEditorPassivesTest extends TestCase
{
    private DocumentEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new DocumentEditor();
    }

    public function testAllocatingAPassiveAddsItWithTheDefaultsTheCorpusShows(): void
    {
        $document = $this->editor->allocatePassive(new BuildDocument(name: 'Build'), 'strength89');

        self::assertSame(
            [['id' => 'strength89', 'level_interval' => [1, 100], 'additional_text' => '']],
            $document->passives,
        );
    }

    public function testAllocatingTwiceChangesNothing(): void
    {
        $once = $this->editor->allocatePassive(new BuildDocument(name: 'Build'), 'strength89');
        $twice = $this->editor->allocatePassive($once, 'strength89');

        self::assertCount(1, $twice->passives);
    }

    public function testATrailingUnderscoreInAnIdSurvives(): void
    {
        $document = $this->editor->allocatePassive(new BuildDocument(name: 'Build'), 'melee22_');

        self::assertSame('melee22_', $document->passives[0]['id']);
    }

    public function testDeallocatingRemovesOnlyThatPassiveAndKeepsTheListAList(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [
            ['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => ''],
            ['id' => 'b', 'level_interval' => [1, 100], 'additional_text' => ''],
            ['id' => 'c', 'level_interval' => [1, 100], 'additional_text' => ''],
        ]);

        $changed = $this->editor->deallocatePassive($document, 'b');

        self::assertSame(['a', 'c'], array_column($changed->passives, 'id'));
        self::assertTrue(array_is_list($changed->passives));
    }

    public function testDeallocatingSomethingAbsentChangesNothing(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => '']]);

        self::assertEquals($document, $this->editor->deallocatePassive($document, 'zzz'));
    }

    public function testTheLevelIntervalIsSetInPlaceAndKeepsEveryOtherField(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [
            ['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => 'keep me', 'weapon_set' => 2],
        ]);

        $changed = $this->editor->setPassiveLevelInterval($document, 'a', 34, 60);

        self::assertSame(
            ['id' => 'a', 'level_interval' => [34, 60], 'additional_text' => 'keep me', 'weapon_set' => 2],
            $changed->passives[0],
        );
    }

    public function testALevelOutsideTheGamesRangeIsRefused(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => '']]);

        $this->expectException(InvalidEditCommand::class);

        $this->editor->setPassiveLevelInterval($document, 'a', 1, 101);
    }

    public function testAnIntervalThatEndsBeforeItStartsIsRefused(): void
    {
        $document = new BuildDocument(name: 'Build', passives: [['id' => 'a', 'level_interval' => [1, 100], 'additional_text' => '']]);

        $this->expectException(InvalidEditCommand::class);

        $this->editor->setPassiveLevelInterval($document, 'a', 60, 34);
    }

    public function testSettingTheIntervalOfAPassiveThatIsNotAllocatedIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setPassiveLevelInterval(new BuildDocument(name: 'Build'), 'a', 1, 100);
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php bin/phpunit tests/Build/DocumentEditorPassivesTest.php`
Expected: FAIL — `App\Build\Edit\DocumentEditor` does not exist.

- [ ] **Step 3: Write the exception**

Create `src/Build/Edit/InvalidEditCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit;

/**
 * An edit the format or the game would not accept. Thrown at the boundary —
 * everything reaching here came from a browser.
 */
final class InvalidEditCommand extends \RuntimeException
{
    public static function unknownAction(string $action): self
    {
        return new self(\sprintf('Unknown edit action "%s".', $action));
    }

    public static function outOfRange(string $field): self
    {
        return new self(\sprintf('%s is outside the range the game accepts.', $field));
    }

    public static function noSuchEntry(string $what): self
    {
        return new self(\sprintf('This build has no %s to change.', $what));
    }
}
```

- [ ] **Step 4: Write the passive half of the editor**

Create `src/Build/Edit/DocumentEditor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Interchange\BuildDocument;

/**
 * Every change a build document can undergo, as pure functions.
 *
 * No database and no framework, because this is where the format's rules live
 * — level ranges, the shape of new entries, which fields a kind of entry may
 * carry — and those rules get readjusted with every game patch.
 *
 * Defaults for new entries are taken from the real-file corpus, not invented:
 * a passive the game wrote carries `level_interval` and `additional_text`, so
 * a passive we write carries them too.
 */
final class DocumentEditor
{
    private const int MIN_LEVEL = 0;
    private const int MAX_LEVEL = 100;

    public function allocatePassive(BuildDocument $document, string $id): BuildDocument
    {
        if (null !== $this->indexOfPassive($document, $id)) {
            return $document;
        }

        $passives = $document->passives;
        $passives[] = ['id' => $id, 'level_interval' => [1, self::MAX_LEVEL], 'additional_text' => ''];

        return $this->withPassives($document, $passives);
    }

    public function deallocatePassive(BuildDocument $document, string $id): BuildDocument
    {
        $index = $this->indexOfPassive($document, $id);

        if (null === $index) {
            return $document;
        }

        $passives = $document->passives;
        unset($passives[$index]);

        return $this->withPassives($document, array_values($passives));
    }

    public function setPassiveLevelInterval(BuildDocument $document, string $id, int $from, int $to): BuildDocument
    {
        $index = $this->indexOfPassive($document, $id) ?? throw InvalidEditCommand::noSuchEntry('passive "'.$id.'"');

        $passives = $document->passives;
        $passives[$index]['level_interval'] = $this->levelInterval($from, $to);

        return $this->withPassives($document, $passives);
    }

    /**
     * @return array{int, int}
     */
    private function levelInterval(int $from, int $to): array
    {
        if ($from < self::MIN_LEVEL || $to > self::MAX_LEVEL || $from > $to) {
            throw InvalidEditCommand::outOfRange('A level interval');
        }

        return [$from, $to];
    }

    private function indexOfPassive(BuildDocument $document, string $id): ?int
    {
        foreach ($document->passives as $index => $passive) {
            if (($passive['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $passives
     */
    private function withPassives(BuildDocument $document, array $passives): BuildDocument
    {
        return new BuildDocument(
            name: $document->name,
            author: $document->author,
            link: $document->link,
            description: $document->description,
            ascendancy: $document->ascendancy,
            passives: $passives,
            skills: $document->skills,
            inventorySlots: $document->inventorySlots,
        );
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Build/DocumentEditorPassivesTest.php`
Expected: PASS, nine tests.

- [ ] **Step 6: Run the gate and commit**

```bash
composer gate
git add src/Build/Edit/DocumentEditor.php src/Build/Edit/InvalidEditCommand.php tests/Build/DocumentEditorPassivesTest.php
git commit -m "$(cat <<'EOF'
feat(build): edit passives on a build document

Pure functions over BuildDocument, so the format's rules — level range,
the shape of a new entry, verbatim ids — are cheap to re-check when a
patch moves them.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 6: DocumentEditor — skills and supports

Skills have no id of their own in the format, so position is the key. Supports are keyed by gem id within their skill.

**Files:**
- Modify: `src/Build/Edit/DocumentEditor.php`
- Test: `tests/Build/DocumentEditorSkillsTest.php`

**Interfaces:**
- Consumes: `DocumentEditor`, `InvalidEditCommand` (Task 5).
- Produces:
  - `addSkill(BuildDocument $d, string $gemId): BuildDocument`
  - `removeSkill(BuildDocument $d, int $index): BuildDocument`
  - `setSkillLevelInterval(BuildDocument $d, int $index, int $from, int $to): BuildDocument`
  - `addSupport(BuildDocument $d, int $skillIndex, string $supportId): BuildDocument`
  - `removeSupport(BuildDocument $d, int $skillIndex, string $supportId): BuildDocument`
  - `setSupportLevelInterval(BuildDocument $d, int $skillIndex, string $supportId, int $from, int $to): BuildDocument`

- [ ] **Step 1: Write the failing tests**

Create `tests/Build/DocumentEditorSkillsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\DocumentEditor;
use App\Build\Edit\InvalidEditCommand;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class DocumentEditorSkillsTest extends TestCase
{
    private DocumentEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new DocumentEditor();
    }

    public function testAddingASkillCarriesNoAdditionalTextAndNoSupportsYet(): void
    {
        $document = $this->editor->addSkill(new BuildDocument(name: 'Build'), 'Metadata/Items/Gems/SkillGemEarthquake');

        self::assertSame(
            [['id' => 'Metadata/Items/Gems/SkillGemEarthquake', 'level_interval' => [1, 100]]],
            $document->skills,
            'the corpus shows additional_text on passives and slots, never on skills',
        );
    }

    public function testBothGemPathPrefixesSurviveVerbatim(): void
    {
        $document = $this->editor->addSkill(new BuildDocument(name: 'Build'), 'Metadata/Items/Gem/SkillGemHatefulFocus');
        $document = $this->editor->addSkill($document, 'Metadata/Items/Gems/SkillGemEarthquake');

        self::assertSame(
            ['Metadata/Items/Gem/SkillGemHatefulFocus', 'Metadata/Items/Gems/SkillGemEarthquake'],
            array_column($document->skills, 'id'),
        );
    }

    public function testTheSameGemMayBeAddedTwice(): void
    {
        $document = $this->editor->addSkill(new BuildDocument(name: 'Build'), 'Metadata/Items/Gems/SkillGemEarthquake');
        $document = $this->editor->addSkill($document, 'Metadata/Items/Gems/SkillGemEarthquake');

        self::assertCount(2, $document->skills, 'one gem at two level intervals is a real plan, not a mistake');
    }

    public function testRemovingASkillClosesTheGapInTheList(): void
    {
        $document = $this->skills(['a', 'b', 'c']);

        $changed = $this->editor->removeSkill($document, 1);

        self::assertSame(['a', 'c'], array_column($changed->skills, 'id'));
        self::assertTrue(array_is_list($changed->skills));
    }

    public function testRemovingASkillThatIsNotThereIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->removeSkill($this->skills(['a']), 7);
    }

    public function testASkillsLevelIntervalIsSetInPlace(): void
    {
        $changed = $this->editor->setSkillLevelInterval($this->skills(['a']), 0, 12, 100);

        self::assertSame([12, 100], $changed->skills[0]['level_interval']);
    }

    public function testASkillsLevelIntervalIsRangeChecked(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setSkillLevelInterval($this->skills(['a']), 0, 0, 101);
    }

    public function testTheSupportsKeyAppearsOnlyOnceASupportIsAdded(): void
    {
        $document = $this->editor->addSupport($this->skills(['a']), 0, 'Metadata/Items/Gems/SupportGemFastForward');

        self::assertSame(
            ['id' => 'a', 'level_interval' => [1, 100], 'support_skills' => [
                ['id' => 'Metadata/Items/Gems/SupportGemFastForward', 'level_interval' => [1, 100]],
            ]],
            $document->skills[0],
        );
    }

    public function testAddingTheSameSupportTwiceToOneSkillChangesNothing(): void
    {
        $document = $this->editor->addSupport($this->skills(['a']), 0, 'support1');
        $document = $this->editor->addSupport($document, 0, 'support1');

        self::assertCount(1, $document->skills[0]['support_skills']);
    }

    public function testOneSupportMayServeTwoDifferentSkills(): void
    {
        $document = $this->editor->addSupport($this->skills(['a', 'b']), 0, 'support1');
        $document = $this->editor->addSupport($document, 1, 'support1');

        self::assertCount(1, $document->skills[0]['support_skills']);
        self::assertCount(1, $document->skills[1]['support_skills'], 'support uniqueness per character was lifted in 0.3');
    }

    public function testRemovingTheLastSupportRemovesTheKeyAgain(): void
    {
        $document = $this->editor->addSupport($this->skills(['a']), 0, 'support1');

        $changed = $this->editor->removeSupport($document, 0, 'support1');

        self::assertSame(['id' => 'a', 'level_interval' => [1, 100]], $changed->skills[0]);
    }

    public function testASupportsLevelIntervalIsSetInPlace(): void
    {
        $document = $this->editor->addSupport($this->skills(['a']), 0, 'support1');

        $changed = $this->editor->setSupportLevelInterval($document, 0, 'support1', 34, 100);

        self::assertSame([34, 100], $changed->skills[0]['support_skills'][0]['level_interval']);
    }

    public function testTouchingASupportThatIsNotOnThatSkillIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setSupportLevelInterval($this->skills(['a']), 0, 'absent', 1, 100);
    }

    /**
     * @param list<string> $ids
     */
    private function skills(array $ids): BuildDocument
    {
        return new BuildDocument(
            name: 'Build',
            skills: array_map(static fn (string $id): array => ['id' => $id, 'level_interval' => [1, 100]], $ids),
        );
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php bin/phpunit tests/Build/DocumentEditorSkillsTest.php`
Expected: FAIL — `Call to undefined method App\Build\Edit\DocumentEditor::addSkill()`.

- [ ] **Step 3: Implement the skill and support methods**

Add to `src/Build/Edit/DocumentEditor.php`:

```php
    public function addSkill(BuildDocument $document, string $gemId): BuildDocument
    {
        $skills = $document->skills;
        $skills[] = ['id' => $gemId, 'level_interval' => [1, self::MAX_LEVEL]];

        return $this->withSkills($document, $skills);
    }

    public function removeSkill(BuildDocument $document, int $index): BuildDocument
    {
        $skills = $document->skills;
        $this->mustHaveSkill($skills, $index);
        unset($skills[$index]);

        return $this->withSkills($document, array_values($skills));
    }

    public function setSkillLevelInterval(BuildDocument $document, int $index, int $from, int $to): BuildDocument
    {
        $skills = $document->skills;
        $this->mustHaveSkill($skills, $index);
        $skills[$index]['level_interval'] = $this->levelInterval($from, $to);

        return $this->withSkills($document, $skills);
    }

    public function addSupport(BuildDocument $document, int $skillIndex, string $supportId): BuildDocument
    {
        $skills = $document->skills;
        $this->mustHaveSkill($skills, $skillIndex);
        $supports = $this->supportsOf($skills[$skillIndex]);

        if (null !== $this->indexOfSupport($supports, $supportId)) {
            return $document;
        }

        $supports[] = ['id' => $supportId, 'level_interval' => [1, self::MAX_LEVEL]];
        $skills[$skillIndex]['support_skills'] = $supports;

        return $this->withSkills($document, $skills);
    }

    public function removeSupport(BuildDocument $document, int $skillIndex, string $supportId): BuildDocument
    {
        $skills = $document->skills;
        $this->mustHaveSkill($skills, $skillIndex);
        $supports = $this->supportsOf($skills[$skillIndex]);
        $index = $this->indexOfSupport($supports, $supportId);

        if (null === $index) {
            return $document;
        }

        unset($supports[$index]);
        $supports = array_values($supports);

        // A skill the game wrote with no supports carries no key at all.
        if ([] === $supports) {
            unset($skills[$skillIndex]['support_skills']);
        } else {
            $skills[$skillIndex]['support_skills'] = $supports;
        }

        return $this->withSkills($document, $skills);
    }

    public function setSupportLevelInterval(BuildDocument $document, int $skillIndex, string $supportId, int $from, int $to): BuildDocument
    {
        $skills = $document->skills;
        $this->mustHaveSkill($skills, $skillIndex);
        $supports = $this->supportsOf($skills[$skillIndex]);
        $index = $this->indexOfSupport($supports, $supportId) ?? throw InvalidEditCommand::noSuchEntry('support "'.$supportId.'"');

        $supports[$index]['level_interval'] = $this->levelInterval($from, $to);
        $skills[$skillIndex]['support_skills'] = $supports;

        return $this->withSkills($document, $skills);
    }

    /**
     * @param list<array<string, mixed>> $skills
     *
     * @phpstan-assert non-empty-list<array<string, mixed>> $skills
     */
    private function mustHaveSkill(array $skills, int $index): void
    {
        if (!isset($skills[$index])) {
            throw InvalidEditCommand::noSuchEntry('skill #'.$index);
        }
    }

    /**
     * @param array<string, mixed> $skill
     *
     * @return list<array<string, mixed>>
     */
    private function supportsOf(array $skill): array
    {
        $supports = [];

        foreach ((array) ($skill['support_skills'] ?? []) as $support) {
            if (\is_array($support)) {
                /** @var array<string, mixed> $support */
                $supports[] = $support;
            }
        }

        return $supports;
    }

    /**
     * @param list<array<string, mixed>> $supports
     */
    private function indexOfSupport(array $supports, string $supportId): ?int
    {
        foreach ($supports as $index => $support) {
            if (($support['id'] ?? null) === $supportId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $skills
     */
    private function withSkills(BuildDocument $document, array $skills): BuildDocument
    {
        return new BuildDocument(
            name: $document->name,
            author: $document->author,
            link: $document->link,
            description: $document->description,
            ascendancy: $document->ascendancy,
            passives: $document->passives,
            skills: $skills,
            inventorySlots: $document->inventorySlots,
        );
    }
```

If PHPStan objects to the `@phpstan-assert` line, drop the annotation — the `isset()` check is what matters.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Build/DocumentEditorSkillsTest.php`
Expected: PASS, thirteen tests.

- [ ] **Step 5: Run the gate and commit**

```bash
composer gate
git add src/Build/Edit/DocumentEditor.php tests/Build/DocumentEditorSkillsTest.php
git commit -m "$(cat <<'EOF'
feat(build): edit skills and their supports

Skills are keyed by position, supports by gem id within a skill. The
support_skills key appears only when a skill has supports, matching what
the game writes.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 7: Equipment slots and the curated slot vocabulary

The 14 `Inventories` ids have no upstream source — step 0 established that they come from the real-file corpus and become a curated map.

**Files:**
- Create: `config/inventory_slots.yaml`
- Create: `src/Build/InventorySlots.php`
- Modify: `src/Build/Edit/DocumentEditor.php`
- Modify: `config/services.yaml`
- Test: `tests/Build/InventorySlotsTest.php`, `tests/Build/DocumentEditorSlotsTest.php`

**Interfaces:**
- Consumes: `DocumentEditor`, `InvalidEditCommand`.
- Produces:
  - `InventorySlots::all(): list<array{id: string, label: string}>`
  - `InventorySlots::isKnown(string $inventoryId): bool`
  - `setInventorySlot(BuildDocument $d, string $inventoryId, ?string $uniqueName, int $from, int $to, string $additionalText): BuildDocument`
  - `clearInventorySlot(BuildDocument $d, string $inventoryId): BuildDocument`

- [ ] **Step 1: Write the curated vocabulary**

Create `config/inventory_slots.yaml`. Order is wearing order, which is the order the editor lists them in:

```yaml
# The `Inventories` vocabulary, 14 values.
#
# No upstream source provides this list: step 0 looked, and RePoE does not
# carry it. These ids were read out of fourteen .build files the game exported
# at 0.5.5 and are therefore measured, not documented. If a patch adds a slot,
# this file is where it is added — by hand, against a real exported file.
inventory_slots:
    - { id: Weapon1,    label: 'Weapon (set 1)' }
    - { id: Offhand1,   label: 'Offhand (set 1)' }
    - { id: Weapon2,    label: 'Weapon (set 2)' }
    - { id: Offhand2,   label: 'Offhand (set 2)' }
    - { id: Helm1,      label: 'Helmet' }
    - { id: BodyArmour1, label: 'Body armour' }
    - { id: Gloves1,    label: 'Gloves' }
    - { id: Boots1,     label: 'Boots' }
    - { id: Belt1,      label: 'Belt' }
    - { id: Amulet1,    label: 'Amulet' }
    - { id: Ring1,      label: 'Ring (left)' }
    - { id: Ring2,      label: 'Ring (right)' }
    - { id: Trinket1,   label: 'Trinket' }
    - { id: Flask1,     label: 'Flask' }
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Build/InventorySlotsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\InventorySlots;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InventorySlotsTest extends KernelTestCase
{
    public function testTheVocabularyIsTheFourteenValuesTheCorpusShows(): void
    {
        self::bootKernel();
        $slots = self::getContainer()->get(InventorySlots::class);

        self::assertSame(
            ['Weapon1', 'Offhand1', 'Weapon2', 'Offhand2', 'Helm1', 'BodyArmour1', 'Gloves1', 'Boots1', 'Belt1', 'Amulet1', 'Ring1', 'Ring2', 'Trinket1', 'Flask1'],
            array_column($slots->all(), 'id'),
        );
        self::assertTrue($slots->isKnown('Amulet1'));
        self::assertFalse($slots->isKnown('Backpack1'));
    }
}
```

Create `tests/Build/DocumentEditorSlotsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\DocumentEditor;
use App\Build\Edit\InvalidEditCommand;
use App\Build\InventorySlots;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class DocumentEditorSlotsTest extends TestCase
{
    private DocumentEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new DocumentEditor(new InventorySlots(__DIR__.'/../../config/inventory_slots.yaml'));
    }

    public function testSettingASlotWritesEveryFieldTheGameWrites(): void
    {
        $document = $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Amulet1', 'Astramentis', 1, 100, '');

        self::assertSame(
            [['inventory_id' => 'Amulet1', 'slot_x' => 0, 'slot_y' => 0, 'level_interval' => [1, 100], 'additional_text' => '', 'unique_name' => 'Astramentis']],
            $document->inventorySlots,
        );
    }

    public function testASlotWithoutAUniqueCarriesNoUniqueNameKey(): void
    {
        $document = $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Weapon1', null, 1, 100, 'any two-hander');

        self::assertArrayNotHasKey('unique_name', $document->inventorySlots[0]);
        self::assertSame('any two-hander', $document->inventorySlots[0]['additional_text']);
    }

    public function testSettingTheSameSlotTwiceReplacesItInPlace(): void
    {
        $document = $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Ring1', 'First', 1, 100, '');
        $document = $this->editor->setInventorySlot($document, 'Ring2', 'Second', 1, 100, '');
        $document = $this->editor->setInventorySlot($document, 'Ring1', 'Replaced', 1, 100, '');

        self::assertCount(2, $document->inventorySlots);
        self::assertSame(['Ring1', 'Ring2'], array_column($document->inventorySlots, 'inventory_id'));
        self::assertSame('Replaced', $document->inventorySlots[0]['unique_name']);
    }

    public function testClearingASlotRemovesItEntirely(): void
    {
        $document = $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Ring1', 'First', 1, 100, '');

        self::assertSame([], $this->editor->clearInventorySlot($document, 'Ring1')->inventorySlots);
    }

    public function testASlotOutsideTheVocabularyIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Backpack1', null, 1, 100, '');
    }

    public function testASlotLevelIntervalIsRangeChecked(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Ring1', null, 50, 20, '');
    }
}
```

Note the constructor change: `DocumentEditor` now takes `InventorySlots`. The two earlier test classes construct it with `new DocumentEditor()` — update them to `new DocumentEditor(new InventorySlots(__DIR__.'/../../config/inventory_slots.yaml'))` in this task.

- [ ] **Step 3: Run them and watch them fail**

Run: `php bin/phpunit tests/Build`
Expected: FAIL — `App\Build\InventorySlots` does not exist.

- [ ] **Step 4: Write the vocabulary service**

Create `src/Build/InventorySlots.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build;

use Symfony\Component\Yaml\Yaml;

/**
 * The `Inventories` ids a build may name, curated.
 *
 * Step 0 established that no upstream source carries this vocabulary, so it is
 * a file we maintain against real exported files rather than data we sync.
 */
final class InventorySlots
{
    /** @var list<array{id: string, label: string}>|null */
    private ?array $slots = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function all(): array
    {
        if (null !== $this->slots) {
            return $this->slots;
        }

        $slots = [];

        foreach ((array) (Yaml::parseFile($this->file)['inventory_slots'] ?? []) as $slot) {
            if (\is_array($slot) && \is_string($slot['id'] ?? null) && \is_string($slot['label'] ?? null)) {
                $slots[] = ['id' => $slot['id'], 'label' => $slot['label']];
            }
        }

        return $this->slots = $slots;
    }

    public function isKnown(string $inventoryId): bool
    {
        return \in_array($inventoryId, array_column($this->all(), 'id'), true);
    }
}
```

Wire the file path in `config/services.yaml`, beside the existing `SourceFetcher` block:

```yaml
    App\Build\InventorySlots:
        arguments:
            $file: '%kernel.project_dir%/config/inventory_slots.yaml'
```

- [ ] **Step 5: Implement the slot methods**

In `src/Build/Edit/DocumentEditor.php`, add the constructor and the two methods:

```php
    public function __construct(private readonly InventorySlots $slots)
    {
    }
```

(with `use App\Build\InventorySlots;` at the top)

```php
    public function setInventorySlot(BuildDocument $document, string $inventoryId, ?string $uniqueName, int $from, int $to, string $additionalText): BuildDocument
    {
        if (!$this->slots->isKnown($inventoryId)) {
            throw InvalidEditCommand::noSuchEntry('equipment slot "'.$inventoryId.'"');
        }

        $entry = [
            'inventory_id' => $inventoryId,
            'slot_x' => 0,
            'slot_y' => 0,
            'level_interval' => $this->levelInterval($from, $to),
            'additional_text' => $additionalText,
        ];

        if (null !== $uniqueName && '' !== $uniqueName) {
            $entry['unique_name'] = $uniqueName;
        }

        $slots = $document->inventorySlots;
        $index = $this->indexOfSlot($document, $inventoryId);

        if (null === $index) {
            $slots[] = $entry;
        } else {
            $slots[$index] = $entry;
        }

        return $this->withSlots($document, $slots);
    }

    public function clearInventorySlot(BuildDocument $document, string $inventoryId): BuildDocument
    {
        $index = $this->indexOfSlot($document, $inventoryId);

        if (null === $index) {
            return $document;
        }

        $slots = $document->inventorySlots;
        unset($slots[$index]);

        return $this->withSlots($document, array_values($slots));
    }

    private function indexOfSlot(BuildDocument $document, string $inventoryId): ?int
    {
        foreach ($document->inventorySlots as $index => $slot) {
            if (($slot['inventory_id'] ?? null) === $inventoryId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $slots
     */
    private function withSlots(BuildDocument $document, array $slots): BuildDocument
    {
        return new BuildDocument(
            name: $document->name,
            author: $document->author,
            link: $document->link,
            description: $document->description,
            ascendancy: $document->ascendancy,
            passives: $document->passives,
            skills: $document->skills,
            inventorySlots: $slots,
        );
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Build`
Expected: PASS — including the two earlier editor test classes with their updated constructor calls.

- [ ] **Step 7: Run the gate and commit**

```bash
composer gate
git add config/inventory_slots.yaml config/services.yaml src/Build tests/Build
git commit -m "$(cat <<'EOF'
feat(build): edit equipment slots against a curated slot vocabulary

The fourteen Inventories ids have no upstream source — they were read out
of real exported files — so they live in a file we maintain rather than
one we sync.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

### Task 8: Messenger, the commands and their handlers

One action string per edit, one DTO per action, one handler class per area. `BuildEditor` carries the part every handler shares: load, mutate, record, flush.

**Files:**
- Modify: `composer.json` (`composer require symfony/messenger:8.1.*`)
- Create: `config/packages/messenger.yaml`
- Create: `src/Build/Edit/EditCommand.php`
- Create: `src/Build/Edit/Command/` — `SetHeaderField.php`, `AllocatePassive.php`, `DeallocatePassive.php`, `SetPassiveInterval.php`, `AddSkill.php`, `RemoveSkill.php`, `SetSkillInterval.php`, `AddSupport.php`, `RemoveSupport.php`, `SetSupportInterval.php`, `SetSlot.php`, `ClearSlot.php`
- Create: `src/Build/Edit/CommandFactory.php`, `src/Build/Edit/BuildEditor.php`
- Create: `src/Build/Edit/Handler/HeaderEditHandler.php`, `PassiveEditHandler.php`, `SkillEditHandler.php`, `SlotEditHandler.php`
- Test: `tests/Build/CommandFactoryTest.php`, `tests/Build/BuildEditorTest.php`

**Interfaces:**
- Consumes: `DocumentEditor` (Tasks 5–7), `EditHistory` (Task 4), `BuildRepository`, `Build` setters (Task 3).
- Produces:
  - **Action strings, used verbatim by every form (Tasks 11–15) and by the Stimulus controller (Task 17):** `header.set`, `passive.allocate`, `passive.deallocate`, `passive.interval`, `skill.add`, `skill.remove`, `skill.interval`, `support.add`, `support.remove`, `support.interval`, `slot.set`, `slot.clear`. Task 9 adds `snapshot.create` and `history.revert`.
  - `CommandFactory::fromRequest(int $buildId, string $action, InputBag $payload): EditCommand`
  - `BuildEditor::apply(int $buildId, string $action, array $payload, callable $mutate): void` where `$mutate` is `callable(Build): void` and `$payload` is `array<string, scalar|null>`
  - `BuildEditor::document(Build $build, callable $change): void` where `$change` is `callable(BuildDocument): BuildDocument` — the two-liner every document handler uses
  - Marker interface `App\Build\Edit\EditCommand`; every DTO is `final readonly` with a public `int $buildId` as its first property.

- [ ] **Step 1: Install Messenger and configure it synchronous**

```bash
composer require symfony/messenger:8.1.*
```

Replace `config/packages/messenger.yaml` with exactly this — no transports at all, so every command is handled in the same request:

```yaml
framework:
    messenger:
        # Edits are handled in the request that made them: the browser is
        # waiting for the Turbo Stream that shows the result. There is no
        # transport on purpose — a queue here would buy latency, not safety.
        default_bus: command.bus
        buses:
            command.bus: ~
```

- [ ] **Step 2: Write the failing factory test**

Create `tests/Build/CommandFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\Command\AllocatePassive;
use App\Build\Edit\Command\SetHeaderField;
use App\Build\Edit\Command\SetSlot;
use App\Build\Edit\CommandFactory;
use App\Build\Edit\InvalidEditCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class CommandFactoryTest extends TestCase
{
    private CommandFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new CommandFactory();
    }

    public function testAllocatingAPassiveCarriesTheIdVerbatim(): void
    {
        $command = $this->factory->fromRequest(7, 'passive.allocate', new InputBag(['id' => 'melee22_']));

        self::assertInstanceOf(AllocatePassive::class, $command);
        self::assertSame(7, $command->buildId);
        self::assertSame('melee22_', $command->id);
    }

    public function testAHeaderFieldCarriesItsNameAndValue(): void
    {
        $command = $this->factory->fromRequest(7, 'header.set', new InputBag(['field' => 'note', 'value' => 'Respec at 60']));

        self::assertInstanceOf(SetHeaderField::class, $command);
        self::assertSame('note', $command->field);
        self::assertSame('Respec at 60', $command->value);
    }

    public function testAnEmptySlotValueBecomesNullRatherThanAnEmptyString(): void
    {
        $command = $this->factory->fromRequest(7, 'slot.set', new InputBag(['inventory_id' => 'Ring1', 'unique_name' => '', 'from' => '1', 'to' => '100', 'additional_text' => '']));

        self::assertInstanceOf(SetSlot::class, $command);
        self::assertNull($command->uniqueName);
    }

    public function testAnUnknownActionIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->factory->fromRequest(7, 'passive.detonate', new InputBag([]));
    }

    public function testALevelThatIsNotANumberIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->factory->fromRequest(7, 'passive.interval', new InputBag(['id' => 'a', 'from' => 'soon', 'to' => '100']));
    }
}
```

- [ ] **Step 3: Run it and watch it fail**

Run: `php bin/phpunit tests/Build/CommandFactoryTest.php`
Expected: FAIL — `App\Build\Edit\CommandFactory` does not exist.

- [ ] **Step 4: Write the marker interface and the DTOs**

Create `src/Build/Edit/EditCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit;

/**
 * One edit, on its way to its handler. Carries the build's id rather than the
 * entity so a command stays a value: it is built from an HTTP request and must
 * not drag a managed entity across the bus.
 */
interface EditCommand
{
}
```

Create the twelve DTOs in `src/Build/Edit/Command/`. They are all the same shape; these three show every variation, and the remaining nine follow:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit\Command;

use App\Build\Edit\EditCommand;

final readonly class AllocatePassive implements EditCommand
{
    public function __construct(public int $buildId, public string $id)
    {
    }
}
```

```php
final readonly class SetHeaderField implements EditCommand
{
    public function __construct(public int $buildId, public string $field, public string $value)
    {
    }
}
```

```php
final readonly class SetSlot implements EditCommand
{
    public function __construct(
        public int $buildId,
        public string $inventoryId,
        public ?string $uniqueName,
        public int $from,
        public int $to,
        public string $additionalText,
    ) {
    }
}
```

The rest, in the same file-per-class shape:

| Class | Properties after `int $buildId` |
|---|---|
| `DeallocatePassive` | `string $id` |
| `SetPassiveInterval` | `string $id`, `int $from`, `int $to` |
| `AddSkill` | `string $gemId` |
| `RemoveSkill` | `int $index` |
| `SetSkillInterval` | `int $index`, `int $from`, `int $to` |
| `AddSupport` | `int $skillIndex`, `string $supportId` |
| `RemoveSupport` | `int $skillIndex`, `string $supportId` |
| `SetSupportInterval` | `int $skillIndex`, `string $supportId`, `int $from`, `int $to` |
| `ClearSlot` | `string $inventoryId` |

- [ ] **Step 5: Write the factory**

Create `src/Build/Edit/CommandFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Build\Edit\Command\AddSkill;
use App\Build\Edit\Command\AddSupport;
use App\Build\Edit\Command\AllocatePassive;
use App\Build\Edit\Command\ClearSlot;
use App\Build\Edit\Command\DeallocatePassive;
use App\Build\Edit\Command\RemoveSkill;
use App\Build\Edit\Command\RemoveSupport;
use App\Build\Edit\Command\SetHeaderField;
use App\Build\Edit\Command\SetPassiveInterval;
use App\Build\Edit\Command\SetSkillInterval;
use App\Build\Edit\Command\SetSlot;
use App\Build\Edit\Command\SetSupportInterval;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Turns one posted form into one command.
 *
 * A match rather than a registry: a dozen cases read faster in one place than
 * spread over a dozen tagged services, and every unknown action is refused
 * here rather than somewhere deeper.
 */
final class CommandFactory
{
    public function fromRequest(int $buildId, string $action, InputBag $payload): EditCommand
    {
        return match ($action) {
            'header.set' => new SetHeaderField($buildId, $this->string($payload, 'field'), $this->string($payload, 'value')),
            'passive.allocate' => new AllocatePassive($buildId, $this->string($payload, 'id')),
            'passive.deallocate' => new DeallocatePassive($buildId, $this->string($payload, 'id')),
            'passive.interval' => new SetPassiveInterval($buildId, $this->string($payload, 'id'), $this->int($payload, 'from'), $this->int($payload, 'to')),
            'skill.add' => new AddSkill($buildId, $this->string($payload, 'gem_id')),
            'skill.remove' => new RemoveSkill($buildId, $this->int($payload, 'index')),
            'skill.interval' => new SetSkillInterval($buildId, $this->int($payload, 'index'), $this->int($payload, 'from'), $this->int($payload, 'to')),
            'support.add' => new AddSupport($buildId, $this->int($payload, 'skill_index'), $this->string($payload, 'support_id')),
            'support.remove' => new RemoveSupport($buildId, $this->int($payload, 'skill_index'), $this->string($payload, 'support_id')),
            'support.interval' => new SetSupportInterval($buildId, $this->int($payload, 'skill_index'), $this->string($payload, 'support_id'), $this->int($payload, 'from'), $this->int($payload, 'to')),
            'slot.set' => new SetSlot($buildId, $this->string($payload, 'inventory_id'), $this->optionalString($payload, 'unique_name'), $this->int($payload, 'from'), $this->int($payload, 'to'), $this->string($payload, 'additional_text', required: false)),
            'slot.clear' => new ClearSlot($buildId, $this->string($payload, 'inventory_id')),
            default => throw InvalidEditCommand::unknownAction($action),
        };
    }

    private function string(InputBag $payload, string $field, bool $required = true): string
    {
        $value = $payload->get($field);

        if (!\is_string($value) || ('' === $value && $required)) {
            throw InvalidEditCommand::noSuchEntry('value for "'.$field.'"');
        }

        return $value;
    }

    private function optionalString(InputBag $payload, string $field): ?string
    {
        $value = $payload->get($field);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function int(InputBag $payload, string $field): int
    {
        $value = $payload->get($field);

        if (!is_numeric($value)) {
            throw InvalidEditCommand::outOfRange('"'.$field.'"');
        }

        return (int) $value;
    }
}
```

- [ ] **Step 6: Run the factory test to verify it passes**

Run: `php bin/phpunit tests/Build/CommandFactoryTest.php`
Expected: PASS, five tests.

- [ ] **Step 7: Write the failing editor test**

Create `tests/Build/BuildEditorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\Command\AllocatePassive;
use App\Build\Edit\Command\SetHeaderField;
use App\Entity\Build;
use App\Entity\BuildEvent;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildEventRepository;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class BuildEditorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BuildRepository $builds;
    private BuildEventRepository $events;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->builds = self::getContainer()->get(BuildRepository::class);
        $this->events = self::getContainer()->get(BuildEventRepository::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $this->entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    public function testAllocatingAPassiveChangesTheDocumentAndLeavesAnEvent(): void
    {
        $build = $this->build();

        $this->bus->dispatch(new AllocatePassive((int) $build->getId(), 'strength89'));
        $this->entityManager->clear();

        $reloaded = $this->builds->findOneByShareSlug($build->getShareSlug());
        self::assertNotNull($reloaded);
        self::assertContains('strength89', array_column($reloaded->toDocument()->passives, 'id'));

        $timeline = $this->events->timeline($reloaded);
        self::assertSame('passive.allocate', $timeline[0]->getAction());
        self::assertSame('strength89', $timeline[0]->getPayload()['id']);
    }

    public function testAHeaderFieldFromTheFormatReachesTheExportedDocument(): void
    {
        $build = $this->build();

        $this->bus->dispatch(new SetHeaderField((int) $build->getId(), 'ascendancy', 'Sorceress3'));
        $this->entityManager->clear();

        $reloaded = $this->builds->findOneByShareSlug($build->getShareSlug());
        self::assertNotNull($reloaded);
        self::assertSame('Sorceress3', $reloaded->toDocument()->ascendancy);
    }

    public function testAPlanningFieldStaysOutOfTheExportedDocument(): void
    {
        $build = $this->build();
        $before = $build->toDocument();

        $this->bus->dispatch(new SetHeaderField((int) $build->getId(), 'target_level', '92'));
        $this->entityManager->clear();

        $reloaded = $this->builds->findOneByShareSlug($build->getShareSlug());
        self::assertNotNull($reloaded);
        self::assertSame(92, $reloaded->getTargetLevel());
        self::assertEquals($before, $reloaded->toDocument());
    }

    public function testAnUnknownHeaderFieldIsRefused(): void
    {
        $build = $this->build();

        $this->expectException(\Throwable::class);

        $this->bus->dispatch(new SetHeaderField((int) $build->getId(), 'secret_flag', 'yes'));
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

- [ ] **Step 8: Run it and watch it fail**

Run: `php bin/phpunit tests/Build/BuildEditorTest.php`
Expected: FAIL — no handler for `AllocatePassive`.

- [ ] **Step 9: Write BuildEditor**

Create `src/Build/Edit/BuildEditor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Entity\Build;
use App\Interchange\BuildDocument;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What every edit does regardless of what it changes: find the build, change
 * it, leave an event behind, flush. Handlers supply only the change.
 */
final class BuildEditor
{
    public function __construct(
        private readonly BuildRepository $builds,
        private readonly EditHistory $history,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, scalar|null> $payload
     * @param callable(Build): void      $mutate
     */
    public function apply(int $buildId, string $action, array $payload, callable $mutate): void
    {
        $build = $this->builds->find($buildId) ?? throw InvalidEditCommand::noSuchEntry('build');

        $mutate($build);

        $this->history->record($build, $action, $payload);
        $this->entityManager->flush();
    }

    /**
     * @param callable(BuildDocument): BuildDocument $change
     */
    public function document(Build $build, callable $change): void
    {
        $build->applyDocument($change($build->toDocument()));
    }
}
```

- [ ] **Step 10: Write the handlers**

Create `src/Build/Edit/Handler/PassiveEditHandler.php` — one class per area, with the attribute on each method:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildEditor;
use App\Build\Edit\Command\AllocatePassive;
use App\Build\Edit\Command\DeallocatePassive;
use App\Build\Edit\Command\SetPassiveInterval;
use App\Build\Edit\DocumentEditor;
use App\Entity\Build;
use App\Interchange\BuildDocument;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class PassiveEditHandler
{
    public function __construct(
        private readonly BuildEditor $builds,
        private readonly DocumentEditor $documents,
    ) {
    }

    #[AsMessageHandler]
    public function allocate(AllocatePassive $command): void
    {
        $this->builds->apply($command->buildId, 'passive.allocate', ['id' => $command->id], function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->allocatePassive($d, $command->id));
        });
    }

    #[AsMessageHandler]
    public function deallocate(DeallocatePassive $command): void
    {
        $this->builds->apply($command->buildId, 'passive.deallocate', ['id' => $command->id], function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->deallocatePassive($d, $command->id));
        });
    }

    #[AsMessageHandler]
    public function setInterval(SetPassiveInterval $command): void
    {
        $payload = ['id' => $command->id, 'from' => $command->from, 'to' => $command->to];

        $this->builds->apply($command->buildId, 'passive.interval', $payload, function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->setPassiveLevelInterval($d, $command->id, $command->from, $command->to));
        });
    }
}
```

`SkillEditHandler` (handling `AddSkill`, `RemoveSkill`, `SetSkillInterval`, `AddSupport`, `RemoveSupport`, `SetSupportInterval`) and `SlotEditHandler` (`SetSlot`, `ClearSlot`) follow exactly this shape, each method naming its action string and passing its command's own properties as the payload.

Create `src/Build/Edit/Handler/HeaderEditHandler.php` — the one place the two kinds of header field are told apart:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildEditor;
use App\Build\Edit\Command\SetHeaderField;
use App\Build\Edit\InvalidEditCommand;
use App\Entity\Build;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Header fields come in two kinds. Five of them are part of the Build Planner
 * format and reach the exported file through the entity's columns; four are
 * ours alone and must never leave the database. Nothing else may be set.
 */
final class HeaderEditHandler
{
    public function __construct(private readonly BuildEditor $builds)
    {
    }

    #[AsMessageHandler]
    public function set(SetHeaderField $command): void
    {
        $payload = ['field' => $command->field, 'value' => $command->value];

        $this->builds->apply($command->buildId, 'header.set', $payload, function (Build $build) use ($command): void {
            $value = $command->value;
            $optional = '' === $value ? null : $value;

            match ($command->field) {
                'name' => $build->setName('' === $value ? 'Unnamed build' : $value),
                'author' => $build->setAuthor($optional),
                'link' => $build->setLink($optional),
                'description' => $build->setDescription($optional),
                'ascendancy' => $build->setAscendancyKey($optional),
                'class_key' => $build->setClassKey($optional),
                'archetype_key' => $build->setArchetypeKey($optional),
                'note' => $build->setNote($optional),
                'target_level' => $build->setTargetLevel($this->level($value)),
                default => throw InvalidEditCommand::noSuchEntry('header field "'.$command->field.'"'),
            };
        });
    }

    private function level(string $value): ?int
    {
        if ('' === $value) {
            return null;
        }

        if (!is_numeric($value) || (int) $value < 1 || (int) $value > 100) {
            throw InvalidEditCommand::outOfRange('A target level');
        }

        return (int) $value;
    }
}
```

- [ ] **Step 11: Run the editor test to verify it passes**

Run: `php bin/phpunit tests/Build/BuildEditorTest.php`
Expected: PASS, four tests.

- [ ] **Step 12: Run the gate and commit**

```bash
composer gate
git add composer.json composer.lock config/packages/messenger.yaml src/Build/Edit tests/Build
git commit -m "$(cat <<'EOF'
feat(build): dispatch edits as commands over a synchronous bus

One action string per edit, one handler class per area, and BuildEditor
carrying what they share: find, change, record the event, flush. The
header handler is the only place the format's fields and our own planning
fields are told apart.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 9: Named snapshots, revert, and the 30-day prune

Reverting appends. The log is an audit trail, never rewritten — that is what makes it safe to autosave every click.

**Files:**
- Create: `src/Build/Edit/Command/CreateSnapshot.php`, `src/Build/Edit/Command/Revert.php`
- Create: `src/Build/Edit/Handler/HistoryEditHandler.php`
- Create: `src/Command/BuildHistoryPruneCommand.php`
- Modify: `src/Build/Edit/CommandFactory.php`
- Test: `tests/Build/RevertTest.php`, `tests/Command/BuildHistoryPruneCommandTest.php`

**Interfaces:**
- Consumes: `BuildEditor`, `BuildSnapshot`, `EditHistory`, `BuildEventRepository`.
- Produces:
  - Action strings `snapshot.create` (payload `name`) and `history.revert` (payload `event_id`)
  - `CreateSnapshot(int $buildId, string $name)`, `Revert(int $buildId, int $eventId)`
  - Console command `app:build:history:prune --days=30`

- [ ] **Step 1: Write the failing revert test**

Create `tests/Build/RevertTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\Command\AllocatePassive;
use App\Build\Edit\Command\CreateSnapshot;
use App\Build\Edit\Command\Revert;
use App\Entity\Build;
use App\Entity\BuildEvent;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildEventRepository;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RevertTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BuildRepository $builds;
    private BuildEventRepository $events;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->builds = self::getContainer()->get(BuildRepository::class);
        $this->events = self::getContainer()->get(BuildEventRepository::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $this->entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    public function testRevertingPutsTheBuildBackAndKeepsEveryEventThatFollowed(): void
    {
        $build = $this->build();
        $id = (int) $build->getId();

        $this->bus->dispatch(new AllocatePassive($id, 'first'));
        $afterFirst = $this->events->timeline($this->reload($build))[0];
        $this->bus->dispatch(new AllocatePassive($id, 'second'));

        $this->bus->dispatch(new Revert($id, (int) $afterFirst->getId()));
        $this->entityManager->clear();

        $reverted = $this->reload($build);
        $ids = array_column($reverted->toDocument()->passives, 'id');

        self::assertContains('first', $ids);
        self::assertNotContains('second', $ids);
        self::assertSame(
            ['revert', 'passive.allocate', 'passive.allocate'],
            array_map(static fn (BuildEvent $e): string => $e->getAction(), $this->events->timeline($reverted)),
            'reverting appends; nothing is deleted',
        );
    }

    public function testRevertingTwiceWalksForwardAgain(): void
    {
        $build = $this->build();
        $id = (int) $build->getId();

        $this->bus->dispatch(new AllocatePassive($id, 'first'));
        $afterFirst = $this->events->timeline($this->reload($build))[0];
        $this->bus->dispatch(new AllocatePassive($id, 'second'));
        $afterSecond = $this->events->timeline($this->reload($build))[0];

        $this->bus->dispatch(new Revert($id, (int) $afterFirst->getId()));
        $this->bus->dispatch(new Revert($id, (int) $afterSecond->getId()));
        $this->entityManager->clear();

        self::assertContains('second', array_column($this->reload($build)->toDocument()->passives, 'id'));
    }

    public function testANamedSnapshotRecordsTheStateWithoutChangingIt(): void
    {
        $build = $this->build();
        $id = (int) $build->getId();
        $before = $build->toDocument();

        $this->bus->dispatch(new CreateSnapshot($id, 'Before the respec'));
        $this->entityManager->clear();

        $reloaded = $this->reload($build);
        $event = $this->events->timeline($reloaded)[0];

        self::assertTrue($event->isNamedSnapshot());
        self::assertSame('Before the respec', $event->getSnapshotName());
        self::assertEquals($before, $reloaded->toDocument());
    }

    public function testAnEventBelongingToAnotherBuildCannotBeRevertedTo(): void
    {
        $mine = $this->build();
        $theirs = $this->build();
        $this->bus->dispatch(new AllocatePassive((int) $theirs->getId(), 'theirs'));
        $theirEvent = $this->events->timeline($this->reload($theirs))[0];

        $this->expectException(\Throwable::class);

        $this->bus->dispatch(new Revert((int) $mine->getId(), (int) $theirEvent->getId()));
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

Run: `php bin/phpunit tests/Build/RevertTest.php`
Expected: FAIL — `App\Build\Edit\Command\CreateSnapshot` does not exist.

- [ ] **Step 3: Write the two commands**

`src/Build/Edit/Command/CreateSnapshot.php` with `public int $buildId, public string $name`; `src/Build/Edit/Command/Revert.php` with `public int $buildId, public int $eventId`. Both `final readonly` and `implements EditCommand`, exactly like the DTOs in Task 8.

- [ ] **Step 4: Write the handler**

Create `src/Build/Edit/Handler/HistoryEditHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildSnapshot;
use App\Build\Edit\Command\CreateSnapshot;
use App\Build\Edit\Command\Revert;
use App\Build\Edit\EditHistory;
use App\Build\Edit\InvalidEditCommand;
use App\Repository\BuildEventRepository;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The two edits that act on the history itself.
 *
 * Neither goes through BuildEditor: a snapshot changes nothing, and a revert
 * needs the event it is reverting to before it can change anything.
 */
final class HistoryEditHandler
{
    public function __construct(
        private readonly BuildRepository $builds,
        private readonly BuildEventRepository $events,
        private readonly EditHistory $history,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[AsMessageHandler]
    public function snapshot(CreateSnapshot $command): void
    {
        $build = $this->builds->find($command->buildId) ?? throw InvalidEditCommand::noSuchEntry('build');
        $name = trim($command->name);

        if ('' === $name) {
            throw InvalidEditCommand::noSuchEntry('name for this snapshot');
        }

        $this->history->record($build, 'snapshot', ['name' => $name], $name);
        $this->entityManager->flush();
    }

    #[AsMessageHandler]
    public function revert(Revert $command): void
    {
        $build = $this->builds->find($command->buildId) ?? throw InvalidEditCommand::noSuchEntry('build');
        $event = $this->events->findForBuild($build, $command->eventId) ?? throw InvalidEditCommand::noSuchEntry('history entry');

        BuildSnapshot::restore($build, $event->getSnapshot());

        $this->history->record($build, 'revert', ['event_id' => $command->eventId]);
        $this->entityManager->flush();
    }
}
```

- [ ] **Step 5: Add both actions to the factory**

In `src/Build/Edit/CommandFactory.php`, add to the match:

```php
            'snapshot.create' => new CreateSnapshot($buildId, $this->string($payload, 'name')),
            'history.revert' => new Revert($buildId, $this->int($payload, 'event_id')),
```

- [ ] **Step 6: Run the revert test to verify it passes**

Run: `php bin/phpunit tests/Build/RevertTest.php`
Expected: PASS, four tests.

- [ ] **Step 7: Write the failing prune command test**

Create `tests/Command/BuildHistoryPruneCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Build\Edit\EditHistory;
use App\Entity\Build;
use App\Entity\BuildEvent;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BuildHistoryPruneCommandTest extends KernelTestCase
{
    public function testItDropsExpiredEventsAndReportsHowMany(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();

        $build = self::getContainer()->get(BuildRepository::class)->create(new BuildDocumentReader()->read('{"name":"Build"}'), '0.5.5');
        $history = self::getContainer()->get(EditHistory::class);
        $expired = $history->record($build, 'passive.allocate', ['id' => 'a']);
        $history->record($build, 'passive.allocate', ['id' => 'b']);
        $entityManager->flush();

        $entityManager->getConnection()->executeStatement(
            'UPDATE build_event SET created_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-31 days'))->format('Y-m-d H:i:s'), $expired->getId()],
        );

        $tester = new CommandTester(new Application(self::$kernel)->find('app:build:history:prune'));
        $tester->execute(['--days' => '30']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1', $tester->getDisplay());
        self::assertSame(1, (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM build_event'));
    }
}
```

- [ ] **Step 8: Run it and watch it fail**

Run: `php bin/phpunit tests/Command/BuildHistoryPruneCommandTest.php`
Expected: FAIL — command `app:build:history:prune` is not defined.

- [ ] **Step 9: Write the command**

Create `src/Command/BuildHistoryPruneCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\BuildEventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drops edit history past the retention window. Named snapshots are kept
 * whatever their age — the user asked for those.
 *
 * A command rather than a scheduled job, like the catalog sync: deleting
 * someone's history should be something a person decided to do.
 */
#[AsCommand(name: 'app:build:history:prune', description: 'Delete edit history older than the retention window')]
final class BuildHistoryPruneCommand extends Command
{
    private const int DEFAULT_DAYS = 30;

    public function __construct(private readonly BuildEventRepository $events)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days of history to keep', (string) self::DEFAULT_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');

        if ($days < 1) {
            $io->error('Keep at least one day of history.');

            return Command::INVALID;
        }

        $cutoff = new \DateTimeImmutable(\sprintf('-%d days', $days));
        $deleted = $this->events->prune($cutoff);

        $io->success(\sprintf('Deleted %d edit events from before %s. Named snapshots were kept.', $deleted, $cutoff->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 10: Run the test to verify it passes**

Run: `php bin/phpunit tests/Command/BuildHistoryPruneCommandTest.php`
Expected: PASS.

- [ ] **Step 11: Run the gate and commit**

```bash
composer gate
git add src/Build/Edit src/Command/BuildHistoryPruneCommand.php tests/
git commit -m "$(cat <<'EOF'
feat(build): revert to any point, name snapshots, prune expired history

Reverting appends an event rather than truncating, so walking back and
forth loses nothing. Pruning keeps named snapshots whatever their age.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

### Task 10: Frontend scaffolding — AssetMapper, Stimulus, Turbo

No `assets/` directory exists yet. This is scaffolding only: it changes no behaviour, and it earns its own commit because a broken asset pipeline is far easier to diagnose alone than mixed into a feature.

**Files:**
- Modify: `composer.json`, `importmap.php` (created by the recipe)
- Create: `assets/app.js`, `assets/bootstrap.js`, `assets/styles/app.css`
- Modify: `templates/base.html.twig`
- Modify: `docs/setup.md`
- Test: `tests/Controller/BuildControllerTest.php` (no change needed — it must keep passing)

**Interfaces:**
- Consumes: nothing.
- Produces: `{{ importmap('app') }}` available in Twig; Stimulus controllers auto-registered from `assets/controllers/`; `@hotwired/turbo` and `@hotwired/stimulus` importable by name in `assets/`.

- [ ] **Step 1: Install the three packages**

```bash
composer require symfony/asset-mapper:8.1.* symfony/stimulus-bundle symfony/ux-turbo
php bin/console importmap:install
```

Answer yes if Flex asks to run the recipes. `symfony/stimulus-bundle` and `symfony/ux-turbo` are UX packages on their own version line — do **not** pin them to `8.1.*`.

- [ ] **Step 2: Write the entrypoints**

Create `assets/app.js`:

```js
import './bootstrap.js';
import './styles/app.css';
```

Create `assets/bootstrap.js`:

```js
import { startStimulusApp } from '@symfony/stimulus-bundle';

const app = startStimulusApp();

export { app };
```

Create `assets/styles/app.css` with a plain, legible two-column editor layout:

```css
:root { --gap: 1rem; --line: #d5d5d5; }

body { font-family: system-ui, sans-serif; line-height: 1.5; margin: 0 auto; max-width: 72rem; padding: var(--gap); }

.editor { display: grid; gap: var(--gap); grid-template-columns: minmax(0, 2fr) minmax(16rem, 1fr); }
.editor > section { border: 1px solid var(--line); padding: var(--gap); }
.editor > section.full { grid-column: 1 / -1; }

.tree-canvas { background: #101014; display: block; height: 32rem; touch-action: none; width: 100%; }

.error:not(:empty) { background: #fdeaea; border: 1px solid #c00; margin-bottom: var(--gap); padding: 0.5rem; }

@media (max-width: 48rem) { .editor { grid-template-columns: minmax(0, 1fr); } }
```

- [ ] **Step 3: Load them from the layout**

In `templates/base.html.twig`, inside `<head>`, after the `<title>`:

```twig
        {% block stylesheets %}{% endblock %}
        {{ importmap('app') }}
```

- [ ] **Step 4: Verify nothing regressed**

Run: `php bin/console cache:clear && composer gate`
Expected: green — every existing controller test still passes with the new layout.

Then run `symfony server:start` (or `php -S localhost:8000 -t public`), open `/`, and confirm in the browser's dev tools that `app.js` loads with no console errors and no 404s.

- [ ] **Step 5: Document the new tooling**

Add a short section to `docs/setup.md`: assets are served by AssetMapper with no bundler, `php bin/console importmap:install` after a fresh checkout, and `php bin/console asset-map:compile` before deployment.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock importmap.php assets templates/base.html.twig docs/setup.md config
git commit -m "$(cat <<'EOF'
build: add AssetMapper, Stimulus and Turbo

Scaffolding for the editor. No bundler and no Node runtime: AssetMapper
serves the files as they are, which is all the canvas controller needs.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 11: The editor shell, the header form, and the `/act` endpoint

The endpoint every later slice posts to. Form-encoded, because that is the one body shape a plain `<form>` and a `fetch` can both send — which is what makes the editor work with JavaScript switched off.

**Files:**
- Create: `src/Controller/BuildEditorController.php`
- Create: `templates/build/_header.html.twig`, `templates/build/_streams.html.twig`, `templates/build/_error.html.twig`, `templates/build/_findings.html.twig`
- Modify: `templates/build/edit.html.twig`
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: `CommandFactory`, `MessageBusInterface`, `BuildRepository`, `InventorySlots`, `BuildEventRepository`, `CatalogClass` rows.
- Produces:
  - Route `app_build_act` at `POST /b/{slug}/edit/{token}/act`
  - **Turbo Stream target ids, fixed for Tasks 12–17:** `build-header`, `build-nodes`, `build-tree`, `build-skills`, `build-slots`, `build-history`, `build-state`, `build-error`, `build-findings`
  - `App\Controller\EditorContext::of(Build $build, string $token, ?string $error = null): array<string, mixed>` — the one array every partial renders from, used by the page and by the streams. Tasks 12 and 13 add keys to it.
- **Must not regress:** `templates/build/edit.html.twig` keeps the two-links section and the "Replace the stored file" form. `BuildControllerTest::testTheEditPageOpensWithTheRightToken` asserts `form[action$="/update"]` exists on this page.

- [ ] **Step 1: Write the failing test**

Create `tests/Controller/BuildEditorControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Build;
use App\Entity\BuildEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BuildEditorControllerTest extends WebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    public function testAPlainFormPostChangesTheBuildAndRedirectsBack(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'Respec at 60']);

        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Respec at 60');
    }

    public function testATurboRequestGetsStreamsInsteadOfARedirect(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'From Turbo'], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', self::STREAM.'; charset=UTF-8');
        $body = (string) $this->client->getResponse()->getContent();
        foreach (['build-header', 'build-nodes', 'build-history', 'build-state', 'build-error'] as $target) {
            self::assertStringContainsString('target="'.$target.'"', $body);
        }
    }

    public function testARefusedEditAnswers422AndSaysWhyRatherThanFailing(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'target_level', 'value' => '900'], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('target level', (string) $this->client->getResponse()->getContent());
    }

    public function testEditingWithoutTheEditTokenIsRefused(): void
    {
        $slug = $this->slugOf($this->createBuild());

        $this->client->request('POST', '/b/'.$slug.'/edit/'.str_repeat('0', 64).'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'Hijacked']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheEditorStillOffersTheWholeFileReplacement(): void
    {
        $this->client->request('GET', $this->createBuild());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/update"]');
        self::assertSelectorExists('#build-findings');
    }

    /**
     * @return string the edit URL, without a trailing slash
     */
    private function createBuild(): string
    {
        $source = __DIR__.'/../fixtures/build/valid-full.build';
        $copy = sys_get_temp_dir().'/'.uniqid('upload', true).'.build';
        copy($source, $copy);

        $this->client->request('POST', '/builds', files: ['build' => new UploadedFile($copy, 'valid-full.build', 'application/json', test: true)]);

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    private function slugOf(string $editUrl): string
    {
        self::assertSame(1, preg_match('#/b/([0-9a-zA-Z]{22})#', $editUrl, $m));

        return $m[1];
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php bin/phpunit tests/Controller/BuildEditorControllerTest.php`
Expected: FAIL — 404, no `/act` route.

- [ ] **Step 3: Write the shared context builder**

One builder for the page and for the streams, so a field can never appear in one and not the other. Create `src/Controller/EditorContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Build\InventorySlots;
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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(Build $build, string $token, ?string $error = null): array
    {
        return [
            'build' => $build,
            'token' => $token,
            'document' => $build->toDocument(),
            'classes' => $this->entityManager->getRepository(CatalogClass::class)->findBy([], ['id' => 'ASC']),
            'slots' => $this->slots->all(),
            'events' => $this->events->timeline($build),
            'error' => $error,
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

Create `src/Controller/BuildEditorController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Build\Edit\CommandFactory;
use App\Build\Edit\InvalidEditCommand;
use App\Entity\Build;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * In-place editing. The build's own lifecycle — create, share, export,
 * replace the whole file — stays in BuildController.
 *
 * Every edit arrives as a form-encoded POST carrying an action and its
 * payload. That is deliberate: a plain <form> and the canvas controller's
 * fetch send the identical body, so the editor keeps working when the canvas
 * does not. What differs is only the answer — Turbo asks for streams, a
 * browser without JavaScript gets a redirect.
 */
final class BuildEditorController extends AbstractController
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    public function __construct(
        private readonly BuildRepository $builds,
        private readonly CommandFactory $commands,
        private readonly MessageBusInterface $bus,
        private readonly EditorContext $context,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/b/{slug}/edit/{token}/act', name: 'app_build_act', requirements: ['slug' => '[0-9a-zA-Z]{22}', 'token' => '[0-9a-f]{64}'], methods: ['POST'])]
    public function act(Request $request, string $slug, string $token): Response
    {
        $build = $this->mustEdit($slug, $token);
        $wantsStream = str_contains((string) $request->headers->get('Accept'), self::STREAM);

        try {
            $command = $this->commands->fromRequest((int) $build->getId(), (string) $request->request->get('action', ''), $request->request);
            $this->bus->dispatch($command);
        } catch (InvalidEditCommand $e) {
            return $this->refuse($build, $token, $e->getMessage(), $wantsStream);
        } catch (HandlerFailedException $e) {
            $cause = $e->getPrevious();

            if (!$cause instanceof InvalidEditCommand) {
                throw $e;
            }

            return $this->refuse($build, $token, $cause->getMessage(), $wantsStream);
        }

        $this->entityManager->refresh($build);

        if (!$wantsStream) {
            return $this->redirectToRoute('app_build_edit', ['slug' => $slug, 'token' => $token], Response::HTTP_SEE_OTHER);
        }

        return $this->streams($build, $token, null);
    }

    private function refuse(Build $build, string $token, string $message, bool $wantsStream): Response
    {
        if (!$wantsStream) {
            $this->addFlash('error', $message);

            return $this->redirectToRoute('app_build_edit', ['slug' => $build->getShareSlug(), 'token' => $token], Response::HTTP_SEE_OTHER);
        }

        return $this->streams($build, $token, $message, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function streams(Build $build, string $token, ?string $error, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('build/_streams.html.twig', $this->context->of($build, $token, $error), new Response(status: $status));
        $response->headers->set('Content-Type', self::STREAM);

        return $response;
    }

    private function mustEdit(string $slug, string $token): Build
    {
        $build = $this->builds->findOneByShareSlug($slug) ?? throw $this->createNotFoundException();

        if (!$this->builds->isEditableWith($build, $token)) {
            throw $this->createNotFoundException();
        }

        return $build;
    }
}
```

- [ ] **Step 5: Write the streams template**

Create `templates/build/_streams.html.twig`. Every area is re-rendered on every action: the editor state is small, and picking which fragment an action touched would be logic with no payoff.

```twig
<turbo-stream action="replace" target="build-error"><template>{{ include('build/_error.html.twig') }}</template></turbo-stream>
<turbo-stream action="replace" target="build-header"><template>{{ include('build/_header.html.twig') }}</template></turbo-stream>
<turbo-stream action="replace" target="build-state"><template>{{ include('build/_state.html.twig') }}</template></turbo-stream>
<turbo-stream action="replace" target="build-nodes"><template>{{ include('build/_nodes.html.twig') }}</template></turbo-stream>
<turbo-stream action="replace" target="build-skills"><template>{{ include('build/_skills.html.twig') }}</template></turbo-stream>
<turbo-stream action="replace" target="build-slots"><template>{{ include('build/_slots.html.twig') }}</template></turbo-stream>
<turbo-stream action="replace" target="build-history"><template>{{ include('build/_history.html.twig') }}</template></turbo-stream>
```

Tasks 12–15 create `_nodes`, `_skills`, `_slots` and `_history`. To keep this task green on its own, create each of them now as a one-line stub carrying only its wrapper element and id — for example `templates/build/_nodes.html.twig`:

```twig
<section id="build-nodes"><h2>Passives</h2></section>
```

- [ ] **Step 6: Write the error, findings, state and header partials**

`templates/build/_error.html.twig`:

```twig
<div id="build-error" class="error" role="alert" aria-live="polite">{{ error ?? '' }}</div>
```

`templates/build/_findings.html.twig`:

```twig
<section id="build-findings" class="full">
    <h2>Findings</h2>
    <p>Checks against the catalog arrive in the next iteration. Nothing here is inspecting this build yet.</p>
</section>
```

`templates/build/_state.html.twig` — the one bridge between server-rendered HTML and the canvas, which is not in the DOM at all:

```twig
{% set allocated = document.passives|map(p => p.id)|filter(id => id is not null) %}
<script type="application/json" id="build-state" data-tree-target="state">
    {{ {
        allocated: allocated|values,
        ascendancy: build.ascendancyKey,
        classKey: build.classKey,
    }|json_encode|raw }}
</script>
```

`templates/build/_header.html.twig` — every field posts to the same endpoint, on `change` rather than on every keystroke:

```twig
<section id="build-header" class="full">
    <h2>Build</h2>

    {% macro field(build, token, name, label, value, type) %}
        <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
            <input type="hidden" name="action" value="header.set">
            <input type="hidden" name="field" value="{{ name }}">
            <label for="header-{{ name }}">{{ label }}</label>
            <input type="{{ type|default('text') }}" id="header-{{ name }}" name="value" value="{{ value }}" onchange="this.form.requestSubmit()">
            <button type="submit">Save</button>
        </form>
    {% endmacro %}

    {% import _self as header %}

    {{ header.field(build, token, 'name', 'Name', build.name) }}
    {{ header.field(build, token, 'author', 'Author', build.author) }}
    {{ header.field(build, token, 'target_level', 'Target level', build.targetLevel, 'number') }}
    {{ header.field(build, token, 'note', 'Note', build.note) }}
    {{ header.field(build, token, 'archetype_key', 'Archetype', build.archetypeKey) }}

    <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
        <input type="hidden" name="action" value="header.set">
        <input type="hidden" name="field" value="class_key">
        <label for="header-class">Class</label>
        <select id="header-class" name="value" onchange="this.form.requestSubmit()">
            <option value="">Not chosen</option>
            {% for class in classes %}
                <option value="{{ class.id }}" {{ class.id == build.classKey ? 'selected' : '' }}>{{ class.id }}</option>
            {% endfor %}
        </select>
        <button type="submit">Save</button>
    </form>

    <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
        <input type="hidden" name="action" value="header.set">
        <input type="hidden" name="field" value="ascendancy">
        <label for="header-ascendancy">Ascendancy</label>
        <select id="header-ascendancy" name="value" onchange="this.form.requestSubmit()">
            <option value="">Not chosen</option>
            {% for class in classes if class.id == build.classKey %}
                {% for ascendancy in class.ascendancies %}
                    <option value="{{ ascendancy.id }}" {{ ascendancy.id == build.ascendancyKey ? 'selected' : '' }}>{{ ascendancy.name }}</option>
                {% endfor %}
            {% endfor %}
        </select>
        <button type="submit">Save</button>
    </form>

    <p>Game version {{ build.gameVersion }}.</p>
</section>
```

The visible `Save` button is not decoration: `requestSubmit()` needs JavaScript, and without it the button is the only way to submit.

- [ ] **Step 7: Rewrite the editor page**

Replace `templates/build/edit.html.twig`. It renders the same context the streams do, so the two cannot drift:

```twig
{% extends 'base.html.twig' %}

{% block title %}{{ build.name }}{% endblock %}

{% block body %}
    {{ include('build/_error.html.twig') }}

    <div class="editor">
        {{ include('build/_header.html.twig') }}
        {{ include('build/_tree.html.twig') }}
        {{ include('build/_nodes.html.twig') }}
        {{ include('build/_skills.html.twig') }}
        {{ include('build/_slots.html.twig') }}
        {{ include('build/_history.html.twig') }}
        {{ include('build/_findings.html.twig') }}
    </div>

    <section class="full">
        <h2>Your two links</h2>
        <p>
            Share this one — it is read only:
            <a href="{{ url('app_build_show', {slug: build.shareSlug}) }}">{{ url('app_build_show', {slug: build.shareSlug}) }}</a>
        </p>
        <p>
            Keep this one. It is the only way back in to change the build, and it is not recoverable:
            <a href="{{ url('app_build_edit', {slug: build.shareSlug, token: token}) }}">{{ url('app_build_edit', {slug: build.shareSlug, token: token}) }}</a>
        </p>

        <h2>Replace the stored file</h2>
        <form action="{{ path('app_build_update', {slug: build.shareSlug, token: token}) }}" method="post" enctype="multipart/form-data">
            <p>
                <label for="build">A newer .build file</label>
                <input type="file" id="build" name="build" accept=".build,application/json">
            </p>
            <p>
                <label for="json">…or paste its JSON</label>
                <textarea id="json" name="json" rows="8" cols="60"></textarea>
            </p>
            <button type="submit">Replace</button>
        </form>
    </section>
{% endblock %}
```

Create `templates/build/_tree.html.twig` as a stub for now — Task 17 fills it:

```twig
<section id="build-tree"><h2>Passive tree</h2>{{ include('build/_state.html.twig') }}</section>
```

- [ ] **Step 8: Feed the page from the same context**

The editor page is rendered by `BuildController::edit()`, which still passes only `build` and `token` — every partial would blow up on a missing variable. In `src/Controller/BuildController.php`, inject `EditorContext $context` and render through it:

```php
        return $this->render('build/edit.html.twig', $this->context->of($build, $token));
```

The `update()` action renders `build/edit.html.twig` twice on failure; both need the same treatment, passing the message as the third argument:

```php
            return $this->render('build/edit.html.twig', $this->context->of($build, $token, 'Choose a .build file or paste its JSON.'), new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
```

and

```php
            return $this->render('build/edit.html.twig', $this->context->of($build, $token, $e->getMessage()), new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Controller`
Expected: PASS — the new editor tests and every existing `BuildControllerTest` case, including the one asserting the replace form is still there.

- [ ] **Step 10: Check it in a browser**

Start the server, open a build's edit link, change the note and the target level both with JavaScript enabled (the form submits on change, the page does not reload) and with it disabled (the Save button works, the page redirects back). Confirm an out-of-range target level shows the message rather than a stack trace.

- [ ] **Step 11: Run the gate and commit**

```bash
composer gate
git add src/Controller templates/build tests/Controller/BuildEditorControllerTest.php
git commit -m "$(cat <<'EOF'
feat(editor): add the editor shell, the header form and the edit endpoint

One form-encoded endpoint for every edit, answering with Turbo Streams or
a redirect depending on what asked. The redirect path is what keeps the
editor usable without JavaScript.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

### Task 12: The node list — search and allocated passives

The accessible path to everything the canvas does. Nothing in a canvas reaches a keyboard or a screen reader, and findings target node ids that have to stay readable, so this is built before the canvas and works without it.

**Files:**
- Modify: `src/Catalog/CatalogSearch.php`
- Modify: `src/Controller/EditorContext.php`
- Create: `templates/build/_nodes.html.twig` (replacing the Task 11 stub)
- Test: `tests/Catalog/CatalogSearchPassivesTest.php`, add to `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: actions `passive.allocate`, `passive.deallocate`, `passive.interval` (Task 8); `catalog_passive`.
- Produces: `CatalogSearch::passives(string $query): list<array{id: string, name: string, kind: string, stats: string}>` — empty query answers with an empty list, `LIMIT 50`; `EditorContext::of()` gains `passiveQuery` and `passiveResults`.

- [ ] **Step 1: Write the failing search test**

Create `tests/Catalog/CatalogSearchPassivesTest.php`, following the arrangement `tests/Catalog/DoctrineCatalogTest.php` already uses to seed `catalog_passive` (read it first and reuse its helper):

```php
    public function testSearchingMatchesNameOrIdAndAnsweringNothingForAnEmptyQuery(): void
    {
        $this->seedPassives([
            ['id' => 'strength89', 'name' => 'Attribute', 'kind' => 'small'],
            ['id' => 'melee22_', 'name' => 'Brutal Strikes', 'kind' => 'notable'],
        ]);

        self::assertSame(['melee22_'], array_column($this->search->passives('Brutal'), 'id'));
        self::assertSame(['strength89'], array_column($this->search->passives('strength8'), 'id'), 'ids are searchable because findings name them');
        self::assertSame([], $this->search->passives(''));
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php bin/phpunit tests/Catalog/CatalogSearchPassivesTest.php`
Expected: FAIL — `Call to undefined method App\Catalog\CatalogSearch::passives()`.

- [ ] **Step 3: Implement the search**

Add to `src/Catalog/CatalogSearch.php`:

```php
    /**
     * Passives for the node list beside the tree. Ids are matched as well as
     * names: a finding names a node by id, and the list is where that id is
     * looked up.
     *
     * @return list<array{id: string, name: string, kind: string, stats: string}>
     */
    public function passives(string $query): array
    {
        if ('' === trim($query)) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, kind, stats FROM catalog_passive WHERE name LIKE ? OR id LIKE ? ORDER BY name LIMIT 50',
            ['%'.$query.'%', '%'.$query.'%'],
        );

        $results = [];

        foreach ($rows as $row) {
            $stats = json_decode(Row::str($row, 'stats', '[]'), true);

            $results[] = [
                'id' => Row::str($row, 'id'),
                'name' => Row::str($row, 'name'),
                'kind' => Row::str($row, 'kind'),
                'stats' => implode(', ', array_filter(\is_array($stats) ? $stats : [], is_string(...))),
            ];
        }

        return $results;
    }
```

- [ ] **Step 4: Run the search test to verify it passes**

Run: `php bin/phpunit tests/Catalog/CatalogSearchPassivesTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing editor test**

Add to `tests/Controller/BuildEditorControllerTest.php`:

```php
    public function testAPassiveCanBeAllocatedAndRemovedWithoutJavascript(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'melee99_']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-nodes', 'melee99_');

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.deallocate', 'id' => 'melee99_']);
        $this->client->followRedirect();

        self::assertSelectorTextNotContains('#build-nodes', 'melee99_');
    }
```

- [ ] **Step 6: Run it and watch it fail**

Run: `php bin/phpunit tests/Controller/BuildEditorControllerTest.php`
Expected: FAIL — the stub `_nodes.html.twig` lists nothing.

- [ ] **Step 7: Carry the search into the context**

In `src/Controller/EditorContext.php`, inject `CatalogSearch $search` and `RequestStack $requests`, and add to the returned array:

```php
            'passiveQuery' => $query,
            'passiveResults' => $this->search->passives($query),
```

where `$query` is read from the current request: `$query = trim((string) ($this->requests->getCurrentRequest()?->query->get('q') ?? ''));`. Searching is a GET parameter on the editor page, so a search is a link the user can share and the back button understands.

- [ ] **Step 8: Write the node list**

Replace `templates/build/_nodes.html.twig`:

```twig
{% set allocatedIds = document.passives|map(p => p.id)|values %}

<section id="build-nodes">
    <h2>Passives</h2>

    <form action="{{ path('app_build_edit', {slug: build.shareSlug, token: token}) }}" method="get">
        <label for="passive-q">Search the tree</label>
        <input type="search" id="passive-q" name="q" value="{{ passiveQuery }}" placeholder="name or id">
        <button type="submit">Search</button>
    </form>

    {% if passiveQuery is not empty %}
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
                            <button type="submit">Allocate</button>
                        </form>
                    {% endif %}
                </li>
            {% else %}
                <li>Nothing matches “{{ passiveQuery }}”.</li>
            {% endfor %}
        </ul>
    {% endif %}

    <h3>Allocated ({{ document.passives|length }})</h3>
    <ul>
        {% for passive in document.passives %}
            <li>
                <code>{{ passive.id }}</code>
                <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                    <input type="hidden" name="action" value="passive.interval">
                    <input type="hidden" name="id" value="{{ passive.id }}">
                    <label for="from-{{ loop.index }}">From</label>
                    <input type="number" id="from-{{ loop.index }}" name="from" min="0" max="100" value="{{ passive.level_interval[0] ?? 1 }}">
                    <label for="to-{{ loop.index }}">to</label>
                    <input type="number" id="to-{{ loop.index }}" name="to" min="0" max="100" value="{{ passive.level_interval[1] ?? 100 }}">
                    <button type="submit">Set levels</button>
                </form>
                <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                    <input type="hidden" name="action" value="passive.deallocate">
                    <input type="hidden" name="id" value="{{ passive.id }}">
                    <button type="submit">Remove</button>
                </form>
            </li>
        {% else %}
            <li>No passives yet. Search above, or click the tree.</li>
        {% endfor %}
    </ul>
</section>
```

- [ ] **Step 9: Run the tests, check the browser, commit**

Run: `php bin/phpunit tests/Controller tests/Catalog` — expected PASS.
In a browser: search for a node, allocate it, set its levels, remove it — then repeat with JavaScript disabled.

```bash
composer gate
git add src/Catalog/CatalogSearch.php src/Controller/EditorContext.php templates/build/_nodes.html.twig tests/
git commit -m "$(cat <<'EOF'
feat(editor): search and allocate passives from the node list

The accessible path to everything the canvas will do, built first and on
its own: a canvas reaches no keyboard and no screen reader, and findings
name nodes by id, so the ids have to stay visible in the DOM.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 13: Skills and supports

**Files:**
- Modify: `src/Controller/EditorContext.php`
- Create: `templates/build/_skills.html.twig` (replacing the Task 11 stub)
- Test: add to `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: actions `skill.add`, `skill.remove`, `skill.interval`, `support.add`, `support.remove`, `support.interval`; `CatalogSearch::search()` from iteration 2 for gem lookup.
- Produces: `EditorContext::of()` gains `gemQuery` and `gemResults` (from `CatalogSearch::search($query, 'all')`), read from the `gem` query parameter.

- [ ] **Step 1: Write the failing test**

Add to `tests/Controller/BuildEditorControllerTest.php`:

```php
    public function testASkillCanBeAddedGivenASupportAndRemovedAgain(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'skill.add', 'gem_id' => 'Metadata/Items/Gem/SkillGemHatefulFocus']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#build-skills', 'Metadata/Items/Gem/SkillGemHatefulFocus');

        $this->client->request('POST', $edit.'/act', ['action' => 'support.add', 'skill_index' => '2', 'support_id' => 'Metadata/Items/Gems/SupportGemFastForward']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#build-skills', 'SupportGemFastForward');

        $this->client->request('POST', $edit.'/act', ['action' => 'skill.remove', 'index' => '2']);
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('#build-skills', 'HatefulFocus');
    }
```

Index 2 is correct because the fixture build already carries two skills.

- [ ] **Step 2: Run it and watch it fail**

Run: `php bin/phpunit tests/Controller/BuildEditorControllerTest.php`
Expected: FAIL — the stub `_skills.html.twig` lists nothing.

- [ ] **Step 3: Write the template**

Replace `templates/build/_skills.html.twig`. Gem ids are shown verbatim beside names, because that is the key space findings will target and the prefixes differ between otherwise identical-looking gems:

```twig
<section id="build-skills" class="full">
    <h2>Skills</h2>

    <form action="{{ path('app_build_edit', {slug: build.shareSlug, token: token}) }}" method="get">
        <label for="gem-q">Find a gem</label>
        <input type="search" id="gem-q" name="gem" value="{{ gemQuery }}" placeholder="name or id">
        <button type="submit">Search</button>
    </form>

    {% if gemQuery is not empty %}
        <ul>
            {% for gem in gemResults %}
                <li>
                    <strong>{{ gem.name }}</strong> <code>{{ gem.id }}</code> <span>{{ gem.kind }}</span>
                    <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                        <input type="hidden" name="action" value="skill.add">
                        <input type="hidden" name="gem_id" value="{{ gem.id }}">
                        <button type="submit">Add as a skill</button>
                    </form>
                </li>
            {% else %}
                <li>Nothing matches “{{ gemQuery }}”.</li>
            {% endfor %}
        </ul>
    {% endif %}

    <ol>
        {% for skill in document.skills %}
            {% set skillIndex = loop.index0 %}
            <li>
                <code>{{ skill.id }}</code>

                <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                    <input type="hidden" name="action" value="skill.interval">
                    <input type="hidden" name="index" value="{{ skillIndex }}">
                    <label for="skill-from-{{ skillIndex }}">From</label>
                    <input type="number" id="skill-from-{{ skillIndex }}" name="from" min="0" max="100" value="{{ skill.level_interval[0] ?? 1 }}">
                    <label for="skill-to-{{ skillIndex }}">to</label>
                    <input type="number" id="skill-to-{{ skillIndex }}" name="to" min="0" max="100" value="{{ skill.level_interval[1] ?? 100 }}">
                    <button type="submit">Set levels</button>
                </form>

                <ul>
                    {% for support in skill.support_skills|default([]) %}
                        <li>
                            <code>{{ support.id }}</code>
                            <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                                <input type="hidden" name="action" value="support.interval">
                                <input type="hidden" name="skill_index" value="{{ skillIndex }}">
                                <input type="hidden" name="support_id" value="{{ support.id }}">
                                <label for="sup-from-{{ skillIndex }}-{{ loop.index0 }}">From</label>
                                <input type="number" id="sup-from-{{ skillIndex }}-{{ loop.index0 }}" name="from" min="0" max="100" value="{{ support.level_interval[0] ?? 1 }}">
                                <label for="sup-to-{{ skillIndex }}-{{ loop.index0 }}">to</label>
                                <input type="number" id="sup-to-{{ skillIndex }}-{{ loop.index0 }}" name="to" min="0" max="100" value="{{ support.level_interval[1] ?? 100 }}">
                                <button type="submit">Set levels</button>
                            </form>
                            <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                                <input type="hidden" name="action" value="support.remove">
                                <input type="hidden" name="skill_index" value="{{ skillIndex }}">
                                <input type="hidden" name="support_id" value="{{ support.id }}">
                                <button type="submit">Remove support</button>
                            </form>
                        </li>
                    {% endfor %}
                </ul>

                <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                    <input type="hidden" name="action" value="support.add">
                    <input type="hidden" name="skill_index" value="{{ skillIndex }}">
                    <label for="support-id-{{ skillIndex }}">Support gem id</label>
                    <input type="text" id="support-id-{{ skillIndex }}" name="support_id" placeholder="Metadata/Items/Gems/SupportGem…">
                    <button type="submit">Add support</button>
                </form>

                <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                    <input type="hidden" name="action" value="skill.remove">
                    <input type="hidden" name="index" value="{{ skillIndex }}">
                    <button type="submit">Remove skill</button>
                </form>
            </li>
        {% else %}
            <li>No skills yet. Search for a gem above.</li>
        {% endfor %}
    </ol>
</section>
```

- [ ] **Step 4: Add the gem search to the context**

In `src/Controller/EditorContext.php`, read `gem` from the query string the same way `q` is read, and add `gemQuery` and `gemResults` using the existing `CatalogSearch::search($gemQuery ?: null, 'all')`.

- [ ] **Step 5: Run the tests, check the browser, commit**

Run: `php bin/phpunit tests/Controller` — expected PASS. In a browser, add a skill, attach a support, set both intervals, remove the support, remove the skill.

```bash
composer gate
git add src/Controller/EditorContext.php templates/build/_skills.html.twig tests/Controller/BuildEditorControllerTest.php
git commit -m "$(cat <<'EOF'
feat(editor): edit skills and their supports

Gem ids are shown verbatim beside the names: the three path prefixes are
genuine game data, and findings will name gems by id.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 14: Equipment slots

**Files:**
- Create: `templates/build/_slots.html.twig` (replacing the Task 11 stub)
- Test: add to `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: actions `slot.set`, `slot.clear`; `slots` from `EditorContext` (Task 7's `InventorySlots::all()`).
- Produces: nothing new.

- [ ] **Step 1: Write the failing test**

```php
    public function testAUniqueCanBeNamedForASlotAndClearedAgain(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'slot.set', 'inventory_id' => 'Ring1', 'unique_name' => 'Kalandra\'s Touch', 'from' => '1', 'to' => '100', 'additional_text' => '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#build-slots', "Kalandra's Touch");

        $this->client->request('POST', $edit.'/act', ['action' => 'slot.clear', 'inventory_id' => 'Ring1']);
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('#build-slots', "Kalandra's Touch");
    }

    public function testAllFourteenSlotsAreOffered(): void
    {
        $this->client->request('GET', $this->createBuild());

        self::assertSelectorCount(14, '#build-slots form[data-slot]');
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php bin/phpunit tests/Controller/BuildEditorControllerTest.php`
Expected: FAIL — the stub renders no slots.

- [ ] **Step 3: Write the template**

Replace `templates/build/_slots.html.twig`. Every slot is always listed, whether filled or not, because an empty slot is a planning decision too:

```twig
{% set filled = {} %}
{% for slot in document.inventorySlots %}
    {% set filled = filled|merge({(slot.inventory_id): slot}) %}
{% endfor %}

<section id="build-slots" class="full">
    <h2>Equipment</h2>
    <p>One unique per slot, by name. The format carries no rares and no numbers — this is its whole scope.</p>

    <ul>
        {% for slot in slots %}
            {% set current = filled[slot.id]|default(null) %}
            <li>
                <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post" data-slot="{{ slot.id }}">
                    <input type="hidden" name="action" value="slot.set">
                    <input type="hidden" name="inventory_id" value="{{ slot.id }}">

                    <label for="slot-name-{{ slot.id }}">{{ slot.label }}</label>
                    <input type="text" id="slot-name-{{ slot.id }}" name="unique_name" value="{{ current.unique_name|default('') }}" placeholder="a unique's name, or leave empty">

                    <label for="slot-from-{{ slot.id }}">From</label>
                    <input type="number" id="slot-from-{{ slot.id }}" name="from" min="0" max="100" value="{{ current.level_interval[0]|default(1) }}">
                    <label for="slot-to-{{ slot.id }}">to</label>
                    <input type="number" id="slot-to-{{ slot.id }}" name="to" min="0" max="100" value="{{ current.level_interval[1]|default(100) }}">

                    <label for="slot-text-{{ slot.id }}">Note</label>
                    <input type="text" id="slot-text-{{ slot.id }}" name="additional_text" value="{{ current.additional_text|default('') }}">

                    <button type="submit">Save</button>
                </form>

                {% if current %}
                    <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                        <input type="hidden" name="action" value="slot.clear">
                        <input type="hidden" name="inventory_id" value="{{ slot.id }}">
                        <button type="submit">Clear</button>
                    </form>
                {% endif %}
            </li>
        {% endfor %}
    </ul>
</section>
```

- [ ] **Step 4: Run the tests, check the browser, commit**

```bash
php bin/phpunit tests/Controller
composer gate
git add templates/build/_slots.html.twig tests/Controller/BuildEditorControllerTest.php
git commit -m "$(cat <<'EOF'
feat(editor): name a unique per equipment slot

All fourteen slots are always listed: an empty slot is a planning
decision too. No slot-fit check yet — that rule belongs to the engine.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 15: The history panel

**Files:**
- Create: `templates/build/_history.html.twig` (replacing the Task 11 stub)
- Test: add to `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: actions `history.revert`, `snapshot.create` (Task 9); `events` from `EditorContext`.
- Produces: nothing new.

- [ ] **Step 1: Write the failing test**

```php
    public function testAnEditCanBeRevertedFromTheHistoryPanel(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'first']);
        $crawler = $this->client->followRedirect();
        $eventId = $crawler->filter('#build-history button[name="event_id"]')->first()->attr('value');

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'second']);
        $this->client->followRedirect();

        $this->client->request('POST', $edit.'/act', ['action' => 'history.revert', 'event_id' => (string) $eventId]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-nodes', 'first');
        self::assertSelectorTextNotContains('#build-nodes', 'second');
        self::assertSelectorTextContains('#build-history', 'Reverted');
    }

    public function testASnapshotCanBeNamedAndIsMarkedAsKept(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'snapshot.create', 'name' => 'Before the respec']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-history', 'Before the respec');
        self::assertSelectorTextContains('#build-history', 'kept');
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php bin/phpunit tests/Controller/BuildEditorControllerTest.php`
Expected: FAIL — the stub renders no history.

- [ ] **Step 3: Write the template**

Replace `templates/build/_history.html.twig`. Each entry gets one plain sentence, because "passive.allocate" is not something a player should have to read:

```twig
{% set sentences = {
    'header.set': 'Changed a build detail',
    'passive.allocate': 'Allocated a passive',
    'passive.deallocate': 'Removed a passive',
    'passive.interval': 'Changed when a passive is taken',
    'skill.add': 'Added a skill',
    'skill.remove': 'Removed a skill',
    'skill.interval': 'Changed when a skill is used',
    'support.add': 'Added a support',
    'support.remove': 'Removed a support',
    'support.interval': 'Changed when a support is used',
    'slot.set': 'Changed an equipment slot',
    'slot.clear': 'Cleared an equipment slot',
    'snapshot': 'Named a snapshot',
    'revert': 'Reverted to an earlier point',
} %}

<section id="build-history">
    <h2>History</h2>

    <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
        <input type="hidden" name="action" value="snapshot.create">
        <label for="snapshot-name">Name this state</label>
        <input type="text" id="snapshot-name" name="name" placeholder="Before the respec" required>
        <button type="submit">Save a snapshot</button>
    </form>

    <p>Ordinary edits are kept for 30 days. Named snapshots are kept for good.</p>

    <ol>
        {% for event in events %}
            <li>
                <time datetime="{{ event.createdAt|date('c') }}">{{ event.createdAt|date('Y-m-d H:i') }}</time>
                {{ sentences[event.action]|default(event.action) }}
                {% if event.namedSnapshot %}
                    — <strong>{{ event.snapshotName }}</strong> (kept)
                {% endif %}

                <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
                    <input type="hidden" name="action" value="history.revert">
                    <button type="submit" name="event_id" value="{{ event.id }}">Revert to here</button>
                </form>
            </li>
        {% else %}
            <li>No edits yet.</li>
        {% endfor %}
    </ol>
</section>
```

The word "Reverted" the test looks for comes from the `revert` sentence.

- [ ] **Step 4: Run the tests, check the browser, commit**

In a browser: make three edits, revert to the first, confirm the later edits are still listed above the revert, then revert forward again.

```bash
php bin/phpunit tests/Controller
composer gate
git add templates/build/_history.html.twig tests/Controller/BuildEditorControllerTest.php
git commit -m "$(cat <<'EOF'
feat(editor): show the edit history with revert and named snapshots

One plain sentence per entry. Reverting appends, so the entries a revert
stepped over stay listed above it.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

### Task 16: Canvas geometry in JS, and the JS test runner

The two pure modules the canvas rests on. They are separated from the controller and tested on their own because both fail *silently*: a wrong zoom or an off-by-one cell shows up as "clicking feels off", never as an error.

**Files:**
- Create: `package.json`, `vitest.config.js`, `.gitignore` entry for `node_modules`
- Create: `assets/lib/camera.js`, `assets/lib/spatial_grid.js`
- Create: `tests/js/camera.test.js`, `tests/js/spatial_grid.test.js`
- Modify: `docs/setup.md`

**Interfaces:**
- Consumes: nothing.
- Produces, relied on by Task 17:
  - `createCamera({scale, x, y})` → `{scale, x, y}` where `x`/`y` is the world point at the canvas centre
  - `worldToScreen(camera, width, height, wx, wy)` → `{x, y}`
  - `screenToWorld(camera, width, height, sx, sy)` → `{x, y}`
  - `zoomAt(camera, width, height, sx, sy, factor, min, max)` → a new camera
  - `panBy(camera, dxScreen, dyScreen)` → a new camera
  - `buildGrid(nodes, cellSize)` where `nodes` is `[{id, x, y}]` → an opaque grid
  - `nearestNode(grid, x, y, radius)` → a node or `null`

- [ ] **Step 1: Set up the runner**

Create `package.json`:

```json
{
    "name": "poe-creation-helper",
    "private": true,
    "type": "module",
    "scripts": {
        "test:js": "vitest run",
        "test:e2e": "playwright test"
    },
    "devDependencies": {
        "vitest": "^2"
    }
}
```

Create `vitest.config.js`:

```js
export default {
    test: {
        include: ['tests/js/**/*.test.js'],
    },
};
```

Add `/node_modules/` to `.gitignore`, then run `npm install`.

- [ ] **Step 2: Write the failing camera tests**

Create `tests/js/camera.test.js`:

```js
import { describe, expect, it } from 'vitest';
import { createCamera, panBy, screenToWorld, worldToScreen, zoomAt } from '../../assets/lib/camera.js';

const WIDTH = 800;
const HEIGHT = 600;

describe('camera', () => {
    it('puts the camera centre in the middle of the canvas', () => {
        const camera = createCamera({ scale: 0.5, x: 100, y: -50 });

        expect(worldToScreen(camera, WIDTH, HEIGHT, 100, -50)).toEqual({ x: 400, y: 300 });
    });

    it('round-trips a point through screen space and back', () => {
        const camera = createCamera({ scale: 0.37, x: 1234, y: -987 });
        const screen = worldToScreen(camera, WIDTH, HEIGHT, -22597, 20196);
        const world = screenToWorld(camera, WIDTH, HEIGHT, screen.x, screen.y);

        expect(world.x).toBeCloseTo(-22597, 6);
        expect(world.y).toBeCloseTo(20196, 6);
    });

    it('leaves the world point under the cursor exactly where it was when zooming', () => {
        const camera = createCamera({ scale: 0.1, x: 0, y: 0 });
        const before = screenToWorld(camera, WIDTH, HEIGHT, 650, 120);

        const zoomed = zoomAt(camera, WIDTH, HEIGHT, 650, 120, 1.25);
        const after = screenToWorld(zoomed, WIDTH, HEIGHT, 650, 120);

        expect(after.x).toBeCloseTo(before.x, 6);
        expect(after.y).toBeCloseTo(before.y, 6);
    });

    it('clamps the zoom to its limits', () => {
        const camera = createCamera({ scale: 0.05, x: 0, y: 0 });

        expect(zoomAt(camera, WIDTH, HEIGHT, 0, 0, 0.1, 0.02, 2).scale).toBe(0.02);
        expect(zoomAt(camera, WIDTH, HEIGHT, 0, 0, 1000, 0.02, 2).scale).toBe(2);
    });

    it('pans by screen pixels, not world units', () => {
        const camera = createCamera({ scale: 0.5, x: 0, y: 0 });

        expect(panBy(camera, 50, -25)).toEqual({ scale: 0.5, x: -100, y: 50 });
    });
});
```

- [ ] **Step 3: Run them and watch them fail**

Run: `npm run test:js`
Expected: FAIL — cannot resolve `assets/lib/camera.js`.

- [ ] **Step 4: Write the camera**

Create `assets/lib/camera.js`:

```js
// Pan and zoom for the passive tree.
//
// A camera is {scale, x, y}, where x/y is the world point sitting at the
// canvas centre. Cameras are values: every function here returns a new one
// rather than mutating, so a failed edit can put the old one back.

export function createCamera({ scale = 0.1, x = 0, y = 0 } = {}) {
    return { scale, x, y };
}

export function worldToScreen(camera, width, height, wx, wy) {
    return {
        x: (wx - camera.x) * camera.scale + width / 2,
        y: (wy - camera.y) * camera.scale + height / 2,
    };
}

export function screenToWorld(camera, width, height, sx, sy) {
    return {
        x: (sx - width / 2) / camera.scale + camera.x,
        y: (sy - height / 2) / camera.scale + camera.y,
    };
}

// Zooming about a point: the world point under the cursor must not move, or
// the tree slides away from wherever the player is looking.
export function zoomAt(camera, width, height, sx, sy, factor, min = 0.02, max = 2) {
    const before = screenToWorld(camera, width, height, sx, sy);
    const scale = Math.min(max, Math.max(min, camera.scale * factor));
    const after = screenToWorld({ ...camera, scale }, width, height, sx, sy);

    return { scale, x: camera.x + (before.x - after.x), y: camera.y + (before.y - after.y) };
}

export function panBy(camera, dxScreen, dyScreen) {
    return { scale: camera.scale, x: camera.x - dxScreen / camera.scale, y: camera.y - dyScreen / camera.scale };
}
```

- [ ] **Step 5: Run the camera tests to verify they pass**

Run: `npm run test:js`
Expected: PASS, five tests.

- [ ] **Step 6: Write the failing grid tests**

Create `tests/js/spatial_grid.test.js`:

```js
import { describe, expect, it } from 'vitest';
import { buildGrid, nearestNode } from '../../assets/lib/spatial_grid.js';

const nodes = [
    { id: 'a', x: 0, y: 0 },
    { id: 'b', x: 300, y: 0 },
    { id: 'c', x: 0, y: 300 },
    { id: 'edge-of-next-cell', x: 121, y: 0 },
];

describe('spatial grid', () => {
    it('finds the node under the point', () => {
        expect(nearestNode(buildGrid(nodes, 120), 5, 5, 40).id).toBe('a');
    });

    it('finds a node that sits in a neighbouring cell', () => {
        // 119 and 121 fall either side of a 120-unit cell boundary.
        expect(nearestNode(buildGrid(nodes, 120), 119, 0, 40).id).toBe('edge-of-next-cell');
    });

    it('answers null when nothing is within the radius', () => {
        expect(nearestNode(buildGrid(nodes, 120), 1000, 1000, 40)).toBeNull();
    });

    it('picks the closest of two candidates', () => {
        const grid = buildGrid([{ id: 'near', x: 10, y: 0 }, { id: 'far', x: 30, y: 0 }], 120);

        expect(nearestNode(grid, 12, 0, 50).id).toBe('near');
    });

    it('searches far enough out when the radius is larger than a cell', () => {
        expect(nearestNode(buildGrid(nodes, 20), 0, 0, 320).id).toBe('a');
        expect(nearestNode(buildGrid(nodes, 20), 250, 0, 100).id).toBe('b');
    });

    it('handles negative coordinates, which half the tree has', () => {
        const grid = buildGrid([{ id: 'left', x: -22597, y: -19013 }], 120);

        expect(nearestNode(grid, -22590, -19010, 40).id).toBe('left');
    });
});
```

- [ ] **Step 7: Run them and watch them fail**

Run: `npm run test:js`
Expected: FAIL — cannot resolve `assets/lib/spatial_grid.js`.

- [ ] **Step 8: Write the grid**

Create `assets/lib/spatial_grid.js`:

```js
// Hit testing for the passive tree.
//
// The tree is 4912 nodes spread over roughly 45,000 by 39,000 world units.
// Walking all of them on every click is wasteful and gets worse on hover, so
// nodes are bucketed into square cells once and only the cells near the
// cursor are searched. A uniform grid rather than a quadtree: the point set is
// static, loaded once, and this is a dozen lines instead of a hundred.

export function buildGrid(nodes, cellSize = 120) {
    const cells = new Map();

    for (const node of nodes) {
        const key = cellKey(node.x, node.y, cellSize);
        const bucket = cells.get(key);

        if (bucket) {
            bucket.push(node);
        } else {
            cells.set(key, [node]);
        }
    }

    return { cellSize, cells };
}

export function nearestNode(grid, x, y, radius) {
    const { cellSize, cells } = grid;
    const cx = Math.floor(x / cellSize);
    const cy = Math.floor(y / cellSize);
    // A radius wider than a cell has to look further than the eight
    // neighbours, or nodes just outside the ring are missed.
    const reach = Math.max(1, Math.ceil(radius / cellSize));

    let best = null;
    let bestDistance = radius * radius;

    for (let ix = cx - reach; ix <= cx + reach; ix++) {
        for (let iy = cy - reach; iy <= cy + reach; iy++) {
            for (const node of cells.get(`${ix}:${iy}`) ?? []) {
                const dx = node.x - x;
                const dy = node.y - y;
                const distance = dx * dx + dy * dy;

                if (distance <= bestDistance) {
                    bestDistance = distance;
                    best = node;
                }
            }
        }
    }

    return best;
}

function cellKey(x, y, cellSize) {
    return `${Math.floor(x / cellSize)}:${Math.floor(y / cellSize)}`;
}
```

- [ ] **Step 9: Run everything and commit**

Run: `npm run test:js` — expected PASS, eleven tests. Then `composer gate`.

Add a line to `docs/setup.md`: `npm install` once, `npm run test:js` for the JavaScript unit tests.

```bash
git add package.json package-lock.json vitest.config.js assets/lib tests/js .gitignore docs/setup.md
git commit -m "$(cat <<'EOF'
feat(editor): add the canvas geometry the tree renderer needs

Camera and spatial grid as pure modules with their own tests. Both fail
silently when wrong — a zoom that drifts or a cell boundary that swallows
a node reads as "clicking feels off", never as an error.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 17: The tree Stimulus controller

The canvas. Nothing it draws is in the DOM, which is why the node list from Task 12 exists and why the state tag from Task 11 is the only way state reaches it.

**Files:**
- Create: `assets/controllers/tree_controller.js`, `assets/lib/tree_renderer.js`
- Modify: `templates/build/_tree.html.twig` (replacing the Task 11 stub)

**Interfaces:**
- Consumes: `/catalog/tree.json` (Task 2, node tuples `[id, name, kind, ascendancy_key, x, y]`), `camera.js` and `spatial_grid.js` (Task 16), the `build-state` script tag and actions `passive.allocate`/`passive.deallocate` (Tasks 11, 8).
- Produces: `drawTree(context, {nodes, edges, allocated, startNodeId, hovered}, camera)` in `tree_renderer.js`.

- [ ] **Step 1: Write the renderer**

Create `assets/lib/tree_renderer.js`:

```js
import { worldToScreen } from './camera.js';

const COLOURS = {
    edge: '#3a3a46',
    edgeAllocated: '#c7a54a',
    small: '#6a6a7a',
    notable: '#9a8ad6',
    keystone: '#d67a7a',
    allocated: '#e8c56a',
    start: '#ffffff',
};

const RADIUS = { small: 4, notable: 7, keystone: 9 };

// Edges are straight lines. In the game they follow the orbit they were laid
// out on; matching that is a visual nicety and is deliberately not done here.
export function drawTree(context, { nodes, edges, allocated, startNodeId, hovered }, camera) {
    const { width, height } = context.canvas;

    context.clearRect(0, 0, width, height);
    context.lineWidth = 1.5;

    for (const [from, to] of edges) {
        const a = nodes.get(from);
        const b = nodes.get(to);

        if (!a || !b) {
            continue;
        }

        const start = worldToScreen(camera, width, height, a.x, a.y);
        const end = worldToScreen(camera, width, height, b.x, b.y);

        context.strokeStyle = allocated.has(from) && allocated.has(to) ? COLOURS.edgeAllocated : COLOURS.edge;
        context.beginPath();
        context.moveTo(start.x, start.y);
        context.lineTo(end.x, end.y);
        context.stroke();
    }

    for (const node of nodes.values()) {
        const point = worldToScreen(camera, width, height, node.x, node.y);

        if (point.x < -20 || point.y < -20 || point.x > width + 20 || point.y > height + 20) {
            continue;
        }

        const radius = (RADIUS[node.kind] ?? RADIUS.small) * Math.max(0.6, Math.min(1.6, camera.scale * 6));

        context.fillStyle = node.id === startNodeId
            ? COLOURS.start
            : allocated.has(node.id) ? COLOURS.allocated : (COLOURS[node.kind] ?? COLOURS.small);

        context.beginPath();
        context.arc(point.x, point.y, radius, 0, Math.PI * 2);
        context.fill();

        if (node.id === hovered) {
            context.strokeStyle = COLOURS.start;
            context.stroke();
        }
    }
}
```

- [ ] **Step 2: Write the controller**

Create `assets/controllers/tree_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { createCamera, panBy, screenToWorld, zoomAt } from '../lib/camera.js';
import { buildGrid, nearestNode } from '../lib/spatial_grid.js';
import { drawTree } from '../lib/tree_renderer.js';

const DRAG_SLOP = 4;
const CLICK_RADIUS = 30;

export default class extends Controller {
    static targets = ['canvas', 'state'];
    static values = { treeUrl: String, actUrl: String };

    async connect() {
        this.camera = createCamera();
        this.allocated = new Set();
        this.nodes = new Map();
        this.edges = [];
        this.hovered = null;
        this.startNodeId = null;

        this.readState();
        await this.loadTree();
        this.resize();
        this.redraw();

        this.onResize = () => { this.resize(); this.redraw(); };
        window.addEventListener('resize', this.onResize);
    }

    disconnect() {
        window.removeEventListener('resize', this.onResize);
    }

    // Turbo replaces the state tag after every edit, including one made from
    // the node list or a revert. Re-reading here is what keeps the canvas
    // honest without a second channel back from the server.
    stateTargetConnected() {
        this.readState();

        if (this.nodes?.size) {
            this.redraw();
        }
    }

    readState() {
        const state = JSON.parse(this.stateTarget.textContent || '{}');

        this.allocated = new Set(state.allocated ?? []);
        this.ascendancy = state.ascendancy ?? null;
        this.classKey = state.classKey ?? null;
    }

    async loadTree() {
        const response = await fetch(this.treeUrlValue, { headers: { Accept: 'application/json' } });
        const tree = await response.json();

        for (const [id, name, kind, ascendancy, x, y] of tree.nodes) {
            // Another ascendancy's nodes are not reachable by this build and
            // would only be clutter around the part that is.
            if (ascendancy && ascendancy !== this.ascendancy) {
                continue;
            }

            this.nodes.set(id, { id, name, kind, x, y });
        }

        this.edges = tree.edges.filter(([from, to]) => this.nodes.has(from) && this.nodes.has(to));

        const startClass = (tree.classes ?? []).find((c) => c.id === this.classKey);
        this.startNodeId = startClass?.start_node_id ?? null;

        const start = this.startNodeId ? this.nodes.get(this.startNodeId) : null;
        this.camera = createCamera({ scale: 0.12, x: start?.x ?? 0, y: start?.y ?? 0 });
    }

    resize() {
        const canvas = this.canvasTarget;
        const ratio = window.devicePixelRatio || 1;

        canvas.width = canvas.clientWidth * ratio;
        canvas.height = canvas.clientHeight * ratio;
    }

    redraw() {
        const context = this.canvasTarget.getContext('2d');

        drawTree(context, {
            nodes: this.nodes,
            edges: this.edges,
            allocated: this.allocated,
            startNodeId: this.startNodeId,
            hovered: this.hovered,
        }, this.camera);
    }

    pointerdown(event) {
        this.dragging = { x: event.offsetX, y: event.offsetY, moved: 0 };
        this.canvasTarget.setPointerCapture(event.pointerId);
    }

    pointermove(event) {
        if (this.dragging) {
            const dx = event.offsetX - this.dragging.x;
            const dy = event.offsetY - this.dragging.y;

            this.dragging.moved += Math.abs(dx) + Math.abs(dy);
            this.dragging.x = event.offsetX;
            this.dragging.y = event.offsetY;
            this.camera = panBy(this.camera, dx, dy);
            this.redraw();

            return;
        }

        const node = this.nodeAt(event.offsetX, event.offsetY);
        const id = node?.id ?? null;

        if (id !== this.hovered) {
            this.hovered = id;
            this.canvasTarget.title = node ? `${node.name} (${node.id})` : '';
            this.redraw();
        }
    }

    pointerup(event) {
        const dragged = (this.dragging?.moved ?? 0) > DRAG_SLOP;
        this.dragging = null;

        if (dragged) {
            return;
        }

        const node = this.nodeAt(event.offsetX, event.offsetY);

        if (node && node.id !== this.startNodeId) {
            this.toggle(node.id);
        }
    }

    wheel(event) {
        event.preventDefault();
        this.camera = zoomAt(this.camera, this.canvasTarget.width, this.canvasTarget.height, event.offsetX * this.ratio(), event.offsetY * this.ratio(), event.deltaY < 0 ? 1.15 : 1 / 1.15);
        this.redraw();
    }

    nodeAt(offsetX, offsetY) {
        const ratio = this.ratio();
        const world = screenToWorld(this.camera, this.canvasTarget.width, this.canvasTarget.height, offsetX * ratio, offsetY * ratio);

        this.grid ??= buildGrid([...this.nodes.values()]);

        return nearestNode(this.grid, world.x, world.y, CLICK_RADIUS / this.camera.scale);
    }

    ratio() {
        return this.canvasTarget.width / this.canvasTarget.clientWidth;
    }

    async toggle(id) {
        const allocating = !this.allocated.has(id);

        // Draw the change at once and put it back if the server refuses:
        // waiting a round trip before the node lights up makes the tree feel
        // broken on a slow connection.
        if (allocating) {
            this.allocated.add(id);
        } else {
            this.allocated.delete(id);
        }

        this.redraw();

        const body = new URLSearchParams({ action: allocating ? 'passive.allocate' : 'passive.deallocate', id });
        const response = await fetch(this.actUrlValue, {
            method: 'POST',
            headers: { Accept: 'text/vnd.turbo-stream.html' },
            body,
        });

        if (!response.ok && response.status !== 422) {
            if (allocating) {
                this.allocated.delete(id);
            } else {
                this.allocated.add(id);
            }

            this.redraw();

            return;
        }

        Turbo.renderStreamMessage(await response.text());
    }
}
```

- [ ] **Step 3: Write the template**

Replace `templates/build/_tree.html.twig`:

```twig
<section id="build-tree"
         data-controller="tree"
         data-tree-tree-url-value="{{ path('app_catalog_tree') }}"
         data-tree-act-url-value="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}">
    <h2>Passive tree</h2>

    {% if build.classKey is null %}
        <p>Choose a class above and the tree will centre on where that class starts.</p>
    {% endif %}

    <canvas class="tree-canvas"
            data-tree-target="canvas"
            data-action="pointerdown->tree#pointerdown pointermove->tree#pointermove pointerup->tree#pointerup wheel->tree#wheel"
            aria-label="Passive tree. Drag to pan, scroll to zoom, click a node to allocate it. The searchable list beside this canvas does the same thing without a mouse."></canvas>

    {{ include('build/_state.html.twig') }}
</section>
```

- [ ] **Step 4: Verify it in a browser — this task cannot be called done without it**

Start the server and open a build's edit link. Check, in order:
1. The tree draws, and choosing a class re-centres it on that class's start node.
2. Dragging pans; the wheel zooms and the node under the cursor stays under the cursor.
3. Hovering a node shows its name and id in the tooltip.
4. Clicking an unallocated node lights it up **immediately** and adds it to the allocated list beside the canvas.
5. Clicking it again removes it from both.
6. Removing a node from the list redraws the canvas.
7. Reverting from the history panel redraws the canvas.
8. The class start node does not toggle when clicked.
9. The browser console is clean throughout.

- [ ] **Step 5: Run the gate and commit**

```bash
composer gate && npm run test:js
git add assets templates/build/_tree.html.twig
git commit -m "$(cat <<'EOF'
feat(editor): render and click the passive tree on a canvas

4912 nodes and 6076 edges: as DOM this would be eleven thousand elements
with layout on every pan. The state script tag is the only way state
reaches the canvas, so a revert or a node-list edit redraws it too.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

### Task 18: The Playwright smoke test and the gate

One test, for the one path nothing else can reach: PHPUnit cannot click a canvas, and vitest cannot prove the controller wired the geometry to the endpoint.

**Files:**
- Create: `playwright.config.js`, `tests/e2e/editor.spec.js`, `src/Command/CreateTestBuildCommand.php`
- Modify: `package.json`, `composer.json`, `docs/setup.md`, `README.md`, `.gitignore`

**Interfaces:**
- Consumes: everything above.
- Produces: `app:test:build` (test environment only), printing the edit URL of a freshly created build; `composer gate` covering the JS unit tests and the E2E run.

- [ ] **Step 1: Install Playwright**

```bash
npm install -D @playwright/test
npx playwright install --with-deps chromium
```

Add `/test-results/` and `/playwright-report/` to `.gitignore`.

- [ ] **Step 2: Write the precondition command**

Create `src/Command/CreateTestBuildCommand.php`. It exists only under `APP_ENV=test`, because a command that mints an edit token has no business in production:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Interchange\BuildDocumentReader;
use App\Repository\BuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Creates a build and prints its edit path, so an end-to-end test can start
 * from a known state without clicking through an upload.
 *
 * Test environment only: it hands out an edit token.
 */
#[When(env: 'test')]
#[AsCommand(name: 'app:test:build', description: 'Create a build and print its edit path (test environment only)')]
final class CreateTestBuildCommand extends Command
{
    public function __construct(
        private readonly BuildRepository $builds,
        private readonly BuildDocumentReader $reader,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = file_get_contents(\dirname(__DIR__, 2).'/tests/fixtures/build/valid-full.build');

        if (false === $json) {
            $output->writeln('The fixture build could not be read.');

            return Command::FAILURE;
        }

        $build = $this->builds->create($this->reader->read($json), '0.5.5');
        $this->entityManager->flush();

        $output->writeln(\sprintf('/b/%s/edit/%s', $build->getShareSlug(), (string) $build->getEditToken()));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 3: Configure Playwright**

Create `playwright.config.js`:

```js
export default {
    testDir: 'tests/e2e',
    timeout: 30000,
    use: {
        baseURL: 'http://127.0.0.1:8001',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'APP_ENV=test php -S 127.0.0.1:8001 -t public',
        url: 'http://127.0.0.1:8001/',
        reuseExistingServer: !process.env.CI,
    },
};
```

- [ ] **Step 4: Write the failing test**

Create `tests/e2e/editor.spec.js`. The canvas is clicked at its centre, which is where the controller centres the class start node — so a node is reliably within the click radius:

```js
import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

function createBuild() {
    return execSync('APP_ENV=test php bin/console app:test:build').toString().trim();
}

test('a node clicked on the canvas appears in the allocated list', async ({ page }) => {
    await page.goto(createBuild());

    const canvas = page.locator('canvas.tree-canvas');
    await expect(canvas).toBeVisible();

    const allocated = page.locator('#build-nodes h3');
    const before = await allocated.textContent();

    // Slightly off centre: the exact centre is the class start node, which
    // does not toggle.
    const box = await canvas.boundingBox();
    await page.mouse.click(box.x + box.width / 2 + 40, box.y + box.height / 2);

    await expect(allocated).not.toHaveText(before ?? '');
});
```

- [ ] **Step 5: Run it and watch it pass or fail honestly**

```bash
composer db:migrate
APP_ENV=test php bin/console app:catalog:sync
npm run test:e2e
```

The catalog sync is a precondition: without tree data there are no nodes to click. If the run is red, fix the cause — do not weaken the assertion.

- [ ] **Step 6: Extend the gate**

In `composer.json`:

```json
        "test:js": "npm run test:js",
        "test:e2e": "npm run test:e2e",
        "gate": [
            "@cs",
            "@stan",
            "@test",
            "@test:js",
            "@test:e2e"
        ],
```

- [ ] **Step 7: Document it**

In `docs/setup.md`, record what a full gate now needs: `npm install`, `npx playwright install chromium`, a migrated test database, and a synced catalog. In `README.md`, mention that the editor's canvas path is covered by one Playwright test and why it is only one.

- [ ] **Step 8: Run the whole gate and commit**

Run: `composer gate`
Expected: green, all five steps.

```bash
git add playwright.config.js tests/e2e src/Command/CreateTestBuildCommand.php package.json package-lock.json composer.json docs/setup.md README.md .gitignore
git commit -m "$(cat <<'EOF'
test(editor): cover the canvas click path end to end

One Playwright test, for the one path nothing else reaches: PHPUnit
cannot click a canvas and vitest cannot prove the controller wired the
geometry to the endpoint. The gate now runs it.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01LWL3UZyB4VHCmk74gEPefm
EOF
)"
```

---

## Notes for the executor

**Spec coverage.** Header fields → Tasks 3, 11. Tree rendering → Tasks 2, 16, 17. Node list → Task 12. Skills and supports → Tasks 6, 13. Equipment slots → Tasks 7, 14. History, revert, snapshots, prune → Tasks 4, 9, 15. Findings placeholder → Task 11. Command flow over Messenger → Task 8. Class start nodes → Task 1. Testing → distributed, with the gate extended in Task 18.

**Deliberately not in this plan**, all named in the spec as later work: Advice findings content and the rules engine (iteration 4), suggestions and the apply button (iteration 5), curved-arc tree edges, slot-fit validation (`unique.slot_mismatch`, iteration 4), and visual distinction for mastery and jewel-socket nodes — these render as ordinary small nodes for now.

**Two places where the design was corrected rather than followed:**

1. The spec's "User journey" section says skills carry free additional text. The measured corpus in the same document says `additional_text` is never present on skills. Task 6 follows the measurement. This is recorded in the spec's iteration 3 section too.
2. Proof #6 (orbit radii) was retired during the spike rather than stamped: every node carries absolute coordinates, so nothing derives from orbit geometry. Task 2 and Task 16 rest on the coordinates directly.

**Three ordering constraints that are not obvious:**

- Task 7 changes `DocumentEditor`'s constructor. The tests written in Tasks 5 and 6 construct it with no arguments and must be updated in Task 7 — this is called out in that task's steps.
- Task 11 creates stub partials for `_nodes`, `_skills`, `_slots`, `_history` and `_tree` so it is green on its own. Tasks 12–15 and 17 replace them. A stub left in place at the end of Task 17 means a slice was skipped.
- Task 18's Playwright run needs a migrated test database **and** a synced catalog. Without tree data there are no nodes to click, and the test fails for a reason that has nothing to do with the code.

**Verification that cannot be delegated to the suite.** Tasks 10, 11, 12, 13, 15 and 17 each end with a browser check. Task 17's is the load-bearing one: PHPUnit cannot click a canvas, and a renderer that draws nothing still passes every type check and unit test in this plan.
