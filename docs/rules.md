# Game rules

Every Path of Exile 2 rule the app relies on, in one place, grouped by
subject. Each entry says what the rule is, how we know it, and where it is
enforced. The reasoning behind a rule stays in the design spec
(`docs/specs/2026-09-09-poe2-build-helper-design.md`); each entry names the
section to read. When a rule changes, this file and the spec change in the
same commit.

Rules are true for a game version, not for PoE2 in general. Every entry
carries one of these sources:

- **data**: measured from the catalog export or the real `.build` corpus.
- **owner 0.5.5**: confirmed by the owner in-game for 0.5.5. Curated
  knowledge, and it can expire at the next patch.
- **docs**: GGG's `.build` format documentation.
- **assumed**: what the code does today without proof. Each assumed entry
  names the open proof that would settle it ("Proofs, to be stamped per game
  version" in the spec, tracked for the owner in `TODO.md`).

**Enforced** names the code that applies a rule today, or the iteration-4
rule ID that will. "Not enforced" means the app knows the rule but
deliberately doesn't police it.

Last updated 2026-09-15.

## Passive tree: allocation

| # | Rule | Source | Enforced |
|---|---|---|---|
| T1 | A node must be adjacent to the allocated set, rooted at the class's start node. The start node itself is always legal and needs no allocation. | data | `AllocationRules::connected()` |
| T2 | Connectivity is per weapon set. A set 1 node reaches the start through shared or set 1 nodes only, and likewise for set 2. A shared node routes through shared nodes only. | owner 0.5.5 | `Allocation::idsVisibleTo()` |
| T3 | A node with an `unlockConstraint` needs every listed gate node allocated (all of them, not any one), and the named ascendancy if the constraint names one. There are 200 such nodes: 197 are `oracle_*` behind *The Unseen Path* (Druid1), and three are notable chains (*Path of the Renegade*, *The Hollowkeeper*, *Sacred Unity*). | data + owner 0.5.5 (proof 8) | `AllocationRules::unlocked()` |
| T4 | *Entwined Realities* (`AscendancyDruid1Notable1`) lets a non-keystone node inside a keystone's Medium Radius be allocated disconnected, but only when both the notable and that keystone are allocated. The coverage is the export's `keystonesInRadius`. | data + owner 0.5.5 (proof 11) | `AllocationRules::excusedByRadius()` |
| T5 | A declared keystone jewel lets a non-keystone node within 1000 units of its keystone be allocated disconnected. Its keystone need not be allocated. It is a separate mechanism from T4 and does not use `keystonesInRadius`. | owner 0.5.5 (proofs 11, 12); the 1000-unit reach is assumed (proof 12) | `PassiveGraph::jewelCovers()`, used by `AllocationRules::excusedByRadius()` |
| T6 | Enablers are scoped to their weapon set. A gate node, *Entwined Realities* or a keystone counts only for nodes taken in the same set, or for every set when it is shared. The declared jewel is set-blind. | owner 0.5.5 (proof 10); jewel set-blindness is a design default | `Allocation::visibleTo()` |
| T7 | A keystone is never excused by a radius. It must be reached by ordinary connection ("Non-Keystone Passive Skills"). | data (stat text) | `AllocationRules::excusedByRadius()` |
| T8 | Removing a node also removes everything that becomes illegal because of it, per weapon set, as one history event. A node that was already illegal before the removal stays. | design, spec "Deallocation cascades, as one event" | `AllocationRules::illegalAfter()` |
| T9 | Changing the class, the ascendancy or the declared jewel doesn't cascade. Now-illegal passives stay and are reported later. | owner 2026-09-13 | Not enforced by design; iteration 4 findings |
| T10 | Jewels that open a radius around their own socket are not modelled. The data has no socket radius. | data | Not modelled |
| T11 | 240 tree nodes have `id: null` (unnamed ascendancy filler) and are not allocatable. | data | Filtered at sync |
| T12 | Ranger3's *Path of the Sorceress* and *Path of the Warrior* each read "Can Allocate Passive Skills from the Sorceress's / Warrior's starting point". An allocated one adds that class's start node as a second root for connectivity. | data (stat text) | **Not enforced yet.** T1 roots only at the build's own class, so today these builds get their off-start nodes refused |

Spec: "Allocation is checked, not merely drawn", "Weapon sets are allocated,
coloured and summed separately", "Deallocation cascades, as one event".

## Passive tree: points

| # | Rule | Source | Enforced |
|---|---|---|---|
| P1 | Each weapon set has its own point pool. A shared passive costs one point from both pools; a weapon-set passive costs one from its own pool only. | owner 0.5.5 (proof 9) | `passive.budget_exceeded` (iteration 4) |
| P2 | Each weapon set's pool is level − 1 (one point per level from level 2) plus 24 quest points, 4 per act. The app approximates the quest points at a level as min(24, 4 × floor(level / 10)), because it knows a target level, not quest progress. | owner 0.5.5 (proof 1); the per-level quest share is an approximation the owner confirmed | `passive.budget_exceeded` (iteration 4) |
| P3 | Two endgame sources each give one extra point: the Site of the Martyr of the First Edict and the Expedition drop *Olroth's Boon*. The budget allows both. | owner 0.5.5 (proof 1) | `passive.budget_exceeded` (iteration 4) |
| P4 | An Instilled Modifier costs no passive point. The amulet grants it. | owner 0.5.5 | Stats overview lists them separately; `passive.budget_exceeded` must not count them |
| P5 | Some ascendancy nodes grant passive points: their stat reads "Grants N Passive Skill Point(s)" (seven nodes, on Druid1 and Ranger3). An allocated one adds N to each pool. One real build needs this: 127 points = 125 + 2 Oracle "Passive Point" nodes. | data (stat text + corpus) | `passive.budget_exceeded` (iteration 4) |
| P6 | *Weapon Master* (Mercenary2): "100 Passive Skill Points become Weapon Set Skill Points". What it does to the two pools is not understood yet. | open (owner question) | Not modelled |

## Passive tree: Instilled Modifiers

| # | Rule | Source | Enforced |
|---|---|---|---|
| I1 | The currency is a Distilled Emotion; applying one produces an Instilled Modifier, applied at `Amulet1`. A node's `recipe` lists three Liquid Emotions, and an ingredient may repeat. 875 nodes have a recipe; no keystone or ascendancy node does. | data | Search at `Amulet1` covers only nodes with a recipe |
| I2 | Which nodes are instilled can't be inferred from the tree, so the player declares them. The declaration is app-only and is never exported. | data | `build.instilled_passives` |
| I3 | A normal amulet carries one Instilled Modifier. The *Twisted Amulet* and *Distorted Amulet* bases drop with 2 random notables, and the unique *Strugglescream* allows 3 more, 4 in all (`UniqueMultipleAnointments1`). | owner 0.5.5 (proof 6) + data | Not enforced: one entry by default, no cap |

## Classes and ascendancies

| # | Rule | Source | Enforced |
|---|---|---|---|
| C1 | All 12 classes are selectable in 0.5.5 and share 6 physical start positions in pairs, keyed by `classStartIndex`. | data | `catalog_class.start_node_id` |
| C2 | An ascendancy id (`Sorceress3`, `Druid1`, `Mercenary1`) uses the tree export's `ascendancyId` key space, and each belongs to exactly one class. A build with only an ascendancy is rooted on that ascendancy's class. | data | `ClassFromAscendancy` (on import and at runtime) |
| C3 | An ascendancy node belongs to one ascendancy only. | data | Other ascendancies are hidden on the canvas; `ascendancy.mismatch` (iteration 4) |
| C4 | There are 8 ascendancy points, 2 per trial level. The app allows all 8 as soon as an ascendancy is chosen, at any level. | owner 0.5.5 (proof 2); allowing all 8 at once is the owner's design choice | `ascendancy.budget_exceeded` (iteration 4) |

## Gems

| # | Rule | Source | Enforced |
|---|---|---|---|
| G1 | Gem paths are stored verbatim. Three prefixes coexist (`Metadata/Items/Gem/`, `Metadata/Items/Gems/`, `Metadata/items/Gems/`), and the game's own files use two of them. | data | Stored unnormalised |
| G2 | `[DNT]` and `[DNT-UNUSED]` gems are placeholders, not real gems. | data | Filtered at sync |
| G3 | `gem_type` is `active`, `support` or `spirit`. | data | Catalog |
| G4 | A support's requirements live in `support_text` prose, not in fields. The parser understands 85% of supports fully, so a parsed requirement can only warn; only a curated requirement can be an error. | data | `support.requirement_unmet` (iteration 4) |
| G5 | A support may be used more than once per character since 0.3. It was once only in 0.1 and 0.2. | data | `support.used_twice_in_build`, `until: 0.2` |
| G6 | A skill holds at most 5 supports. The actual socket count varies per gem and isn't in a `.build`, so only the ceiling is checked. | owner 0.5.5 (proof 3) | `support.socket_limit` (iteration 4) |
| G7 | The game's own skill-to-support pairing is `recommended_supports`. | data | `support.suggestion` (iteration 4) |
| G8 | Skills are bound to weapon types, but the gem export carries no weapon-type field, so this can't be derived from synced data. | data (proof 5) | `weapon.mismatch` is blocked until the granted-skill data is synced |
| G9 | Spirit funds persistent buffs, auras and minions. 100 comes from quests; the rest comes from items and a few tree and ascendancy nodes, several of them conditional and so not summable. | owner 0.5.5 (proof 4) | `spirit.overcommitted`: a warning only, above 100 plus the tree's flat Spirit (iteration 4) |
| G10 | Gem requirements are met by attributes from the tree and levels, but gear isn't modelled and can close the gap. | design | `attributes.unmet`, warning only (iteration 4) |

## Items and slots

| # | Rule | Source | Enforced |
|---|---|---|---|
| S1 | `inventory_id` has 14 values: `Weapon1`, `Weapon2`, `Offhand1`, `Offhand2`, `Helm1`, `BodyArmour1`, `Gloves1`, `Boots1`, `Belt1`, `Amulet1`, `Ring1`, `Ring2`, `Trinket1`, `Flask1`. No upstream source lists them. | data | `config/inventory_slots.yaml` (curated) |
| S2 | A slot is identified by `(inventory_id, slot_x, slot_y)`. `Flask1` holds `slot_x` 0 (life flask) and 1 (mana flask); `Trinket1`, the charm belt, holds 2, 3 and 4. `slot_y` is 0 everywhere. | data | `DocumentEditor` (addresses entries by `(inventory_id, slot_x)`, y = 0) and the one `position` form field `<inventory_id>@<slot_x>` parsed in `CommandFactory` |
| S3 | `weapon_set` 1 and 2 name the slot halves `Weapon1`/`Offhand1` and `Weapon2`/`Offhand2`. | data | Wire format |
| S4 | A `.build` carries no rare items and no modifiers, so nothing about a planned rare can be validated. Mod data serves browsing and crafting only. | docs + data | By construction |
| S5 | Jewels and jewel sockets can't be represented in `.build`. The declared jewel keystone is app-only and never exported. | docs | `build.jewel_keystone`, set by the `jewel.set` action |
| S6 | Resistances, life and energy shield come almost entirely from gear and can't be checked from a `.build`. | design | Dropped as derived rules; curated archetype expectations only |

## Uniques

| # | Rule | Source | Enforced |
|---|---|---|---|
| U1 | A `.build` identifies a unique by `unique_name` alone. | docs | Import |
| U2 | Three unique names repeat in the catalog. *Guiding Palm* is really three sceptres named *of the Eye*, *of the Heart* and *of the Mind*, which the catalog also has; its bare-name rows share their artwork. *Grip of Kulemak* is one ring at five counts of desecrated mods, which a `.build` can't express, so the app treats it as one unique (owner, 2026-09-15). *Grand Spectrum* is a jewel and can't appear in a `.build`. A real export writes the full name, `Guiding Palm of the Eye`. | data + owner 0.5.5 (proof 7) | `unique.name_ambiguous` (iteration 4) |
| U3 | No published source carries a unique's modifiers, so everything the app says a unique does must be curated. | data | The curated `interactions.yaml` (iteration 4, not yet written) |
| U4 | A unique fits a slot according to its `item_class`, through the curated slot map. | data + curated | `unique.slot_mismatch` (iteration 4) |

## Level intervals

| # | Rule | Source | Enforced |
|---|---|---|---|
| L1 | `level_interval` is a `[from, to]` array on every passive, skill and support. | data | Wire format |
| L2 | Levels run 0 to 100, not 1 to 100. | data | `level_interval.invalid` (iteration 4) |
| L3 | A game-exported file may carry different intervals per node, so flattening on import would destroy data. The editor's flat mode is derived from the document, never stored. | data + design | `IntervalMode` |

## Build file format

| # | Rule | Source | Enforced |
|---|---|---|---|
| F1 | The `.build` passive and gem key spaces match the catalog exactly. No mapping layer. | data | Catalog |
| F2 | 527 passive ids end in an underscore (`strength65_`) and are kept verbatim. | data | Stored unnormalised |
| F3 | A shared passive has no `weapon_set` key. The value 0 is documented but no game file writes it, so the app writes absence. | docs + data | `WeaponSet::toWire()` |
| F4 | The three collections round-trip byte-exact. The game's markup (`<b>{<m>{…}}`, CRLF) is real. | data | Interchange round-trip tests |
| F5 | Whether `.build` stays format-stable across the 1.0 jump is unknown. | open | Version acceptance |

## Deliberately not rules (PoE1 thinking)

- **No movement skill.** Every PoE2 character has the dodge roll.
- **No resistances on the tree.** They come from gear.
- **No life or energy shield on the tree.** Same reason.

Spec: "Deliberately dropped (PoE1 thinking)".
