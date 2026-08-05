# Turn runner scaffold and capacity boundaries

## Scope

Roadmap PR #7 adds an auditable World-level turn runner scaffold, published base capacities, owner-only capacity output, and integer economic boundaries. It does not execute the seven queued commands or production, consumption, population, disasters, monsters, missiles, combat, oil, items, notifications, or automatic sales.

The default pipeline is deliberately incomplete. `hakoniwa:turn:run` must not advance a production World while any required phase is a stub. `--dry-run` records and displays the pipeline without changing game state.

## Design-gate handling

The following `docs/open-questions.md` gates are reached. A-06, A-07, and T-01 are formally decided; unresolved gameplay gates remain bounded by stubs.

| Gate | PR #7 decision or boundary | Still open |
|---|---|---|
| A-06 turn order | Preserve randomized sequential causality as an explicit game rule, with stable input order, labelled shuffles, and sequential application | Detailed current-game effects |
| A-07 transaction size | Decided: one World/turn PostgreSQL transaction contains every game-state phase and `current_turn` | Measurement after real commands, all-cell work, and disasters; checkpoint reconsideration requires a separate ADR |
| B-09 disaster population | Keep a required but unimplemented `global_disasters` phase | Disaster-specific draw population |
| T-01 random seed | Decided: private 256-bit master seed, versioned labelled HMAC streams, rejection-sampled integers, deterministic Fisher-Yates, and stable Nation/cell enumeration | Additional labels are introduced only with their gameplay handlers |
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
| 5 | `resource_sales` | PR22 inventory sale boundary after production | implemented; revenue is available to commands |
| 6 | `development_commands` | `Command::exec` | implemented `CommandHandler` boundary |
| 7 | `process_cells` | `Map::process` including local growth, fire, missile and monster movement | implemented sequential cell order |
| 8 | `settle_deferred_effects` | refugees / delayed inter-nation effects | extension boundary; missile refugees resolve at impact |
| 9 | `global_disasters` | earthquake through natural monster appearance | implemented PR21 order |
| 10 | `aggregate_nations` | `Map::estimate` | implemented |
| 11 | `enforce_capacities` | unsold resource overflow then money/food caps | implemented |
| 12 | `finalize_turn` | completion event and commit boundary | implemented |

The legacy code randomises the command Nation order and cell order, while economy uses the pre-existing ranking order. The foundation now exposes deterministic shuffles without connecting gameplay: command Nations start in immutable Nation ID order and use `development_commands:nation_order`; surface cells start in map-space ID, canonical x/y, and cell ID order and use `process_cells:surface_cell_order`. Required gameplay phases remain stubs.

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

The run snapshots the exact `ruleset_version_id` referenced by the World. Before creating or retrying a run, `CurrentRulesetGuard` compares the already-loaded World ruleset ID with the configured current ruleset identity and rejects a historical World with `reset_required`. The transaction rechecks both the World target turn and ruleset ID before executing; deployment config is never substituted for the World's snapshot.

## Lock and transaction

`WorldTurnLock` uses a PostgreSQL session advisory lock derived from the World ID. It uses a non-blocking try operation so a duplicate trigger fails quickly. The lock spans run preparation, validation, transaction, and final history update, and is released in `finally`.

Within that lock, all game-state phase effects and `worlds.current_turn` are one PostgreSQL transaction. `current_turn` is updated only after every required phase succeeds. An exception rolls back every game-state effect from the target turn, including any phase result written inside that transaction.

The `turn_runs` row is created and marked running before the game-state transaction. On success, completion status and phase timings are saved with the atomic turn; on failure, the game-state transaction rolls back first and the failure status is then written separately. This audit split is intentional: an operator must be able to inspect a failed attempt even though none of its game-state writes committed.

The rollback contract forbids partial publication. A failed phase cannot leave command effects, resource changes, cell changes, events, or a higher `current_turn`. Retry reuses the same target turn, ruleset snapshot, and seed.

No phase may perform external HTTP requests, send notifications, invoke webhooks, wait on long-running external processes, or perform other unbounded external I/O inside the transaction. A future transactional outbox may be written as game state, but delivery occurs only after commit and cannot roll back a completed turn.

Each phase duration is measured and stored as `duration_ms` in `turn_runs.phase_results`. Once real command handlers, all-cell processing, and disasters exist, operations must measure total advisory-lock hold time and transaction duration at realistic World sizes. Only evidence that these durations are unacceptable in production may reopen the boundary. Any checkpoint or partial-commit design requires a separate ADR covering visibility, idempotency, retry, and cross-phase invariants; it must not be introduced incrementally inside handlers.

## Seed and retry

A new target turn generates 32 random bytes and stores the lowercase 64-character hexadecimal master seed before phase execution. The seed is private until execution and is not a player prediction interface. A failed or scaffold-blocked retry reuses the same row and seed. Every attempt creates a fresh random factory and turn-scoped state inside the transaction, so retry reconstructs in-memory state from database state and the saved seed.

`TurnRandomStreamFactory` derives independent, versioned streams and `TurnOrderService` owns stable enumeration plus shuffle boundaries. The exact HMAC blocks, bounded draw, Fisher-Yates algorithm, labels, and fixed vector are specified in `docs/architecture/turn-randomness.md`. A draw added to one label cannot advance another label. Full random-call logs are not stored; the master seed and phase results are the operational investigation boundary.

`TurnState` is a typed, non-persistent per-attempt object. It can collect future missile launch intents during `development_commands` and expose them to `process_cells`, but this foundation does not fire missiles, charge costs, consume queue items, or mark either phase implemented.

Caught failures store a bounded message, failure code, failed phase key, and exception class, without stack traces, request credentials, or secrets. The CLI returns non-zero. Automatic retry and stale `running` takeover remain disabled. An operator first runs status, fixes the cause, and invokes the same run command; only `failed` or `blocked` runs are eligible for explicit reuse.

## Published capacities

PR #7 publishes `roadmap-pr7-v1` instead of updating `roadmap-pr6-v1`.

```text
base_money_capacity = 9,999       (money unit: 1億円)
base_food_capacity_tons = 999,900 (food unit: 1 ton)
```

Historically, only `shared-world` was moved from the exact expected PR6 ruleset by a data-preserving migration. Queue definition foreign keys were remapped by command key. This migration record remains until the canonical schema rebaseline; it is not a current procedure for continuing a historical World.

PR #19 publishes `roadmap-pr19-v1` as a new immutable ruleset. `NationCapacityResolver` now returns the World's published money and aggregate food capacities together with the generic per-resource capacity map.

```text
resource_capacities.industrial_goods = 9,999,000
resource_capacities.minerals         = 9,999,000
```

The generic map may reference only a storable, non-food resource in that same published payload. Unknown resources, food-category duplication, a missing overflow contract, or a configuration that converts overflow to money fail authoring validation. `CapacityModifier` remains a marker boundary: if any modifier is supplied, resolution fails closed until E-04 decides addition, multiplication, caps, priority, and cycle prevention.

```text
current effective capacity = published base capacity
future effective capacity = E-04-defined composition(base, modifiers)
```

PR #19 does not encode additive modifier arithmetic or ordering. No fixed capacity is placed in a DB CHECK constraint. Existing balances are not migrated, and historical Worlds are not repointed: they remain readable but mutation requires World reset to the current ruleset.

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

For `sell_all` and `keep_amount`, it therefore preserves both money-capacity-blocked whole batches and sub-1,000 remainders up to the individual resource cap. The same integer boundary applies to `industrial_goods` and `minerals`; handlers do not create decimal money.

PR #19 established this sale formula. PR22 separates sale into phase 5 `resource_sales` and remaining overflow enforcement into phase 11 `enforce_capacities`:

1. derive requested inventory from the Nation's policy: all inventory for `sell_all`, inventory above the target for `keep_amount`, and inventory above the individual cap for `stockpile`;
2. sell complete rate batches while the existing money capacity has room;
3. enforce the individual `industrial_goods` and `minerals` capacities;
4. discard only the remaining over-cap amount, including a sub-batch remainder or money-capacity-blocked excess;
5. record `resource.automatic_sale` and `capacity.overflow` audit events in that order.

This allows `sell_all` and `keep_amount` to retain unsold inventory up to the published cap, while `stockpile`—displayed as `上限まで備蓄`—sells only its overflow before discarding anything that still cannot fit. A failed turn rolls back the sale, revenue, inventory clamp, and audit events together. Owner API resources expose `capacity`, `remaining_capacity`, and `is_at_capacity`; exact inventories and capacities remain private.

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

## Next PR work and decisions

- Implement A-06 randomized sequential causality for the peaceful turn slice without converting it to simultaneous resolution.
- A-07 operational lock-duration budget and measurements with realistic Nation/cell counts; the atomicity decision remains in force unless replaced by a separate ADR.
- RES-01 farm production balance after converting the legacy 1,000 tons per scale.
- D-02 automatic retry/backoff and stale-running recovery.
- Exact command result/event schema and the seven handlers.
- Production, food consumption, population, forest, disasters, monsters, combat, missiles, oil and item specifications.
- Whether and when multiple Worlds justify a dedicated scheduler container.
