# CLAUDE.md — poe-creation-helper

## Dependency choices

Prefer PSR-compliant and already-installed Symfony packages over a hand-rolled
equivalent. Before writing a new abstraction (a dispatcher, a cache layer, an
event bus, ...), check whether Symfony already ships the building block —
pull it in rather than inventing a project-specific version of the same
behaviour. Decided 2026-09-11 when choosing symfony/messenger over a hand-rolled
command dispatcher for the build editor's edit commands.

## Plans name only code that exists

Before a plan, a brief or a dispatch names a helper, a command, an API or a
test utility, grep for it or run it — `bin/console list` for a console
command, one PHPStan run for a pasted snippet. A plan must not name code that
does not exist. Slice B1's briefs named six things that did not, each costing
an implementer deviation or a ruling (see `.claude/mistakes.md`). Decided
2026-09-14.

## View-state query keys live in one registry

A query parameter that must survive an edit is registered in one place: the
`App\Controller\ViewState` registry, once slice B2's first task lands. Until
then, adding such a key means `grep -rn "intervals" src templates assets` and
updating every site it shows — the hidden fields, `searchParams()`, the
`_header` `field()` macro, the link maps and the GET search forms. A key missed
at one of them was silently dropped five times. Slice B2 adds a gate test that
makes this mechanical. Decided 2026-09-14.

## The owner's to-do list

Anything the owner has to do that Claude cannot is tracked in `TODO.md` at the
repository root: a decision that is theirs to make, a check only the game can
answer, or a destructive or outward-facing action waiting on their go-ahead.
Add an item the moment one comes up, rather than leaving it only in a chat
message. Remove it once it is done, and record the outcome where it belongs —
the spec, the proof list, a commit. Decided 2026-09-13.
