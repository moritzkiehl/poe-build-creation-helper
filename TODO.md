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

Proofs 1–4, 6 and 7 were settled on 2026-09-15 from your answers and the new
reference builds. They're recorded in the spec and in `docs/rules.md`.

- [ ] ***Weapon Master* (Mercenary2):** its stat reads "100 Passive Skill
  Points become Weapon Set Skill Points". What does that do to the two
  weapon-set pools? The points budget needs the answer.

## Decisions from the proof answers

- [ ] **Confirm the quest-point approximation:** the app assumes min(24,
  4 × floor(level / 10)) quest points at a level. That matches your figures
  for levels 20, 50, 60 and 80, but it's a guess between acts. For example,
  it gives 4 at level 15 and 0 below level 10.
- [ ] **Treat *Grip of Kulemak* as one unique?** Its five catalog rows differ
  only in desecrated mods, which a `.build` can't carry. Treating it as one
  would mean no "ambiguous unique" warning for it.
- [ ] **Hide the three bare "Guiding Palm" rows** from the unique search? A
  real export writes the full name, *Guiding Palm of the Eye*, so the bare
  rows look like base entries no build would name.
- [ ] **What are the "drop-only 2 instills"** in your proof 6 answer? I read
  it as amulets that drop carrying two Instilled Modifiers. Correct me if
  that's wrong.

Proof 5 (weapon binding of skills) is not yours: it is a question about the
catalog data, answered from it on 2026-09-14 and recorded in the spec.

## Git

- [ ] **Push the local commits** ahead of `origin/main`: the runtime class
  fallback (`edfbd77`, `afa786b`, `4a24737`), enablers scoped per weapon set
  (`66b73aa`), the 2026-09-14 decisions (`eee2487`) and `docs/rules.md`.
  `git log --oneline origin/main..HEAD` lists them.
