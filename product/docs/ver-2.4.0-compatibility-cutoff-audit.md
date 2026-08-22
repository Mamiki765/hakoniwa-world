# ver 2.4.0 data-preserving compatibility cutoff audit

> Status: documentation-only audit
>
> Date: 2026-08-22
>
> Repository: `Mamiki765/hakoniwa-world`
>
> Release base: `release/2.4.0`
>
> Audit branch: `codex/ver-2.4.0-compatibility-cutoff-audit`

## 0. Purpose and conclusion

This audit investigates a **data-preserving compatibility cutoff** for ver 2.4.0.

The intended distinction is:

```text
End support for executing historical application / Ruleset behavior
!=
Delete production player data or audit history
```

The repository evidence supports narrowing the future product contract to an exact supported source state, but it does **not** yet support deleting historical source, migrations, tests, or runtime branches in this PR.

The most important findings are:

1. Current mutation runtime is already effectively current-only: `CurrentRulesetGuard` rejects a World whose loaded Ruleset identity differs from the configured current Ruleset.
2. Configuration boot is not current-only: `product/config/hakoniwa.php` requires 10 roadmap Rulesets and formal v1 through v11, for 21 authored source payloads.
3. The current v11 source is not standalone. It inherits v10, which inherits v9, continuing back through formal v1 and the roadmap chain.
4. Normal current application code still has a direct historical config dependency: `SecretaryService::ensureForUser()` reads `hakoniwa-2s-plus-v7` to initialize Secretary skills.
5. Normal command duplicate handling still contains an exact v10 compatibility path through `HistoricalMonsterDispatchRequestInspector`.
6. Historical DB rows can remain immutable audit records without remaining executable source, but read-only presentation must be proven against DB snapshots before source files leave normal config.
7. The normal fresh-install path currently replays every migration in `product/database/migrations`; no current-schema direct-install baseline or schema dump was found.
8. Exact v11-only upgrade is feasible as a future contract only after a fail-closed source preflight, global unresolved TurnRun precondition, live-reference/provenance proof, and backup/restore rehearsal exist.
9. The current test tree contains exactly 112 PHPUnit files. The last executed identifier inventory recorded 1,042 identifiers; this Chat did not execute PHPUnit and therefore did not independently reproduce that identifier count.
10. At least 16 dedicated historical migration/Ruleset test files become removal or archive candidates after the product contract changes. This is a candidate set, not an approved deletion count.

Recommended sequence:

```text
PR A: this dependency audit
PR B: standalone current Ruleset and current-only normal config/runtime
PR C: exact v11 production upgrade plus direct fresh-install baseline
PR D: remove or archive historical tests/code whose contract has ended
```

No gameplay, balance, Ruleset, schema, migration, runtime, or test code is changed by this audit.

---

## 1. Current GitHub baseline

### 1.1 Branch and PR state at audit start

| Item | Confirmed state |
|---|---|
| `main` | `6c75e869d9c223f87c9e1811bf98ca1c78a69e60`, PR #71 merge, ver 2.3.0 integration |
| PR #72 | merged into `release/ver-2.3.1` |
| PR #72 final implementation HEAD | `34ffd53cb60bf4605a520f93dd3eb4001879c7d7` |
| PR #72 merge commit | `502cf0172ffea4d18cc59e20b6bea2f23fad0f3c` |
| `release/ver-2.3.1` | canonical ver 2.3.1 release baseline at the PR #72 merge |
| `release/2.4.0` | created from the canonical ver 2.3.1 release baseline |
| `release/ver-2.4.0` | not present at audit start; the Owner's latest explicit branch name is `release/2.4.0` |
| open PRs before this audit PR | 0 |
| audit branch | `codex/ver-2.4.0-compatibility-cutoff-audit`, created from `release/2.4.0` |

`main` is not yet the ver 2.3.1 baseline. Therefore this audit correctly starts from the release branch rather than from the older default branch.

### 1.2 Application and Ruleset identity

`release/2.4.0` currently configures:

```text
application_version: 2.3.1
Ruleset key: hakoniwa-2s-plus-v11
Ruleset version: 11
checksum: 5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8
```

The v11 migration source identity is exact v10:

```text
source key: hakoniwa-2s-plus-v10
source version: 10
source checksum: 6a0f5354f8894081bacdb8eaaba328d3e4ee80a2c4136819b16cfa924f485dc1
```

### 1.3 Evidence limits

This audit used GitHub repository reads only.

Not performed:

- local Composer install;
- PHP, PHPUnit, Docker, PostgreSQL, Artisan, Pint, or PHPStan execution;
- test identifier enumeration;
- production DB queries;
- OCI or production host access;
- deploy, migration, backup, restore, or official turn execution.

Repository structure and prior exact-head CI documentation are evidence only for what they explicitly record. No unexecuted command is reported as successful.

---

## 2. Test inventory

### 2.1 Current size

The current audit branch tree contains:

| PHPUnit location | File count | Evidence type |
|---|---:|---|
| `product/tests/Feature` | 78 | current repository tree |
| `product/tests/Unit` | 34 | current repository tree |
| Total PHPUnit files | **112** | current repository tree |

The latest executed identifier inventory in `product/docs/ver-2.3.1-test-rationalization.md` records:

```text
serial identifiers: 1,042
assigned identifiers: 1,042
missing: 0
duplicate: 0
unexpected: 0
```

This audit did not rerun the identifier script. Therefore 1,042 is the latest recorded executed value, not a newly measured value.

### 2.2 Current CI topology

`.github/workflows/quality.yml` currently:

- defaults to 16 PHPUnit shards;
- verifies complete file assignment;
- compares serial and sharded test identifiers without executing test bodies twice;
- executes each assigned PHPUnit file once in a shard job;
- runs frontend typecheck, lint, tests, and build separately;
- uses a 30-minute timeout per PHPUnit shard.

The existing workflow is not doing one complete serial PHPUnit run plus one complete sharded PHPUnit run. The long wall time therefore comes mainly from the retained test contracts and shard imbalance, not an obvious duplicate full execution.

---

## 3. Test classification

Classification meanings:

- **KEEP**: remains part of the current production contract.
- **UPGRADE-ONLY**: needed for a supported source-to-current upgrade, not normal runtime forever.
- **ARCHIVE**: preserves historical evidence but need not remain in the normal correctness gate.
- **REMOVE CANDIDATE**: contract can end after replacement evidence exists.
- **UNKNOWN**: repository evidence is insufficient for a final decision.

### 3.1 Meaningful test groups

| Group | Representative files | Primary contract | Classification after rebaseline |
|---|---|---|---|
| Current turn/gameplay | `CompleteTurnIntegrationTest`, `TurnCellProcessingTest`, `TurnEconomyTest`, `DomesticCommandExecutionTest`, `CommandAndMissileTest`, `C4NewMonstersTest`, `DisasterAndOilTurnTest`, Secretary/Item integration tests | current gameplay, phase order, balance, events | KEEP |
| Current API/UI/backend presentation | `ApiAndAssetTest`, `PublicLobbyApiTest`, `PlayerIslandEventApiTest`, `MessageBoard*`, `AnnouncementApiTest`, `Inquiry*`, frontend tests | current player-visible contract | KEEP |
| DB integrity | `RulesetImmutabilityTest`, `MessageBoardIntegrityTest`, `SecretaryEquipmentSchemaTest`, `MonsterFoundationPersistenceTest`, capacity/integrity assertions | immutable records, FK/CHECK/UNIQUE/trigger behavior | KEEP |
| Transaction and rollback | `TurnRunnerTest`, `RuntimeMetadataFailureTest`, command/Item retry tests | one-turn atomicity, fail-closed rollback | KEEP; consolidate duplicate corrupt-fixture cases only with replacement proof |
| Concurrency and locks | PostgreSQL registration, equipment, OAuth, abandonment and World-lock tests | lock ordering and race safety | KEEP |
| Current retry/idempotency | `TurnRunnerTest`, request-key/fingerprint tests, equipment optimistic-concurrency tests | current same-run/same-seed and request idempotency | KEEP |
| Performance/query budget | `TurnRuntimePerformanceTest`, `MonsterPerformanceTest`, `TerritoryInfluencePerformanceTest`, `RuntimeMutationQueryCountTest` | current hot-path regression | KEEP initially; remeasure after implementation |
| Current Ruleset validation | `RulesetV11ContractTest`, current parts of `RulesetAuthoringValidatorTest`, `RulesetValidationCommandTest` | current authored payload and publisher contract | KEEP until replaced by standalone canonical Ruleset tests |
| Historical formal Ruleset contracts | `FirstProductionRulesetContractTest`, `RulesetV6ContractTest` through `RulesetV10ContractTest` | exact old payload behavior/checksum | ARCHIVE / REMOVE CANDIDATE from normal CI |
| Historical migration suites | `RulesetV2MigrationTest`, `RulesetV2LiveMonsterReferenceRepairTest`, `RulesetV3MigrationTest` through `RulesetV10MigrationTest` | direct upgrade and exact idempotency of unsupported source versions | ARCHIVE / REMOVE CANDIDATE from normal CI after direct old-source upgrades end |
| v11 migration | `RulesetV11MigrationTest` | v10 to v11 conversion, provenance, live-row rebind, fail-closed guards | UPGRADE-ONLY while exact v11 source baseline is established; later archive with source release artifact |
| First production release | `FirstProductionReleaseTest`, production configuration tests | original v1 go-live and operator baseline | mixed: retain current production safeguards; archive v1-specific assertions |
| Historical World read-only boundary | `CurrentRulesetRuntimeBoundaryTest` | old World data remains readable; mutation is rejected | KEEP, but split read-only-history proof from pre-release `reset_required` wording if the contract changes |
| Documentation/version checkpoint | `Ver230DocumentationContractTest` and release-document assertions | past release documentation identity | ARCHIVE / REMOVE CANDIDATE after historical docs become archive artifacts |
| Test infrastructure | `TestShardPlannerTest`, `ParallelTestDatabaseManagerTest` | CI assignment and isolated test DB operation | KEEP while that infrastructure remains |
| Extreme-value and malformed-fixture tests | portions of validator, API, expansion, RNG, monster and metadata suites | mixed input boundary, algorithmic bound, or artificial corruption | classify case-by-case; do not delete as one category |

### 3.2 Dedicated historical file candidate set

The following 16 files are the clearest dedicated candidates once direct pre-v11 upgrade and old payload execution leave the supported contract.

Feature migration files:

1. `RulesetV2MigrationTest.php`
2. `RulesetV2LiveMonsterReferenceRepairTest.php`
3. `RulesetV3MigrationTest.php`
4. `RulesetV4MigrationTest.php`
5. `RulesetV5MigrationTest.php`
6. `RulesetV6MigrationTest.php`
7. `RulesetV7MigrationTest.php`
8. `RulesetV8MigrationTest.php`
9. `RulesetV9MigrationTest.php`
10. `RulesetV10MigrationTest.php`

Unit historical contract files:

11. `FirstProductionRulesetContractTest.php`
12. `RulesetV6ContractTest.php`
13. `RulesetV7ContractTest.php`
14. `RulesetV8ContractTest.php`
15. `RulesetV9ContractTest.php`
16. `RulesetV10ContractTest.php`

This list does not authorize deletion. It identifies tests whose only durable value is historical execution compatibility, provided equivalent archive evidence and the new exact-v11 upgrade contract are established first.

---

## 4. Test to contract to code dependency

| Test/group | Contract protected | Code/runtime dependency | Future product contract? |
|---|---|---|---|
| `RulesetV2MigrationTest` through `RulesetV10MigrationTest` | every historical source can be upgraded and re-run exactly | historical migrations, source payloads, old checksums and conversion branches | No, if direct upgrade source is exact 2.3.1/v11 only |
| `RulesetV2LiveMonsterReferenceRepairTest` | a specific v2 repair remains repeatable | v2 repair migration and old monster definitions | No for normal CI; archive evidence |
| `RulesetV11MigrationTest` | v10 data reaches v11 without losing history, fingerprints, provenance, Items or live references | `RulesetV11MigrationService`, v10/v11 payloads, triggers and constraints | UPGRADE-ONLY until v11 is the proven canonical source |
| old Ruleset contract tests | exact old balance, metadata and checksums remain executable | all authored old Ruleset source files and validator support | No for normal runtime; keep manifest/archive evidence |
| `CurrentRulesetRuntimeBoundaryTest` | historical DB state remains readable while mutation is blocked | DB `RulesetVersion` relationships, presenters, `CurrentRulesetGuard` | Yes for read-only audit history; historical execution is not required |
| `RulesetImmutabilityTest` | published DB snapshot and definitions cannot drift | `RulesetPublisher`, DB constraints/triggers | Yes |
| `TurnRunnerTest` | current transaction, rollback, retry, status and seed semantics | `TurnRunner`, World lock, TurnRun model | Yes |
| `RuntimeMetadataFailureTest` | corrupt current DB metadata fails without partial game mutation | runtime metadata readers plus transaction rollback | Yes in representative form; individual artificial cases may consolidate |
| command request history tests | duplicate request keeps key/fingerprint/provenance semantics | `CommandQueueService`, `HistoricalMonsterDispatchRequestInspector`, request definition lookup | Current request integrity yes; exact v10 reconstruction only UPGRADE-ONLY |
| `FirstProductionReleaseTest` | original v1 fresh go-live and production invariants | first-release migration/config and current safety code | mixed; split current safeguards from historical v1 reproduction |
| performance tests | current paths stay within query/runtime budgets | current services and fixtures | Yes, but baseline must be remeasured after rebaseline |
| current gameplay integration | current player-visible behavior | current Ruleset, Turn engine, services | Yes |

### 4.1 Code that exists partly because historical tests require it

Strong candidates after replacement proof:

- historical source files kept in normal `published_rulesets` config only so old migration/contract tests can resolve them;
- `CompleteTurnEngine::calculateTerrainContext()` compatibility work for historical `sea_edge_bands` payloads;
- `MonsterDisplayOrderResolver` fallback from missing `display_order` to historical `source_metadata.kind`;
- exact v10 selector-less monster dispatch reconstruction in `HistoricalMonsterDispatchRequestInspector`;
- the historical duplicate-request branch in `CommandQueueService` that loads request-time old definitions;
- old migration-specific checksum and partial-state branches not used by the exact supported source upgrade;
- old release assertions that force current CI to validate superseded balance snapshots.

These are not dead merely because a test names them. Current production rows may still depend on them until the source preflight and migration postconditions prove otherwise.

---

## 5. Ruleset dependency inventory

### 5.1 Authored source inventory

`product/config/hakoniwa.php` explicitly loads 21 authored Ruleset files:

#### Roadmap snapshots: 10

```text
roadmap-pr2-v1
roadmap-pr6-v1
roadmap-pr7-v1
roadmap-pr11-v1
roadmap-pr14-v1
roadmap-pr15-v1
roadmap-pr18-v1
roadmap-pr19-v1
roadmap-pr21-v1
roadmap-pr22-v1
```

#### Formal production snapshots: 11

```text
hakoniwa-2s-plus-v1
hakoniwa-2s-plus-v2
hakoniwa-2s-plus-v3
hakoniwa-2s-plus-v4
hakoniwa-2s-plus-v5
hakoniwa-2s-plus-v6
hakoniwa-2s-plus-v7
hakoniwa-2s-plus-v8
hakoniwa-2s-plus-v9
hakoniwa-2s-plus-v10
hakoniwa-2s-plus-v11
```

`RulesetAuthoringCollection::fromFiles()` executes `require` for every listed path during config construction. This means historical authored PHP remains part of normal configuration boot even though the mutable World must use v11.

### 5.2 Classification by role

| Ruleset source | Application boot | Normal mutation | TurnRun | Migration | Tests | Future classification |
|---|---|---|---|---|---|---|
| Pre-MVP `mvp-v1` prototype | no current file | no | no | recognized by earliest migration/history | historical evidence | ARCHIVE in Git history/manifest |
| roadmap snapshots | yes, all 10 | no supported current mutation | historical DB/read fixture only | publication/source checks | many historical tests | remove from normal config; ARCHIVE / UPGRADE-ONLY artifact |
| formal v1-v5 | yes | no supported current mutation | historical DB/read fixture only | publication/conversion | historical tests | remove from normal config after standalone baseline |
| formal v6 | yes | no supported current mutation | historical fixture | later source chain/migrations | contract/migration tests | ARCHIVE / UPGRADE-ONLY |
| formal v7 | yes | **yes, indirectly**: `SecretaryService` reads it for initial skill state | current user registration path | Secretary migration/history | tests | blocker: move current initialization contract before removal |
| formal v8-v9 | yes | no direct current application reader found | historical fixture/chain | publication/migration | tests | ARCHIVE / UPGRADE-ONLY |
| formal v10 | yes | duplicate request historical inspection and v11 migration source | old request provenance compatibility | v11 migration | contract/migration tests | UPGRADE-ONLY; remove normal execution dependency after proof |
| formal v11 | yes | current | current | current publication/migration target | current tests | KEEP until replaced by new standalone canonical Ruleset |
| `V11SecretaryItemRulesetFixture` | no | no | test only | no | Secretary Item tests | replace or retain as current test fixture |
| DB `ruleset_versions` rows | DB, not authored config | current row drives current World; old rows support history | TurnRun FK/history | source and target rows | tests | KEEP immutable audit records |

### 5.3 Important dependency chain

The current v11 authored source is not self-contained:

```text
v11 -> v10 -> v9 -> ... -> v1 -> roadmap-pr22 -> ... -> roadmap-pr2
```

Therefore deleting historical files or reducing the config list before introducing a standalone current payload would break v11 construction.

### 5.4 Current direct historical config dependency

`SecretaryService::ensureForUser()` reads:

```php
config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v7')
```

to construct initial Secretary skill rows.

Before current-only config, this must become one of:

- a current canonical Ruleset section;
- a dedicated current Secretary initialization catalog;
- a typed current snapshot resolved at application boot.

It must not silently continue depending on v7 after v7 leaves normal config.

---

## 6. Normal runtime and historical compatibility call graph

### 6.1 Current mutation path

```text
request / turn / queue mutation
-> load World and DB RulesetVersion
-> CurrentRulesetGuard::assertMutable
-> current service path
```

A historical World is rejected with `reset_required` before mutation. Therefore the repository already does not promise ordinary mutation execution for arbitrary old Worlds.

### 6.2 Historical read-only path

`CurrentRulesetRuntimeBoundaryTest` proves that a historical World can still expose:

- public summary/ranking/events;
- Nation and map presentation;
- owner read-only map/queue/sale-policy views;
- Turn status and stored Ruleset snapshot.

The future contract should preserve **readable audit history**, not historical execution. Follow-up implementation must prove these presenters can use DB snapshots and historical definition rows without loading all authored source PHP at normal boot.

### 6.3 Historical duplicate-request path

`CommandQueueService::add()` always checks for a duplicate request key before normal registration. For historical monster-dispatch rows it can:

- inspect v10 request identity;
- find the old request Ruleset and command definition;
- reconstruct old quantity/parameter semantics;
- recompute and compare the historical fingerprint.

`HistoricalMonsterDispatchRequestInspector` is an exact v10 compatibility component in a current request path. It may be removed only after the upgrade guarantees one of these outcomes:

1. no request key requiring this reconstruction can be retried; or
2. every retained row contains enough canonical request provenance/fingerprint data to compare without executable v10 semantics.

### 6.4 Current legacy data guard

`MonsterKillCycleService::assertLegacySeedCoverage()` is called by current `TurnRunner` and current award/stat paths. It is not merely historical test support. The future source upgrade must prove all required seed rows complete or replace the legacy requirement table with an equivalent canonical postcondition before this guard can be removed.

---

## 7. Migration and fresh-install inventory

### 7.1 Current migration roles

All migrations remain in the ordinary Laravel `product/database/migrations` directory. They include:

| Role | Examples | Current reason |
|---|---|---|
| Foundation schema | users/cache, `2026_07_26_000000_create_hakoniwa_schema.php` | create base application/database |
| Historical schema evolution | queue parameters, TurnRuns, profile/auth/message/award/Secretary/Item tables | incrementally reaches current schema |
| Historical data conversion | axial-to-staggered coordinates, food normalization, per-World Nation numbers, timestamp repair, live-reference repairs | transforms old persisted data |
| Roadmap Ruleset publication | PR2, PR6, PR7, PR11, PR14, PR15, PR18, PR19, PR21, PR22 migrations | reconstructs prototype/MVP publication history |
| First formal production baseline | `2026_08_05_020000_prepare_first_production_release.php` | publishes formal v1 and go-live schema boundary |
| Formal Ruleset upgrades | v2 through v11 publication/conversion migrations | historical production upgrade chain |
| Current production conversion | v10 to v11 migration and Item/live-reference/provenance postconditions | produces current v11 state |
| Historical audit | immutable `ruleset_versions`, definitions, TurnRuns, events and request provenance | retained DB history |

### 7.2 Fresh install conclusion

No current-schema direct-install migration set or schema dump was found in the repository. A normal fresh migration therefore replays the historical migration chain from the initial schema through roadmap publication and formal v1-v11.

This is a product contract only because all migrations are placed in the normal migration directory. It is not inherently required by Laravel or by production data preservation.

A future direct fresh-install baseline can instead create:

```text
current canonical schema
+ current canonical Ruleset
+ current required catalog rows
```

while retaining the old chain as an upgrade/archive artifact outside the normal fresh path.

### 7.3 Exact v11 source upgrade result

If the only supported production source becomes exact application 2.3.1 / v11, the future production upgrade does not need to implement direct:

```text
v8 -> 2.4.0
v9 -> 2.4.0
v10 -> 2.4.0
roadmap snapshot -> 2.4.0
```

Those sources must first reach exact v11 under the old supported application.

This ends future responsibility for:

- direct old-source migration branches;
- normal CI execution of v2-v10 migration suites;
- old Ruleset mutation/runtime compatibility;
- current fresh install replaying every publication migration;
- old application rollback after the new baseline commits.

It does not end responsibility for retaining historical DB rows, command history, audit records, provenance or backup restoration.

---

## 8. Historical retry dependency

### 8.1 Current TurnRunner behavior

`TurnRunner`:

- requires the World to use the configured current Ruleset;
- creates or reuses one non-dry run for the next target turn;
- rejects automatic cron retry for `failed` or `blocked`;
- permits manual retry only while the run and World still reference the same Ruleset;
- keeps target turn, Ruleset and random seed identity;
- rolls game state back on failure and records bounded failure information.

These current retry/idempotency guarantees remain KEEP.

### 8.2 Proposed upgrade precondition

The new cutoff should require, before any baseline migration:

```text
all non-dry TurnRuns with status
pending / running / failed / blocked
= 0
```

The current repository does not yet expose this exact global cutoff contract. Existing migrations and preflight logic generally focus on the next production TurnRun for each World. A future implementation must decide whether the zero condition applies:

- across every World;
- only supported production Worlds;
- only the next target turn or every unresolved historical row.

The Owner intent in this audit is the strict global unresolved condition for supported production Worlds. It must be implemented and tested explicitly rather than inferred from the v11 migration guard.

### 8.3 Post-cutoff behavior

After successful migration:

- old failed TurnRuns remain readable audit rows;
- old failed TurnRuns are not executable by the new application;
- unresolved old work must be resolved under the source application before upgrade;
- current application retries continue only for current-baseline TurnRuns.

---

## 9. Extreme-value and defensive-code inventory

### 9.1 KEEP boundaries

| Boundary | Evidence/reason | Decision |
|---|---|---|
| HTTP/player parameter validation | attacker-controlled input | KEEP |
| authorization and membership | security boundary | KEEP |
| DB type, FK, CHECK, UNIQUE and trigger constraints | persistent integrity | KEEP |
| actual gameplay capacity and resource/money caps | player-visible balance/integrity | KEEP |
| quantity and selector validation | player-controlled future plans | KEEP |
| Nation-number allocation maximum | allocation boundary before a signed integer identifier is created | KEEP at the allocation boundary |
| transaction and lock ordering | prevents partial turn and concurrent corruption | KEEP |
| request key/fingerprint/provenance | current idempotency and audit identity | KEEP |
| deterministic RNG signed 32-bit bounds | part of the unbiased 32-bit sampling algorithm, not speculative gameplay defense | KEEP |
| cheap fail-closed checks when reading mutable DB state | corruption must not silently commit | KEEP, preferably once per trust boundary |

### 9.2 MOVE or REMOVE candidates

| Candidate | Current form | Proposed direction |
|---|---|---|
| Monster display-order integer maximum in every resolution | runtime checks PostgreSQL integer max and historical fallback | enforce at publication/DB; current hot path consumes typed valid value; retain duplicate-order check where required |
| Historical display order from `source_metadata.kind` | current resolver supports old definitions without explicit order | remove after all current/read presentation definitions have canonical order |
| Exact monster-dispatch metadata validation | full exact v11 two-option catalog is revalidated whenever options/defaults resolve | validate once at publication or current snapshot load; keep selector availability validation at input boundary |
| Historical v10 duplicate-request reconstruction | current request path loads old Ruleset/definition and validates old shape | remove after provenance conversion and retry cutoff prove it unreachable |
| Repeated full Ruleset array-shape checks | publisher, contract resolver and runtime readers may repeat the same immutable validation | publisher validates full source; runtime checks only mutable references and minimal fail-closed invariants |
| Test-fixture-only corrupt metadata permutations | tests directly mutate immutable DB payloads to prove many individual messages | retain representative transaction rollback/integrity proof; consolidate cases that add no separate production contract |
| Old incomplete payload compatibility | branches exist only for authored historical payload shapes | remove after historical execution ends and DB presentation is source-independent |
| Theoretical max-int cases unrelated to an input/allocation/algorithm boundary | no reachable gameplay producer | remove test and branch only after proving there is no persistent or external producer |

### 9.3 Not all 2.1-billion checks are the same

Examples that should not be grouped together:

- `DeterministicRandomStream` signed 32-bit bounds are required by its sampling algorithm.
- command parameter maxima are an external-input boundary.
- Nation-number exhaustion is an allocation boundary.
- a repeated display-order maximum check after DB/publication validation is a hot-path simplification candidate.

The correct question is not whether a large number appears. It is whether the check sits at the boundary that can create the invalid value.

---

## 10. KEEP

The following remain production contracts:

- World, Nation, MapCell and ownership state;
- money, resources, capacities and sale policy state;
- Secretary, skills, Item instances and equipment;
- command history and terminal states;
- audit/event history;
- request key, exact fingerprint bytes and request provenance;
- migration-time valid current TurnRun and RNG identity/history;
- historical DB `RulesetVersion` and definition rows as immutable audit data;
- current transaction, rollback, lock and concurrency behavior;
- current retry/idempotency semantics;
- current deterministic random streams;
- DB integrity constraints and triggers;
- current gameplay/API/UI behavior;
- read-only presentation of retained history;
- pre-upgrade backup and supported-source restore/re-upgrade capability.

Essential tests include current gameplay integration, TurnRunner rollback/retry, DB integrity, lock/concurrency, request identity, current Ruleset contract, one production-like source upgrade, one restore/re-upgrade rehearsal, and historical read-only presentation.

---

## 11. UPGRADE-ONLY

The following should be available to the supported upgrade path but need not remain normal runtime forever:

- exact v10-to-v11 migration service and its checks while establishing the 2.3.1/v11 source;
- v10/v11 source checksums and definition mappings;
- v11 migration tests for data preservation, fingerprints, provenance, live reference rebinding and fail-closed source checks;
- historical authored payloads required to build an exact source fixture until a frozen source-release artifact exists;
- legacy seed-coverage conversion requirements until current data postconditions prove complete;
- exact v11 source preflight and production-like fixture.

After the new baseline is deployed and recovery artifacts are proven, these can move to an upgrade/archive package rather than every current application boot and normal PR CI.

---

## 12. ARCHIVE

Archive candidates retain evidence without remaining normal executable product code:

- Pre-MVP prototype identity and repository history;
- roadmap Ruleset source/checksum manifest;
- formal v1-v10 payload/checksum manifest;
- superseded release docs and ADR context;
- old migration source plus exact source-application release/tag/container identity;
- old migration test results or frozen upgrade fixture;
- old behavior regressions not part of current gameplay.

Archive does not mean deleting DB rows or rewriting history. It means moving historical reproduction out of normal boot/runtime/current PR correctness gates.

---

## 13. REMOVE CANDIDATE

Removal requires a preceding contract change and replacement proof.

Candidates:

- 20 historical authored Ruleset inputs from normal application config after a standalone current payload exists;
- roadmap and formal v1-v10 contract tests from normal CI;
- direct v2-v10 migration suites from normal CI after exact v11 becomes the only supported source;
- historical Ruleset execution branches in current turn processing;
- historical display-order fallback;
- exact v10 selector-less duplicate-request inspector and its current-path wiring after provenance/cutoff proof;
- repeated immutable catalog validation in hot paths;
- duplicate corrupt-fixture tests whose contract is already covered by publisher validation, DB integrity and representative rollback tests;
- historical release/checksum assertions duplicated by an archive manifest;
- normal fresh-install replay of roadmap and old formal publication migrations.

No candidate is removed in this audit PR.

---

## 14. UNKNOWN

Repository evidence cannot answer:

- the actual production application version currently deployed;
- the actual production World Ruleset row;
- whether every supported production World is exact v11;
- whether any unresolved non-dry TurnRun exists in production;
- whether all queued command, alive monster and current kill-stat references are v11;
- whether every relevant request has complete provenance/fingerprint bytes;
- whether legacy monster-cycle seed requirements are complete;
- whether a recent production backup has passed restore and re-upgrade rehearsal;
- whether every historical read-only API is independent from authored source after normal config reduction;
- stable per-file test durations on the future baseline;
- the actual wall-time reduction from removing historical contracts;
- whether historical migrations remain in-repository, move to an upgrade package, or are preserved only by a tagged source release plus manifest.

These are blockers or Owner decisions, not assumptions to fill in.

---

## 15. Runtime code unlocked by ending historical test contracts

If the new contract and production evidence are established, removing historical tests can safely unlock removal or simplification of:

1. normal config loading of roadmap and formal v1-v10 payloads;
2. old payload inheritance required only to construct v11 source;
3. historical turn branches such as legacy sea-edge calculation;
4. historical monster display-order fallback;
5. exact v10 monster-dispatch request reconstruction;
6. old direct-upgrade migration services and source checks from current application wiring;
7. duplicated old checksum assertions;
8. repeated immutable array/catalog validation that is already authoritative at publication;
9. old release-specific validation cases that do not protect current gameplay or retained data.

The dependency direction matters:

```text
end product contract
-> migrate/prove retained data
-> remove runtime dependency
-> remove obsolete test
```

Deleting the test first is not evidence that the code is obsolete.

---

## 16. Current-only runtime blockers

Before normal runtime/config can load only the canonical current Ruleset:

1. A standalone complete current Ruleset does not exist; v11 inherits the full historical source chain.
2. `SecretaryService` directly reads formal v7 during current user/Nation registration.
3. `product/config/hakoniwa.php` constructs all 21 authored payloads at boot.
4. migrations and validation commands resolve historical payloads through `published_rulesets`.
5. historical DB read-only presentation must be tested with historical source files absent from normal config.
6. current duplicate request handling contains exact v10 semantics.
7. current turn/stat code still enforces legacy monster-cycle seed coverage.
8. unresolved old TurnRuns and live old definition references have not been proven absent in production.
9. current test fixtures and contract tests assume old keys remain in normal config.
10. backup/restore behavior after source separation has not been rehearsed.

---

## 17. Exact 2.3.1/v11-only upgrade blockers

Before declaring exact application 2.3.1 / v11 the only supported source:

1. Verify production source application identity; `main` is still ver 2.3.0 and repository state alone does not prove deployed state.
2. Verify every supported production World references exact v11 and expected checksum.
3. Require zero non-dry `pending`, `running`, `failed` and `blocked` TurnRuns according to the final World scope.
4. Verify queued commands use current execution definitions while retaining request-time provenance.
5. Verify alive monsters and current kill stats use current definitions; retain terminal history on historical definitions.
6. Verify request keys, fingerprint bytes, parameters, targets and provenance are complete and unchanged.
7. Verify Secretary/skills/Items/equipment and all player balances match pre-upgrade snapshots.
8. Verify legacy seed requirements are complete or canonically converted.
9. Produce a pre-upgrade backup and complete restore/re-upgrade rehearsal.
10. Run one successful official source-version turn before upgrade and one controlled current-baseline turn after upgrade.
11. Define recovery as backup restore to the supported source plus forward re-upgrade, not old-application execution against the migrated DB.

Failure of any precondition must stop the upgrade before mutation.

---

## 18. Canonical current Ruleset rebaseline proposal

A follow-up PR should create a new immutable Ruleset identity, for example `hakoniwa-2s-plus-v12`, as one complete payload that does not `require` v11 or any historical source.

Suggested readable sections:

```text
basic identity and map
terrain and facilities
economy and resources
commands
turn processing and disasters
monsters
missiles and combat
Secretary skills
Items and equipment
```

Requirements:

- gameplay must remain byte/behavior-equivalent to the selected current v11 baseline unless a separately approved gameplay PR changes it;
- current Secretary initialization must come from the new current contract, not v7;
- normal config registers only the current authored payload;
- historical DB Ruleset rows remain immutable and readable;
- historical source/checksums move to an explicit archive/upgrade artifact;
- publication validates the complete payload once;
- runtime validates mutable inputs/references rather than re-walking the immutable payload in each service.

This audit does not create v12.

---

## 19. Fresh-install rebaseline proposal

Separate two paths:

### Fresh install

```text
current schema baseline
-> current catalogs
-> current canonical Ruleset
-> current World initialization
```

### Existing production upgrade

```text
exact 2.3.1 / v11 source
-> fail-closed source and unresolved-run preflight
-> one forward data-preserving migration
-> current canonical baseline
```

Possible implementation shapes for Owner selection:

- Laravel schema dump plus a small current-baseline migration set;
- a new baseline migration directory selected only for empty databases;
- a generated current schema artifact plus explicit production-upgrade migrations.

Do not use `migrate:fresh` against production. Do not delete production history to make fresh install simpler.

---

## 20. Expected reduction

### 20.1 Config/runtime source reduction

If normal config changes from 21 authored Ruleset inputs to one standalone current input:

```text
21 -> 1
20 fewer historical source payloads in normal config construction
approximately 95% fewer authored Ruleset inputs at boot
```

This is a structural count, not a measured wall-time improvement.

### 20.2 Test reduction candidates

At least 16 dedicated historical test files are identified in section 3.2. Additional mixed files contain historical-only methods that may be split or consolidated.

Potential outcomes include:

- fewer historical migration database resets;
- fewer old Ruleset validations/checksum calculations;
- less shard imbalance from migration-heavy files;
- fewer places that must change when the current Ruleset evolves.

Do not claim a target file count, identifier count, or CI duration until the implementation PR runs exact-head CI and reports before/after data.

### 20.3 Runtime/code reduction candidates

Expected structural reductions:

- no 21-file source construction on normal config boot;
- fewer old payload branches in Turn and presentation resolvers;
- no exact v10 request reconstruction after proven cutoff;
- fewer repeated immutable metadata validations;
- simpler source-upgrade preflight with one supported input state.

---

## 21. Unresolved Owner decisions

1. Is the supported upgrade source exactly one World at exact v11, or every configured production World at exact v11?
2. Must the unresolved TurnRun zero check cover all historical unresolved non-dry rows or only each World's next target turn?
3. Should historical authored PHP remain in the repository under an archive path, or be preserved by tagged releases plus a checksum manifest?
4. Should old migration tests move to an optional upgrade workflow, or leave normal CI entirely after the cutoff?
5. Should historical World owner/private read APIs remain fully supported, or only public/audit presentation?
6. Which fresh-install technique is preferred: schema dump, separate baseline migrations, or generated schema artifact?
7. When may the v10 duplicate-request compatibility path be retired: immediately after proof of complete provenance, or after an additional release window?
8. May legacy monster-cycle requirement tables be collapsed after their completion is proven, or must they remain as audit rows?
9. What exact backup age and restore/re-upgrade rehearsal evidence is required before migration approval?
10. Should the release branch naming remain `release/2.4.0` for the entire stacked series? This audit follows the latest explicit Owner instruction.

---

## 22. Recommended implementation PR split

### PR A — dependency audit

This PR.

- documentation only;
- no runtime, Ruleset, migration, schema or test change;
- establishes classifications and blockers.

### PR B — current Ruleset baseline

- standalone complete canonical Ruleset;
- remove historical authored payloads from normal config;
- replace `SecretaryService` v7 dependency;
- make normal runtime current-only while retaining historical DB read presentation;
- no gameplay/balance change;
- add source-independent historical presentation proof.

### PR C — fresh install and exact-source production upgrade

- direct current-schema fresh install;
- exact 2.3.1/v11 source preflight;
- global unresolved TurnRun cutoff;
- one forward data-preserving migration;
- live-reference/provenance postconditions;
- backup restore and re-upgrade runbook/tests;
- no direct v8/v9/v10-to-current path.

### PR D — historical test/runtime reduction

- archive/remove old migration suites from normal CI;
- archive/remove old Ruleset contract tests;
- delete proven-unreachable historical runtime branches;
- consolidate duplicate corrupt-fixture/extreme-value tests;
- preserve current safety, data integrity, retry and read-only history tests;
- report exact before/after files, identifiers, shard durations and Quality wall time.

Each PR should target `release/2.4.0` and use a separate stacked work branch.

---

## 23. Stop gates for implementation

Do not begin deletion if any of these remain unresolved:

- production source identity unknown;
- unresolved non-dry TurnRun exists;
- old live references remain without conversion;
- request provenance/fingerprint completion is unproven;
- current Secretary initialization still requires v7;
- historical read presentation still requires authored source config;
- no successful backup restore/re-upgrade rehearsal;
- no standalone current Ruleset;
- no replacement current-baseline and source-upgrade tests.

---

## 24. Audit outcome

The audit supports the Owner's intended direction:

> Preserve production data and auditable history, while ending the obligation to execute every historical Ruleset and replay every historical migration in normal fresh installs and CI.

The repository is closer to this boundary than the raw file count suggests because historical World mutation is already blocked. The remaining work is concentrated in:

- authored config/source inheritance;
- one current v7 runtime dependency;
- v10 duplicate-request compatibility;
- old migration/fresh-install topology;
- source preflight and production evidence;
- test contracts that intentionally keep old behavior executable.

The next implementation should be PR B, but only after the unresolved Owner decisions and source-preflight scope are confirmed. No deletion is justified by this document alone.
