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
- [ ] **When and how to execute the canvas feedback plan**
  (`docs/plans/2026-09-15-canvas-feedback.md`): subagent-driven like B2, or
  inline. Slice B2 is built (`49a252d`..`d51b8e7`), so the canvas's
  `allocatable()` already inherits the jewel's own 1000-unit coverage.
- [ ] **Update the "View-state query keys live in one registry" section of
  `CLAUDE.md`?** It still says "once slice B2's first task lands" and
  "Until then, grep…". Proposed text: "A query parameter that must survive
  an edit is registered in one place: `App\Controller\ViewState::KEYS`.
  Templates never list the keys by hand. `_search_state.html.twig` loops
  over `viewState`, and link maps merge into it.
  `testTheHeaderFieldFormCarriesEveryViewStateKey` checks every key in the
  registry, and `ViewStateTest` fails if a key is dropped. Decided
  2026-09-14, mechanised in slice B2." Say yes and I'll put it in.

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

- [ ] **Hide the three bare "Guiding Palm" rows** from the unique search? A
  real export writes the full name, *Guiding Palm of the Eye*, so the bare
  rows look like base entries no build would name.

Proof 5 (weapon binding of skills) is not yours: it is a question about the
catalog data, answered from it on 2026-09-14 and recorded in the spec.
