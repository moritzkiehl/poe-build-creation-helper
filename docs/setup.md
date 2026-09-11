# Setting the project up on another machine

Written for Windows with WSL2, which is the awkward case; on plain Linux or
macOS skip straight to "Bring it up".

Everything runs inside DDEV, so the only things installed on the host are Docker,
DDEV and git. No PHP, no Composer, no MariaDB.

## Windows with WSL2

**Work inside the Linux filesystem, not `/mnt/c`.** Clone to somewhere like
`~/projects/poe-build-creation-helper`. A project living under `/mnt/c` is
reachable from WSL, but every file read crosses the Windows filesystem boundary,
and Composer and PHPUnit become painfully slow. This is the single biggest
mistake to avoid.

**Docker.** Either install `docker-ce` inside the WSL distro, which DDEV
recommends, or use Docker Desktop with WSL integration enabled for that distro.
Both work; the first is faster and keeps everything on the Linux side.

**Line endings.** `.gitattributes` pins the repository to LF, so a checkout is
correct by default. If git on the Windows side has ever been configured with
`core.autocrlf true`, set `git config --global core.autocrlf false` in the WSL
distro as well, or shell scripts arrive with carriage returns and fail in ways
that read as nonsense.

**Hostnames.** DDEV writes `poe-build-helper.ddev.site` into the hosts file and
needs elevation for it — on WSL2 it manages the Windows hosts file too, so
expect a prompt. If `ddev start` stops with a sudo error, run it from an
interactive terminal where you can answer.

## Bring it up

```bash
git clone git@github.com:moritzkiehl/poe-build-creation-helper.git
cd poe-build-creation-helper

ddev start                 # builds the containers, creates the test database
ddev composer install
ddev composer db:migrate   # development and test schema
```

Then open <https://poe-build-helper.ddev.site>.

`ddev start` also creates the `db_test` database. That is not decoration: PHPUnit
runs against real MariaDB rather than SQLite, because JSON and generated columns
behave differently and a green SQLite run would prove nothing. The hook lives in
`.ddev/config.testdb.yaml` rather than `config.yaml`, because `ddev config`
rewrites the latter.

## Check it works

```bash
ddev composer gate
```

Coding standard, PHPStan at its highest level, and the whole test suite. This is
the same command CI runs, and it should be green on a fresh clone before you
change anything.

## Frontend assets

There is no bundler and no Node runtime: Symfony's AssetMapper serves
`assets/` as-is, versioned by content hash, with Stimulus and Turbo pulled in
through `importmap.php` rather than `npm install`. After a fresh checkout, run

```bash
ddev php bin/console importmap:install
```

to download the pinned third-party entries (`@hotwired/stimulus`,
`@hotwired/turbo`, …) into `assets/vendor/`, which is gitignored. Before
deploying, run

```bash
ddev php bin/console asset-map:compile
```

to write the versioned files to `public/assets/` for production to serve
directly.

No bundler and no Node runtime in production, but the two pure geometry
modules the editor's canvas rests on (`assets/lib/camera.js`,
`assets/lib/spatial_grid.js`) do have unit tests, run under Node inside the
DDEV web container: `ddev npm install` once, then `ddev npm run test:js`.

## Game data

The catalog is empty until it is fetched, and the application is meant to work
that way — builds open, edit, share and export without it, only without names,
icons and checks.

Before the first sync, put a real contact address in `.env.local`, which is not
committed:

```
APP_CATALOG_CONTACT=you@example.com
```

Upstream asks third-party tools to identify themselves, and the default value is
deliberately invalid so an unconfigured machine cannot quietly pretend otherwise.

```bash
ddev exec php bin/console app:catalog:sync
```

That fetches roughly 18 MB into `var/catalog/`, which is gitignored. Snapshots
are never committed: the data belongs to Grinding Gear Games, and we display it
rather than redistribute it. A second run revalidates and answers in about a
second if nothing upstream has moved.

Add `--report-unparsed` to list every support clause the requirement parser could
not read.

## A note on APP_SECRET

`.env.dev` carries a generated `APP_SECRET`, committed by Symfony's own recipe.
It applies to the development environment only and is fine in a public
repository. Production must supply its own through the environment or
`.env.local`, and must never inherit this one.

## Optional: real build files

Drop any `.build` files exported by the game into `var/sample/` and the test
suite starts checking the reader against them instead of only against fixtures we
wrote ourselves. The directory is gitignored — those are personal build documents
carrying GGG identifiers, and this repository is public.

## When something is wrong

**`ddev start` fails on the hosts file.** It needs a password. Run it from a
terminal you can type into.

**The site does not resolve.** `*.ddev.site` normally resolves through public
DNS; where that is blocked, DDEV falls back to the hosts file, which is what the
elevation prompt is for. `ddev describe` prints the working URLs and ports.

**Tests cannot reach the database.** `ddev restart` re-runs the hook that creates
`db_test`, then `ddev composer db:migrate`.

**Odd container or cache errors after switching branches.**
`ddev exec rm -rf var/cache/*` clears a stale compiled container — it caused a
misleading "non-existent service" error once already.
