# PoE2 Build Helper

A web app for creating, checking, sharing and exporting Path of Exile 2 builds.
You sketch a build in the browser, a rules engine checks it, and the result
leaves the tool as an official `.build` file for the game's build planner.

Not a DPS or EHP simulator — that stays with Path of Building 2.

## Status

Design stage. There is no code yet, only the design under
[`docs/specs/`](docs/specs/). The public launch is planned for January 2027,
shortly after the PoE2 1.0 release; development runs against the Early Access
version 0.5.5.

## Game data

This repository contains no game data. Passive tree, gems, uniques and base
items are fetched from their sources at runtime and stored in an ignored
directory. They are owned by Grinding Gear Games; see [`NOTICE`](NOTICE) for
details.

Catalog, editor, rules engine and export are free and will stay free. No
GGG-derived content is ever behind a paywall.

## License

Our own code is under [MIT](LICENSE). What that does not cover is listed in
[`NOTICE`](NOTICE).

---

This product isn't affiliated with or endorsed by Grinding Gear Games in any way.
