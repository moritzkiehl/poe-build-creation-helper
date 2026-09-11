# Mistake log

## 2026-09-11 — read "no Node" too broadly
**What:** Read the part 1 stack decision "no Node" as banning every Node dependency. It meant: no NodeJS backend. Presented Playwright as a stack conflict.
**Cost:** One superfluous question about the E2E tool; the user had to correct it.
**How it was detectable:** The doc line reads "assets via AssetMapper (no Node)" — the parenthesis qualifies assets, not the project. Read the line's context, not the keyword.
**Status:** one-off
