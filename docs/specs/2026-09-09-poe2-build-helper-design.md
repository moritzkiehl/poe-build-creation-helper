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

Deliberately out of the MVP: ladder meta, prices, login, LLM.

Revised 2026-09-11: **craftable item mods are in** after all. The original line
excluded them because the MVP does no rare crafting, and that reasoning still
holds for the rules engine — see the limit below. What changed is the goal: item
recommendations need to know what a base can carry. Synced are the 2586
`domain: item` prefixes and suffixes with their readable text and the base tags
they roll on. Unique-generation mods stay out: 10447 exist with readable text,
and nothing published links any of them to a unique item.

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
SteamOS, per GGG's documentation,
`/home/deck/.local/share/Steam/steamapps/compatdata/2315204395/pfx/drive_c/users/steamuser/Documents/My Games/Path of Exile 2/BuildPlanner`.

The app id in that documented path is not what a Linux Steam install uses. On
the development machine (2026-09-11) Path of Exile 2 is app id **2694490**:

```
~/.local/share/Steam/steamapps/compatdata/2694490/pfx/drive_c/users/steamuser/Documents/My Games/Path of Exile 2/BuildPlanner
```

So the export page must not print one hardcoded Linux path. It should name the
Windows path, explain the compatdata shape, and say that the app id is found
under `steamapps/compatdata` next to a `Path of Exile 2` prefix. The
`BuildPlanner` directory does not exist until the feature is first used, so the
instructions have to say it may need creating.

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

  **Reversed 2026-09-12.** The paragraph above no longer holds. The tree is
  edited from the canvas and from nowhere else: the allocated list and its remove
  controls became the stats overview, and the search box lost its allocate
  buttons too. Search now finds a node and highlights it on the canvas.

  The reason is not indifference to the constraint but a reassessment of it: the
  passive tree **in the game itself** is not keyboard or screen-reader operable,
  so building a parallel text path here invents a requirement the medium does not
  have, and costs UI that a player then has to read past. The rest of the editor —
  header, skills, supports, equipment, history — stays ordinary forms and keeps
  working without JavaScript. See "Iteration 3 refinements".
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

### What the item data can and cannot do (2026-09-11)

Established by reading the 0.5.5 files rather than by assuming:

- **A unique has no modifiers in any published source.** `uniques` carries name,
  class, size and artwork — nothing else. 10447 unique-generation mods exist in
  `mods` and 7905 have readable text, but nothing maps them to the 441 unique
  items; fuzzy name matching scores 27 hits in 200, which is noise. So every
  claim the interface makes about what a unique *does* comes from
  `interactions.yaml`, never from derivation.
- **Unique names are genuinely ambiguous, and not through alternate art.** Not
  one of the 449 entries is flagged as alternate art. Grand Spectrum (3),
  Grip of Kulemak (5) and Guiding Palm (3) are distinct items sharing a name and
  differing in modifiers we cannot see. Since `.build` identifies a unique by
  name alone, `unique.name_ambiguous` is unavoidable.
- **Crafting mods are usable.** 2586 item prefixes and suffixes, 2558 with
  readable text, each carrying spawn weights per tag; 42 of those 54 tags occur
  on base items, so "what can roll on this base" is a real query and is answered
  by joining `catalog_mod_spawn_tag` to `catalog_base_item_tag`.
- **The hard limit stays.** A `.build` file carries no rare items and no
  modifiers, so the rules engine can never see a mod on a player's build. Mod
  data serves browsing, crafting guidance and curated advice. It can never
  validate what someone actually plans to wear, and the interface must not
  suggest otherwise.

Stored from a real run: 449 uniques, 2401 equippable bases, 10522 base tags,
2558 mods, 3322 spawn links.

### Re-indexing per patch

Path of Exile 2 patches on roughly a four-month cycle, and every patch means a
full re-sync and re-index of all five sources. Three consequences are built in
rather than left to discipline:

- Each `catalog_sync` row stamps the game version, so rows can be told apart and
  the version-gated rules have something to compare against.
- Each source rebuild runs in one transaction. Readers keep seeing the previous
  catalog until it commits, so a sync never exposes an empty or half-filled
  table.
- Every run revalidates first, so a source that has not moved costs one request
  instead of a download.

Rare and heavy beats frequent and light here: the work per patch is a checklist,
not a background job, and it belongs with the version acceptance.

### The support_text parser, measured (2026-09-11)

Built and run over all 614 support gems that survive placeholder filtering in
0.5.5:

| Outcome | Count | Share |
|---|---|---|
| Every clause understood | 523 | 85.2% |
| Some clauses understood | 18 | 2.9% |
| Nothing extracted | 73 | 11.9% |

45 distinct clauses resist extraction, led by "any skill that deals damage",
"Skills which already have a cooldown", "Skills you use yourself" and "Skills
which create Ground Surfaces" — requirements no term vocabulary can express.
`app:catalog:sync --report-unparsed` lists them all, which is the report the
testing section asks for.

This is the measurement that justifies the severity split: a parser wrong about
one support in seven must not produce errors. What it produces is 821
requirement rows across 541 gems, every one carrying `origin = parsed` and the
clause it came from, so a wrong extraction can be reviewed rather than guessed
at.

Two corrections the real data forced, both caught by measuring rather than by
reading:

- Placeholder gems are marked **`[DNT]`** and **`[DNT-UNUSED]`**, in brackets, in
  the display name. A filter looking for a bare `DNT` prefix matches none of the
  77 entries that carry it. Earlier notes said 22; that number counted only the
  supports without a leading clause.
- At least one gem lists the same support twice under `recommended_supports`
  (SkillGemColdSnap). The pair is a primary key, so the duplicate aborted the
  entire sync until the normaliser de-duplicated.

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

**Iteration 1 — round trip. Built 2026-09-11.** Symfony skeleton, upload and
paste, storage, unchanged download, share slug and edit token, read-only view.
No catalog, no rules, no editor.

Status: **provisionally accepted.** Fourteen game-exported files round-trip
byte-exact, and a build exported through the app into the game's BuildPlanner
directory is byte-identical to the file the game wrote. The full acceptance
condition — PoE2 actually reading a file the tool produced — is still open and
will be checked later. Until it is, "the game accepts our output" remains an
inference from byte equality, not an observation.

The layout follows Symfony conventions (`src/Entity`, `src/Repository`,
`src/Controller`) rather than a directory per module; the five modules of part 1
survive as namespaces and as the dependency rule, not as folders.

**Iteration 2 — catalog. Built 2026-09-11.** Sync command, normalisers, tables,
the `catalog_sync` log, `CatalogPort`, search and browse view.

`CatalogPort` carries only the questions the rules engine asks. Browsing goes
through a separate `CatalogSearch` instead, so paging and filtering never grow
the port with things no rule needs. Two implementations exist — one backed by
Doctrine, one in memory — and both are held to the same test contract, because a
fake that drifts from the real one makes every rule test worthless.

**Iteration 3 — editor.** Editing over Turbo and Stimulus: skills, supports,
slots, level intervals, planning fields — and the rendered passive tree, which is
the primary way nodes are allocated. Revised 2026-09-11: the tree was originally
deferred to iteration 8 as a later nicety. It is the main interaction of the
editor, so a searchable node list alone would not be a usable editor. The list
survives beside the tree, because findings target node ids and those ids have to
stay readable. Design brainstormed and confirmed 2026-09-11, see "Iteration 3
design — editor".

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

## Iteration 3 design — editor (2026-09-11)

Brainstormed and confirmed with the user 2026-09-11, architectural path (a new
subsystem: canvas tree renderer, custom hit-testing, Stimulus, the first
client/server editing flow — nothing like it exists yet; `edit.html.twig` so
far is only the iteration-1 whole-file replace form, and no `assets/` directory
exists).

### Scope

Five areas on the editor page: **Header**, **Passive tree** (canvas + accessible
node list), **Skills**, **Equipment slots**, and an **empty findings
placeholder** (a Turbo Frame iteration 4 fills; nothing here renders real
findings yet). A sixth area, **History**, is new versus the original user
journey: a per-build timeline of edits with revert and named snapshots — added
because autosave-per-action means nothing is ever explicitly "saved," so
undoing a mistake needs its own mechanism. Catalog browsing and share/export
links are already built (iterations 1–2) and are untouched.

Out of scope here: Advice findings content (iteration 4), suggestions/apply
button (iteration 5), curved-arc edge rendering for the tree (deferred as a
visual nicety, see "Passive tree rendering" below).

### Spike: class start nodes (settles proof #7, retires proof #6)

Run against the live `poe2-skilltree-export` data, 2026-09-11, nothing
committed — same method as step 0.

- All 12 classes are present in the export's `classes[]` array (`Marauder,
  Witch, Ranger, Duelist, Shadow, Templar, Warrior, Sorceress, Huntress,
  Mercenary, Monk, Druid`), each with `base_str`/`base_dex`/`base_int` and an
  `ascendancies[]` list (id, name, flavour text).
- They share only **6 physical start positions** on the tree. Each start node
  (a child of the synthetic `root` node) carries `classStartIndex: [a, b]`, a
  pair of indices into `classes[]` — e.g. node `witch595` has
  `classStartIndex: [1, 7]`, serving both `Witch` (index 1) and `Sorceress`
  (index 7).
- Ascendancy start nodes are separately flagged `isAscendancyStart: true` on
  ascendancy-tagged nodes (669 nodes carry `ascendancyId`). Not currently
  captured by `PassiveTreeNormalizer`/`CatalogPassive`.
- Every one of the 4912 real nodes (root and the 240 `id: null` filler nodes
  excluded, matching the normalizer's existing exclusions) carries absolute
  `x`/`y`, confirmed non-null for all of them, spanning roughly
  ±22.6k/±19k world units. This is what retires proof #6: nothing needs to be
  derived from orbit radii when every node already has a measured position.

### Data model additions

None of these touch the existing `document` JSON column or `BuildDocument` —
the byte-exact round trip proven in iteration 1 stays untouched.

- **`build` table gains app-only columns**: `class_key`, `target_level` (int,
  nullable), `note` (text, nullable), `archetype_key` (nullable). Planning
  metadata the `.build` format has no room for; never enters `document` or
  `BuildDocument`.
- **New `catalog_class` table**, 12 rows, synced alongside the passive tree
  from the same `classes[]` array: `key`/`name`, `base_str`/`dex`/`int`,
  `ascendancies` (JSON: id + name pairs), `start_node_id` — resolved at sync
  time from `classStartIndex` (both `Witch` and `Sorceress` rows get
  `start_node_id: witch595`). Read by the `Ui` layer directly (like
  `CatalogSearch`), not through `CatalogPort` — `CatalogPort` stays scoped to
  what `Advice` asks.
- **New `build_event` table**: `id`, `build_id`, `created_at`, `action`
  (string), `payload` (JSON, the command as received), `document_after` (JSON,
  full snapshot of `.build` document + header columns together — a revert
  restores everything editable at once), `is_named_snapshot` (bool),
  `snapshot_name` (nullable). Snapshotting full state per event is a deliberate
  simplification over event-sourced replay: the `.build` document is small, so
  storing it whole is cheap, and it avoids building a replay engine to answer
  "what did build state look like at event N."

### History and revert

Persisted timeline, not a session-local undo stack — the edit-token model
means the tab can close anytime and reopening the edit link must still show
full history. A scheduled prune (a console command, run manually like
`catalog:sync` — no cron infrastructure exists) deletes `build_event` rows
older than 30 days where `is_named_snapshot = false`; named snapshots are kept
indefinitely. **Revert appends a new event** (`action = 'revert'`, payload
names the target event, `document_after` copies that event's snapshot) rather
than truncating forward history — nothing is ever deleted by reverting, and
the log stays a complete audit trail.

### Command flow

One route, `POST /build/{slug}/{token}/act`, body `{action, payload}`. The
controller resolves `Build` via the existing slug+token check, maps `action` to
a small Command DTO via a `match` (a dozen cases doesn't earn a registry:
`AllocatePassive`, `DeallocatePassive`, `SetPassiveLevelInterval`, `AddSkill`,
`RemoveSkill`, `SetSkillField`, `AddSupport`, `RemoveSupport`,
`SetInventorySlot`, `ClearInventorySlot`, `SetHeaderField`, `CreateSnapshot`,
`Revert`), and dispatches it on **symfony/messenger**'s default synchronous
bus (no transport — every command is handled in the same request). Decided
over a hand-rolled dispatcher because the project now has a standing rule
(see the project's `CLAUDE.md`) to prefer PSR-compliant, already-installed
Symfony packages over inventing project-specific equivalents.

Each handler loads `Build`, computes the new state (`toDocument()`/
`applyDocument()` for `.build`-format changes, a direct setter for the app-only
header columns), persists, and appends a `BuildEvent` row.

The controller always responds with a Turbo Stream
(`text/vnd.turbo-stream.html`) containing: the updated allocated-node-list
frame, the updated history frame, and a re-rendered
`<script type="application/json" data-editor-target="state">` tag carrying the
current allocated-node-id set and header summary. That script tag is the one
bridge from server-rendered HTML to the canvas's non-DOM state — the Stimulus
tree controller watches it via a target-connected callback and resyncs +
redraws whenever Turbo replaces it, regardless of whether the change came from
a canvas click, a node-list button, or a history revert. One response shape
for every command, rather than a second parallel client-state sync path.

### Passive tree rendering

- **Tree data delivery**: a new cacheable `GET /catalog/tree.json`, a compact
  array format (not object-keyed, to save bytes over 4912 nodes) of
  `{id, name, kind, ascendancy_key, pos_x, pos_y}` per node, `{from, to}`
  edges, and the 12 `catalog_class` rows. HTTP-cached with an
  `ETag`/`Last-Modified` derived from the latest `catalog_sync` timestamp for
  `passive_tree`, so it refetches only after a re-sync.
- **Rendering**: a Stimulus `tree` controller owns a `<canvas>` 2D context,
  loads `/catalog/tree.json` once, and draws edges as straight lines between
  node centers — curved arcs matching the in-game renderer are a visual
  nicety, explicitly deferred, not a gap — and nodes as circles/icons colored
  by `kind` and allocation state. Pan/zoom is a plain affine transform
  (translate + scale) applied before drawing. Only nodes with
  `ascendancy_key === null` or matching the build's selected ascendancy are
  drawn; other ascendancies' nodes are filtered out client-side.
- **Hit-testing**: a uniform spatial grid (bucket every node by
  `floor(x/cellSize), floor(y/cellSize)`, cell size picked from measured
  typical inter-node spacing), built once from the loaded data. A click
  converts screen → world coordinates through the inverse pan/zoom transform,
  checks the containing cell and its 8 neighbors, and picks the nearest node
  within a small threshold — never a full 4912-node scan. Plain project JS,
  not a library; a quadtree would be over-engineering for a static,
  one-time-loaded point set this size.
- **Class start nodes**: the node matching the build's selected class's
  `start_node_id` is drawn distinctly, always-allocated and not
  clickable/toggleable, and used to center the initial camera position.
- **Accessible node list**: a normal server-rendered Turbo Frame with two
  parts, matching the "search and a list of allocated nodes" the confirmed
  user journey asks for. A search box queries `catalog_passive` by name/id
  (server-side, over all 4912 nodes) and lists matches with an allocate
  button; below it, a `<ul>` of currently-allocated nodes has remove buttons.
  (**Superseded 2026-09-12**: the allocated list and its remove buttons became
  the collective stats overview, and the search box lost its allocate buttons —
  search now locates and highlights a node on the canvas instead. The tree is
  edited from the canvas only. See "Iteration 3 refinements".)
  Both post to the same `/act` endpoint as the canvas. Zero custom JS; the
  same Hotwire form-in-a-frame pattern as Header/Skills/Slots. This is the
  load-bearing accessible path: nothing in the canvas is reachable by
  keyboard or a screen reader on its own, and findings target node ids that
  must stay readable somewhere in the DOM.

### Skills, equipment slots, header forms

Plain Turbo Frame forms posting to `/act`, no custom JS:

- **Header**: name, class (dropdown from `catalog_class`), ascendancy
  (dropdown filtered to the selected class's `ascendancies`), target level,
  `game_version`, note, archetype.
- **Skills**: a repeatable list of skill rows, each an autocomplete over
  `catalog_gem` (iteration 2's `CatalogSearch`) for the main gem, a
  `level_interval` pair, and a nested repeatable list of supports (same
  autocomplete, filtered to support-type gems) each with its own
  `level_interval`. **Correction to the "User journey" section above**: it
  says skills carry "free additional text," but the measured corpus in "The
  `.build` format as the pivot" states `additional_text` is never present on
  skills — only on passives and slots. Going with the measured fact; the
  skills form has no additional-text field.
- **Equipment slots**: the fixed 14-slot vocabulary from
  `inventory_slots.yaml`, each an optional autocomplete over `catalog_unique`
  by name, plus `level_interval` and `additional_text`. No slot-fit validation
  yet — that is `unique.slot_mismatch`, iteration 4.

### Testing

- `PassiveTreeNormalizer`/`CatalogClass` sync: unit tests against a fixture
  extended with `classStartIndex`/`isAscendancyStart`, mirroring the existing
  `tree-shape.json` pattern.
- Each command handler: a unit test (apply → assert `Build` state + the
  resulting `BuildEvent` row).
- The `/act` endpoint: a functional test per command type, plus one for
  revert and one for the 30-day prune command.
- The hit-test grid is the one algorithmic piece of client code and the one
  part not covered by PHPUnit. A minimal JS test setup (e.g. vitest) is added
  for pure-logic modules like this — no DOM/browser automation needed, since
  the screen→world/grid-lookup/nearest-node math is itself DOM-free. First use
  of a JS test runner in this project.

## Iteration 3 refinements (2026-09-12)

**Split into two slices, decided 2026-09-12.** Everything below is designed, but
only the first slice is built next:

- **Slice A (next).** Level-interval modes; the node hover; the collective stats
  overview; the Instilled Modifier search beside `Amulet1` — pulled in because
  the overview's distilled section has no data without it. Plus the three gaps
  iteration 3 left against this spec: the support-gem autocomplete, the
  unique-name autocomplete, and making `game_version` editable. `catalog_passive`
  gains only `recipe`; the node tuple goes from six fields to eight.
- **Slice B (later).** The tree edited from the canvas only, with search becoming
  find-and-highlight, and the four allocation-legality rules. That is where
  `keystones_in_radius` and `unlock_constraint` are added and the tuple reaches
  ten. Scope added 2026-09-12, after the format was re-checked against the
  documentation and the corpus: deallocation cascades as one event, the declared
  jewel keystone gets its app-only pseudo-slot, weapon-set passives get their own
  allocation mode, colours and summary split, and equipment slots stop being
  keyed by `inventory_id` alone — which is losing charm and flask entries today.
  See the dated subsections at the end of this section.

  **Split in two, decided 2026-09-12**, because that scope roughly doubled the
  slice and its two halves share no file, test or interface:

  - **B1 — the tree.** Canvas-only editing, search as find-and-highlight, the
    four legality rules with `keystones_in_radius` and `unlock_constraint`
    synced, cascading deallocation, and weapon sets (mode, colours, per-set
    connectivity, three-way summary). The risky half.
  - **B2 — equipment.** The declared jewel keystone's pseudo-slot and the
    `(inventory_id, slot_x, slot_y)` re-key that stops losing charm and flask
    entries. Mechanical, and reviewable without any tree context.

  B1 runs first. The ordering is a judgement call rather than a dependency —
  B2 fixes data loss that exists today, but it touches no code B1 touches, so
  running it second costs nothing but the delay.

The split follows the shape of the risk. Slice A changes what the editor shows;
slice B changes how it behaves under the cursor, and that is worth judging after
the tooltip and overview have been used rather than from a document. Until slice
B lands, the search box keeps its allocate buttons — only the per-node remove
controls go, with the list they lived on.

Decided after the first look at the built editor in a browser, and after the
catalog was synced for the first time on the development machine (4912 passives,
1120 gems, 5408 items). These change confirmed decisions, so they are recorded
here rather than folded silently into the iteration 3 section.

### Level intervals get two modes, derived from the document

Per-passive level intervals were judged something "nobody would configure". They
are not removed, because the format requires `level_interval` on every passive
and because a game-exported file may legitimately carry different values per
node — flattening those on import would destroy data the round-trip guarantee
exists to protect.

Instead there are two modes, and **the mode is derived from the document, never
stored**. A stored mode could disagree with the document it describes; a derived
one cannot.

- **Passives.** If every allocated passive shares one interval, the editor opens
  flattened: a single From/To seeded from the widest span (lowest `from`, highest
  `to`), written to every passive on submit. Otherwise it opens per-passive.
  Since the flat allocated list is gone, per-passive controls live inside the
  overview itself: each family group expands to its member nodes, each with its
  own From/To. Those rows carry no remove button — deallocating is canvas-only in
  both modes, deliberately.
- **Skills.** If every support's interval already equals its parent skill's, the
  editor opens flattened: each skill keeps its own From/To and its supports
  inherit it, shown read-only. Otherwise each support keeps its own control.
  Skills always differ from one another — only the supports collapse into their
  skill, because a skill setup comes online together while different skills do
  not.

**A toggle switches the view and writes nothing.** The rewrite happens only when
a value is submitted. Otherwise a stray click while inspecting an imported file
would flatten it.

One accepted quirk: a build edited per-passive into uniform intervals reopens
flattened. The data is identical either way, so this costs nothing but a
surprise, and avoiding it would mean storing a mode that can lie.

New actions: `passive.interval_all` (from, to) and `skill.interval_cascade`
(index, from, to).

### The node hover shows what the node actually does

The tree export carries a `recipe` field the normaliser currently discards: the
Liquid Emotions needed to instil that node. 875 nodes have one, always three
ingredients, drawn from 13 distinct emotions, and an ingredient may repeat
within a recipe (*Cold Coat* needs `LiquidEnvy, LiquidDespair, LiquidEnvy`).
`catalog_passive` gains a `recipe` column, one of three added below.

`stats` is already stored and simply was not shipped. The `tree.json` node tuple
therefore grows from six to ten by **appending**, never reordering:
`[id, name, kind, ascendancy_key, x, y, stats, recipe, keystones_in_radius,
unlock_constraint]`. Appending keeps the canvas controller's existing
destructuring working untouched. The last two carry the legality data the canvas
needs to grey out nodes it cannot allocate; see "Allocation is checked, not
merely drawn" for what the server does with the same facts.

The measured cost is under "Catalog and payload" below, kept in one place so the
two halves of this section cannot drift apart.

Two pure functions, shared by the tooltip and the overview:

- **Stat markup.** `[Key|Display]` renders as `Display`, `[Key]` as `Key`, so
  `40% reduced [BuffMagnitude|Magnitude] of [Ignite|Ignite] on you` reads as
  written.
- **Emotion names.** `ConcentratedLiquidSuffering` becomes
  `Concentrated Liquid Suffering` by splitting camel case. This is a mechanical
  transformation, not a claim about GGG's own wording; if their display names
  differ, this becomes a curated map like `inventory_slots.yaml`.

The tooltip is a positioned DOM element, not the `title` attribute — that has a
browser-imposed delay of about a second and cannot format several lines.

### The allocated list becomes a collective stats overview

Allocated passives group by **id family**: the id with trailing digits and any
trailing underscore removed, so `area_attacks38` and `area_attacks39` are one
group and `melee22_` joins `melee`. The synced tree has 506 such families across
4912 nodes; the largest are `criticals` (99), `attributes` (90), `strength` (82).

Within a family, stat lines are summed by a rule holding **no game knowledge**:
normalise a line by replacing its numbers with a placeholder, and lines sharing a
normalised form that contain exactly one number are summable, their numbers
added. Everything else is listed verbatim with a count.

The rule can therefore never be wrong about a mechanic — it can only decline to
sum, and a declined line appears in full on screen. That visible degradation is
why this needs no accuracy measurement, unlike the `support_text` parser, whose
failures are invisible at the point of use.

Grouping is by id, so two families that both grant critical chance stay separate;
there is no build-wide total. That was asked for deliberately.

**Distilled nodes list separately**, above the tree families. The reason is a
mechanic, not tidiness: **an Instilled Modifier costs no passive point** — it is granted
by the amulet — so folding instilled nodes into the tree's own figures would
misstate both what the tree gives and what it cost. Owner-confirmed for 0.5.5;
this is not derivable from catalog data, so it is curated knowledge and carries a
version stamp like every other such claim. The same fact governs
`passive.budget_exceeded`, which must not count them (see "Notes for the later
rule walkthrough"). See "Instilled nodes are declared, not inferred" below for why this
cannot be inferred.

### The tree is edited from the canvas only

The search box keeps its place but loses its allocate buttons: it now finds a
node and highlights it on the canvas. Nothing in the DOM allocates or
deallocates a passive any more.

The justification is the medium, not convenience. The passive tree **in the game
itself** is not keyboard or screen-reader operable, so a parallel text path here
invents a requirement the game does not meet, at the cost of UI every player has
to read past. The rest of the editor stays ordinary forms and still works with
JavaScript disabled.

### Allocation is checked, not merely drawn

Clicking a node no longer simply adds it. Allocation is refused unless the node
is legal for this build, and the check runs **on the server**, in the command
handler — the canvas greys illegal nodes for immediate feedback, but the
endpoint is what enforces. Four rules, all derivable from data already synced:

1. **Connectivity.** A node must be adjacent to the allocated set, rooted at the
   class's start node — and **per weapon set**: a set 1 node reaches the start
   through shared or set 1 nodes only, likewise set 2. See "Weapon sets are
   allocated, coloured and summed separately".
2. **Unlock constraints.** 200 nodes carry an explicit `unlockConstraint`. 197 of
   them are the `oracle_*` nodes gated behind `AscendancyDruid1Notable2` — *The
   Unseen Path*, "Walk the Paths Not Taken" — and require both the Druid1
   ascendancy and that notable **allocated**, not merely reachable
   (owner-confirmed, 0.5.5). The other three are notable chains: *Path of the
   Renegade* (Mutewind Agility, Brinerot Ferocity, Redblade Discipline), *The
   Hollowkeeper*, and Huntress's *Sacred Unity*.
3. **Entwined Realities.** `AscendancyDruid1Notable1` permits non-keystone
   passives near a keystone to be allocated disconnected. The radius needs no
   geometry: 1573 nodes carry `keystonesInRadius`, the precomputed tree-keys of
   the keystones covering them, across 33 distinct keystones.

   **Two things must both be allocated** (owner-confirmed, 0.5.5): the notable
   itself, *and* the keystone whose radius is being used. Allocating the notable
   alone unlocks nothing; it arms the mechanism, and each keystone switches on its
   own neighbourhood as it is taken. Keystones are not themselves exempt — the
   stat reads "Non-Keystone Passive Skills" — so a keystone still has to be
   reached by ordinary connection before it can enable anything.
4. **A declared jewel.** A build may nominate **one** keystone as jewel-enabled,
   granting the same radius exception around it.

Rules 3 and 4 differ only in what enables a keystone, so they are one mechanism
with two sources. That is the seam to extend: a further source adds a case, not a
rewrite.

**Two things deliberately not supported, because the data cannot back them.**
Jewels that enable a radius around their own socket are out: nodes carry
`keystonesInRadius` but nothing equivalent for sockets, and while the 31 socket
node-keys and their coordinates exist, the radius values do not. And the app
cannot detect which jewel a player owns: `uniques.json` carries only
`id`, `name`, `item_class`, `visual_identity` and inventory dimensions — no mod
text — so although two granting mods exist in `mods.json`
(`JewelUniqueAllocateDisconnectedPassives` and
`AllocateDisconnectedPassivesDonut`), nothing links a mod to the unique carrying
it. The jewel is therefore declared by the player, and the interface says plainly
that socket-radius jewels are not modelled rather than pretending otherwise.

The `.build` format has no jewels at all — its fourteen inventory ids are
weapons, armour, jewellery, flasks and the charm belt — so none of this can be
stored in the exported document. The declared keystone is an app-only column,
like `class_key`. Confirmed against the documentation 2026-09-12; see "The
declared jewel keystone is app-only, and the format says so".

### Instilled nodes are declared, not inferred

**The game's own vocabulary**, taken from the mod data rather than from habit:
the currency is a **Distilled Emotion**, applying it produces an **Instilled
Modifier**. The tree's `recipe` field lists the three Liquid Emotions a node
costs. "Anoint" survives in some mod names and in the Oil Extractor's
description, but the live markup is `[DistilledEmotion|Instilled]`.

Which cannot be inferred. A disconnected node might be instilled, jewel-enabled,
Oracle-enabled, or a mistake; an instilled notable might equally be sitting
connected on the tree. Having a recipe is necessary but far from sufficient — 875
nodes have one, against 1192 notables, and no keystone or ascendancy node does.

Since the app models no items except uniques by name, it does not describe the
amulet at all. It records the *result*: an app-only column listing the passive
ids the player declared. It never reaches the exported file, which is correct —
the game reconstructs the modifier from the amulet, not from the tree.

**The interface** is a search field beside `Amulet1` in the equipment area,
searching the 875 nodes that have a recipe, because that is mechanically where a
Distilled Emotion is applied. The resulting passives appear in the overview's
separate distilled section, where their stats are read.

**One entry by default, with more addable and no hard cap.** A normal amulet
carries one; `UniqueMultipleAnointments1` ("Can have 3 additional
`[DistilledEmotion|Instilled]` Modifiers", `local_item_can_have_x_additional_
enchantments` = 3, required level 66) shows at least one unique exceeds that. The
app cannot know which amulet the player wears, so it does not police a limit it
cannot verify — the same reasoning as the declared jewel keystone.

**A caution about the source.** `mods.json` carries visible PoE1 leftovers — a
Blight *map* mod reading "Can be Anointed up to 3 times", a Heist chest "of
Anointments". Presence in that file is therefore not proof a mechanic is live in
0.5.5. `UniqueMultipleAnointments1` is treated as strong evidence rather than
settled fact because it uses the PoE2-style `[Key|Display]` markup that current
tree stats use.

### Catalog and payload

`catalog_passive` gains three columns, all populated at sync from fields the
normaliser currently discards: `recipe`, `keystones_in_radius` (tree-keys
resolved to stored string ids through the map the normaliser already builds), and
`unlock_constraint`.

Measured payload cost, for the node array alone: today 341 KB raw / 82 KB
gzipped; with stats and recipes 744 KB / 142 KB; with the legality fields as well
798 KB / 146 KB. The legality data therefore costs about 4 KB on the wire, and
the whole detail about 64 KB, downloaded once per game patch behind the existing
ETag. That is why everything ships with the tree instead of being fetched per
node: hover must be instant, and a request per node never can be.

### Carried into slice B: one place to register view state

Slice A left a key registered in two places. A query parameter that survives an
edit has to be rendered as a hidden field by `templates/build/_search_state.html.twig`
*and* listed in `BuildEditorController::searchParams()`, which forwards it on the
non-Turbo redirect. Miss the second and the parameter is silently dropped after a
plain form POST — which happened twice in slice A: for three of the five search
terms, and again for the two interval-mode overrides. Both were found by testing,
not by reading. Slice B adds more view state, so it collapses the two lists into
one declaration that both the template and the controller read.

### Deallocation cascades, as one event

Allocation is refused when illegal; removal is not refused. Deallocating a node
that others reach the start *through* removes it **and everything that becomes
illegal as a result**, recorded as a single history event that names the count.

"Becomes illegal" rather than "loses its connection", because connection is only
the first of the four rules. Removing *The Unseen Path* leaves its `oracle_*`
nodes perfectly connected and no longer permitted; removing a keystone closes the
radius that let its neighbourhood sit disconnected. One rule for the cascade —
re-check the four rules and remove what now fails — covers all three cases, and
the alternative leaves a build the editor would refuse to let you build.

The asymmetry is deliberate. Refusing the removal would be the tidier rule, but
it makes the only way to abandon a path clicking back along it leaf by leaf,
which is the common case in planning rather than the rare one. Leaving the
orphans allocated and reporting them is the other alternative, and it is worse
for a reason of sequencing: `passive.disconnected` is an iteration 4 finding, so
until the rules engine ships nothing would tell the player anything happened.
Cascading is safe here specifically because the editor already has a persisted
history with revert-as-new-event — the undo exists before the destructive
operation does, which is the order that makes a cascade acceptable.

The cascade is computed **per weapon set**, for the reason given under "Weapon
sets are allocated, coloured and summed separately": removing a shared node can
orphan nodes in all three groups, while removing a set 1 node can orphan only
set 1 nodes.

**Nodes held by an exception are never swept.** An instilled node, a
jewel-enabled node, an Oracle node — none of them is connected in the first
place, so "lost its connection" does not describe them. The cascade walks the
connected component rooted at the class start and removes only what falls out of
it; every node whose legality came from one of the four rules' exceptions is
evaluated against that rule instead, and stays unless its own enabling condition
is what was removed.

### The declared jewel keystone is app-only, and the format says so

Settled by the documentation rather than by inference: the build planner format
describes `inventory_id` as an *Inventory table identifier* and never enumerates
the permitted values, and **jewels and jewel sockets are not documented as
representable at all**. None of the four real exported files carries one. The
declared keystone therefore cannot round-trip, and is stored in an app-only
column beside `class_key` and `instilled_passives`, omitted from the exported
file.

**Its control is a pseudo-slot in the equipment area**, labelled in the interface
as not exported. That placement is the player's decision, made 2026-09-12, and it
disagrees with the reasoning that put the Instilled Modifier at `Amulet1` —
`Amulet1` is where a Distilled Emotion is mechanically applied, whereas a jewel
pseudo-slot corresponds to nothing the format holds. The label is what keeps that
honest: a control that looks like the fourteen real slots but never reaches the
file has to say so where it is read, not in a document.

### The belt is a grid, and one entry per `inventory_id` loses data

Measured against the four real files, 2026-09-12: `Flask1` appears at `slot_x` 0
and 1 (a life and a mana flask), and `Trinket1` at `slot_x` 2, 3 and 4 — Sapphire,
Thawing, Golden, Stone and Silver Charms. `Trinket1` is the **charm belt**, and
both ids share one grid strip rather than naming one slot each. `slot_y` is 0
everywhere in the corpus, so its meaning stays unmeasured; the documentation
leaves `slot_x`/`slot_y` undefined.

`DocumentEditor::indexOfSlot()` keys on `inventory_id` alone, so it matches the
first entry and no other. In all four builds the editor therefore shows one flask
of two and one charm of three, and `clearInventorySlot` would remove only the
first. Export stays byte-exact because export does not pass through the editor,
which is why the round-trip proof never caught it and why no test does today.

The fix, folded into slice B because the slice already opens the equipment area:
**slots are identified by `(inventory_id, slot_x, slot_y)`**, and an id that can
hold several entries renders as the container it is. `config/inventory_slots.yaml`
stays curated and hand-maintained — no upstream source carries this vocabulary —
but gains, per id, how many positions it may hold. The labels `Trinket` and
`Flask` are corrected to name what the corpus shows them to be.

### Weapon sets are allocated, coloured and summed separately

`weapon_set` is documented on a passive as an optional index 0-2, and the corpus
shows it in real use. Measured across the four files, 2026-09-12: three of them
carry **exactly 24 nodes at `weapon_set: 1` and 24 at `weapon_set: 2`**, against
about 101 with the key absent; the fourth (Campaign, level 1-51) has none. The
value `0` never appears — only absence, `1` and `2` — so absence is what "shared"
looks like on the wire, and the app writes absence rather than `0` for it.

Two further measurements decide the data model. **No id appears twice** in any
file, and the three groups do not overlap at all: a node carries at most one
weapon set, so passives stay keyed by id and this is not a second instance of the
equipment-slot defect above. And the two sets occupy **entirely different
sub-trees** — set 1 is an `ailments*` branch, set 2 a `cooldowns*`/`duration*`
branch — rather than the same path allocated twice.

**The canvas colours all three groups differently** and carries an explicit
three-way mode above it — Shared / Set 1 / Set 2 — saying what a click allocates
into, defaulting to Shared. The colour legend is that control. A mode is needed
because nothing in the tree data marks a node as belonging to a weapon set: the
grouping is the player's decision, not a property of the node.

**Connectivity is per set** (owner-confirmed, 0.5.5): a set 1 node must reach the
class start through shared or set 1 nodes only, and likewise for set 2. The two
branches hang independently off the shared trunk, which is what the corpus shows.
Rule 1 therefore runs three roots over one graph rather than one root over
everything, and the cascade in "Deallocation cascades" inherits that: removing a
shared node can orphan nodes in all three groups, while removing a set 1 node can
orphan only set 1 nodes.

**The stats overview follows the same split**, since a summary that silently
mixed the three would misstate every build that uses weapon sets. It gains a
three-way view control: Shared, With set 1, With set 2. The two set views sum
**shared plus that set**, because that is the question a player is asking — what
this build gives while that weapon set is equipped — and not what the set's own
nodes contribute in isolation. The control is view state carried by the same
query-parameter mechanism as the interval modes and the five searches, never
persisted.

**One question this opens and does not answer.** `passive.budget_exceeded`
(iteration 4) counts allocated points against what the target level affords. If
weapon-set passives draw on a separate pool rather than the same one — the 24/24
symmetry in the corpus hints that they might — then counting all three groups
together would misreport every build that uses them. Slice B does not implement
that rule, so it does not need the answer; iteration 4 does, and must not assume
one pool without checking. Listed under "Open points".

### Deferred: a design round on how the editor is organised

Raised 2026-09-12 and explicitly postponed. The editor is one long scroll —
Build, then the tree beside Passives, then Skills, Equipment, History, Findings —
with five of eight sections spanning both grid columns, every region expanded at
all times, and the five searches spread across three regions. `#build-history`
is not `.full` and renders in the wide column with an empty gap beside it, which
is a plain layout defect rather than a design question.

The one input the round already has: asked what a working session looks like, the
owner answered **rounds across all areas** — tree, then skills, then gear, then
back, with no single region dominating. That rules out a tree-first full-screen
layout and argues for making every region quickly reachable. Visual polish is
separately deferred by the owner and is not what this round is about.

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
6. How many Instilled Modifiers a normal amulet allows. `UniqueMultipleAnointments1`
   grants "3 additional", which implies a base of at least one, but the base
   itself is nowhere in the catalog. The editor deliberately does not enforce a
   limit; this proof would only be needed if it ever should.
7. Which spelling of `unique_name` the game accepts for the three ambiguous
   uniques, and whether `.build` offers any disambiguation at all
8. Whether the three multi-node `unlockConstraint` chains require **all** their
   listed gate nodes or **any one**. Measured 2026-09-12: the field is
   `{nodes: [tree-keys], ascendancy?: string}`; 197 entries list one node
   (*The Unseen Path*, Druid1), and three list three each — *Path of the
   Renegade* (Mutewind Agility, Brinerot Ferocity, Redblade Discipline), *The
   Hollowkeeper* (First Teachings of the Keeper, First Principle of the Hollow —
   two, not three), and Huntress's *Sacred Unity* (Vivid Stampede, Wild
   Protector, Primal Bounty). The data cannot distinguish the two readings.
   B1 implements **all-of** as the conservative reading, since requiring too much
   refuses a legal build visibly while requiring too little permits an illegal
   one silently
9. Whether weapon-set passives draw on their own point pool or the shared one.
   Binds `passive.budget_exceeded`: counting all three groups against one budget
   misreports every build using weapon sets if the pools are separate. The corpus
   is suggestive but not decisive — 24 nodes on each set across three files, a
   symmetry that a shared pool would not require. Slice B does not need this;
   iteration 4 must not assume an answer

Settled 2026-09-12 (owner, 0.5.5): the two tree-legality exceptions both need
their enabling node *allocated*. *The Unseen Path* must be allocated before any
`oracle_*` node can be; *Entwined Realities* must be allocated **and** the
individual keystone taken before that keystone's neighbourhood opens up. The
catalog states which nodes are affected but nothing about what switches them on,
so this is curated.
Settled 2026-09-12 (owner, 0.5.5): connectivity is evaluated per weapon set — a
set 1 node reaches the class start through shared or set 1 nodes only, and
likewise set 2. The corpus agrees (the two sets occupy disjoint sub-trees) but
cannot prove it, since a build file records what was allocated and never what
would have been refused. Curated.
Settled 2026-09-12 (owner, 0.5.5): an Instilled Modifier costs no passive point.
Curated, not derivable — the catalog carries the Distilled Emotion recipes but
nothing about their cost. Binds `passive.budget_exceeded` and the stats
overview's separate listing.
Settled: uniqueness of supports per character — applied up to 0.2, lifted in 0.3.
Settled: the `.build` key spaces for passives and gems match their sources
exactly (step 0).
Settled: `weapon_set` is 1 or 2, naming the numbered slot halves
`Weapon1`/`Offhand1` and `Weapon2`/`Offhand2` (real-file corpus, 0.5.5).
Settled 2026-09-11: all 12 classes are selectable in 0.5.5 and share only 6
physical start positions in pairs, keyed by each start node's
`classStartIndex`; see "Iteration 3 design — editor" for the measurement and
the resulting `catalog_class` table.
Retired 2026-09-11: orbit radii and slot counts for the tree renderer are not
needed. Every one of the 4912 real nodes already carries absolute `x`/`y` in
the export (confirmed non-null for all of them), so placement and hit-testing
work directly from measured coordinates — nothing is derived from orbit
geometry. What was proof #6 is dropped rather than stamped.

### Notes for the later rule walkthrough

- `passive.budget_exceeded` can legitimately be exceeded: via a rune, and via the
  league mechanic "Martyr of the First Edict", which grants every player in a
  league an extra point. The rule needs a tolerance or a user field for extra
  points, otherwise it fires on correct builds.
- **Instilled nodes are not counted by it at all.** An Instilled Modifier costs
  no passive point — it is granted by the amulet — so counting the declared ones
  towards the budget would make correct builds look over-spent. Owner-confirmed
  for 0.5.5, not derivable from catalog data; see "Instilled nodes are declared,
  not inferred". This is the practical reason the editor records them explicitly
  rather than guessing: a guess here would feed a wrong number straight into this
  rule.
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
notice visible, `paid_credits` switched off, and a production `APP_SECRET` set
through the environment rather than the development one committed in `.env.dev`.

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
