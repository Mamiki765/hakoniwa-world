# Turn runner scaffold and capacity boundaries

## Scope

Roadmap PR #7 adds an auditable World-level turn runner scaffold, published base capacities, owner-only capacity output, and integer economic boundaries. It does not execute the seven queued commands or production, consumption, population, disasters, monsters, missiles, combat, oil, items, notifications, or automatic sales.

The default pipeline is deliberately incomplete. `hakoniwa:turn:run` must not advance a production World while any required phase is a stub. `--dry-run` records and displays the pipeline without changing game state.

## Design-gate handling

The following `docs/open-questions.md` gates are reached but not completely resolved.

| Gate | PR #7 boundary | Still open |
|---|---|---|
| A-06 turn order | Preserve the observed Hakoniwa 2+ causal order as named phases and test its order | Simultaneous resolution rules and detailed current-game effects |
| A-07 transaction size | Implement one World/turn game-state transaction as the simple scaffold | Load test and a decision whether production scale requires checkpoints |
| B-09 disaster population | Keep a required but unimplemented `global_disasters` phase | Disaster-specific draw population |
| T-01 random seed | Persist a 256-bit master seed before execution and reuse it for a failed-run retry | Stable enumeration and labelled stream contract for each future random phase |
| D-01 scheduler | Choose OCI host cron as the thin hourly trigger | When multiple Worlds justify a scheduler/worker service |
| D-02 retry | Roll game state back, record failure, and permit explicit retry of the same run/seed | Automatic retry count, backoff, stale-running recovery |

No unresolved gameplay rule is hidden in a working production phase.

## Source-derived pipeline

The order is derived from `_references/hakoniwa-2plus/extracted/turn.c:9-148`, with names translated to current domain boundaries.

| Order | Phase key | Legacy source position | PR #7 |
|---:|---|---|---|
| 1 | `prepare_turn` | increment, map load, stable inputs, order arrays, log open | implemented context boundary |
| 2 | `calculate_terrain_context` | `Map::calcSea` | stub |
| 3 | `resolve_territory_influence` | `Map::infLand` | stub |
| 4 | `nation_economy` | `Island::clear2`, `Island::income`, `prePop` | stub; `EconomyHandler` extension |
| 5 | `development_commands` | `Command::exec` | stub; `CommandHandler` extension |
| 6 | `process_cells` | `Map::process` including local growth, fire, missile and monster movement | stub; must later be decomposed without reordering effects silently |
| 7 | `settle_deferred_effects` | refugees / delayed inter-nation effects | stub |
| 8 | `global_disasters` | earthquake through monster appearance | stub |
| 9 | `aggregate_nations` | `Map::estimate` | stub |
| 10 | `enforce_capacities` | food overflow/sale then money cap | stub; capacity services are ready |
| 11 | `finalize_turn` | elimination, prizes, ranking/owner projection, persistence | implemented commit boundary only |

The legacy code randomises the command Nation order and cell order, while economy uses the pre-existing ranking order. PR #7 does not reproduce those shuffles yet. A later gameplay PR must use the saved master seed plus stable Nation IDs and x/y coordinates, not ranking IDs or implicit random-call order.

## Turn run schema

`turn_runs` records:

- `world_id`
- `target_turn`
- `ruleset_version_id`
- `random_seed`
- `source` (`manual` or `cron`)
- `is_dry_run`
- `status`
- `attempt_count`
- `pipeline`
- `phase_results`
- `started_at`
- `completed_at`
- `failure_code`
- `failure_message`
- `failure_context`
- timestamps

A PostgreSQL partial unique index on `(world_id, target_turn)` for non-dry runs prevents the same turn from being applied twice. Dry runs remain history records without occupying the application slot.

The run snapshots the exact `ruleset_version_id` referenced by the World. The transaction rechecks both the World target turn and ruleset ID before executing. It never resolves current deployment config as an existing World's rules.

## Lock and transaction

`WorldTurnLock` uses a PostgreSQL session advisory lock derived from the World ID. It uses a non-blocking try operation so a duplicate trigger fails quickly. The lock spans run preparation, validation, transaction, and final history update, and is released in `finally`.

Within that lock, all game-state phase effects and `worlds.current_turn` are one DB transaction. `current_turn` is updated only after every required phase succeeds. An exception rolls back all phase effects. The run row is created before the game transaction so failure can be recorded after rollback without pretending that game state committed.

The current 60×60 World and empty effect scaffold do not justify checkpoint complexity. Before real full-cell production use, PostgreSQL integration tests and measured lock duration must confirm A-07. If a single transaction proves unsuitable, checkpoint semantics require a separate decision; this PR does not silently add partial commits.

## Seed and retry

A new run generates 32 random bytes and stores the lowercase 64-character hexadecimal master seed before phase execution. A failed or scaffold-blocked retry reuses the same row and seed. Future random handlers must derive deterministic, labelled streams from the saved seed and stable target identifiers.

Caught failures store a bounded message, failure code, failed phase key, and exception class, without stack traces, request credentials, or secrets. The CLI returns non-zero. Automatic retry and stale `running` takeover remain disabled. An operator first runs status, fixes the cause, and invokes the same run command; only `failed` or `blocked` runs are eligible for explicit reuse.

## Published capacities

PR #7 publishes `roadmap-pr7-v1` instead of updating `roadmap-pr6-v1`.

```text
base_money_capacity = 9,999       (money unit: 1億円)
base_food_capacity_tons = 999,900 (food unit: 1 ton)
```

Only `shared-world` is moved from the exact expected PR6 ruleset by a data-preserving migration. Queue definition foreign keys are remapped by command key. The initializer associates new Worlds with PR7 but never moves an existing World.

`NationCapacityResolver` reads the World's published snapshot and applies zero or more future `CapacityModifier` implementations. PR #7 registers no modifiers.

```text
effective capacity
= published base capacity
+ future capital modifiers
+ future facility modifiers
+ future item/effect modifiers
```

No fixed capacity is placed in a DB CHECK constraint. Existing balances are not migrated or clamped.

## Capacity-bounded additions

`CapacityBoundedAssetService` is the shared credit boundary.

- Money credit locks the Nation and returns `before`, `requested`, `applied`, `overflow`, `after`, and `capacity`.
- Food credit locks the Nation and its balances, sums every resource whose catalog `category` is `food`, and credits only the target food resource.
- Negative credits are rejected; payment and consumption need separate future debit operations.
- New food resource keys are automatically included by category.
- Production overflow can be recorded in a phase result instead of disappearing silently.

The service accepts the run's ruleset snapshot so future handlers do not switch rules during a turn.

## Industrial goods and minerals

`InventorySalePlanner` is a pure quote boundary, not an automatic sale implementation.

```text
whole money requested = floor(inventory units / 1,000)
money applied = min(whole money requested, remaining money capacity)
inventory consumed = money applied × 1,000
inventory remaining = original inventory - consumed
```

It therefore preserves both unsellable whole batches and sub-1,000 remainders. The same boundary applies to `industrial_goods` and `minerals`; handlers do not create decimal money.

## Commands

`CommandHandler` owns all execution-time meaning:

- validation against current x/y cell state
- cost and resource checks
- immediate or deferred effects
- whether the plan consumes the Nation's action
- quantity decrement
- retain/remove/return-to-head disposition
- bulk same-turn quantity use
- command-specific parameters

`CommandQueueService` continues to store and edit the universal integer quantity but does not interpret it.

## CLI and cron

```console
php artisan hakoniwa:turn:run --world=shared-world --dry-run
php artisan hakoniwa:turn:run --world=shared-world
php artisan hakoniwa:turn:status --world=shared-world
```

The production run command returns non-zero while required phases are stubs.

The selected operations example is an OCI host cron at the top of every hour in `Asia/Tokyo`, calling `product/docker/cron/run-turn.sh`. The wrapper only invokes the Artisan command in `hakoniwa-web`; no gameplay code or authoritative lock lives in shell. Optional host `flock` is a first filter, while the DB advisory lock and unique index are authoritative.

Production cron registration, production DB access, and a production turn are outside this PR.

## Next PR decisions

- A-06 simultaneous resolution and which current-domain effects share a snapshot.
- A-07 measured transaction duration and lock budget with realistic Nation/cell counts.
- RES-01 farm production balance after converting the legacy 1,000 tons per scale.
- T-01 labelled random streams, stable enumeration, and replay assertions.
- D-02 automatic retry/backoff and stale-running recovery.
- Exact command result/event schema and the seven handlers.
- Production, food consumption, population, forest, disasters, monsters, combat, missiles, oil and item specifications.
- Whether and when multiple Worlds justify a dedicated scheduler container.
