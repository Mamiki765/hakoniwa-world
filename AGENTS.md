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

Do not implement the game yet.

The current phase is limited to:

1. arranging the workspace
2. recording provenance and licensing information
3. inspecting both references separately
4. documenting requirements and architecture
5. listing unresolved design questions

Wait for explicit approval before implementing files under `product/`.

## Design gates

Before starting implementation work, read `docs/open-questions.md`.

If an `Open` item is related to the implementation scope and its `Required before` gate has been reached, do not decide it implicitly or implement around it. Report the item, the viable options, and the effect on the planned implementation.

For `Deferred` items, preserve only a clear extension boundary. Do not implement the deferred feature early as part of the MVP.
