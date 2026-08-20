# ver 2.3.0 C1 Secretary equipment contract

Checkpoint 1 adds safe User-global Secretary equipment mutation. It does not enable Item gameplay effects, add Ring acquisition/production data, or publish ruleset v11.

## Durable state contract

`secretaries.equipment_version` is an unsigned bigint with default `1` and a PostgreSQL check constraint requiring a value of at least `1`. Existing rows are backfilled to `1`. A meaningful committed equipment mutation increments the value exactly once. An exact no-op, rejected request, or rolled-back transaction does not increment it.

Empty equipment slots remain the absence of an `equipped_slot` value. The existing unique equipped-slot constraint remains the final database integrity guard; replacement clears and assigns rows only inside one transaction after the complete proposed five-slot state has passed validation.

The historical `2026_08_17_010000_create_secretary_items_and_inquiries.php` migration and its starter grant remain independent of `equipment_version`, equipment APIs, active Nations, World state, later tables, ruleset v11, and Item effects. A fresh or repeated historical grant still creates only one level-1 `old_bow` in slot 1 with grant key `starter:old_bow`.

## Mutation serialization

Every membership-set writer and equipment mutation uses this outer order:

1. acquire `UserMembershipMutationLock` for the User;
2. enumerate active owner memberships and stable-sort affected Worlds by `world_id`;
3. acquire every affected `WorldMutationLock` in ascending order;
4. enter the database transaction and lock World, membership, and Nation rows in stable order;
5. verify the active owner membership snapshot did not change;
6. reject if any affected World's next non-dry TurnRun is `pending`, `running`, `failed`, or `blocked`;
7. lock the Secretary and all owned Item rows in ID order;
8. verify `expected_version`, construct and validate the final state, then write and audit;
9. commit, release World locks in reverse order, then release the User lock in `finally`.

If no active owner Nation exists, the User lock and Secretary/Item transaction still apply but no World lock is acquired. Partial World-lock acquisition releases already-held locks in reverse and writes nothing.

Nation registration and abandonment use the same User-then-World order and the same next-production-TurnRun guard before membership or lifecycle mutation. This preserves a single authoritative boundary for membership enumeration and same-ruleset/same-seed TurnRun retry inputs.

## API and policy

- `GET /api/v1/me/secretary/equipment/{slot}/options` returns the slot, `equipment_version`, current Item, legal candidates, and category-limit presentation metadata.
- `PUT /api/v1/me/secretary/equipment/{slot}` accepts nullable `item_id` and required `expected_version`, then returns the neutral authoritative Secretary payload.
- A stale version returns HTTP 409 with `secretary_equipment_version_conflict`.
- Player-safe invalid ownership, slot, or policy requests return HTTP 422 with `secretary_equipment_invalid`.

The server filters and revalidates candidates from one bounded inventory load. It calculates limits against the proposed final state, so a target-slot replacement may be legal while a second bow in another slot is not. An Item already equipped elsewhere is omitted and a forged move is rejected. C1 runtime policy contains only the existing Old Bow (`bow`, category max 1, same-item max 1); generic multi-instance limit behavior is covered by test-only catalog fixtures because Ring definitions and effects belong to C2.

The options endpoint executes exactly two SQL queries for an empty inventory, Old Bow only, 50 Items, and all five slots occupied. Candidate evaluation adds no per-Item query.

## UI boundary

Each of the five compact slots is an interactive button. The modal uses an inner native `overflow-y: auto` radio list with `外す` first, current Item next and selected, then legal server-provided candidates. It shows name, level, and category only; Warehouse flavor is not copied and C2 effect text is not fabricated.

The footer stays outside the scrolling list and contains only the bottom-right `変更する` action. Selection never mutates immediately. The close button, backdrop, and Escape cancel without mutation. Focus moves into the dialog, Tab is trapped sensibly, native radios support keyboard selection, and focus returns to the invoking slot. Loading disables duplicate submission. A stale 409 reloads both the neutral Secretary state and slot options and disables submission until the player makes a fresh selection. Backend errors remain visible inside the dialog.

## C2 entry boundary

C2 may begin after this checkpoint is integrated into `release/ver-2.3.0` with green Quality and no unresolved P0-P2. C2 owns Ring presentation/catalog data, explicit authorized World-scoped effect presentation, v11 test fixtures, gameplay effect definitions, snapshots, Old Bow damage, and Ring finance behavior. C2 must retain this lock/version/API contract and prove v1-v10 TurnRuns remain effect-free. Publishing the final immutable v11 payload remains C5 work.
