# World expansion foundation

Status: ver 1.5.0 foundation. World expansion and Nation abandonment are not implemented.

Implementation evidence and exclusions are recorded in
`docs/reference-analysis/h2-realignment-and-world-expansion-audit.md`.

## Bounds contracts

`InitialWorldBounds` is the zero-origin initial-generation contract. Published ruleset values
`initial_x_min=0`, `initial_x_max=59`, `initial_y_min=0`, and `initial_y_max=59` remain immutable
and are not the current generated range after a future expansion.

`MapBounds` is the signed, non-empty current-range value. A `MapSpace` constructs it from
`min_x`, `max_x`, `min_y`, and `max_y`; those four columns are the authoritative current
generated range. Existing coordinates are never translated when this range eventually grows.

## Coverage publication invariant

Published current bounds mean that every coordinate in the rectangle has exactly one `MapCell`
whose chunk/local coordinates match mathematical floor division and modulo. Partial edge chunks
are normal: a 60 by 60 MapSpace has 12 by 12 cells in its bottom-right chunk, not 256.

`MapSpaceCoverageValidator` performs the complete coordinate-by-coordinate check only at an
initialization, future expansion, preflight, or test boundary. Initialization runs it before its
transaction commits and before its generation run is marked completed. A future expansion must:

1. acquire the common World mutation lock;
2. start one database transaction;
3. generate and validate all newly required cells and chunk metadata;
4. update the MapSpace current bounds last; and
5. commit the cells and new bounds together.

MapChunk GET does not repeat the full coverage scan. It only compares the already-loaded cell
count with the cheap bounds-intersection count before exposing an existing chunk row as
`generated`; the operation/preflight validator owns the complete invariant.

## World mutation lock ordering

Every mutation that can overlap turn execution uses `WorldMutationLock` with the retained
`hakoniwa.turn.world.{world_id}` PostgreSQL session advisory key. The retained key serializes a
rolling deploy with older turn workers. The mandatory ordering is:

1. acquire `WorldMutationLock` before opening the mutation transaction;
2. begin the transaction;
3. lock the `worlds` row;
4. lock narrower rows in stable parent-to-child order; and
5. commit or roll back, then release the advisory lock in `finally`.

Turn, Nation registration, reset, and monster award-cycle seeding share this boundary now.
Future `WorldExpansionService` and Nation abandonment must use the same ordering. This foundation
does not implement either operation.

## Cache invalidation

The API exposes `bounds_revision`, a deterministic SHA-256 token derived from the current signed
bounds and chunk size. No schema migration or backfill is needed, and deploying this foundation
does not change a stored value. Frontend map state clears loaded, in-flight, and confirmed-empty
chunk caches when the MapSpace ID or bounds revision changes. A future bounds mutation therefore
keeps the same MapSpace ID while producing a new invalidation token.
