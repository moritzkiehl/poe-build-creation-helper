# CLAUDE.md — poe-creation-helper

## Dependency choices

Prefer PSR-compliant and already-installed Symfony packages over a hand-rolled
equivalent. Before writing a new abstraction (a dispatcher, a cache layer, an
event bus, ...), check whether Symfony already ships the building block —
pull it in rather than inventing a project-specific version of the same
behaviour. Decided 2026-09-11 when choosing symfony/messenger over a hand-rolled
command dispatcher for the build editor's edit commands.

## The owner's to-do list

Anything the owner has to do that Claude cannot is tracked in `TODO.md` at the
repository root: a decision that is theirs to make, a check only the game can
answer, or a destructive or outward-facing action waiting on their go-ahead.
Add an item the moment one comes up, rather than leaving it only in a chat
message. Remove it once it is done, and record the outcome where it belongs —
the spec, the proof list, a commit. Decided 2026-09-13.
