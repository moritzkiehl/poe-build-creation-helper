# CLAUDE.md — poe-creation-helper

## Dependency choices

Prefer PSR-compliant and already-installed Symfony packages over a hand-rolled
equivalent. Before writing a new abstraction (a dispatcher, a cache layer, an
event bus, ...), check whether Symfony already ships the building block —
pull it in rather than inventing a project-specific version of the same
behaviour. Decided 2026-09-11 when choosing symfony/messenger over a hand-rolled
command dispatcher for the build editor's edit commands.
