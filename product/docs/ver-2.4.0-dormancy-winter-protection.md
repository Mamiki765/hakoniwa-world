# ver 2.4.0 Nation dormancy, winter protection, and abandonment

> Historical release record. References to a "current" Ruleset or release below describe
> the ver 2.4.0 implementation context, not the current application contract. Current
> authoring is v16; see
> [`architecture/current-ruleset-baseline.md`](architecture/current-ruleset-baseline.md).

## Scope and authority

This slice implements the Owner decision supplied for ver 2.4.0. The current
`release/2.4.0` head at the start of work is
`9c9fee470789584f35bed21b9a2fdbea5df07594`. It replaces the old 30/180/365-day
proposal in ADR-0004; the old document remains as provenance and is marked
superseded. KARMA and `recovery` were outside this original v12 slice and are
now the formal ver 2.4.0 v13 extension documented in
`product/docs/ver-2.4.0-karma-recovery.md`.

The implementation rule is the existing canonical Turn, finance, damage,
monster, territory, abandonment, and asset paths plus a small explicit
lifecycle/protection/theme delta. No dormant-specific parallel engine is
introduced.

## Pre-implementation audit

| Area | Existing canonical owner | ver 2.4.0 delta |
|---|---|---|
| Turn state and ordering | `TurnState`, `TurnOrderService`, `CompleteTurnEngine` | Freeze `active`/`dormant` at target-turn start; decide automatic transitions at target-turn end. |
| Activity counter | `NationIdleCounterFinalizer` | Preserve one increment maximum and meaningful-command reset; new Nations start at 2000. |
| Finance | `DomesticCommandExecutor` finance path, `CapacityBoundedAssetService`, Secretary Ring effect | Reuse the same credit operation for a dormant heartbeat without consuming the queue. |
| Manual abandonment | `NationAbandonmentService` | Extract one locked internal cleanup operation for manual and automatic callers. |
| Damage and map effects | current missile, disaster, monster, and territory services | Call one shared Capital-radius protection policy immediately before missile/disaster/territory mutation; treat protected monster destinations as ordinary blocked candidates within the existing three-attempt loop. |
| Map presentation | `MapChunkService`, `MapCellPresenter`, `AssetManifestResolver` | Preload dormant Capitals once, select allowlisted `snow`, and fall back to the base asset. |
| Ruleset and upgrade | standalone immutable v11, `RulesetPublisher`, current-specific ver 2.4 installer/upgrader | Publish standalone v12 and support exact v11 to exact v12 only, preserving existing rows and provenance. |
| UI/API | owner profile and public Nation presenters | Extend the existing profile danger section with a neutral 1-7-day dormancy block immediately above the unchanged red abandonment block; add lifecycle fields/badges without a broad redesign. |

The third-party reference source contains no authoritative ver 2.4.0 contract
for these new semantics. The supplied Owner decision is authoritative; legacy
behavior is not inferred into the new rules.

## Test impact forecast (recorded before implementation)

Supported production contracts added or changed:

- exact `active <-> dormant`, `dormant -> abandoned`, and existing manual
  `active -> abandoned` lifecycle behavior at the official Turn boundary;
- dormant heartbeat finance/counter/queue behavior, deterministic emergency
  food/farm recovery, and canonical automatic cleanup;
- shared distance-2 protection for missile, disaster, monster, and territory
  mutation while preserving outside-radius behavior and RNG opportunity;
- presentation-only allowlisted snow assets, normal fallback, and visible
  credit;
- authenticated 1-7-day manual dormancy with World/row/TurnRun guards;
- immutable standalone v12 publication, fresh install, and exact-v11 forward
  migration with production provenance preservation.

Representative test owners:

- lifecycle integration: one new `NationDormancyTest`;
- creation and cleanup: existing `NationCreationTest` and
  `NationAbandonmentTest`;
- canonical finance and Ring: existing `SecretaryItemEffectsTest`;
- missile/disaster/monster/territory: existing `CommandAndMissileTest`,
  `DisasterAndOilTurnTest`, `MonsterSystemTest`, and
  `TerritoryExpansionAndInfluenceTest`;
- asset/API/frontend: existing `TileAssetTest`, `ApiAndAssetTest`, and
  `App.test.ts`;
- install/upgrade: existing `Ver240InstallUpgradeRebaselineTest` and
  `FreshInstallRebaselineTest`.

| Metric | Forecast | Reason |
|---|---:|---|
| New test files | 1 | One cohesive lifecycle/Turn-boundary contract cannot be read clearly from a subsystem-specific existing file. |
| New test identifiers | about 24 | Representative transitions, heartbeat, protection categories, theme/API, and exact upgrade; no Cartesian matrix. |
| Production-size World constructions | 0 | Use existing lightweight World/map fixtures. |
| Official Turn executions | about 6 | One representative normal/dormant transition path per materially different lifecycle boundary. |
| Migration/fresh-install executions | 3 | Successful exact-v11 upgrade, rejected unresolved-run upgrade, and current fresh install. |
| World expansions | 0 | Emergency farm uses a small existing map fixture. |
| Concurrency/lock orchestrations | 0 | Existing World/TurnRun lock contracts remain the representative owner; API tests prove their integration. |
| Performance profiles | 0 | No new performance threshold is introduced. |
| Estimated serial runtime delta | small | Focused lightweight fixtures plus three existing install/upgrade paths. |

The 12/84-turn manual boundary is proved from stored turn arithmetic and one
boundary Turn on each side; the test suite does not execute 84 otherwise
identical official Turns. Shared protection is proved once at policy level and
with one representative integration for each materially different mutation
category.

Manual dormancy uses the period selection and explicit no-early-resume notice
as confirmation. It does not request the Nation name and does not use the red
danger button/background/border. While dormant, the same block presents the
period, resume target Turn, remaining Turns/days, and disables or hides another
request. The existing irreversible abandonment modal, exact-name confirmation,
and red styling remain unchanged below it.

## Actual test impact

| Metric | Forecast | Actual | Reason for difference |
|---|---:|---:|---|
| New test files | 1 | 1 | `NationDormancyTest` owns the cohesive lifecycle and Turn-boundary path. |
| New identifiers | about 24 | 18 added / 13 retired (net +5) | Existing subsystem tests were rewritten around v12 instead of retaining obsolete state matrices; one redundant historical custom-Ruleset fixture was retired instead of mixing v12 lifecycle policy into it. |
| Production World constructions | 0 | 0 | All protection and lifecycle coverage uses lightweight Worlds. |
| Official Turn executions added | about 6 | 3 | Stored-turn assertions cover the 12/84 boundaries; only the due-turn pair and one automatic transition Turn execute. |
| Migration/fresh-install executions | 3 | 0 new fixtures; 9 existing upgrade scenarios and 2 existing fresh-baseline scenarios adapted | Existing representative install/upgrade tests already own successful and fail-closed cases. |
| World expansions | 0 | 0 | Emergency farm uses an existing generated map. |
| Concurrency/lock orchestrations | 0 | 0 | Existing lock contracts remain unchanged; request guards are covered without a second orchestration fixture. |
| Performance profiles | 0 | 0 | No performance threshold was introduced. |
| Estimated serial runtime delta | small | small | One lightweight test file and focused extensions; no production-size World or timing-only test. |

Final verification is recorded in the Ready PR against its exact head SHA.

## Owner addendum: formal label and event presentation

Before implementation, the Owner extended this slice with three presentation
contracts:

- the application release label is `2.4.0`; the published
  `hakoniwa-2s-plus-v12` payload remains immutable historical provenance, while
  the supported ver 2.4.0 World uses the forward-only v13 extension;
- each actual disaster cell/Capital mutation is projected into the public
  island log with the affected Nation, coordinate, and public-safe result, so a
  global disaster announcement can be followed by its concrete damage trail;
- secret facilities remain masked while they exist and when they are built,
  but a destruction or monster-trample log reveals the exact destroyed
  facility, including missile bases, decoys, and seabed bases;
- the development screen shows one owner island log. Its existing owner API
  already combines public events for that Nation with nation/private events by
  Turn, so the UI removes the redundant second panel instead of creating a new
  merge path. Existing companion-event deduplication remains valid; duplicate
  text is not treated as an error.

### Addendum test impact forecast

| Metric | Forecast | Ownership |
|---|---:|---|
| New test files | 0 | Extend `PlayerIslandEventApiTest`, `IslandEventLog.test.ts`, and the existing `App.test.ts` layout contract. |
| New test identifiers | 0 | Rewrite the representative disaster visibility and owner-log presentation assertions. |
| Database/World/Turn/migration fixtures | 0 | Reuse lightweight event projection fixtures; no gameplay or schema change. |
| Estimated runtime delta | negligible | Only existing API and component paths gain assertions. |

### Addendum actual test impact

| Metric | Forecast | Actual | Reason for difference |
|---|---:|---:|---|
| New test files | 0 | 0 | Existing representative API and component files own the presentation contract. |
| New test identifiers | 0 | 0 net | Existing disaster and secret-facility projection tests were renamed or rewritten in place. |
| Database/World/Turn/migration fixtures | 0 | 0 | Existing lightweight audit-event fixtures were reused. |
| Estimated runtime delta | negligible | negligible | Assertions only; no expensive fixture was added. |
