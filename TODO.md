# Owner's to-do list

Things only the project owner can do: decisions that are theirs to make,
checks that need the game itself, and actions that are destructive or
outward-facing and wait for the owner's go-ahead. Claude adds an item here
whenever it hits one, instead of leaving it in a chat message. When an item
is done, it is removed, and its outcome is recorded where it belongs (the
spec, the proof list, a commit).

Last updated 2026-09-15.

## Decisions

- [ ] **When to hold the design round on how the editor is organised.** It
  was postponed on 2026-09-12; see the spec, "Deferred: a design round on how
  the editor is organised". Your input so far: a working session is "rounds
  across all areas".
- [ ] **How to execute slice B2 and the canvas feedback, and in which
  order:** subagent-driven or inline, B2 first or the canvas first. The plans
  are `docs/plans/2026-09-13-slice-b2-equipment.md` and
  `docs/plans/2026-09-15-canvas-feedback.md`. They don't conflict: both touch
  `EditorContext`, but different lines, and the canvas's `allocatable()`
  reuses the radius check that B2 changes, so it picks the change up either
  way.

## Checks only the game can answer

These are recorded as proofs in the spec, under "Proofs, to be stamped per
game version". Each note says what the code assumes today.

- [ ] **Proof 12 (narrowed):** the keystone jewel is separate from *Entwined
  Realities*, so it gets its own coverage: every node within 1000 units of the
  declared keystone. Check in-game that 1000 is its real reach. With the jewel
  on, a node about 950 units from its keystone should be allocatable
  unconnected, and one about 1050 away should not. Ask me for candidate nodes
  near any keystone and I'll list them with their distances.

Each of the next six needs one number or rule from the game, stamped with the
game version. Tell me the answer and I'll record it in
`docs/version-acceptance.md`.

- [ ] **Proof 1: passive points.** On a character at a known level (50, and
  100 if you have one), how many passive points has it been given in total,
  and how many of those came from quest rewards? Iteration 4's budget check
  counts exactly these two parts, per weapon-set pool.
- [ ] **Proof 2: ascendancy points.** How many ascendancy points exist in
  total, what awards them (the trials), and how many does each completion give?
- [ ] **Proof 3: support sockets on a skill gem.** What decides how many
  support gems a skill gem can hold: gem level, quality, the gem's tier, or
  something else? Compare one skill at two gem levels in the gem menu.
- [ ] **Proof 4: Spirit.** How much Spirit can the passive tree give in total,
  and which sources exist only outside it (body armour, amulet, sceptre,
  quests)?
- [ ] **Proof 6: Instilled Modifiers per amulet.** Can a normal (non-unique)
  amulet carry more than one? Try instilling a second on an amulet that already
  has one. Low priority: the editor enforces no limit either way.
- [ ] **Proof 7: ambiguous unique names.** *Grip of Kulemak* is the name of 5
  different rings, and *Guiding Palm* of 3 sceptres. Put one in a build in the
  game's planner, export the `.build`, and tell me what it wrote for
  `unique_name`: the bare name, or something that tells the variants apart.
  (*Grand Spectrum*, 3 jewels, can't appear in a `.build` at all.)

Proof 5 (weapon binding of skills) is not yours: it is a question about the
catalog data, answered from it on 2026-09-14 and recorded in the spec.

## Git

- [ ] **Push the local commits** ahead of `origin/main`: the runtime class
  fallback (`edfbd77`, `afa786b`, `4a24737`), enablers scoped per weapon set
  (`66b73aa`), the 2026-09-14 decisions (`eee2487`) and `docs/rules.md`.
  `git log --oneline origin/main..HEAD` lists them.
