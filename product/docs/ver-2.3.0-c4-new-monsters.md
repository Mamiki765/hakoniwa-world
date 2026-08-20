# ver 2.3.0 C4 new monster gameplay

## Scope and activation boundary

C4 implements the approved Aoi Inora and Mecha Inora Zero runtime contracts against the shared inactive `V11SecretaryItemRulesetFixture`. It adds no formal `hakoniwa-2s-plus-v11.php`, publishes no v11 row, rebinds no World or gameplay data, backfills no historical request, and stores no custom GIF. Published v1-v10 settings, rows, checksums, and migrations remain immutable.

The C4 behavior is ruleset-owned. `source_metadata.behavior` is a closed contract for movement, dispatch eligibility, spawn-turn action, special action, island-creation displacement, and optional World spawn. The runtime resolver accepts the exact approved Aoi/Zero/ordinary shapes and fails closed for unknown fields or values. Historical rows without the field retain the legacy land-monster compatibility behavior; they do not gain Aoi, Zero, or World-spawn behavior.

## Turn pipeline and deterministic streams

The World Aoi stage runs once after ordinary global disasters and land subsidence and before Nation-scoped natural monster spawn. It is absent, query-free, and draw-free when the active TurnRun ruleset has no authored World-spawn behavior.

Aoi uses three dedicated versioned streams:

- `global_disasters:monster_spawn:world:trigger:v1`
- `global_disasters:monster_spawn:world:candidate:v1`
- `global_disasters:monster_spawn:world:hp:v1`

The trigger probability is `min(10_000, active-Nation-owned land cells) / 10_000`. Zero active owned land consumes no draw. A successful trigger loads and locks the surface cells once, computes the radius-3 land exclusion in memory, loads occupied cell IDs once, and selects uniformly from stable cell-ID-ordered neutral empty sea/shallow candidates. Shallow water is normalized to sea before occupancy creation. Failure to find a candidate is nondestructive and player-silent.

Both World-spawned Aoi and dispatched monsters are recorded in `TurnState` and excluded from the normal monster batch for that spawn turn.

## Aoi movement, reward, and island displacement

The authored Aoi movement contract permits only sea or shallow destinations. Empty neutral water emits the normal movement event only. A Nation-owned, populated, or removable-water-facility destination is normalized atomically to sea, owner null, population zero, and no facility; it emits movement plus the public/affected-Nation trample event with pre-impact owner, terrain, facility, and coordinates. Seabed base and seabed oil field are the only removable facility keys. Other facilities, land, protected facilities, bounds, and occupied cells remain blocked. `MonsterTurnBatch`, model versions, and changed chunks are updated through the same movement pass.

An attributed ordinary kill of hostless Aoi uses C3's `hostless_full_killer_money` policy through `MonsterDamageService`: all 1,200 wreckage money is requested for the killer, no host meat or unclaimed half is created, capacities still apply, and kill/cycle/base-experience behavior remains shared.

Initial Nation creation now separates deterministic plan creation from plan application. One immutable `InitialIslandPlan` is derived from the locked reservation prestate, exact ruleset, and seed. It contains exact changed cell IDs, prestates, final writes, changed chunks, and Capital identity. RNG is consumed only while creating that plan. Occupancies are then locked only for changed IDs. An explicitly authored displacement-capable Aoi is removed through the rewardless World-mutation path with reason `island_creation_displacement` and an admin-only audit containing request, World, ruleset, new Nation, monster, cell, and coordinate context. Any ordinary occupancy fails the transaction closed. Reserved but unchanged Aoi is untouched. The exact validated plan is then applied, so every provisional Nation/resource/request/Secretary/Item change rolls back on failure.

## Dispatch selector, dynamic cost, and request provenance

There remains exactly one `monster_dispatch` command. The inactive v11 shape owns this closed catalog:

| selector | monster | effective cost |
|---:|---|---:|
| `1` | `mecha_inora` / メカいのら | 3,000億円 |
| `2` | `mecha_inora_zero` / メカいのら零式 | 9,999億円 |

The definition cost remains the real selector-1 cost, 3,000. A narrow typed resolver is shared by ruleset validation, catalog/queue projection, registration, fingerprint input, execution validation and deduction, spawn selection, and success/failure/audit metadata. The UI presents selector labels and effective costs, sends the selector explicitly in the existing `quantity` field, and does not expose the ordinary quantity editor. Missing or invalid selectors, client monster keys, and client costs are rejected before mutation. Execution re-resolves the option from the TurnRun definition, so 9,998 fails through the existing insufficient-funds removal semantics while 9,999 succeeds and is deducted once.

C4 adds nullable `nation_command_queue_items.request_ruleset_version_id` as request provenance. Existing rows remain null; every newly authored single or bulk queue row stores the locked request ruleset. The request fingerprint uses that immutable identity and is never reconstructed from the mutable current queue position. Provenance does not require equality with a later execution ruleset: C5 must be able to retain v10 request identity while rebinding a safely attributable queued definition for v11 execution.

`HistoricalMonsterDispatchRequestInspector` is the reusable C5 boundary. It can prove only an exact v10 `monster_dispatch`, quantity 1, target-only parameter and coordinate snapshot, valid request key, supported queue/terminal status, and null-or-lowercase-64-hex fingerprint. It does not infer from cost, queue position, target Nation identity, database ID, display order, or current World ruleset. A proved row may normalize the omitted historical selector only to 1 for duplicate comparison while preserving fingerprint bytes. Selector 2 conflicts. Null fingerprints and all ambiguous rows remain conflict-only. C4 performs no backfill or rebind.

## Mecha Inora Zero action

Zero is authored with fixed HP4, no natural spawn, ordinary land movement while HP is above 1, zero wreckage money, standard attributed kill statistics, and missile-base experience 35 on an ordinary firing-base kill.

At the normal monster stage, HP1 is checked before hardening, movement limits, candidates, and defense contact. The occupied Zero is removed once with reason `nuclear_self_destruct`, no reward/stat/experience/cycle, and one public `monster.nuclear_self_destructed` event. The current huge-meteor settings are then applied at that exact fixed center through the already-loaded mutable cell index, without a center draw or generic trigger event. Collateral monsters use the rewardless terrain path and reason `nuclear_self_destruct_blast`; a collateral HP1 Zero cannot chain. The batch, changed cells, chunks, and later cell processing share the updated in-memory state.

Owned and neutral public trigger messages are exact:

```text
○○島(X,Y)のメカいのら零式が突然輝きだし、とてつもない爆発を起こしました！
中立海域(X,Y)のメカいのら零式が突然輝きだし、とてつもない爆発を起こしました！
```

Old Bow continues to call the shared authored target-safety policy. Zero HP2 is excluded because damage would leave the certain HP1 nuclear hazard; HP1 is a legal kill and HP3 is a legal nonlethal hit. No raw monster-key branch was added to the Item service.

## C5 entry contract

C5 alone owns the final v11 config/checksum, forward-only publication migration, safe provenance backfill, queued definition rebind, terminal provenance-only backfill, alive-monster and kill-stat rebind, World activation, all-or-nothing postconditions, and idempotent second run. C4's fixture and inspector are evidence and reusable seams; they are not publication or live conversion.

Before C5, preserve these C4 invariants:

- no formal v11 file or published row;
- no v1-v10 authored or migration rewrite;
- no World/data rebind or historical fingerprint rewrite;
- no production/OCI/production-DB access;
- no custom GIF binary;
- no `_references` change.

## Verification snapshot

The implementation is covered by focused ruleset, dispatch, provenance, Aoi spawn/movement/reward, island plan/displacement, Zero action/no-chain/message, and Old Bow safety regressions. The final PR validation also runs the complete migration chain on the isolated `hakoniwa_test` database, backend tests, PHPStan, Pint, frontend tests/lint/typecheck/build, open-question validation, immutable-source diffs, and exact-head CI/review gates.
