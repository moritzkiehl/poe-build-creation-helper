# PoE2 Build Helper

A web app for creating, checking, sharing and exporting Path of Exile 2 builds.
You sketch a build in the browser, a rules engine checks it, and the result
leaves the tool as an official `.build` file for the game's build planner.

Not a DPS or EHP simulator — that stays with Path of Building 2.

## Getting started

Everything runs in DDEV; only Docker, DDEV and git are installed on the host.

```bash
ddev start
ddev composer install
ddev composer db:migrate
ddev composer gate          # coding standard, PHPStan, tests
```

Then <https://poe-build-helper.ddev.site>. Full instructions, including Windows
and WSL2, are in [`docs/setup.md`](docs/setup.md).

`composer gate` includes one Playwright test that drives a real browser
against the editor's passive-tree canvas. Nothing inside a `<canvas>` is part
of the DOM, so it's the only kind of test — not PHPUnit, not a JS unit test —
that can click a node and check it was allocated; everything else about the
editor is covered more cheaply than that, which is why there is exactly one.
It needs Chromium installed once (`docs/setup.md` has the command).

## Status

Design stage. There is no code yet, only the design under
[`docs/specs/`](docs/specs/). The public launch is planned for January 2027,
shortly after the PoE2 1.0 release; development runs against the Early Access
version 0.5.5.

## Game data

This repository contains no game data. Passive tree, gems, uniques and base
items are fetched from their sources at runtime by `app:catalog:sync` and stored
in an ignored directory. They are owned by Grinding Gear Games; see
[`NOTICE`](NOTICE) for details.

Catalog, editor, rules engine and export are free and will stay free. No
GGG-derived content is ever behind a paywall.

## Where the data comes from

Everything the MVP needs is a static file over HTTPS. No API key, no OAuth, no
account.

| Source | What it gives us | Standing |
|---|---|---|
| [poe2-skilltree-export](https://github.com/grindinggear/poe2-skilltree-export) | The passive tree: nodes, connections, layout | Official, published by Grinding Gear Games |
| [Build Planner format](https://www.pathofexile.com/developer/docs/game#buildplanner) | The `.build` interchange format, version 1 | Official documentation |
| [RePoE fork](https://github.com/repoe-fork/poe2) | Skill gems, support gems, uniques, base items, tags | Unofficial community project |
| [poe.ninja economy API](https://poe.ninja) | Prices | Not used yet; planned after the MVP |
| [Path of Exile API](https://www.pathofexile.com/developer/docs) | Ladder data | Not used; would need a confidential OAuth client |

**RePoE** is used only for what no official source publishes. Its code is MIT,
Copyright (c) 2016 brather1ng; the data it generates is explicitly *not* covered
by that licence and belongs to Grinding Gear Games. We display it, we never
redistribute it: snapshots are gitignored, there is no bulk download and no
public data API.

Sync identifies itself with a `User-Agent` carrying a contact address, and
revalidates with `If-None-Match` or `If-Modified-Since` so an unchanged file is
never fetched twice. It is a manual command rather than a scheduled job:
adopting a game patch is a decision, and a broken upstream must not take the
running site down with it. The application works without any catalog at all —
builds still open, edit, share and export, only without names, icons and checks.

## Built with

Symfony 8.1 on PHP 8.5 with MariaDB, Twig and Symfony UX; DDEV for local
development; PHPUnit, PHPStan and PHP-CS-Fixer for the quality gate; Playwright
for the browser tests. Full list in [`composer.json`](composer.json).

## License

Our own code is under [MIT](LICENSE). What that does not cover is listed in
[`NOTICE`](NOTICE).

---

This product isn't affiliated with or endorsed by Grinding Gear Games in any way.
