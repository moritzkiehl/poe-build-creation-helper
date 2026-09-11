# PoE2 Build Helper — Design

Status: 2026-09-11. Part 1 (architecture, data flow) and part 2 (data model,
rules engine, testing, iteration plan) are both discussed and confirmed, and
step 0 of the iteration plan — the key mapping spike — has been run; its results
are in "Step 0 findings" and have already been folded into the data model and
the rules engine. What remains open is the list of proofs and the joint
walkthrough of warnings and hints — see "Open points".

Project language is English throughout: prose, identifiers, file names, code
comments and commit messages. (This document was originally written in German
and translated on 2026-09-11.)

## Purpose

A web app that helps build Path of Exile 2 characters: sketch a build, have it
checked, share it, and export it as an official `.build` file for the game.

Not a DPS or EHP simulator — that stays with Path of Building 2.

## Settled fundamentals

| Question | Decision |
|---|---|
| Core job | Build discovery + guided assistant, first focus on the user's own build |
| Assistant engine | Stage 1 rule-based. LLM in stage 2 with the user's own key or a capped operator budget |
| Data path | MVP without any third-party API dependency; extensibility towards API access designed in |
| Stack | Symfony 8 as the server from the MVP on, MariaDB/MySQL |
| Frontend | Twig + Symfony UX (Stimulus/Turbo), assets via AssetMapper |
| Build ownership | Anonymous, unguessable share slug + separate edit token, no login |
| Catalog storage | Option C: narrow filter columns + one JSON column per record |
| MVP scope | Catalog, build editor, rules engine, `.build` export and import |
| Publication | Application code public under MIT; deployment configuration private |
| LLM funding | Stage 2: the user's own key plus a capped shared monthly budget; paid credits as a switch that stays off |

Deliberately out of the MVP: ladder meta, prices, login, LLM, rare mods and
crafting.

Clarified on 2026-09-11: "no Node" means no NodeJS backend and no Node
dependency in the asset pipeline. Node as a development and CI tool is fine —
the E2E tests run on Playwright.

Revised on 2026-09-11: the stack row said Symfony 7, and the scaffold was first
built on 7.4 because of it. Corrected to **Symfony 8.1**. Reasoning: upgrades
within a major are deprecation work rather than BC breaks, so starting on the
current major trades one expensive 7 → 8 jump for several cheap ones, and the
next LTS (8.4) is a natural place to settle shortly after launch. The price is a
standing chore, and it has a date: **8.1 reaches end of maintenance in 01/2027**,
the same month as the planned launch, so being on 8.2 by then is part of the
launch checklist rather than an afterthought.

PHP target is **8.5** (8.5.9 in development). Doctrine 3 and PHPUnit set a hard
floor of 8.4 — Symfony 8.1 itself still allows 8.2 — and 8.5 is the current
stable series, so `composer.json` requires `>=8.5`. No `config.platform.php`
pin: the production PHP version is not decided, and pinning it now would be a
guess.

Local development runs on **DDEV** (PHP 8.5, MariaDB 11.4 LTS, nginx-fpm,
docroot `public`, project type `symfony`). The Doctrine recipe's `compose.yaml`
and `compose.override.yaml` were deleted: they define a PostgreSQL service that
would run alongside DDEV's MariaDB and contradict the stack decision. `.ddev/config.yaml` is committed: it is local development
configuration, and the only hostname in it is a `.ddev.site` development name.
Real hosts, domains and secrets stay out, per "Deployment configuration kept
private".

## Data sources (researched 2026-09-09)

| What | Source | Status |
|---|---|---|
| Passive tree (nodes, IDs, layout) | https://github.com/grindinggear/poe2-skilltree-export | official |
| Build interchange format | https://www.pathofexile.com/developer/docs/game#buildplanner | official, version 1 (experimental) |
| Gems, supports, uniques, base items, ascendancies, tags | https://repoe-fork.github.io/poe2/ | unofficial; code MIT, data explicitly owned by GGG (see "Licensing and rights") |
| Prices | poe.ninja economy, `GET /poe2/api/economy/...` | public, no auth, `User-Agent` required, respect the 5-minute cache |
| Ladder (rank, name, class, level) | GGG `GET /league/<league>/ladder`, scope `service:leagues:ladder` | needs a confidential client with `client_credentials` |
| Other players' characters with gear and tree | — | does not exist |

### Documented constraints

- No OAuth scope returns other players' characters. Every `account:*` scope
  applies to the token holder alone. Other players' gear and trees are not
  officially retrievable.
- All `service:*` scopes require a confidential client. Public clients (PKCE)
  may not use them and share their rate limits with every other public client.
- OAuth redirect URIs must be registered HTTPS domains. Verbatim: "We cannot
  accept IP addresses or localhost domains even for in-development projects."
  Developing a login locally needs a tunnel or a stub.
- CORS is nowhere promised; direct browser access to `api.pathofexile.com` is
  not intended.
- poe.ninja: "The builds / profiles API, and every other non-economy endpoint
  (character, Path of Building, authentication), are internal. They are
  undocumented, unsupported, and not available for third-party use." Only the
  economy endpoints are usable.
- Mandatory GGG API header:
  `User-Agent: OAuth {clientId}/{version} (contact: {contact})`.
  Rate limits via `X-Rate-Limit-{rule}`, `X-Rate-Limit-{rule}-State`,
  `Retry-After`.

### RePoE fork file sizes (`.min.json`, Last-Modified 2026-09-07)

`mods` 8.7 MB · `ascendancies` 3.9 MB · `base_items` 3.1 MB ·
`skill_gems` 957 KB · `uniques` 115 KB · `tags` 25 KB.
`stat_translations` and `passive_skill_trees` are directories, not files — that
was the 404 (resolved 2026-09-11, see "Step 0 findings"). `passive_skill_trees`
holds `Default.json`, `Atlas.json`, `EndgameMap.json`, `Royale.json` and
`BrequelTree.json`; `stat_translations` holds 54 files.
`mods` is not needed for the MVP (no rare crafting).

## Licensing and rights (settled 2026-09-09)

RePoE deliberately grants no rights to the data. `LICENSE.md`, identical in
`repoe-fork/repoe` and in the original `brather1ng/RePoE`: MIT for the code,
followed by

> Contents of generated files (all files in the `data` directory) are owned by
> Grinding Gear Games and shall not be used or published without being in
> accordance with their terms of use.

The data repository `repoe-fork/poe2` has no licence file at all. The generator
`repoe-fork/pypoe` is GPL-3.0, which covers the generator, not its output.

So GGG's terms of use apply. Verbatim:

> Grinding Gear Games grants you a limited licence […] for your own personal and
> non-commercial use

> Under no circumstances, without the prior written approval of Grinding Gear
> Games, may you: Adapt, reproduce, store, distribute, print, display, publish or
> create derivative works from any part of the Website, Materials or Services
> other than in accordance with the Licence.

From the developer documentation, additionally: "We do not officially provide
access to any in-game data outside of our supported APIs.", "As a general rule,
we cannot allow our Intellectual Property to be used to generate commercial
revenue." and the obligation to state "This product isn't affiliated with or
endorsed by Grinding Gear Games in any way."

Assessment: no blank cheque, but no prohibition either — the entire tooling
ecosystem (Path of Building, poe2db, the wikis) operates on this footing.
Tolerated, not licensed. The risk is of the shut-down kind, not the damages
kind. This is not a legal opinion; with commercial intent, asking GGG would be
the right move.

### Binding measures

1. No money for GGG-derived features. The LLM feature runs on the user's own
   key or out of a capped operator budget; paid credits only after GGG agrees.
   See "Open source, hosting, LLM funding".
2. The notice appears verbatim and visibly in the footer: "This product isn't
   affiliated with or endorsed by Grinding Gear Games in any way."
3. No GGG or PoE logos; no name and no domain that suggests something official.
4. Prefer official sources (skill tree export, `.build` format, economy API).
   Use RePoE only for what is officially missing: gems, supports, uniques, base
   items, tags.
5. RePoE snapshots are never committed and never redistributed. Sync fetches
   upstream at runtime, snapshots live in `.gitignore`. No bulk download, no
   public data API. Displaying yes, redistributing no.
6. Attribution: the MIT notice for the RePoE code plus a sentence stating that
   the game data belongs to Grinding Gear Games.
7. Go easy on upstream: `User-Agent` with contact, evaluate `Last-Modified` and
   ETag, do not pull more often than necessary.
8. Catalog sync must be switchable off without killing the app.

### Architectural consequence of measure 8

The catalog must not be load-bearing. A build has to open, edit, export and
share even when no catalog data is present — without names, icons and rule
checks in that case. This fits `Advice` behind a port: no catalog, no findings,
everything else keeps working.

## Open source, hosting, LLM funding (decided 2026-09-09)

### Publication

Application code public under **MIT**. Chosen deliberately: MIT also allows
third parties to host commercially; the GGG data question is then theirs.

Two files at the repository root:

- `LICENSE` — MIT, our own code.
- `NOTICE` — records what is **not** under MIT: game data is owned by Grinding
  Gear Games and is not shipped; RePoE code is MIT (Copyright 2016 brather1ng),
  its data explicitly is not.

The repository has been public since the first commit on 2026-09-11. That
sharpens measure 5 from day one: committed snapshots would be exactly the
"publish" the terms forbid, and a public repository keeps them visible in the
history even after a later deletion. Snapshots land in a `.gitignore` path and
are produced by the sync command only.

"Going public" elsewhere in this document means launching the website, not
opening the repository — the repository is already open.

### Deployment configuration kept private

The public repository holds only a `.env` with harmless defaults and
`.env.example`. Not in it: `.env.local`, host names, domains, server and
container configuration, deployment scripts, CI secrets. Those live privately or
outside version control.

Checkpoint before every push, not just the first: search the repository for
domains, hosts, keys and tokens. The repository is public, so a secret is
disclosed the moment it is pushed, and rewriting history does not undo that.

### LLM funding (stage 2, not the MVP)

What gets paid for is LLM tokens, not access to GGG data. Catalog, editor, rules
engine and export stay entirely free. **No GGG-derived content is ever behind a
paywall** — that sentence belongs in the README and in the interface.

An `LlmBudget` with three modes, switchable by configuration:

| Mode | Behaviour | Status |
|---|---|---|
| `user_key` | The user supplies their own Anthropic key. No money changes hands. | planned |
| `shared_budget` | One operator key, a hard monthly limit, per-user accounting so a single user cannot drain it. Once exhausted, point at the user's own key. No money changes hands. | planned |
| `paid_credits` | Paid quotas at cost. | **off**, until GGG has agreed |

`paid_credits` stays off because GGG writes that it "cannot allow our
Intellectual Property to be used to generate commercial revenue" — revenue, not
profit. Before switching it on, a short enquiry to GGG describing the tool and
the model. The code differs between the three modes only in accounting;
switching it on is configuration, not a rebuild.

## The `.build` format as the pivot

GGG documentation, verbatim: "Designed for players to import builds from
third-party sources, the Build Planner functions as a plug-and-play feature.
Editing or creating builds within Path Of Exile 2 is currently not supported."

The game can display builds but not create them — exactly the gap this tool
fills. The format is simultaneously the data model: no rares, no numbers, just
passives, skills with supports, and unique hints per inventory slot.
`level_interval` makes the levelling order machine-readable.

```
Build:              name, author, link, description, ascendancy,
                    passives[], skills[], inventory_slots[]
BuildPassive:       id ("strength89", PassiveSkills table), level_interval,
                    additional_text, weapon_set? (1 or 2)
BuildSkill:         id ("Metadata/Items/Gems/SkillGemEarthquake", BaseItemTypes),
                    level_interval, support_skills[]?
BuildSupport:       id ("Metadata/Items/Gems/SupportGemFastForward"),
                    level_interval
BuildInventorySlot: inventory_id ("Weapon1", Inventories table), slot_x, slot_y,
                    level_interval, additional_text, unique_name? ("Astramentis")

Measured against fourteen files exported by the game at 0.5.5 (2026-09-11):
all eight top-level fields are always present; `level_interval` is a
**two-element array `[from, to]`**, never an object, and is present on every
passive, skill, support and slot; `additional_text` is always present on
passives and slots and never on skills; `weapon_set` appears on 312 of 1705
passives and is only ever 1 or 2.
```

`additional_text` allows markup: type `<r> <b> <i> <u> <s> <m> <l>`, colours
`<red> … <gold> <unique>` and `<rgb(r, g, b)>`, shape `<key>{ text }`.

Where the files live: Windows
`C:/Users/Name/Documents/My Games/Path of Exile 2/BuildPlanner`;
SteamOS
`/home/deck/.local/share/Steam/steamapps/compatdata/2315204395/pfx/drive_c/users/steamuser/Documents/My Games/Path of Exile 2/BuildPlanner`.
The game runs a file watcher that picks up changes. Alternatively, subscribe via
pathofexile2.com.

## Architecture (part 1, confirmed)

Five modules, dependencies pointing downwards only:

| Module | Job | Knows |
|---|---|---|
| `Catalog` | Game data, read-only. Sync commands, repositories, search. | nothing above it |
| `Build` | Build sketch as an aggregate, persistence, share and edit tokens. | `Catalog` (ID validation only) |
| `Interchange` | Read and write `.build` JSON, format v1. | `Build`, `Catalog` |
| `Advice` | Rules engine: sketch + catalog → findings. No DB, no HTTP. | ports only |
| `Ui` | Controllers, Twig, Stimulus. | everything below |

Addable later without touching what exists: `Meta` (ladder aggregates), `Assist`
(LLM).

Load-bearing decision: `Advice` receives catalog data through an interface, not
through Doctrine. Signature `(BuildSketch, CatalogPort) -> Finding[]`, extended
in part 2 to `(BuildSketch, CatalogPort, KnowledgePort) -> Finding[]`. That makes
the rules engine fully unit-testable against a fake catalog — the part that gets
readjusted with every PoE2 patch.

### Rendering the passive tree (decided 2026-09-11)

The tree is drawn on a `<canvas>` 2D context, not as SVG or DOM nodes. At 0.5.5
the tree carries 4912 allocatable nodes and 6076 edges; as SVG that is roughly
eleven thousand elements, and pan, zoom and hover all go through layout. This is
the same reason poe.ninja draws its tree on a canvas.

Consequences to design for:

- Hit testing is ours to write. Node positions come from group centre plus orbit
  radius and index, so a coarse grid index over node coordinates is enough to
  resolve a click without walking all 4912.
- Nothing in the tree is in the DOM, so nothing in it is reachable by keyboard or
  a screen reader on its own. The searchable node list beside the tree is not
  decoration — it is the accessible path to the same actions, and it also carries
  the ids that findings target.
- A Stimulus controller owns the canvas and redraws on state change; findings
  keep arriving over Turbo as elsewhere.
- Orbit geometry as measured on 0.5.5: radii `[0, 82, 164, 334, 488, 657, 839,
  250, 1076, 1320]` with `[1, 12, 24, 24, 72, 72, 72, 24, 72, 140]` slots per
  orbit. This belongs on the proof list — it is measured, not documented, and 1.0
  may change it.

### Data flow

```
Sync (CLI, manual)
  poe2-skilltree-export  ──┐
  repoe-fork: skill_gems,  ├─→ normaliser ─→ MariaDB (catalog)
    base_items, uniques,   │                 filter columns + JSON column
    ascendancies, tags   ──┘

User (browser, Turbo)
  Editor ──→ build aggregate ──→ MariaDB (builds)
                  ├──→ Advice ──→ findings inline in the editor
                  ├──→ Interchange ──→ download .build
                  └──← Interchange ←── upload .build / paste JSON
```

Sync stays a command with no automation in the MVP: adopting a patch is a
decision, and a broken upstream must not drag the running app down with it.
Every run writes source, timestamp and the upstream `Last-Modified` to
`catalog_sync`, so the displayed data status is evidenced.

JSON paths are indexed in MariaDB via generated columns — whatever needs an
index becomes a real column. Consistent with option C.

## Timeline and phases (2026-09-11)

PoE2 is in Early Access until December 2026. The development and testing target
is **0.5.5**. Version 1.0 is expected around December 2026, and the **public
launch of the tool around January 2027**, shortly after 1.0.

Hence two phases:

| Phase | Period | Game version | Goal |
|---|---|---|---|
| EA | September–December 2026 | 0.5.5 and later patches | Finish the mechanics, keep content deliberately thin; repository public, website not yet launched |
| 1.0 | December 2026–January 2027 | 1.0 | Re-sync, re-establish proofs, fill in content, launch the website |

**Investment rule.** Anything the version jump devalues is built as late as
possible. Curated knowledge stays at example size until 1.0 — just enough to
exercise `KnowledgePort`, the precedence rule and the display. Derivation logic,
ports, data model and sync are built immediately; they survive the jump.

**What 1.0 is likely to break:** passive IDs and tree layout, gem paths, support
tag requirements, spirit values, point budgets — and possibly the `.build`
format itself, which GGG explicitly labels "version 1 (experimental)". No part
of the design may assume catalog data is stable across the jump.

**Old builds.** A build keeps its `game_version`. Opened against a newer
catalog, its `document` JSON is **not** silently rewritten; unknown IDs show up
as findings. Before the website launch only our own test builds exist — the
cheapest possible moment for a hard cut, should a 0.5.5 → 1.0 mapping not be
worth it. A mapping command would be a bonus, not a requirement.

## Standing rule: PoE2 binding

Every rule, every field, every check names the PoE2 mechanic it rests on. No
carry-over from PoE1 without evidence. Where a mechanic cannot be evidenced from
catalog data, it is not derived but curated — or it is dropped.

Equally: PoE2 changes mechanics between patches. "True for PoE2" is not a
durable statement, only "true for version X". Rules and curated entries
therefore carry validity ranges (see the rules engine), and proofs carry a
version stamp.

## Step 0 findings — key mapping spike (2026-09-11)

Run against the live upstream sources; nothing was committed. RePoE labelled the
data "PoE2 version 4.5.5.1.6", the internal build matching 0.5.5.

**The headline risk from part 1 is cleared. `Catalog` needs no mapping layer.**
Both key spaces referenced by `.build` match their sources exactly.

- Passive tree: the export keys nodes by a numeric skill id, but each node
  carries a string `id` field, and that field is the `.build` key space.
  `strength89` — the example from GGG's documentation — is present verbatim
  (node 35426, "Attribute"). 4912 ids, no duplicates.
- Gems: `Metadata/Items/Gems/SkillGemEarthquake` and
  `Metadata/Items/Gems/SupportGemFastForward`, both documentation examples, are
  present verbatim as RePoE keys, and `base_item.id` always equals the key.

### Traps that constrain the implementation

- **Three path prefixes coexist in `skill_gems`:** `Metadata/Items/Gem/` (595),
  `Metadata/Items/Gems/` (594) and `Metadata/items/Gems/` (2). No leaf name
  collides across them. Paths are therefore stored verbatim and never
  normalised; a "tidy-up" of the prefix would break export into the game.
- **527 passive ids end in an underscore** (`strength65_`). Likewise verbatim.
- **240 tree nodes carry `id: null`** — unnamed ascendancy filler. They are not
  referenceable from `.build` and must not reach the catalog as allocatable
  nodes.
- **22 support gems are `DNT` placeholders** ("DNT Description", "DNT-UNUSED
  Ezomyte Four"). Sync filters them out, or they surface in search as real gems.
- **Unique names are not unique.** 449 uniques, 441 distinct names;
  `Grand Spectrum`, `Grip of Kulemak` and `Guiding Palm` each occur more than
  once. Since `.build` identifies uniques by `unique_name`, those three are
  ambiguous on import and need a disambiguation decision.
- **No inventories data exists anywhere.** Nothing maps `.build`'s
  `inventory_id` (`Weapon1`). `item_classes` lists 118 classes and each unique
  carries an `item_class`, so slot validation is reachable — but only through a
  hand-maintained map.

### Support requirements: prose, not fields

There is no `allowed_tags` or `excluded_tags` field. What exists is
`support_text`, and it is more machine-readable than prose usually is: bracket
markup references canonical terms (`[Curse]`, `[CriticalDamageBonus|Critical
Damage Bonus]`), 180 distinct terms across the corpus, led by `Hit` (217),
`Attack` (145), `Minion` (115), `Projectile` (85), `Spell` (54), `Totem` (49),
`Melee` (47), `Channelling` (34).

Measured coverage:

- 608 of 630 supports (97%) open with a `Supports X` clause; the 22 exceptions
  are the `DNT` entries.
- Only 62% of those clauses contain a word from the 59-value gem `tags`
  vocabulary. The rest state requirements tags cannot express: "skills which can
  cause damaging hits", "skills you use yourself", "skills that have cooldowns",
  "offering skills".
- Only 32 supports carry an explicit `Cannot Support` clause. The positive
  clause is the real constraint for nearly everything else.
- Compound clauses need care: "Cannot Support Channelled Skills **and does not
  modify Skills used by Minions**" is one restriction plus one behaviour note,
  and a naive parser reads two restrictions.

Consequence: support compatibility is derivable, but only against the 180-term
bracket vocabulary and never at full coverage. It therefore may not produce hard
errors — see the rules engine.

### Confirmed against real build files (2026-09-11)

Fourteen `.build` files exported by the game at 0.5.5 were read. They are not
committed — they are someone's build documents, they carry GGG identifiers, and
the repository is public — but an optional test suite picks them up from
`var/sample` when they are present.

All fourteen are read by `Interchange` and round-trip byte-exact, which
validates carrying the three collections as decoded data rather than modelling
them.

What the corpus settles:

- **`level_interval` is `[from, to]`**, a two-element array. The design
  deliberately had no shape for it; guessing an object would have produced
  exports the game rejects.
- **`weapon_set` is 1 or 2**, not 0..2, and names the numbered slot halves.
- **The `Inventories` vocabulary, 14 values:** `Weapon1`, `Weapon2`, `Offhand1`,
  `Offhand2`, `Helm1`, `BodyArmour1`, `Gloves1`, `Boots1`, `Belt1`, `Amulet1`,
  `Ring1`, `Ring2`, `Trinket1`, `Flask1`. This is what `inventory_slots.yaml`
  needs, and no upstream source provided it.
- **`ascendancy` is an identifier like `Sorceress3`, `Druid1`, `Mercenary1`** —
  the same key space as the tree export's `ascendancyId`, so no mapping layer is
  needed there either.
- **Both gem path prefixes occur in real game files**, `Metadata/Items/Gems`
  491 times and `Metadata/Items/Gem` 301. The dual prefix is genuine game data
  rather than a RePoE artefact, so storing paths verbatim is proven, not merely
  prudent.
- **Level values run 0 to 100**, not 1 to 100.
- The documented markup is real: `<b>{<m>{Allocation Step #1}}` with CRLF line
  breaks.

### A better source for suggestions

`recommended_supports` is present on 379 of 505 active gems and on all 44 spirit
gems: the game's own skill-to-support pairing, already machine-readable. It
replaces tag inference as the basis for `support.suggestion`.

`gem_type` has three values, not two: `active` (505), `support` (642) and
`spirit` (44). The third confirms a data basis for spirit handling.

## Data model (part 2, confirmed)

### Catalog

Option C from part 1: narrow filter columns plus one JSON column per record.
List values live in side tables rather than JSON arrays, because MariaDB cannot
index arrays usefully.

| Table | Filter columns | JSON |
|---|---|---|
| `catalog_gem` | `id` (PK, `Metadata/Items/Gems/…`), `kind` (`active`\|`support`), `name`, `primary_attribute`, `required_level` | stats, description, icon reference |
| `catalog_gem_tag` | `gem_id`, `tag` | — |
| `catalog_gem_requirement` | `support_id`, `term` (bracket vocabulary), `mode` (`requires`\|`excludes`), `origin` (`parsed`\|`curated`) | parsed clause for review |
| `catalog_gem_recommended_support` | `gem_id`, `support_id`, `rank` | — |
| `catalog_passive` | `id` (`strength89`), `name`, `kind` (`small`\|`notable`\|`keystone`\|`ascendancy`), `ascendancy_key?`, `pos_x`, `pos_y` | stats, neighbours |
| `catalog_passive_edge` | `from_id`, `to_id` | — |
| `catalog_base_item` | `id`, `name`, `item_class`, `inventory_id` | requirements, implicit |
| `catalog_unique` | `id`, `name`, `base_item_id` | mods as text |
| `catalog_sync` | `source`, `ran_at`, `upstream_last_modified`, `game_version`, `status`, `count` | error text |

Three of these tables are load-bearing for the rules engine:
`catalog_passive_edge` for tree connectivity, `catalog_gem_requirement` for
support compatibility, and `catalog_gem_recommended_support` for suggestions.
Everything else serves display.

`catalog_gem_requirement` is filled by a parser over `support_text` during sync,
keyed on the 180-term bracket vocabulary rather than on the 59 gem tags, with
`origin` recording whether a row was parsed or curated. The parsed clause is
kept in JSON so a wrong extraction can be reviewed rather than guessed at.
Sync drops `DNT` entries and tree nodes with `id: null` before writing.

### Build

One root row with planning columns; the content lives as a **single** JSON
document shaped like the `.build` format:

```
build: id, share_slug (unique), edit_token_hash, name, author?, link?,
       description?, ascendancy_key?, target_level?, game_version,
       note?, archetype_key?, created_at, updated_at,
       document JSON  -- passives[], skills[], inventory_slots[]
```

Rationale: the aggregate is always read whole and written whole, and the MVP has
no query of the form "which builds use skill X". Child tables would add three
tables, ordering maintenance and import sequencing for no return. If that query
arrives later, it becomes a generated column.

`edit_token` is stored hashed only, `share_slug` is unguessable — both from part
1. `game_version` is a real field, not a label: it defaults to the version of the
last catalog sync and the user can change it.

### Curated knowledge

Not in the database, but version-controlled in the repository under
`config/knowledge/`. It is our own text and not a GGG data export, so it may be
committed — unlike catalog snapshots.

```
archetypes.yaml          key, name, since, until, expected building blocks
                         (term patterns, stat keywords, mandatory keystones)
interactions.yaml        key, since, until, unique?, skill?/term?,
                         kind (enables|modifies|forbids|synergy),
                         severity, text, source
support_requirements.yaml  support_id, since, until, requires[], excludes[],
                         severity — overrides and completes what the
                         support_text parser cannot express
inventory_slots.yaml     inventory_id ("Weapon1"), allowed item classes —
                         the map no upstream source provides
```

Interactions between uniques and skills are their own entry type alongside
archetypes — they are the part no derivation from tags would ever find.

`Advice` sees catalog and knowledge exclusively through `CatalogPort` and
`KnowledgePort`. No Doctrine, no file access inside the engine.

## Rules engine (part 2, confirmed)

### Shape

Signature: `(BuildSketch, CatalogPort, KnowledgePort) -> Finding[]`.

Every rule is its own class behind a `Rule` interface, registered by service tag,
stateless and unaware of the other rules. The engine collects and sorts stably by
severity and target.

```
Finding: rule (rule ID), severity (error|warning|hint),
         target (passive:<id> | skill:<idx> | support:<idx>.<idx>
                 | slot:<inventory_id> | build),
         message, origin (derived | curated:<key>),
         source_note?  (source text from the YAML),
         catalog_state (timestamp and version from catalog_sync),
         fix?  (patch against the sketch, for the apply button)
```

`origin` and `catalog_state` are the provenance: the user can see whether a
finding follows from game data or from curated opinion, and which data status it
rests on.

**Curated beats derived.** If a curated entry shares a target with a derived
suggestion, it displaces it. The severity of curated entries lives in the YAML,
not in code — readjusting after a patch is then data maintenance rather than
shipping logic.

**Without a catalog** the engine returns no findings but exactly one:
`catalog.unavailable` as a hint. That is measure 8 from part 1, made visible
inside the engine.

### Version binding

The `Rule` interface carries `since` and `until` as game versions (open-ended
upwards allowed); the engine filters against the build's `game_version` before
the run. The same two fields appear in `archetypes.yaml` and `interactions.yaml`,
so curated knowledge can age out instead of becoming wrong.

If the build version and the catalog version differ, that is itself a finding —
a hint, not an error: the check ran against data other than the data the build
was meant for.

Worked example: a support gem could only be socketed once per character in 0.1
and 0.2; since 0.3 that restriction is gone. The rule
`support.used_twice_in_build` therefore stays in the catalogue, but with
`until: 0.2`.

### Rule catalogue (as of 0.5.5)

Errors — mechanically broken:

- `passive.unknown`, `gem.unknown`, `unique.unknown`, `slot.unknown` — ID not in
  the catalog
- `passive.disconnected` — node not connected to the class start, checked via
  `catalog_passive_edge`
- `passive.budget_exceeded` — more points allocated than target level plus quest
  points allows (needs tolerance, see "Open points")
- `ascendancy.mismatch` — ascendancy node without an ascendancy, or from a
  different one
- `ascendancy.budget_exceeded`
- `support.requirement_unmet` (curated only) — the support's requirement is
  contradicted by the skill, per `support_requirements.yaml`. The parsed variant
  of this check is a warning, not an error; see below
- `support.socket_limit` — more supports than sockets
- `unique.slot_mismatch` — the unique does not fit this inventory slot, judged
  against `inventory_slots.yaml` and the unique's `item_class`
- `level_interval.invalid` — end before start, or outside 0–100 (the corpus
  contains intervals starting at 0)
- `support.used_twice_in_build` — up to 0.2 only, see version binding

Warnings — gaps and uncertainty:

- `attributes.unmet` — attributes from tree and level do not cover a gem
  requirement. Deliberately a warning: gear is not modelled and can close the
  gap
- `spirit.overcommitted` — spirit is the PoE2 resource for persistent buffs,
  auras and minions. A warning rather than an error, because most of it comes
  from gear and ascendancy, which the format does not carry
- `weapon.mismatch` — PoE2 skills are tightly bound to weapon types (crossbow,
  spear, staff). The strongest derivable warning there is
- `weapon_set.*` — the format carries `weapon_set` 0..2 because PoE2 allows
  weapon-set-specific passive allocation. Checks for invalid or unused set
  assignment
- `support.requirement_unmet` (parsed) — the same check driven by the
  `support_text` parser. A warning rather than an error, because coverage is
  partial by measurement: 97% of supports yield a clause, but the clause is not
  always expressible as a term comparison
- `unique.name_ambiguous` — the imported `unique_name` matches more than one
  unique (`Grand Spectrum`, `Grip of Kulemak`, `Guiding Palm` as of 0.5.5)
- `archetype.missing_block` — curated, active only when an archetype is set
- `interaction.forbidden` — curated, `kind: forbids`

Hints — suggestions:

- `support.suggestion` — derived from `recommended_supports`, filtered to those
  not yet socketed
- `passive.suggestion` — derived: nodes a short tree distance away carrying a
  wanted stat keyword
- `interaction.synergy` — curated: unique ↔ skill, `kind: enables|modifies|synergy`

### Deliberately dropped (PoE1 thinking)

- `mobility.no_movement_skill` — PoE2 gives every character the dodge roll.
  Movement skills are the exception, not a mandatory building block.
- `defense.no_resistances` — in PoE2 resistances come almost entirely from gear;
  the tree barely carries them. Their absence from the tree is not a finding.
- `defense.no_life_or_es` — same pattern.

Defences are fundamentally uncheckable through `.build`, because the format has
no rares. They move entirely into the curated part, as an archetype expectation
rather than a derived rule.

## User journey

No login, no account. Two ways in: create a new build, or upload an existing
`.build` file (or paste the JSON).

The editor has four areas:

1. **Header** — name, class, ascendancy, target level, `game_version`, note,
   optionally an archetype. The archetype is the switch that arms the curated
   checks.
2. **Passive tree** — the rendered tree, click to allocate, with search and a
   list of allocated nodes beside it so the ids that findings target stay
   visible. Each node takes an optional `level_interval`.
3. **Skills** — main and secondary skills with their supports, level interval and
   free additional text.
4. **Equipment slots** — optionally one unique per slot, by name. No rares, no
   numbers — exactly the scope of the format.

Alongside it sits the **findings list**, refilled over Turbo on every change.
Three severities; every finding is clickable and jumps to its target; every
finding shows its provenance. Suggestions carry an apply button that changes the
sketch directly.

A separate **catalog view** serves browsing and searching gems, supports,
uniques and tree nodes; from there items can be pulled into the open build.

To finish: **share** produces a read-only link and, separately, the edit link.
**Export** downloads the `.build` file, with the storage path for Windows and
SteamOS shown next to it.

Without catalog data all of this stays usable — only names, icons and findings
are missing. The footer carries the GGG notice.

## Testing approach (part 2, confirmed)

Principle: TDD, test before code. The weight sits where every patch forces
readjustment — in the rules engine.

**Unit, `Advice`.** One test class per rule against an in-memory fake
`CatalogPort` and fake `KnowledgePort`, without a database and without the
kernel. What is asserted is the identity of the finding — rule ID, severity,
target — and **not the message text**: texts get rephrased and later translated,
and tests must not break on that. Version binding gets its own tests: the same
sketch, two `game_version` values, different sets of findings.

**Unit, `Interchange`.** Pure mapping tests document ↔ aggregate, no database.

**Contract tests for `.build`.** A corpus of hand-written files under
`tests/fixtures/build/`, valid and broken, shaped after the real files (see
"Confirmed against real build files"). Alongside them an optional suite reads
whatever real exports lie in the ignored `var/sample`: real files never enter
the repository, but when they are present the reader is checked against reality
instead of against a fixture we wrote ourselves. Guaranteed property: import → export →
import is stable from the second pass on. Failure cases — broken JSON, missing
required fields, wrong types, unknown fields — produce typed errors, never
escaping exceptions. Plus a test that the output contains only fields the GGG
documentation lists for version 1.

**Functional (Symfony, real MariaDB in CI).** The controller paths: create, open
by share slug, export, upload, search the catalog. Explicit dedicated tests for
the edit token — missing, wrong, correct — because a mistake there silently
exposes data. No SQLite shortcut: JSON and generated columns behave differently
from MariaDB, so a green SQLite run would be worthless.

**E2E (Playwright), deliberately small.** Only paths that cannot be checked
without JavaScript, target size about six: allocate a node and a finding appears;
apply a suggestion and the sketch changes; export downloads a file; upload fills
the editor; a read-only link shows no editing controls. Preconditions are set by
a console command that creates a build with its tokens and exists only under
`APP_ENV=test`.

**Catalog sync — where licensing reaches into the test setup.** Real excerpts
from RePoE or the skill tree export must not live in the repository; that would
be exactly the redistribution measure 5 forbids. The normaliser is therefore
tested against **synthetic** files that mimic the shape of upstream, not its
content. Alongside them, exactly one test that really pulls from upstream —
skipped by default, run by hand, part of the version acceptance. It checks not
content but whether the shape still holds.

**Gate:** PHPUnit, PHPStan at its highest level, coding standard check,
Playwright. CI runs everything except the upstream test.

## Iteration plan (part 2, confirmed)

Three principles set the order: the named risk first, every iteration ends on
something usable, and anything the 1.0 jump devalues is built as late as
possible.

### Phase EA (September–December 2026, against 0.5.5, website not yet launched)

**Step 0 — key mapping spike. Done 2026-09-11, see "Step 0 findings".** Outcome:
no mapping layer needed for passives or gems; the `Inventories` IDs have no
upstream source and become a curated map; support requirements come from a
parser over `support_text` plus a curated override file.

**Iteration 1 — round trip.** Symfony skeleton, module `Build`, module
`Interchange`, upload and paste, storage, unchanged download, share slug and edit
token, read-only view. No catalog, no rules, no editor. Acceptance is not a green
test but this: a file produced by the tool is read by PoE2.

**Iteration 2 — catalog.** Sync commands, normaliser, tables, the `catalog_sync`
log, `CatalogPort`, search and browse view. From here on builds show names and
icons instead of IDs.

**Iteration 3 — editor.** Editing over Turbo and Stimulus: skills, supports,
slots, level intervals, planning fields — and the rendered passive tree, which is
the primary way nodes are allocated. Revised 2026-09-11: the tree was originally
deferred to iteration 8 as a later nicety. It is the main interaction of the
editor, so a searchable node list alone would not be a usable editor. The list
survives beside the tree, because findings target node ids and those ids have to
stay readable.

**Iteration 4 — rules engine, derived part.** `Advice`, `Rule` with version
filters, the error and warning rules, the findings list, jump-to-target,
provenance display. `KnowledgePort` exists but holds only examples.

**Iteration 5 — suggestions and the knowledge scaffold.** `support.suggestion`,
`passive.suggestion`, the apply button, curated interactions with a handful of
entries. Content stays thin on purpose.

### Phase 1.0 (December 2026–January 2027, website launch)

**Iteration 6 — version acceptance for 1.0.** Re-sync, proofs re-stamped, a
walkthrough of the rule catalogue, a check of whether `.build` stayed
format-stable, and a decision about test builds from the EA period —
presumably a hard cut.

**Iteration 7 — content.** Fill archetypes and interactions for 1.0, plus the
full joint walkthrough of warnings and hints.

**Iteration 8 — launch readiness.** Polish; secret scan; `LICENSE` and `NOTICE`
present; footer notice visible; `paid_credits` off; deployment; website launch.
Re-check the tree renderer against the 1.0 layout, which is expected to move
node positions wholesale.

Not included and not planned for this arc: LLM, ladder meta, prices, rares and
crafting, login.

## Open points

### Proofs, to be stamped per game version

These belong in `docs/version-acceptance.md`; first pass 0.5.5, second 1.0. A
proof without a version stamp is not a proof — it expires unnoticed at the next
patch.

1. Passive points: points per level plus quest points, exact figure
2. Ascendancy points: count and source (trials)
3. Support sockets per skill gem: what the number depends on
4. Spirit sources: how much sits on the tree, how much only on gear
5. Weapon binding of skills: whether RePoE models it as a tag or a requirement
6. Orbit radii and slot counts for the tree renderer, measured above on 0.5.5
   rather than taken from any documentation
7. Which of the 12 classes the tree export lists (Marauder, Witch, Ranger,
   Duelist, Shadow, Templar, Warrior, Sorceress, Huntress, Mercenary, Monk,
   Druid) are actually selectable in 0.5.5, before class-to-start-node logic
   relies on the list
8. Which spelling of `unique_name` the game accepts for the three ambiguous
   uniques, and whether `.build` offers any disambiguation at all

Settled: uniqueness of supports per character — applied up to 0.2, lifted in 0.3.
Settled: the `.build` key spaces for passives and gems match their sources
exactly (step 0).
Settled: `weapon_set` is 1 or 2, naming the numbered slot halves
`Weapon1`/`Offhand1` and `Weapon2`/`Offhand2` (real-file corpus, 0.5.5).

### Notes for the later rule walkthrough

- `passive.budget_exceeded` can legitimately be exceeded: via a rune, and via the
  league mechanic "Martyr of the First Edict", which grants every player in a
  league an extra point. The rule needs a tolerance or a user field for extra
  points, otherwise it fires on correct builds.
- A full joint walkthrough of warnings and hints, after the first sync and
  against a concrete version.

### Technically open

- Whether `.build` survives the 1.0 jump format-stable is unknown.
- The `support_text` parser needs an accuracy measurement against a
  hand-checked sample before its findings are shown, and a report of every
  clause it could not extract, so the curated override file can be kept
  honest.

### Before the website launch (January 2027)

Secret scan across the whole repository, `LICENSE` and `NOTICE` present, footer
notice visible, `paid_credits` switched off.

Note that the repository itself is already public, so the licensing measures and
the secret checkpoint apply from today rather than from the launch date.

## Design mockups

The four core screens — start page, build editor, catalog, shared read-only view
— are drawn as Design Component artboards under `design/`, and seeded into a
published canvas for review. The `.dc.html` files and `canvas.json` are the
source; the seeded page is a build artifact and is ignored.

Two constraints shaped them and are worth keeping in mind when the real UI is
built:

- **The mockup tree is synthetic.** It uses the real orbit geometry but invented
  positions and names. Real node coordinates in a file committed to a public
  repository would be exactly the redistribution measure 5 forbids. The real
  tree renders at runtime from the ignored snapshot.
- Gem paths, unique names and `.build` ids in the mockups are real 0.5.5 values
  from step 0, so the layout is sized against the strings it will actually carry.
