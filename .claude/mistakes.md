# Mistake log

## 2026-09-13 — plan code written against unchecked APIs
**What:** B1 briefs named `openEditor()`, `clickNodeOnCanvas()`, `actUrl()`, `app:catalog:sync passive_tree`, `assertInstanceOf(Connection::class, …)`, `body.set = …` on a `URLSearchParams`. None exist or work.
**Cost:** ~6 implementer deviations, rulings R6 and R14; `body.set` would have shipped an inert weapon-set control.
**How it was detectable:** grep each helper, `bin/console list` each command, one PHPStan run on a pasted snippet — before writing it into a plan.
**Status:** repeated (6) — CLAUDE.md line proposed to owner

## 2026-09-13 — plan's cascade swept already-illegal passives
**What:** B1 plan's handler re-validated every passive on removal; a deallocate for an unallocated id wiped every passive the rules cannot justify. Fix wave found a second hole: nodes routed through a kept illegal node.
**Cost:** one Critical (R12), one Important (final review I3); silent data loss on imported builds.
**How it was detectable:** spec says "becomes illegal as a result" — a delta. Test against a fixture carrying pre-existing illegal passives, not a clean one.
**Status:** repeated (2)

## 2026-09-13 — per-task gate commands narrower than the gate
**What:** SDD dispatches told implementers to run `bin/phpunit`, `stan`, `lint:twig` — not `ddev composer gate`. Playwright and php-cs-fixer excluded.
**Cost:** e2e suite red for ~9 tasks (17 fixture rows at (0,0)), cs violations in 5 files. Found only by controller spot-check.
**How it was detectable:** plan's Global Constraints named `ddev composer gate`; every dispatch contradicted it.
**Status:** one-off — full gate in every dispatch since (R13b)

## 2026-09-13 — unverified rationale stated as codebase fact
**What:** Claimed twice that `Revert` replays single-id events, to justify keeping `DocumentEditor::deallocatePassive()`. False — `Revert` restores a `BuildSnapshot`.
**Cost:** compatibility requirement imposed on Task 7 on a false premise; implementer caught it.
**How it was detectable:** `grep -rn "deallocatePassive" src` before asserting a caller exists.
**Status:** one-off

## 2026-09-13 — plan dropped a spec requirement
**What:** spec says the canvas greys illegal nodes; B1 plan had no task for it and its own spec-coverage check missed it.
**Cost:** found only by the final whole-branch review; now an owner decision.
**How it was detectable:** walk each spec subsection sentence by sentence against the task list, not by feature headline.
**Status:** one-off

## 2026-09-12 — view-state key missed at one of its registration points
**What:** A query key must reach hidden fields, `searchParams()`, the `_header` `field()` macro, template link maps, and the canvas fetch URL. Missed: 3 search terms, `intervals`/`supports`, then in B1 the link maps (T13), `_skills` map (R16), canvas fetch (final I1).
**Cost:** each a silent state drop, found by running the app or by the final review — never by reading.
**How it was detectable:** no single list of view-state keys exists; `grep -rn "intervals" src templates assets` shows every site in one command.
**Status:** repeated (5) — CLAUDE.md line + gate test proposed to owner; `ViewState` refactor recommended as B2 task 1

## 2026-09-12 — every tree node exported at (0,0), past two reviews
**What:** `TreeExport` read `pos_x`/`pos_y` with `Row::str($row, 'pos_x', '0')`. DBAL returns a native PHP float for a FLOAT column fetched outside the ORM, so `Row::str()` returned its default and every one of 4912 nodes exported at world (0,0). Found only when the first Playwright click ran.
**Cost:** Shipped through the exporting task's review — which asked about the float round trip specifically and judged it deliberate — and through the canvas task's review. Fixed in task 18 with `Row::float()` plus a regression test.
**How it was detectable:** `Row::str()` is documented to fall back rather than coerce. Using a string accessor on a numeric column and then casting the result is a contradiction visible in the line itself. No PHP test put a node in front of a renderer, so only a browser could see it.
**Status:** one-off — argues for the end-to-end test existing at all, not against it

## 2026-09-11 — production markup grown to satisfy a blind assertion
**What:** Plan tests used `assertSelectorTextContains` on values that live in `<input value="...">` attributes, which the crawler cannot see. Implementers twice answered by adding visible duplicate markup instead of fixing the assertion — `_header.html.twig` (task 11, `<span class="value">`), `_slots.html.twig` (task 14, `<strong>`). Each made a value render twice, once editable and once inert.
**Cost:** Two fix rounds. On a page whose stated purpose is the accessible path, a screen reader would read every affected value twice.
**How it was detectable:** A value only reachable as an attribute needs an attribute assertion — `input[value="…"]` or the crawler's `attr('value')`. Writing a text assertion against an input is wrong at the moment it is written.
**Status:** repeated (2) — proposed rule for the project CLAUDE.md: never change production markup to make a test assertion match; fix the assertion.

## 2026-09-11 — plan used Twig syntax this Twig version does not have
**What:** Iteration 3 plan's templates used a `|values` filter (does not exist in Twig 3.28, threw 500) and an inline `{% for ... if ... %}` (removed in Twig 3). Hit in `_state.html.twig`, `_nodes.html.twig`, `_header.html.twig`.
**Cost:** Three separate implementer deviations across tasks 11 and 12; one 500 found only by curling the page, not by the test suite.
**How it was detectable:** Neither construct was checked against the installed Twig before being written into the plan. `ddev php bin/console lint:twig templates/` would have caught both in one command.
**Status:** repeated (2)

## 2026-09-11 — plan's kernel tests fetched services nothing referenced yet
**What:** Iteration 3 plan wrote kernel tests calling `getContainer()->get(EditHistory::class)` and `get(InventorySlots::class)`. Their first real consumer arrives in task 8, so the compiler pruned both. Two implementers independently added `public: true` to `config/services.yaml`.
**Cost:** Two production service definitions made public for test reasons only. Caught on the second occurrence, not the first.
**How it was detectable:** Symfony removes services no other service references. Writing a kernel test against a service before anything consumes it needs test-env wiring (`config/services_test.yaml`), and the plan never said so.
**Status:** repeated (2)

## 2026-09-11 — plan asserted `array_is_list()` on a declared `list<>`
**What:** Iteration 3 plan's task 5 test carried `self::assertTrue(array_is_list($changed->passives));`. `BuildDocument::$passives` is declared `list<array<string, mixed>>`, so PHPStan max folds it to constant true: `function.alreadyNarrowedType` + `staticMethod.alreadyNarrowedType`, gate red.
**Cost:** One implementer died mid-fix on it; the retry needed an explicit ruling. Same trap re-flagged into tasks 6 and 7 briefs.
**How it was detectable:** PHPStan max rejects any assertion it can prove from PHPDoc. Checking a declared `list<>` for list-ness is that, by construction.
**Status:** one-off (pre-empted in later briefs)

## 2026-09-11 — claimed Symfony 8 while the lock held 7.4.18
**What:** Reported "Symfony 8.1.6" from `bin/console about` after a later `composer update -W` had re-resolved back to v7.4.18. Per-package constraints still read `7.4.*`; only `extra.symfony.require` had been changed. Output was stale cache.
**Cost:** A wrong version claim to the user and in commit 3154b56; user had to catch it.
**How it was detectable:** `composer.lock` is the source of truth for installed versions, `bin/console about` reads a built container. Changing `extra.symfony.require` alone never rewrites the per-package constraints.
**Status:** one-off

## 2026-09-11 — read "no Node" too broadly
**What:** Read the part 1 stack decision "no Node" as banning every Node dependency. It meant: no NodeJS backend. Presented Playwright as a stack conflict.
**Cost:** One superfluous question about the E2E tool; the user had to correct it.
**How it was detectable:** The doc line reads "assets via AssetMapper (no Node)" — the parenthesis qualifies assets, not the project. Read the line's context, not the keyword.
**Status:** one-off
