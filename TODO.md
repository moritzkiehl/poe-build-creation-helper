# Owner's to-do list

Things only the project owner can do: decisions that are theirs to make,
checks that need the game itself, and actions that are destructive or
outward-facing and wait for the owner's go-ahead. Claude adds an item here
whenever it hits one, instead of leaving it in a chat message. When an item
is done, it is removed, and its outcome is recorded where it belongs (the
spec, the proof list, a commit).

Last updated 2026-09-13.

## Decisions

- [ ] **Should the canvas grey out illegal nodes?** The spec requires it
  ("the canvas greys illegal nodes for immediate feedback"), but no plan task
  ever built it. Either build it, or amend the spec to "the server enforces,
  the canvas rolls back". The server already refuses illegal allocations, so
  this is about feedback only. Found by slice B1's final review.
- [ ] **A second visual cue for the weapon-set colours.** Shared, set 1 and
  set 2 are told apart by colour alone. Gold (`#e8c56a`) against green
  (`#7ad67a`) has a luminance contrast of 1.08:1, which makes them look the
  same to someone with deuteranopia and in greyscale. Options: a shape per
  group, or an inner ring or dot, mirrored in the legend swatches.
- [ ] **Two proposed `CLAUDE.md` lines**, from mistakes that repeated (see
  `.claude/mistakes.md`):
  - "Before a plan names a helper, command or API, grep for it." (repeated 6×)
  - "A new view-state query key goes through the one `ViewState` registry." It
    would be backed by a gate test; slice B2 task 2 adds that test either way.
    (repeated 5×)
- [ ] **When to hold the design round on how the editor is organised.** It
  was postponed on 2026-09-12; see the spec, "Deferred: a design round on how
  the editor is organised". Your input so far: a working session is "rounds
  across all areas".
- [ ] **How to execute slice B2:** subagent-driven or inline. The plan is
  `docs/plans/2026-09-13-slice-b2-equipment.md`.

## Checks only the game can answer

These are recorded as proofs in the spec, under "Proofs, to be stamped per
game version". Each note says what the code assumes today.

- [ ] **Does PoE2 import a `.build` file this app exported?** Round-tripping
  14 game-exported files byte-exact makes it very likely, but nobody has
  loaded an app-written file into the game yet, so it is still an inference.
- [ ] **Proof 8:** do the three multi-node unlock chains need *all* their gate
  nodes, or *any one*? The chains are *Path of the Renegade*, *The
  Hollowkeeper* and Huntress's *Sacred Unity*. The code requires all of them.
- [ ] **Proof 9:** do weapon-set passives draw on their own point pool, or on
  the shared one? Nothing uses this until iteration 4's
  `passive.budget_exceeded`.
- [ ] **Proof 10:** does a legality enabler (a keystone, *Entwined
  Realities*, an unlock gate node) count only within the weapon set it is
  allocated in? The code counts it in every set; the test to invert is
  `testAnEnablerCountsWhicheverWeaponSetItSitsIn`.
- [ ] **Proof 11:** does a keystone-radius jewel need its keystone
  *allocated*? The code does not require it.
- [ ] **Proofs 1-7** (passive points, ascendancy points, support sockets,
  spirit, weapon binding, Instilled Modifiers per amulet, `unique_name`
  spelling). These still need stamping for the current game version.

## Environment

- [ ] **Rebuild the test database.** The `catalog_passive.recipe` column in
  `db_test` lacks its `DEFAULT '[]'`, although the migration is recorded as
  run. It most likely ran a version of that migration from before its
  correction, and recorded migrations never re-run. Nothing fails because of
  it today. Rebuilding drops the test database, which is why it's left to you.
- [ ] **Give your existing development builds a class.** They were imported
  before classes were derived from the ascendancy, so they still have none,
  and nothing on their tree can be allocated until they do. Pick a class in the
  header, or re-upload the file.

## Git

- [ ] **Push the local docs commits** ahead of `origin/main`: `9fdb28a` (the
  B2 design), `dcadce2` (the B2 plan), and the commit that added this file and
  its `CLAUDE.md` rule. Or leave them until B2 ships.
