# Mistake log

## 2026-09-11 — claimed Symfony 8 while the lock held 7.4.18
**What:** Reported "Symfony 8.1.6" from `bin/console about` after a later `composer update -W` had re-resolved back to v7.4.18. Per-package constraints still read `7.4.*`; only `extra.symfony.require` had been changed. Output was stale cache.
**Cost:** A wrong version claim to the user and in commit 3154b56; user had to catch it.
**How it was detectable:** `composer.lock` is the source of truth for installed versions, `bin/console about` reads a built container. Changing `extra.symfony.require` alone never rewrites the per-package constraints.
**Status:** one-off

## 2026-09-11 — read "no Node" too broadly
**What:** Read the part 1 stack decision "no Node" as banning every Node dependency. It meant: no NodeJS backend. Presented Playwright as a stack conflict.
**Cost:** One superfluous question about the E2E tool; the user had to correct it.
**How it was detectable:** The doc line reads "assets via AssetMapper (no Node)" — the parenthesis qualifies assets, not the project. Read the line's context, not the keyword.
**Status:** one-off
