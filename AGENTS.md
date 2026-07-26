# Project instructions

## Work area

The new application must be implemented only under `product/`.

## Read-only references

Everything under `_references/` is third-party reference material.

Never modify, format, rename, delete, or commit files under `_references/`.

## Reference roles

`_references/hakoniwa-2plus/source` is used to study:

- shared-world behavior
- player and nation placement
- territory and borders
- turn processing
- disasters
- missiles
- world expansion
- legacy game rules

`_references/yamanity/repository` is used to study:

- Laravel architecture
- Vue user interface
- Docker development environment
- entity and persistence separation
- JSON-based management
- administration features
- maintainability

Neither reference implementation is the target architecture.

Do not translate the C source directly into PHP.

Do not copy third-party images, text, or substantial code into `product/`.

Extract behavior into documentation and tests before implementing it independently.

## Encoding

Preserve the original encoding of files under `_references/`.

All newly created source code, documentation, database text, APIs, and user input must use UTF-8.

## Current phase

Game implementation under `product/` has been explicitly approved by the repository owner.

The shared-world MVP is being implemented through roadmap-scoped pull requests. The current approval includes the game state, commands, turn processing, economic loop, and the supporting API, UI, persistence, tests, documentation, and operations needed by those roadmap slices.

Keep each implementation within its approved roadmap scope. Do not implement a `Deferred` item early without separate explicit approval. The design gates in `docs/open-questions.md` remain in force: when an `Open` item reaches its `Required before` gate, report the options and obtain a decision instead of deciding it implicitly or implementing around it.

## Design gates

Before starting implementation work, read `docs/open-questions.md`.

If an `Open` item is related to the implementation scope and its `Required before` gate has been reached, do not decide it implicitly or implement around it. Report the item, the viable options, and the effect on the planned implementation.

For `Deferred` items, preserve only a clear extension boundary. Do not implement the deferred feature early as part of the MVP.

## Coordinate system

The canonical surface-map coordinate system is the staggered square-tile `x`/`y` grid defined by `docs/decisions/ADR-0003-hex-coordinate-system.md`.

- Use `x`, `y`, `chunk_x`, `chunk_y`, `local_x`, `local_y`, `target_x`, and `target_y` in current code and public interfaces.
- The initial world is `0..59` on both axes with 60 cells in every row.
- Even absolute `y` rows are rendered 16px to the right; never derive parity from a capital-relative row.
- Keep the six-neighbor direction numbering identical in Backend and Frontend.
- Cube conversion is permitted only as a private distance-calculation detail.
- Historical migrations may name the retired coordinate columns only to backfill or roll back them.

Do not implement the turn runner while performing coordinate, migration, reset, or rendering work.
