# Slice B2 — Equipment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every view-state query key one registry, identify equipment slots by position so every flask and charm is editable, and add the declared jewel keystone that switches on the tree's rule 4.

**Architecture:** A `ViewState` class holds the eight view-state keys and the query-over-body rule for reading them; every consumer — the redirect whitelist, the hidden-field partial, the header macro, the link maps, the GET search forms — reads from it. `InventorySlots` gains positions from the curated YAML; `DocumentEditor` and the slot commands address `(inventory_id, slot_x)` through one `position` form field (`Trinket1@3`). A new app-only `build.jewel_keystone` column is set by a `jewel.set` action, handed to the legality rules through `TreeContextFactory`, and captured — together with the previously missed `instilled_passives` — by the history snapshot.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM/DBAL + migrations, MariaDB 11.4, Twig, symfony/messenger, PHPUnit 13, vitest, Playwright.

**Spec:** `docs/specs/2026-09-09-poe2-build-helper-design.md` — subsection "Slice B2 decisions (2026-09-13)" (commit `9fdb28a`), plus "The declared jewel keystone is app-only, and the format says so" and "The belt is a grid, and one entry per `inventory_id` loses data". Read those three before Task 1.

## Global Constraints

- **Everything runs inside DDEV.** No PHP, Composer, MariaDB or usable Node on the host — the host `npm` is a broken Windows shim. Every command is prefixed `ddev`. Never a bare `php`, `composer` or `npm`.
- **The gate is `ddev composer gate`** (php-cs-fixer, PHPStan, PHPUnit, vitest, Playwright) and it must be green at every commit. Run the FULL gate at the end of every task — a narrower command list let a Playwright regression rot for nine tasks in slice B1.
- **PHPStan runs at level max.** Forbidden: `treatPhpDocTypesAsCertain: false`, a baseline, `@phpstan-ignore`, loosening a production type, deleting a test. `self::assertInstanceOf(Connection::class, $db)` after `getContainer()->get(Connection::class)` is rejected as already-narrowed; no test in this repo writes it.
- **The repository is public.** `var/catalog/` and `var/sample/` are gitignored real GGG data — never commit from them or copy them into a fixture. Every fixture id is invented (`test_…`).
- **Never change production code or markup to make a test assertion match;** fix the assertion.
- **Every test you add or change must be one you have seen fail.** For a pure refactor whose tests pass before the change, prove each can fail by a deliberate mutation, then restore — and say so in the report.
- **App-only fields never reach the exported `.build` file:** `class_key`, `target_level`, `note`, `archetype_key`, `instilled_passives`, and now `jewel_keystone`.
- **Slots are addressed by one `position` form field** of the form `<inventory_id>@<slot_x>` (owner's choice, 2026-09-13). The history payload still records `inventory_id` and `slot_x` separately.
- **Clearing or changing the jewel keystone keeps the passives it made legal** (owner, 2026-09-13), like a class or ascendancy change.
- **Prefer PSR-compliant and already-installed Symfony packages** over a hand-rolled equivalent (`CLAUDE.md`). Comments and identifiers in English.

---

## File Structure

**Created:**
- `src/Controller/ViewState.php` — the eight view-state keys and how a term is read from a request.
- `src/Build/Edit/Command/SetJewelKeystone.php`, `src/Build/Edit/Handler/JewelEditHandler.php` — the `jewel.set` action.
- `migrations/Version20260913100000.php` — `build.jewel_keystone`.
- `tests/Controller/ViewStateTest.php`, `tests/Build/BuildSnapshotTest.php`.

**Modified:**
- `src/Controller/EditorSearches.php`, `src/Controller/EditorContext.php`, `src/Controller/BuildEditorController.php` — read the registry.
- `templates/build/_search_state.html.twig`, `_header.html.twig`, `_nodes.html.twig`, `_skills.html.twig`, `_slots.html.twig`, `_history.html.twig`.
- `config/inventory_slots.yaml`, `src/Build/InventorySlots.php` — positions.
- `src/Build/Edit/DocumentEditor.php`, `src/Build/Edit/Command/SetSlot.php`, `src/Build/Edit/Command/ClearSlot.php`, `src/Build/Edit/CommandFactory.php`, `src/Build/Edit/Handler/SlotEditHandler.php` — slots by position.
- `src/Entity/Build.php`, `src/Entity/BuildEvent.php`, `src/Build/Edit/BuildSnapshot.php`, `src/Build/Tree/TreeContextFactory.php`, `src/Catalog/CatalogSearch.php` — the jewel keystone.
- Tests: `tests/Controller/BuildEditorControllerTest.php`, `tests/Build/InventorySlotsTest.php`, `tests/Build/DocumentEditorSlotsTest.php`, `tests/Build/CommandFactoryTest.php`.

---

### Task 1: One registry for view state, in PHP

**Files:**
- Create: `src/Controller/ViewState.php`, `tests/Controller/ViewStateTest.php`
- Modify: `src/Controller/EditorSearches.php`, `src/Controller/EditorContext.php`, `src/Controller/BuildEditorController.php`
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Produces: `ViewState::KEYS` (`list<string>`: `q, gem, support, unique, instilled, intervals, supports, stats`); `ViewState::fromRequest(?Request $request): array<string, string>` — every key present, raw and trimmed, `''` when absent. `EditorSearches::all()` gains a `viewState` entry holding that array; templates receive it as `viewState`.

This task moves the list and the reading rule into one class and makes the three PHP consumers read it. Templates are untouched and keep working — they still receive `passiveQuery`, `gemQuery`, … from `EditorSearches::all()`. It is a refactor: its guard tests pass before the change, so each is proven by mutation.

- [ ] **Step 1: Write the unit test**

`tests/Controller/ViewStateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ViewState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ViewStateTest extends TestCase
{
    public function testEveryKeyIsPresentAndEmptyWithoutARequest(): void
    {
        // Hand-written, not array_fill_keys(ViewState::KEYS, ''): dropping a key
        // from the registry must fail here, not shrink both sides together.
        self::assertSame(
            ['q' => '', 'gem' => '', 'support' => '', 'unique' => '', 'instilled' => '', 'intervals' => '', 'supports' => '', 'stats' => ''],
            ViewState::fromRequest(null),
        );
    }

    public function testTheQueryStringWinsEvenWhenEmptyAndTheBodyFillsTheRest(): void
    {
        $state = ViewState::fromRequest(new Request(query: ['stats' => ''], request: ['stats' => '1', 'gem' => '  Fireball ']));

        self::assertSame('', $state['stats'], 'clearing a term in the URL must beat a stale hidden field');
        self::assertSame('Fireball', $state['gem']);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit tests/Controller/ViewStateTest.php`
Expected: FAIL — `Class "App\Controller\ViewState" not found`.

- [ ] **Step 3: Write `ViewState`**

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * Every query parameter that must survive an edit, in one list.
 *
 * A view-state key has to reach every place that carries the editor's state
 * across a request: the hidden fields every edit form posts, the redirect a
 * plain form POST follows, the header's field macro, the links that change one
 * view, the GET search forms, and the canvas's fetch URL. Each of those once
 * kept its own list, and a key missed in one was silently dropped — five times
 * before this registry existed. Every consumer reads this list now.
 */
final class ViewState
{
    public const array KEYS = ['q', 'gem', 'support', 'unique', 'instilled', 'intervals', 'supports', 'stats'];

    /**
     * Every key with its raw, trimmed term — '' when absent, never a resolved
     * default, so a view nobody chose never lands in a link.
     *
     * @return array<string, string>
     */
    public static function fromRequest(?Request $request): array
    {
        $state = [];

        foreach (self::KEYS as $key) {
            $state[$key] = self::term($request, $key);
        }

        return $state;
    }

    /**
     * A term normally arrives in the query string — the search forms submit
     * via GET. An edit is a POST carrying neither, so the redirect and the
     * Turbo response that follow it fall back to the request body, which the
     * `/act` forms carry the term in as a hidden field. The query string wins
     * when present, even empty, so clearing the search box still clears the
     * term instead of resurrecting it from a stale hidden field.
     */
    private static function term(?Request $request, string $key): string
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

- [ ] **Step 4: Make `EditorSearches` read it**

In `src/Controller/EditorSearches.php`, delete the private `term()` method (its docblock moved into `ViewState`) and replace the nine `$this->term($request, …)` calls with one read:

```php
    public function all(): array
    {
        $state = ViewState::fromRequest($this->requests->getCurrentRequest());

        $passive = $state['q'];
        $gem = $state['gem'];
        $support = $state['support'];
        $unique = $state['unique'];
        $instilled = $state['instilled'];

        return [
            'viewState' => $state,
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
            'intervalsOverride' => $state['intervals'],
            'supportsOverride' => $state['supports'],
            'statsOverride' => $state['stats'],
        ];
    }
```

Add `viewState: array<string, string>,` as the first line of the `@return array{…}` shape, and remove the now-unused `use Symfony\Component\HttpFoundation\Request;` if nothing else in the file uses it.

- [ ] **Step 5: Make `EditorContext` and `searchParams()` read it**

In `src/Controller/EditorContext.php`, delete the hand-built `'viewState' => [ … ],` entry in the array `of()` returns, together with the comment block directly above it. `viewState` now arrives from `$searches` through the existing `array_merge([...], $searches)`. Keep the comment's substance on `ViewState::fromRequest()` if anything in it is not already said there.

In `src/Controller/BuildEditorController.php`, `searchParams()`:

```php
        foreach (ViewState::KEYS as $key) {
```

replacing the literal eight-element array. `ViewState` is in the same namespace, so no import.

- [ ] **Step 6: Write the controller guard test**

Add to `tests/Controller/BuildEditorControllerTest.php`:

```php
    public function testAPlainEditPostKeepsEveryViewStateKeyInTheRedirect(): void
    {
        $edit = $this->createBuild();
        $state = ['q' => 'a', 'gem' => 'b', 'support' => 'c', 'unique' => 'd', 'instilled' => 'e', 'intervals' => 'flat', 'supports' => 'flat', 'stats' => '1'];

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'kept'] + $state);

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');

        foreach ($state as $key => $value) {
            self::assertStringContainsString($key.'='.$value, $location, $key.' must survive the redirect a plain form POST follows');
        }
    }
```

- [ ] **Step 7: Run, then prove the guards by mutation**

Run: `ddev php bin/phpunit tests/Controller/ViewStateTest.php` then `ddev php bin/phpunit --filter 'EditorSearches|testAPlainEditPostKeepsEveryViewStateKeyInTheRedirect'`
Expected: PASS — including the four existing `EditorSearchesTest` tests, which now guard the moved query-over-body rule.

Mutation: remove `'stats'` from `ViewState::KEYS`, run both commands, watch `testEveryKeyIsPresentAndEmptyWithoutARequest` and the redirect test fail; restore. Report both failure texts.

- [ ] **Step 8: Full gate and commit**

Run: `ddev composer gate` — green.

```bash
git add src/Controller/ViewState.php src/Controller/EditorSearches.php src/Controller/EditorContext.php src/Controller/BuildEditorController.php tests/Controller/ViewStateTest.php tests/Controller/BuildEditorControllerTest.php
git commit -m "refactor(editor): read every view-state key from one registry"
```

---

### Task 2: The templates read the registry

**Files:**
- Modify: `templates/build/_search_state.html.twig`, `_header.html.twig`, `_nodes.html.twig`, `_skills.html.twig`, `_slots.html.twig`
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: `viewState` (`array<string, string>`) from Task 1.
- Produces: `_search_state.html.twig` accepts an optional `except` (a key to omit). The `field()` macro in `_header.html.twig` takes `viewState` in place of eight separate terms.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Controller/BuildEditorControllerTest.php`:

```php
    /**
     * The gate test the registry exists for: every key must reach the header's
     * field macro, which inherits no Twig context and so is the form most
     * likely to drop one.
     */
    public function testTheHeaderFieldFormCarriesEveryViewStateKey(): void
    {
        $state = ['q' => 'a', 'gem' => 'b', 'support' => 'c', 'unique' => 'd', 'instilled' => 'e', 'intervals' => 'flat', 'supports' => 'flat', 'stats' => '1'];
        $crawler = $this->client->request('GET', $this->createBuild().'?'.http_build_query($state));

        $form = $crawler->filter('#header-note')->closest('form');
        self::assertNotNull($form);

        foreach ($state as $key => $value) {
            $hidden = $form->filter(\sprintf('input[type="hidden"][name="%s"]', $key));
            self::assertCount(1, $hidden, $key.' is missing from the header field form');
            self::assertSame($value, $hidden->attr('value'));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function searchForms(): iterable
    {
        yield 'passive' => ['#passive-q', 'q'];
        yield 'gem' => ['#gem-q', 'gem'];
        yield 'support' => ['#support-q', 'support'];
        yield 'unique' => ['#unique-q', 'unique'];
        yield 'instilled' => ['#instilled-q', 'instilled'];
    }

    #[DataProvider('searchForms')]
    public function testAGetSearchFormCarriesTheRestOfTheViewStateButNotItsOwnKeyTwice(string $box, string $ownKey): void
    {
        $crawler = $this->client->request('GET', $this->createBuild().'?stats=1&intervals=flat&'.$ownKey.'=old');

        $form = $crawler->filter($box)->closest('form');
        self::assertNotNull($form);
        self::assertCount(1, $form->filter('input[type="hidden"][name="stats"][value="1"]'), 'a search must not reset the stats view');
        self::assertCount(1, $form->filter('input[type="hidden"][name="intervals"][value="flat"]'));
        // Two inputs sharing the form's own name would let the stale hidden
        // one override what the player just typed: PHP keeps the last value
        // of a repeated name.
        self::assertCount(1, $form->filter(\sprintf('input[name="%s"]', $ownKey)));
    }
```

Add `use PHPUnit\Framework\Attributes\DataProvider;` to the test file's imports if it is not already there.

- [ ] **Step 2: Run them and watch them fail**

Run: `ddev php bin/phpunit --filter 'testTheHeaderFieldFormCarriesEveryViewStateKey|testAGetSearchFormCarriesTheRestOfTheViewStateButNotItsOwnKeyTwice'`
Expected: the five data-provider cases FAIL — the GET forms carry no hidden `stats` today. The header test passes already (the macro carries eight hand-passed terms); it is the guard for Step 4 and is proven by mutation in Step 5.

- [ ] **Step 3: Rewrite the hidden-field partial as a loop**

`templates/build/_search_state.html.twig` — the whole file:

```twig
{# Every view-state key from the one registry (App\Controller\ViewState).
   A GET search form passes `except` with its own key, which it already
   carries in its search box. #}
{% for key, value in viewState %}
    {% if key != except|default('') %}
        <input type="hidden" name="{{ key }}" value="{{ value }}">
    {% endif %}
{% endfor %}
```

- [ ] **Step 4: Hand the macro the map, and give the GET forms the rest of the state**

`templates/build/_header.html.twig`, the macro:

```twig
    {% macro field(build, token, name, label, value, viewState, type) %}
        <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
            <input type="hidden" name="action" value="header.set">
            <input type="hidden" name="field" value="{{ name }}">
            {{ include('build/_search_state.html.twig', {viewState: viewState}) }}
            <label for="header-{{ name }}">{{ label }}</label>
            <input type="{{ type|default('text') }}" id="header-{{ name }}" name="value" value="{{ value }}" onchange="this.form.requestSubmit()">
            <button type="submit">Save</button>
        </form>
    {% endmacro %}
```

and each of its six call sites passes `viewState` in place of the eight terms, for example:

```twig
    {{ header.field(build, token, 'name', 'Name', build.name, viewState) }}
    {{ header.field(build, token, 'target_level', 'Target level', build.targetLevel, viewState, 'number') }}
```

(the others: `author`, `note`, `archetype_key`, `game_version`).

In each of the five GET search forms, add one line directly after its search `<input>`:
- `templates/build/_nodes.html.twig` (form containing `#passive-q`): `{{ include('build/_search_state.html.twig', {except: 'q'}) }}`
- `templates/build/_skills.html.twig` (`#gem-q`): `{except: 'gem'}`; (`#support-q`): `{except: 'support'}`
- `templates/build/_slots.html.twig` (`#unique-q`): `{except: 'unique'}`; (`#instilled-q`): `{except: 'instilled'}`

- [ ] **Step 5: Run, prove the header guard, lint**

Run: `ddev php bin/phpunit --filter 'testTheHeaderFieldFormCarriesEveryViewStateKey|testAGetSearchFormCarriesTheRestOfTheViewStateButNotItsOwnKeyTwice'` then `ddev php bin/console lint:twig templates/`
Expected: PASS, lint clean.

Mutation: change the macro's include to `{{ include('build/_search_state.html.twig', {viewState: {}}) }}`, watch the header test fail, restore. Report the failure text.

Then `grep -rn "passiveQuery, gemQuery" templates/` — must return nothing: no call site may still pass the eight terms.

- [ ] **Step 6: Full gate and commit**

Run: `ddev composer gate` — green.

```bash
git add templates/build tests/Controller/BuildEditorControllerTest.php
git commit -m "feat(editor): every form carries the whole view state from one registry"
```

---

### Task 3: Slot positions in the curated vocabulary

**Files:**
- Modify: `config/inventory_slots.yaml`, `src/Build/InventorySlots.php`
- Test: `tests/Build/InventorySlotsTest.php`

**Interfaces:**
- Produces: `InventorySlots::positions(): list<array{id: string, x: int, key: string, label: string}>` (`key` is `"<id>@<x>"`); `InventorySlots::hasPosition(string $inventoryId, int $x): bool`. `all()` and `isKnown()` keep their current behaviour — Task 4 switches `DocumentEditor` over, and changing `isKnown()` here would break Trinket edits in the commit between.

- [ ] **Step 1: Write the failing test**

Add to `tests/Build/InventorySlotsTest.php`:

```php
    public function testTheBeltStripListsEachFlaskAndCharmPosition(): void
    {
        self::bootKernel();
        $slots = self::getContainer()->get(InventorySlots::class);

        $belt = array_values(array_filter(
            $slots->positions(),
            static fn (array $position): bool => \in_array($position['id'], ['Trinket1', 'Flask1'], true),
        ));

        // Measured against the corpus, 2026-09-12: charms at slot_x 2-4, a
        // life flask at 0 and a mana flask at 1, on one shared strip.
        self::assertSame(
            [
                ['id' => 'Trinket1', 'x' => 2, 'key' => 'Trinket1@2', 'label' => 'Charm 1'],
                ['id' => 'Trinket1', 'x' => 3, 'key' => 'Trinket1@3', 'label' => 'Charm 2'],
                ['id' => 'Trinket1', 'x' => 4, 'key' => 'Trinket1@4', 'label' => 'Charm 3'],
                ['id' => 'Flask1', 'x' => 0, 'key' => 'Flask1@0', 'label' => 'Life flask'],
                ['id' => 'Flask1', 'x' => 1, 'key' => 'Flask1@1', 'label' => 'Mana flask'],
            ],
            $belt,
        );
        self::assertCount(17, $slots->positions(), '12 single slots, 3 charms, 2 flasks');
        self::assertTrue($slots->hasPosition('Trinket1', 3));
        self::assertFalse($slots->hasPosition('Trinket1', 0), 'the charm strip starts at 2');
        self::assertTrue($slots->hasPosition('Amulet1', 0));
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit tests/Build/InventorySlotsTest.php`
Expected: FAIL — `Call to undefined method App\Build\InventorySlots::positions()`.

- [ ] **Step 3: Add positions to the YAML**

In `config/inventory_slots.yaml`, replace the last two entries and extend the header comment:

```yaml
# Flask1 and Trinket1 share one strip on the belt and each holds several
# entries, so they list their positions: the slot_x values the corpus shows,
# not a count — Trinket1 starts at 2. Every other id is one position at 0.
```

```yaml
    - { id: Trinket1,   label: 'Charms', positions: [{ x: 2, label: 'Charm 1' }, { x: 3, label: 'Charm 2' }, { x: 4, label: 'Charm 3' }] }
    - { id: Flask1,     label: 'Flasks', positions: [{ x: 0, label: 'Life flask' }, { x: 1, label: 'Mana flask' }] }
```

- [ ] **Step 4: Read them in `InventorySlots`**

Add a cached `$positions` property and two methods:

```php
    /** @var list<array{id: string, x: int, key: string, label: string}>|null */
    private ?array $positions = null;

    /**
     * Every place an item can go: one per id, or one per position for an id
     * that holds several — the belt strip flasks and charms share.
     *
     * @return list<array{id: string, x: int, key: string, label: string}>
     */
    public function positions(): array
    {
        if (null !== $this->positions) {
            return $this->positions;
        }

        $parsed = Yaml::parseFile($this->file);
        $entries = \is_array($parsed) ? ($parsed['inventory_slots'] ?? []) : [];
        $positions = [];

        foreach ((array) $entries as $slot) {
            if (!\is_array($slot) || !\is_string($slot['id'] ?? null) || !\is_string($slot['label'] ?? null)) {
                continue;
            }

            $listed = \is_array($slot['positions'] ?? null) ? $slot['positions'] : [['x' => 0, 'label' => $slot['label']]];

            foreach ($listed as $position) {
                if (\is_array($position) && \is_int($position['x'] ?? null) && \is_string($position['label'] ?? null)) {
                    $positions[] = ['id' => $slot['id'], 'x' => $position['x'], 'key' => $slot['id'].'@'.$position['x'], 'label' => $position['label']];
                }
            }
        }

        return $this->positions = $positions;
    }

    public function hasPosition(string $inventoryId, int $x): bool
    {
        foreach ($this->positions() as $position) {
            if ($position['id'] === $inventoryId && $position['x'] === $x) {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 5: Run it and watch it pass**

Run: `ddev php bin/phpunit tests/Build/InventorySlotsTest.php` then `ddev composer stan`
Expected: PASS (both tests — the existing fourteen-id assertion on `all()` is unchanged), PHPStan clean.

- [ ] **Step 6: Full gate and commit**

Run: `ddev composer gate` — green.

```bash
git add config/inventory_slots.yaml src/Build/InventorySlots.php tests/Build/InventorySlotsTest.php
git commit -m "feat(build): list the belt's flask and charm positions"
```

---

### Task 4: Equipment slots by position, end to end

**Files:**
- Modify: `src/Build/Edit/DocumentEditor.php`, `src/Build/Edit/Command/SetSlot.php`, `src/Build/Edit/Command/ClearSlot.php`, `src/Build/Edit/CommandFactory.php`, `src/Build/Edit/Handler/SlotEditHandler.php`, `src/Controller/EditorContext.php`, `templates/build/_slots.html.twig`
- Test: `tests/Build/DocumentEditorSlotsTest.php`, `tests/Build/CommandFactoryTest.php`, `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: `InventorySlots::positions()`, `InventorySlots::hasPosition()` (Task 3).
- Produces: `DocumentEditor::setInventorySlot(BuildDocument $document, string $inventoryId, int $slotX, ?string $uniqueName, int $from, int $to, string $additionalText): BuildDocument`; `DocumentEditor::clearInventorySlot(BuildDocument $document, string $inventoryId, int $slotX): BuildDocument`; `SetSlot(int $buildId, string $inventoryId, int $slotX, ?string $uniqueName, int $from, int $to, string $additionalText)`; `ClearSlot(int $buildId, string $inventoryId, int $slotX)`. Forms post `position` = `"<id>@<x>"`. Template variable `slotPositions` replaces `slots`.

One task, not two: the commands reading `position` and the template posting it must land together, or the commit between would have the forms posting `inventory_id` to a server that reads `position` — broken in the browser with every test green.

- [ ] **Step 1: Write the failing unit tests**

Update every existing call in `tests/Build/DocumentEditorSlotsTest.php` to the new signature, inserting the position — `0` for every id except the fixture's `Trinket1`, which sits at `2`. For example:

```php
        $document = $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Amulet1', 0, 'Astramentis', 1, 100, '');
```

and in `testEditingAnImportedSlotKeepsItsCoordinates()`:

```php
        $edited = $this->editor->setInventorySlot($document, 'Trinket1', 2, null, 5, 90, 'a new note');
```

Then add:

```php
    public function testTwoFlasksAreTwoEntriesAndEditingOneLeavesTheOther(): void
    {
        $document = $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Flask1', 0, null, 1, 100, 'life');
        $document = $this->editor->setInventorySlot($document, 'Flask1', 1, null, 1, 100, 'mana');
        $document = $this->editor->setInventorySlot($document, 'Flask1', 1, null, 1, 100, 'mana, edited');

        self::assertSame(
            [['Flask1', 0, 'life'], ['Flask1', 1, 'mana, edited']],
            array_map(static fn (array $slot): array => [$slot['inventory_id'], $slot['slot_x'], $slot['additional_text']], $document->inventorySlots),
        );
    }

    public function testClearingOneCharmKeepsTheOthers(): void
    {
        $document = new BuildDocument(name: 'Build', inventorySlots: [
            ['inventory_id' => 'Trinket1', 'slot_x' => 2, 'slot_y' => 0, 'level_interval' => [1, 100], 'additional_text' => 'Stone Charm'],
            ['inventory_id' => 'Trinket1', 'slot_x' => 3, 'slot_y' => 0, 'level_interval' => [1, 100], 'additional_text' => 'Golden Charm'],
            ['inventory_id' => 'Trinket1', 'slot_x' => 4, 'slot_y' => 0, 'level_interval' => [1, 100], 'additional_text' => 'Silver Charm'],
        ]);

        $cleared = $this->editor->clearInventorySlot($document, 'Trinket1', 3);

        self::assertSame(['Stone Charm', 'Silver Charm'], array_column($cleared->inventorySlots, 'additional_text'));
    }

    public function testAPositionTheBeltDoesNotHaveIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->editor->setInventorySlot(new BuildDocument(name: 'Build'), 'Trinket1', 0, null, 1, 100, '');
    }
```

In `tests/Build/CommandFactoryTest.php`, change `testAnEmptySlotValueBecomesNullRatherThanAnEmptyString()`'s payload key `'inventory_id' => 'Ring1'` to `'position' => 'Ring1@0'`, and add (importing `App\Build\Edit\Command\ClearSlot` and `App\Build\Edit\InvalidEditCommand` if missing):

```php
    public function testAPositionNamesTheSlotAndItsPlaceOnTheBelt(): void
    {
        $command = $this->factory->fromRequest(7, 'slot.clear', new InputBag(['position' => 'Trinket1@3']));

        self::assertInstanceOf(ClearSlot::class, $command);
        self::assertSame('Trinket1', $command->inventoryId);
        self::assertSame(3, $command->slotX);
    }

    public function testAMalformedPositionIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->factory->fromRequest(7, 'slot.clear', new InputBag(['position' => 'Trinket1']));
    }
```

- [ ] **Step 2: Change the signatures only, and watch the behaviour fail**

Give `setInventorySlot()` and `clearInventorySlot()` the new `int $slotX` parameter, but leave `indexOfSlot()` matching on `inventory_id` alone for now. Run: `ddev php bin/phpunit tests/Build/DocumentEditorSlotsTest.php`
Expected: `testTwoFlasksAreTwoEntriesAndEditingOneLeavesTheOther` and `testClearingOneCharmKeepsTheOthers` FAIL on their assertions — editing position 1 overwrites position 0, clearing charm 3 clears charm 2. That is the defect the spec measured. Record the failure texts.

- [ ] **Step 3: Address entries by position**

In `src/Build/Edit/DocumentEditor.php`:

```php
    public function setInventorySlot(BuildDocument $document, string $inventoryId, int $slotX, ?string $uniqueName, int $from, int $to, string $additionalText): BuildDocument
    {
        if (!$this->slots->hasPosition($inventoryId, $slotX)) {
            throw InvalidEditCommand::noSuchEntry('equipment position "'.$inventoryId.'@'.$slotX.'"');
        }

        $slots = $document->inventorySlots;
        $index = $this->indexOfSlot($document, $inventoryId, $slotX);

        // An entry the game wrote may carry keys this command does not own —
        // `weapon_set` only appears on some entries. Update only what this
        // command changes and leave the rest exactly as it was.
        $entry = null !== $index ? $slots[$index] : [
            'inventory_id' => $inventoryId,
            'slot_x' => $slotX,
            'slot_y' => 0,
        ];
```

(the rest of the method unchanged), `clearInventorySlot(BuildDocument $document, string $inventoryId, int $slotX)` calling `$this->indexOfSlot($document, $inventoryId, $slotX)`, and:

```php
    private function indexOfSlot(BuildDocument $document, string $inventoryId, int $slotX): ?int
    {
        foreach ($document->inventorySlots as $index => $slot) {
            // slot_y is part of the key, but the corpus never varies it and no
            // form sets it, so only y = 0 is addressable.
            if (($slot['inventory_id'] ?? null) === $inventoryId && ($slot['slot_x'] ?? 0) === $slotX && ($slot['slot_y'] ?? 0) === 0) {
                return $index;
            }
        }

        return null;
    }
```

- [ ] **Step 4: Carry the position through the commands**

`SetSlot`: add `public int $slotX,` directly after `public string $inventoryId,`. `ClearSlot`: `public function __construct(public int $buildId, public string $inventoryId, public int $slotX)`.

`src/Build/Edit/CommandFactory.php` — the two arms become:

```php
            'slot.set' => $this->setSlot($buildId, $payload),
            'slot.clear' => $this->clearSlot($buildId, $payload),
```

with three private methods following the file's `@param InputBag<string|int|float|bool|null>` convention:

```php
    /**
     * @param InputBag<string|int|float|bool|null> $payload
     */
    private function setSlot(int $buildId, InputBag $payload): SetSlot
    {
        [$inventoryId, $slotX] = $this->position($payload);

        return new SetSlot($buildId, $inventoryId, $slotX, $this->optionalString($payload, 'unique_name'), $this->int($payload, 'from'), $this->int($payload, 'to'), $this->string($payload, 'additional_text', required: false));
    }

    /**
     * @param InputBag<string|int|float|bool|null> $payload
     */
    private function clearSlot(int $buildId, InputBag $payload): ClearSlot
    {
        [$inventoryId, $slotX] = $this->position($payload);

        return new ClearSlot($buildId, $inventoryId, $slotX);
    }

    /**
     * A slot is named by one field, `<inventory_id>@<slot_x>` — the only
     * place a position is parsed, so the direct slot forms and the unique
     * search's target dropdown share one input shape.
     *
     * @param InputBag<string|int|float|bool|null> $payload
     *
     * @return array{0: string, 1: int}
     */
    private function position(InputBag $payload): array
    {
        $value = $this->string($payload, 'position');

        if (1 !== preg_match('/^([A-Za-z0-9]+)@(\d+)$/', $value, $match)) {
            throw InvalidEditCommand::noSuchEntry('equipment position "'.$value.'"');
        }

        return [$match[1], (int) $match[2]];
    }
```

`src/Build/Edit/Handler/SlotEditHandler.php`: add `'slot_x' => $command->slotX,` after `'inventory_id'` in the `slot.set` payload, make the `slot.clear` payload `['inventory_id' => $command->inventoryId, 'slot_x' => $command->slotX]`, and pass `$command->slotX` as the new third argument to `setInventorySlot()` and `clearInventorySlot()`.

- [ ] **Step 5: Render one row per position**

`src/Controller/EditorContext.php`: replace `'slots' => $this->slots->all(),` with `'slotPositions' => $this->slots->positions(),`. Then `grep -rn "\bslots\b" templates/build/` must show no remaining use of a `slots` variable.

`templates/build/_slots.html.twig`:
- the `filled` map keys by position: `{% set filled = filled|merge({(entry.inventory_id ~ '@' ~ (entry.slot_x|default(0))): entry}) %}` (rename the loop variable to `entry`).
- the unique-search target: `<select id="unique-result-{{ loop.index0 }}" name="position">` with `{% for position in slotPositions %}<option value="{{ position.key }}">{{ position.label }}</option>{% endfor %}`.
- the slot list: `{% for position in slotPositions %}`, `{% set current = filled[position.key]|default(null) %}`, `{% set domId = position.id ~ '-' ~ position.x %}`; each form carries `data-slot="{{ position.key }}"` and `<input type="hidden" name="position" value="{{ position.key }}">` in place of the `inventory_id` hidden field; every element id uses `domId` (`slot-name-{{ domId }}`, `slot-from-{{ domId }}`, `slot-to-{{ domId }}`, `slot-text-{{ domId }}`); the label shows `{{ position.label }}`; the clear form posts `position` too; the Instilled Modifier block's condition becomes `{% if 'Amulet1' == position.id %}`.

- [ ] **Step 6: Update and add the controller tests**

In `tests/Controller/BuildEditorControllerTest.php`:
- `testAUniqueCanBeNamedForASlotAndClearedAgain()`: post `'position' => 'Ring1@0'` instead of `'inventory_id' => 'Ring1'` (both requests) and assert on `#slot-name-Ring1-0`.
- rename `testAllFourteenSlotsAreOffered()` to `testEveryEquipmentPositionIsOffered()` and assert `assertSelectorCount(17, '#build-slots form[data-slot]')`.
- `testAUniqueCanBeFoundBySearch()`: `select[name="position"]` instead of `select[name="inventory_id"]`.

Add:

```php
    public function testEveryFlaskAndCharmOfAnImportedBuildIsShownAndEditableOnItsOwn(): void
    {
        $entry = static fn (string $id, int $x, string $text): array => ['inventory_id' => $id, 'slot_x' => $x, 'slot_y' => 0, 'level_interval' => [1, 100], 'additional_text' => $text];
        $json = json_encode(['name' => 'Belt', 'inventory_slots' => [
            $entry('Flask1', 0, 'Test Life Flask'),
            $entry('Flask1', 1, 'Test Mana Flask'),
            $entry('Trinket1', 2, 'Test Charm A'),
            $entry('Trinket1', 3, 'Test Charm B'),
            $entry('Trinket1', 4, 'Test Charm C'),
        ]], \JSON_THROW_ON_ERROR);

        $this->client->request('POST', '/builds', ['json' => $json]);
        $edit = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->request('GET', $edit);

        // Before B2 the editor showed one flask of two and one charm of three.
        foreach (['Flask1-0' => 'Test Life Flask', 'Flask1-1' => 'Test Mana Flask', 'Trinket1-2' => 'Test Charm A', 'Trinket1-3' => 'Test Charm B', 'Trinket1-4' => 'Test Charm C'] as $domId => $text) {
            self::assertSelectorExists(\sprintf('#slot-text-%s[value="%s"]', $domId, $text));
        }

        $this->client->request('POST', $edit.'/act', ['action' => 'slot.set', 'position' => 'Flask1@1', 'unique_name' => '', 'from' => '1', 'to' => '100', 'additional_text' => 'changed']);
        $this->client->followRedirect();

        self::assertSelectorExists('#slot-text-Flask1-1[value="changed"]');
        self::assertSelectorExists('#slot-text-Flask1-0[value="Test Life Flask"]', 'editing the mana flask must leave the life flask alone');
    }
```

- [ ] **Step 7: Run, lint, gate**

Run: `ddev php bin/phpunit --filter 'DocumentEditorSlots|CommandFactory|Slot|Unique|EquipmentPosition|FlaskAndCharm'` then `ddev php bin/console lint:twig templates/` then `ddev composer stan`.
Expected: PASS, lint clean, PHPStan clean. The new controller test fails before Step 5 and passes after it — confirm and report.

Run: `ddev composer gate` — green.

- [ ] **Step 8: Commit**

```bash
git add src/Build src/Controller/EditorContext.php templates/build/_slots.html.twig tests/Build tests/Controller/BuildEditorControllerTest.php
git commit -m "feat(editor): edit every flask and charm by its position on the belt"
```

---

### Task 5: The jewel keystone column, and a snapshot that restores every app-only field

**Files:**
- Create: `migrations/Version20260913100000.php`, `tests/Build/BuildSnapshotTest.php`
- Modify: `src/Entity/Build.php`, `src/Build/Edit/BuildSnapshot.php`, `src/Entity/BuildEvent.php`

**Interfaces:**
- Produces: `Build::getJewelKeystone(): ?string`, `Build::setJewelKeystone(?string $jewelKeystone): void`, `Build::setInstilledPassives(list<string> $ids): void`. The `Snapshot` header gains optional `jewel_keystone?: string|null` and `instilled_passives?: list<string>`.

**Snapshots already stored carry neither key.** A revert to one must leave the current value alone rather than clear it — that snapshot never recorded the field, and clearing would invent a state the player never had.

- [ ] **Step 1: Write the failing tests**

`tests/Build/BuildSnapshotTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\BuildSnapshot;
use App\Entity\Build;
use App\Interchange\BuildDocument;
use PHPUnit\Framework\TestCase;

final class BuildSnapshotTest extends TestCase
{
    public function testARevertRestoresTheJewelKeystoneAndTheInstilledPassives(): void
    {
        $build = $this->build();
        $build->setJewelKeystone('test_jewel_keystone');
        $build->addInstilledPassive('test_notable_a');
        $snapshot = BuildSnapshot::capture($build);

        $build->setJewelKeystone(null);
        $build->addInstilledPassive('test_notable_b');
        BuildSnapshot::restore($build, $snapshot);

        self::assertSame('test_jewel_keystone', $build->getJewelKeystone());
        self::assertSame(['test_notable_a'], $build->getInstilledPassives());
    }

    public function testASnapshotFromBeforeTheseFieldsLeavesThemAlone(): void
    {
        $build = $this->build();
        $snapshot = BuildSnapshot::capture($build);
        unset($snapshot['header']['jewel_keystone'], $snapshot['header']['instilled_passives']);

        $build->setJewelKeystone('test_jewel_keystone');
        $build->addInstilledPassive('test_notable_a');
        BuildSnapshot::restore($build, $snapshot);

        self::assertSame('test_jewel_keystone', $build->getJewelKeystone(), 'an old snapshot never recorded the jewel, so it must not clear it');
        self::assertSame(['test_notable_a'], $build->getInstilledPassives());
    }

    private function build(): Build
    {
        return new Build(document: new BuildDocument(name: 'Snapshot'), gameVersion: '0.5.5', shareSlug: str_repeat('a', 22), editTokenHash: 'hash');
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev php bin/phpunit tests/Build/BuildSnapshotTest.php`
Expected: FAIL — `Call to undefined method App\Entity\Build::setJewelKeystone()`.

- [ ] **Step 3: Add the column and the accessors**

`migrations/Version20260913100000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The build\'s declared jewel keystone — app-only, never exported.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE build ADD jewel_keystone VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE build DROP jewel_keystone');
    }
}
```

In `src/Entity/Build.php`, directly after the `$instilledPassives` property:

```php
    /**
     * The keystone a declared keystone-radius jewel works around. App-only:
     * the `.build` format has no jewels at all, so it never enters `document`
     * or an export.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $jewelKeystone = null;
```

and, beside the other accessors, following their `touch()` convention:

```php
    public function getJewelKeystone(): ?string
    {
        return $this->jewelKeystone;
    }

    public function setJewelKeystone(?string $jewelKeystone): void
    {
        $this->jewelKeystone = $jewelKeystone;
        $this->touch();
    }

    /**
     * @param list<string> $ids
     */
    public function setInstilledPassives(array $ids): void
    {
        $this->instilledPassives = array_values(array_unique($ids));
        $this->touch();
    }
```

Run `ddev composer db:migrate` (both databases), then `ddev php bin/console doctrine:schema:update --dump-sql` — **dump only, never `--force`** — and confirm `jewel_keystone` does not appear.

- [ ] **Step 4: Capture and restore both fields**

`src/Build/Edit/BuildSnapshot.php` — the type:

```php
 * @phpstan-type Snapshot array{document: array<string, mixed>, header: array{class_key: string|null, target_level: int|null, note: string|null, archetype_key: string|null, jewel_keystone?: string|null, instilled_passives?: list<string>}}
```

`capture()` adds to `'header'`:

```php
                'jewel_keystone' => $build->getJewelKeystone(),
                'instilled_passives' => $build->getInstilledPassives(),
```

`restore()` ends with:

```php
        // Snapshots written before these two fields existed carry no key for
        // them. Their value at that point is unknown, so a revert to one leaves
        // the current value alone — clearing it would invent a state the
        // player never had.
        if (\array_key_exists('jewel_keystone', $snapshot['header'])) {
            $build->setJewelKeystone($snapshot['header']['jewel_keystone']);
        }

        if (\array_key_exists('instilled_passives', $snapshot['header'])) {
            $build->setInstilledPassives($snapshot['header']['instilled_passives']);
        }
```

`src/Entity/BuildEvent.php` declares the same `Snapshot` shape by hand. Replace its local `@phpstan-type Snapshot …` line with `@phpstan-import-type Snapshot from BuildSnapshot` (and `use App\Build\Edit\BuildSnapshot;`), so the shape lives in one place.

- [ ] **Step 5: Run and gate**

Run: `ddev php bin/phpunit tests/Build/BuildSnapshotTest.php` then `ddev php bin/phpunit --filter Revert` then `ddev composer stan`.
Expected: PASS — the existing `RevertTest` stays green — PHPStan clean.

Run: `ddev composer gate` — green.

- [ ] **Step 6: Commit**

```bash
git add migrations/Version20260913100000.php src/Entity/Build.php src/Entity/BuildEvent.php src/Build/Edit/BuildSnapshot.php tests/Build/BuildSnapshotTest.php
git commit -m "feat(build): store a jewel keystone and restore every app-only field on revert"
```

---

### Task 6: Declaring a jewel keystone switches on rule 4

**Files:**
- Create: `src/Build/Edit/Command/SetJewelKeystone.php`, `src/Build/Edit/Handler/JewelEditHandler.php`
- Modify: `src/Build/Edit/CommandFactory.php`, `src/Catalog/CatalogSearch.php`, `src/Build/Tree/TreeContextFactory.php`, `src/Controller/EditorContext.php`, `templates/build/_slots.html.twig`, `templates/build/_history.html.twig`
- Test: `tests/Controller/BuildEditorControllerTest.php`

**Interfaces:**
- Consumes: `Build::getJewelKeystone()` / `setJewelKeystone()` (Task 5); `PassiveGraph::isKeystone(string $id): bool` and the existing `AllocationRules` rule-4 branch, which already honours `TreeContext::$jewelKeystoneId`.
- Produces: action `jewel.set` with field `keystone_id` (empty clears); `CatalogSearch::keystones(): list<array{id: string, name: string}>`; template variable `keystones`.

- [ ] **Step 1: Write the failing controller tests**

Add to `tests/Controller/BuildEditorControllerTest.php`:

```php
    public function testADeclaredJewelKeystoneLetsANodeInItsRadiusBeTakenUnconnected(): void
    {
        $edit = $this->seedBuildWithTree();
        $this->seedJewelKeystoneCovering('far');

        // `far` touches nothing, and no jewel is declared yet.
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'far'], server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', $edit.'/act', ['action' => 'jewel.set', 'keystone_id' => 'test_jewel_keystone']);
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'far'], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseIsSuccessful();
        self::assertContains('far', $this->allocatedOn($edit));
    }

    public function testClearingTheJewelKeystoneKeepsWhatItMadeLegal(): void
    {
        $edit = $this->seedBuildWithTree();
        $this->seedJewelKeystoneCovering('far');
        $this->client->request('POST', $edit.'/act', ['action' => 'jewel.set', 'keystone_id' => 'test_jewel_keystone']);
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'far'], server: ['HTTP_ACCEPT' => self::STREAM]);

        $this->client->request('POST', $edit.'/act', ['action' => 'jewel.set', 'keystone_id' => '']);

        // Owner's decision, 2026-09-13: like a class or ascendancy change, the
        // passives stay; iteration 4's findings report them.
        self::assertContains('far', $this->allocatedOn($edit));
        self::assertSelectorTextContains('#build-history', 'Changed the jewel keystone');
    }

    public function testAJewelKeystoneMustBeAKeystone(): void
    {
        $edit = $this->seedBuildWithTree();

        $this->client->request('POST', $edit.'/act', ['action' => 'jewel.set', 'keystone_id' => 'near'], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheJewelKeystonePickerSaysItIsNotExported(): void
    {
        $edit = $this->seedBuildWithTree();
        $this->seedJewelKeystoneCovering('far');

        $this->client->request('GET', $edit);

        self::assertSelectorExists('#jewel-keystone option[value="test_jewel_keystone"]');
        self::assertSelectorTextContains('#build-jewel', 'Not exported');
    }

    private function seedJewelKeystoneCovering(string $coveredId): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement('DELETE FROM catalog_passive WHERE id = ?', ['test_jewel_keystone']);
        $db->executeStatement(
            'INSERT INTO catalog_passive (id, name, kind, pos_x, pos_y, stats, recipe, keystones_in_radius, unlock_constraint) VALUES (?, ?, ?, 0, 0, ?, ?, ?, NULL)',
            ['test_jewel_keystone', 'Test Jewel Keystone', 'keystone', '[]', '[]', '[]'],
        );
        $db->executeStatement('UPDATE catalog_passive SET keystones_in_radius = ? WHERE id = ?', ['["test_jewel_keystone"]', $coveredId]);
    }

    /**
     * @return list<string>
     */
    private function allocatedOn(string $edit): array
    {
        $state = json_decode($this->client->request('GET', $edit)->filter('#build-state')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['allocated'] ?? null);

        return array_values(array_filter($state['allocated'], is_string(...)));
    }
```

`seedBuildWithTree()` already seeds a class, `start–near–leaf`, and an unconnected `far` (see its docblock); only the keystone and `far`'s radius are added here.

- [ ] **Step 2: Run them and watch them fail**

Run: `ddev php bin/phpunit --filter 'JewelKeystone'`
Expected: FAIL — `jewel.set` is an unknown action (`Unknown edit action "jewel.set"`), so the allocation after it is still refused and the picker does not exist.

- [ ] **Step 3: The command and its handler**

`src/Build/Edit/Command/SetJewelKeystone.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit\Command;

use App\Build\Edit\EditCommand;

final readonly class SetJewelKeystone implements EditCommand
{
    public function __construct(public int $buildId, public ?string $keystoneId)
    {
    }
}
```

`src/Build/Edit/Handler/JewelEditHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildEditor;
use App\Build\Edit\Command\SetJewelKeystone;
use App\Build\Edit\InvalidEditCommand;
use App\Build\Tree\PassiveGraph;
use App\Entity\Build;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The keystone a declared keystone-radius jewel works around — rule 4 of the
 * tree's legality. The format has no jewels, so this is app-only.
 */
final class JewelEditHandler
{
    public function __construct(
        private readonly BuildEditor $builds,
        private readonly PassiveGraph $graph,
    ) {
    }

    #[AsMessageHandler]
    public function set(SetJewelKeystone $command): void
    {
        $keystone = $command->keystoneId;

        if (null !== $keystone && !$this->graph->isKeystone($keystone)) {
            throw InvalidEditCommand::noSuchEntry('keystone "'.$keystone.'"');
        }

        // Clearing or changing the jewel keeps the passives it made legal
        // (owner, 2026-09-13) — the same as a class or ascendancy change.
        $this->builds->apply($command->buildId, 'jewel.set', ['keystone_id' => $keystone], static function (Build $build) use ($keystone): void {
            $build->setJewelKeystone($keystone);
        });
    }
}
```

`src/Build/Edit/CommandFactory.php` — one arm, importing `SetJewelKeystone`:

```php
            'jewel.set' => new SetJewelKeystone($buildId, $this->optionalString($payload, 'keystone_id')),
```

- [ ] **Step 4: Hand it to the rules, and list the keystones**

`src/Build/Tree/TreeContextFactory.php` — the return:

```php
        return new TreeContext($class?->getStartNodeId(), $build->getAscendancyKey(), $build->getJewelKeystone());
```

and update the `TreeContext` class docblock, which still says `jewelKeystoneId` "is always null in slice B1".

`src/Catalog/CatalogSearch.php`:

```php
    /**
     * Every keystone, for the jewel keystone picker. All 33 cover at least one
     * node (measured 2026-09-13), so none is filtered out.
     *
     * @return list<array{id: string, name: string}>
     */
    public function keystones(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => Row::str($row, 'id'), 'name' => Row::str($row, 'name')],
            $this->db->fetchAllAssociative("SELECT id, name FROM catalog_passive WHERE kind = 'keystone' ORDER BY name"),
        );
    }
```

`src/Controller/EditorContext.php` — add `'keystones' => $this->catalog->keystones(),`.

- [ ] **Step 5: The picker and the history sentence**

`templates/build/_slots.html.twig`, after the slot list's closing `</ul>`:

```twig
    <div id="build-jewel">
        <h3>Jewel keystone</h3>
        <p>A keystone-radius jewel lets passives near one keystone be allocated without connecting to your tree. Not exported: the .build format has no jewels, so this lives only in this planner.</p>
        <form action="{{ path('app_build_act', {slug: build.shareSlug, token: token}) }}" method="post">
            <input type="hidden" name="action" value="jewel.set">
            {{ include('build/_search_state.html.twig') }}
            <label for="jewel-keystone">Keystone</label>
            <select id="jewel-keystone" name="keystone_id" onchange="this.form.requestSubmit()">
                <option value="">No jewel</option>
                {% for keystone in keystones %}
                    <option value="{{ keystone.id }}" {{ keystone.id == build.jewelKeystone ? 'selected' : '' }}>{{ keystone.name }}</option>
                {% endfor %}
            </select>
            <button type="submit">Save</button>
        </form>
    </div>
```

`templates/build/_history.html.twig` — add to the `sentences` map, after `'instilled.remove'`:

```twig
    'jewel.set': 'Changed the jewel keystone',
```

- [ ] **Step 6: Run, lint, gate**

Run: `ddev php bin/phpunit --filter 'JewelKeystone'` then `ddev php bin/console lint:twig templates/` then `ddev composer stan`.
Expected: PASS, lint clean, PHPStan clean.

Mutation: make `TreeContextFactory` pass `null` instead of `$build->getJewelKeystone()`; watch `testADeclaredJewelKeystoneLetsANodeInItsRadiusBeTakenUnconnected` fail; restore. Report the failure text — it proves the wiring, not only the column.

Run: `ddev composer gate` — green.

- [ ] **Step 7: Commit**

```bash
git add src/Build/Edit/Command/SetJewelKeystone.php src/Build/Edit/Handler/JewelEditHandler.php src/Build/Edit/CommandFactory.php src/Build/Tree src/Catalog/CatalogSearch.php src/Controller/EditorContext.php templates/build tests/Controller/BuildEditorControllerTest.php
git commit -m "feat(editor): declare a jewel keystone and let its radius bypass connectivity"
```

---

## Self-Review

**Spec coverage.** "Slice B2 decisions": the registry → Task 1 (PHP) and Task 2 (templates, including the five GET forms and the gate test); slots by position with positions in the YAML, corrected labels, one `position` field, `slot_x` in the history payload, no migration → Tasks 3 and 4; the jewel column, `jewel.set`, all 33 keystones, the not-exported label, `TreeContextFactory` wiring, keep-on-clear → Tasks 5 and 6; the snapshot capturing `jewel_keystone` and `instilled_passives` → Task 5. Proof 11 is already in the spec; no task changes the code it describes. The canvas fetch URL is the fifth registration point only implicitly — it appends `window.location.search`, which already carries whatever the registry-driven forms put there — so no task touches `tree_controller.js`.

**Placeholder scan.** None. Two steps describe a template edit in prose plus the changed lines rather than the whole file (Task 4 Step 5, Task 2 Step 4's call sites) — each names every element that changes.

**Type consistency.** `setInventorySlot(document, inventoryId, slotX, uniqueName, from, to, additionalText)` in Task 4's implementation, handler and tests; `hasPosition(string, int)` and `positions()`'s `{id, x, key, label}` from Task 3 used in Task 4; `SetSlot`/`ClearSlot` with `slotX` after `inventoryId`; `getJewelKeystone()`/`setJewelKeystone()`/`setInstilledPassives()` from Task 5 used in Task 6; `ViewState::KEYS`/`fromRequest()` and the `viewState` template variable from Task 1 used in Task 2.

**Boundary check (slice B1's R1 lesson).** Task 3 leaves `isKnown()` unchanged so the commit before Task 4 keeps Trinket edits working. Task 4 lands the commands and the template together. Task 1 leaves templates untouched and working.

---

## Amendment, 2026-09-14: the jewel gets its own coverage

The owner confirmed that the keystone jewel is a separate mechanism from
*Entwined Realities* (spec proof 12). B1's `AllocationRules::excusedByRadius()`
used `keystonesInRadius` for both, and that list is *Entwined Realities*' own:
it reaches 1379 units from a keystone, while the jewel's mod carries a base
radius of 1000. Task 6 therefore also does the following.

- **`PassiveGraph` loads node positions** — `pos_x`, `pos_y`, read with the
  existing `Row::float()` — alongside the columns it already reads, and gains
  `jewelCovers(string $keystoneId, string $id): bool`: true when `$id` is not
  itself a keystone and lies within 1000.0 units (Euclidean, stored
  positions) of the keystone. Name the radius as a constant with a comment
  pointing at the mod's `local_jewel_effect_base_radius`.
- **`AllocationRules::excusedByRadius()`** tests the declared jewel keystone
  with `jewelCovers()` instead of `keystonesCovering()`. *Entwined Realities*
  keeps `keystonesCovering()` unchanged.
- **Tests.** The `AllocationRulesTest::rulesOver()` helper inserts every node
  at (0, 0) today; give it an optional `positions` argument. Add a unit test
  showing the jewel covers a node 950 units from its keystone and does not
  cover one 1050 units away. In `BuildEditorControllerTest`, Task 6's
  `seedJewelKeystoneCovering()` must place the keystone by position — within
  1000 units of `far`, not by writing `far`'s `keystones_in_radius` — and a
  second assertion moves it 1500 units away and expects the allocation refused.

The owner may rework the radius logic later if it does not feel true to the
game. Proof 12's in-game check narrows whether 1000 export units is the jewel's
real reach.
