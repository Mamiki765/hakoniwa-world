# ver 2.3.1 simplification audit

この文書はver 2.3.1のコード変更前に固定したdocumentation-only監査である。目的は削除量ではなく、ver 2.3.0のcorrectness、replay、production safetyを維持したまま調査箇所、call depth、runtime work、test maintenance costを減らせる境界を特定することである。

## Baseline and stop-gate result

| Item | Result |
|---|---|
| starting `origin/main` | `6c75e869d9c223f87c9e1811bf98ca1c78a69e60` |
| known integration commit | `6c75e869d9c223f87c9e1811bf98ca1c78a69e60`; current `origin/main`と同一 |
| integrated release HEAD | `9cce5d8e85b4b681673fcd46ae59f8dba6c30298`; current mainのancestor |
| release baseline | remote `release/ver-2.3.1`は未作成。local `release/ver-2.3.1`をexact current mainから作成 |
| work branch | `codex/ver-2.3.1-runtime-ruleset-simplification` |
| formal current Ruleset | `hakoniwa-2s-plus-v11`, version `11` |
| formal v11 checksum | `5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8`; validatorとdirect JSON encodingの双方で一致 |
| Open design gate | cleanup-only scopeで到達する`Open` itemなし。B-03、B-05、B-12、B-13、T-02が対象にするgameplay/dormancy変更は行わない |
| production evidence | production rollout状態は未調査・未推測。formal v11と既存player dataはproduction historyになり得るものとして扱う |
| prohibited access | production DB、deploy、OCI、production assetsへのaccessなし |

## Ruleset inventory: Pre-MVP through v11

`published`はrepositoryのmigrationがimmutable DB snapshotとしてpublishする契約を持つかを表す。`runtime-read`の`historical`は、保存済みWorld、migration、またはsame-target/same-ruleset/same-seed retryが参照し得ることを表し、現在productionに該当rowが存在するとは推測しない。

| Identity / path | Classification | Authored version | Published | Runtime-read | Migration dependency | Checksum | Current role | Change policy | Evidence |
|---|---|---:|---|---|---|---|---|---|---|
| Git `6f37a410...:product/config/hakoniwa.php` (`mvp-v1`) | prototype / Pre-MVP | 1 | historical/unknown | never in current config; migration source identity only | `2026_07_26_010000_add_roadmap_pr2_systems.php` | unknown | Pre-MVP prototype and migration predecessor | immutable repository history; do not reconstruct or delete migration recognition | Git history; migration lookup of `mvp-v1` |
| `rulesets/roadmap-pr2-v1.php` | roadmap | 3 | yes | historical | `2026_07_26_010000_add_roadmap_pr2_systems.php` | `091494cae4988c2517417f91bb9810e277ee665525c98ff67eeb305b23592fe3` | immutable roadmap history | immutable | config registration; migration publication |
| `rulesets/roadmap-pr6-v1.php` | roadmap | 1 | yes | historical | `2026_07_27_010000_publish_roadmap_pr6_ruleset.php` | `e037bec2bb55672fa0497c8238d31f5217f1f17ff48ad153a61993f20ac0fc39` | immutable roadmap history | immutable | config registration; migration source/target checks |
| `rulesets/roadmap-pr7-v1.php` | roadmap | 1 | yes | historical | `2026_07_29_000000_publish_roadmap_pr7_ruleset.php` | `fa9819d1deed15db3c394eb94f0fba5fc1645add2b1e39af2e74873b95a9c7df` | immutable roadmap history | immutable | config registration; migration source/target checks |
| `rulesets/roadmap-pr11-v1.php` | roadmap | 1 | yes | historical | `2026_07_30_000000_publish_roadmap_pr11_ruleset.php` | `6a5cad238c6a051fd59c8c45785cbdc880e0354abe30af3ad32946413e27acb6` | immutable roadmap history | immutable | config registration; migration publication |
| `rulesets/roadmap-pr14-v1.php` | roadmap | 1 | yes | historical | `2026_08_02_000000_publish_roadmap_pr14_ruleset.php` | `c12fe26af6858ed79650c1cb4617fdce03a1c4fc53d4f641f546fd442b87e78e` | immutable roadmap history | immutable | config registration; forward-only migration |
| `rulesets/roadmap-pr15-v1.php` | roadmap | 1 | yes | historical | `2026_08_02_010000_publish_roadmap_pr15_ruleset.php` | `5c3a5a339cb379a612a65ffb7918854fb772b169e5a2fd3e6fb42d506dba06d8` | immutable roadmap history | immutable | config registration; unresolved-run migration guard |
| `rulesets/roadmap-pr18-v1.php` | roadmap | 1 | yes | historical | `2026_08_04_000000_publish_roadmap_pr18_ruleset.php` | `ccca701614928f9eb5a8eaea4d27d1b56e9a6254ad281dca11a0a089c9bdabde` | immutable roadmap history | immutable | config registration; forward-only migration |
| `rulesets/roadmap-pr19-v1.php` | roadmap | 1 | yes | historical | `2026_08_04_020000_publish_roadmap_pr19_ruleset.php` | `f5adac988282b5c35029210db59135312056238845f5cb0b891ec7d9a6d922d7` | immutable roadmap history | immutable | config registration; forward-only migration |
| `rulesets/roadmap-pr21-v1.php` | roadmap | 1 | yes | historical | `2026_08_05_000000_create_monster_system_and_publish_roadmap_pr21_ruleset.php` | `2097df8cf87469fef8b8ec47f5cffc80569a479a9a50a5b119f513d42b458687` | immutable roadmap history | immutable | monster schema/publication migration |
| `rulesets/roadmap-pr22-v1.php` | roadmap | 1 | yes | historical | `2026_08_05_010000_add_pr22_command_event_state_and_publish_ruleset.php` | `3c88b8c34b382f9c3fbce96f3d9609c19d1e04599f253b7aae42173b4e351bd0` | final roadmap snapshot before formal v1 | immutable | command/event schema/publication migration |
| `rulesets/hakoniwa-2s-plus-v1.php` | production | 1 | yes | historical | `2026_08_05_020000_prepare_first_production_release.php` | `0c03226dd5c99c0293392ed1bc5528a03093084e622ff21e3784a8810c3b8ba0` | immutable formal history | immutable | first production-release migration; contract test |
| `rulesets/hakoniwa-2s-plus-v2.php` | production | 2 | yes | historical | `2026_08_09_000000_publish_hakoniwa_2s_plus_v2.php`; live monster reference repair | `8c865b7e53593ad90a97357d50fa39e3ebdaf4e97bc925118b1012e01ea38234` | immutable formal history | immutable | v2 migration and repair migration |
| `rulesets/hakoniwa-2s-plus-v3.php` | production | 3 | yes | historical | `2026_08_10_000000_publish_hakoniwa_2s_plus_v3.php` | `3d03cb6912ba7082376e9b262fb95d03ca30917d8eecbbc521bf63b27a53ce36` | immutable formal history | immutable | exact territory-contract migration |
| `rulesets/hakoniwa-2s-plus-v4.php` | production | 4 | yes | historical | `2026_08_13_000000_publish_hakoniwa_2s_plus_v4.php` | `b899c7cf92c47be3d464ec1d52c93ff2e5177605fe4453d32c6b529fcb37bd42` | immutable formal history | immutable | launch-base migration |
| `rulesets/hakoniwa-2s-plus-v5.php` | production | 5 | yes | historical | `2026_08_14_000000_publish_hakoniwa_2s_plus_v5.php` | `4d1a004332b79b298460c0316c6ec00972a27517e01079f2378fc5de78591ab6` | immutable formal history | immutable | sea-edge contract migration |
| `rulesets/hakoniwa-2s-plus-v6.php` | production | 6 | yes | historical | `2026_08_16_000000_publish_hakoniwa_2s_plus_v6.php` | `5f3567fb352379727878f83cd1f66c36885cb4485c9153baaf315bab4140dcb2` | immutable formal history | immutable | v6 migration |
| `rulesets/hakoniwa-2s-plus-v7.php` | production | 7 | yes | historical | `2026_08_16_030000_publish_hakoniwa_2s_plus_v7.php` | `6b9def1bb8921d233bd2080e5f89584cccf8a3a09184dcfac475ddb599f2a670` | immutable formal history | immutable | Secretary v1 migration |
| `rulesets/hakoniwa-2s-plus-v8.php` | production | 8 | yes | historical | `2026_08_16_040000_publish_hakoniwa_2s_plus_v8.php` | `fdceaec1e45bad64ceb177f880e513adeb5c3816c96858b00d8a988ad347990d` | immutable formal history | immutable | defense-interception migration |
| `rulesets/hakoniwa-2s-plus-v9.php` | production | 9 | yes | historical | `2026_08_17_000000_publish_hakoniwa_2s_plus_v9.php` | `78b55d34ce3148eb1e4b6dd97939468cee9df508d28f4084947a09cdd10fd883` | immutable formal history | immutable | normal-monster-stage migration |
| `rulesets/hakoniwa-2s-plus-v10.php` | production | 10 | yes | historical | `2026_08_19_010000_publish_hakoniwa_2s_plus_v10.php` | `6a0f5354f8894081bacdb8eaaba328d3e4ee80a2c4136819b16cfa924f485dc1` | immutable formal history and v11 migration source | immutable | v10 migration; v11 source checksum gate |
| `rulesets/hakoniwa-2s-plus-v11.php` | production | 11 | yes | current | `2026_08_21_010000_publish_hakoniwa_2s_plus_v11.php` / `RulesetV11MigrationService` | `5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8` | current gameplay and immutable history | immutable | current config; validator; v11 migration postconditions |
| `tests/Support/V11SecretaryItemRulesetFixture.php` | test-only fixture | derived v11 | no | never outside tests | none | not a published snapshot | changes key and supplies v11-shaped Secretary Item settings to focused tests | test-only; keep synchronized by contract tests | Secretary Item unit/feature tests |
| v10/v11 checksum and definition expectations embedded in `RulesetV11MigrationService` | migration snapshot / guard | 10 to 11 | no independent publication | migration-only | v11 migration | source `6a0f...`; target `5c65...` | fail-closed migration evidence | immutable with applied migration code | migration preflight, fingerprint and postcondition checks |

Repository history and current config exposed no additional never-published gameplay Ruleset, obsolete removable duplicate, or unclassified payload. Absence is not deletion evidence: old-looking sources remain immutable until a separate audit proves no migration, retry, presentation, or backup/restore dependency.

## Runtime call graph and frequency

| Entry | Calls / readers | Frequency | Finding |
|---|---|---|---|
| `TurnRunner::run` | advisory World lock -> current Ruleset guard -> TurnRun prepare/retry -> one DB transaction -> canonical pipeline | per TurnRun attempt | same-target/ruleset/seed retry, transaction, rollback and row-lock order are safety boundaries |
| `CompleteTurnEngine::execute` | one named private phase or narrow phase service | once per canonical phase per TurnRun | the dispatcher is already a readable phase index; no phase-order rewrite is justified |
| `prepareTurn` | `turn_processing` guard, stable Nation snapshots, Secretary snapshots | once per TurnRun; snapshot work per Nation/item | correct place to resolve immutable per-turn contracts once |
| `processCells` | surface batch load -> ordinary cell effects -> missile finalization -> Old Bow -> optional separated normal monster pass | one phase per TurnRun; ordinary work per cell; monster entry per occupied/action cell | order and RNG population must not change |
| `MonsterTurnService::load` | locks occupancy rows and eager-loads instance/definition; constructs turn-local occupancy batch | once per TurnRun | behavior can be validated/resolved per unique loaded definition here |
| `MonsterTurnService::processCell` | occupancy/deferred check -> behavior resolver -> special action -> hardening/move limit -> three candidate draws -> standard defense/block/occupancy -> movement delta | per actionable occupied cell; a moved monster may act again up to authored limit | behavior metadata is currently reparsed for every action, including repeated action by one monster |
| Secretary snapshot | `SecretaryTurnService::loadAttemptSnapshots` -> one full Item contract validation -> per Nation -> per equipped Item `resolvedEffects` -> another full validation | once per TurnRun, then per Nation and equipped Item | repeated full immutable contract validation is the clearest hot-path simplification candidate |
| Old Bow | snapshot scan -> one occupancy query -> safety policy per candidate -> one trigger and target draw per eligible Nation when applicable -> shared damage | once per TurnRun; per Nation and candidate monster | authoritative ownership, HP/hardening and target safety are mutable and remain runtime checks |
| Ring finance | shared finance -> base capped addition -> snapshot Ring resolution -> bonus capped addition | per explicit or automatic finance execution per Nation | standard path plus a small numerical delta is already readable; snapshot shape guard remains last-resort safety |
| command registration | auth/membership -> World/Nation/queue locks -> current ruleset and selector validation -> provenance/fingerprint -> idempotent write | API request / queue mutation | external input, lock ordering, request bytes and provenance must remain validated |
| command execution | locked queue item -> mutable target/resources/cost validation -> standard executor; selector is resolved at validation, cost, application and event/failure metadata sites | per command attempt | repeated selector resolution is measurable, but request/provenance compatibility makes broad caching risky in 2.3.1 |
| Ruleset authoring | explicit config list -> `RulesetAuthoringValidator` | validation command / release | full structural, numeric, selector, behavior and Item validation belongs here |
| Ruleset publication | publisher validates payload -> checksum -> immutable snapshot and definitions | per release/migration | publication must stay fail-closed and is not runtime cleanup scope |
| historical retry | `TurnRunner::prepareRun` reuses failed/blocked run only when World and run still reference the same Ruleset | manual retry attempt | production zero-row evidence is absent, so compatibility stays |
| v11 migration | migration preflight -> immutable publish -> locks/live-row rebind -> postconditions/fingerprints | migration only | do not alter applied service or historical migration |
| mutation guards | current Ruleset, unresolved next TurnRun, advisory World lock, then ordered row locks | per turn, Nation creation/abandonment, queue mutation | similar names do not imply interchangeable lock scope |

Meaningful call-depth observations:

- top-level phase discovery is `TurnPipeline -> CompleteTurnEngine::execute -> named phase`, with gameplay detail below the named phase; this is acceptable.
- Secretary Item preparation currently reaches `loadAttemptSnapshots -> resolvedItemSnapshots -> resolvedEffects -> validate` for every equipped Item after the same `validate` already ran once.
- monster action currently reaches `processCell -> MonsterBehaviorResolver::forDefinition -> resolve -> validate` per action, although definition metadata is immutable within the TurnRun.

## Validation inventory and proposed boundary

| Contract | Publication | Snapshot / prepare | Runtime | DB constraint | Test | Classification |
|---|---|---|---|---|---|---|
| monster movement contract | exact shape, candidate attempts, blocked terrain/facility keys | definition eager-loaded | attempts and stream version checked per action; mutable occupancy/destination checked per candidate | occupancy uniqueness/FKs | authoring, monster integration, performance | immutable parse: `move to publication/snapshot`; mutable eligibility: `keep` |
| monster behavior / world spawn | exact v11 behavior map and monster-key combinations | none beyond eager load | full behavior map resolved/validated per action; Nation creation also checks displacement behavior | definition snapshot/FKs | `MonsterRulesetContractTest`, C4 integration | resolve once per unique loaded definition; keep initial-island use at its operation boundary |
| Secretary Item effect shape | full exact categories/items/effects | one validation at prepare, then repeated again per equipped Item | Old Bow/Ring snapshot consumers retain narrow shape checks | equipment slot/version constraints | unit contract, equipment and integration tests | full repeat: `move to snapshot`; narrow snapshot guard: `keep` |
| Ring bonus | exact type, stacking and `bonus_money_per_level = 1` | resolved effect snapshot | per finance validates snapshot fields and applies capacity twice | money bounds | Ring integration/rollback/retry | `keep`; already standard finance plus explicit delta |
| Old Bow target scope | exact effect type/timing/scope/safety/RNG version | resolved effect snapshot | authoritative active Nation, ownership, safe HP/hardening, damage result | occupancy/FKs | target, ordering, RNG and rollback tests | immutable shape to snapshot; mutable safety `keep` |
| monster dispatch selector | exact two-option authored catalog | command definition loaded with queue item | selector and dynamic price re-resolved at registration/execution/event/failure | terminal queue history and definition FKs | C4 provenance/fingerprint/retry tests | `requires measurement`; do not weaken external selector or historical fallback |
| request key/fingerprint/provenance | command definition parameter contract | canonical request built before write | checked on duplicate, execution and historical backfill paths | unique/idempotency constraints | queue, C4 historical retry | `keep` |
| display order | authored stable integer and resolver fallback | presentation query | resolved for list output | persisted integer bounds | monster API/unit contract | `keep`; not a turn hot path |
| reward policy | authored monster value/XP and removal reason | definition eager-loaded | mutable killer/target, single removal and capacity application | killed/removed history, stat keys | kill/reward/rollback tests | `keep` |
| Ruleset numeric bounds | comprehensive authoring validation | selected current values read into context | mutable resource amounts and persisted decimal compatibility checked where used | numeric columns/check constraints | validator boundary tests | authored extremes at publication; persisted mutable values `keep` |
| current Ruleset identity | config plus published snapshot | World and relation loaded | guard compares World relation/id and configured current identity | World FK | historical World mutation tests | `keep` |
| unresolved TurnRun | migration/deploy preflight | locked World/turn state | creation/abandonment/release/turn guards | unique target/run state | blocked/failed/pending regressions | `keep` |
| occupancy/equipment version | authoring only defines capacities | batch snapshot | duplicate cell/monster, slot bounds and optimistic equipment version | unique constraints/triggers | concurrency/integrity tests | `keep` |

The audit does not recommend removing all corrupt-payload detection. It recommends one fail-closed validation at publication and one operation snapshot/load boundary, while mutable authorization, ownership, locks, versions, occupancy, target safety, retry and DB integrity remain runtime responsibilities.

## Standard path plus explicit delta

| Special behavior | Standard path | Actual delta | Duplicated work | Safe common path | Integration risk / decision |
|---|---|---|---|---|---|---|
| Aoi movement | ordinary occupancy, three candidate draws, bounds, occupied destination, defense contact, blocked terrain/facility, event recorder | legal water/inland destination set; final terrain `sea`; owner cleared; destructive metric | no parallel candidate engine after PR70 correction | keep current standard eligibility and branch only at final movement | retained as positive model; further rewrite would risk draw/event order |
| Zero HP-one action | normal loaded occupancy and removal/event/disaster services | before hardening/movement, HP exactly one, fixed rewardless huge-meteor blast | no separate movement engine | shared removal and disaster blast | retain; moving the condition changes phase/action order |
| Old Bow | shared Monster damage/removal/reward rules and one authoritative candidate query | pre-normal-monster timing, owned surface target, effect RNG labels | immutable effect shape is rechecked after snapshot | snapshot effect plus shared damage | simplify only prevalidated effect construction; retain target safety and RNG order |
| Ring finance | ordinary finance reward and capacity calculation | second capped addition and explicit metadata | snapshot shape scan per finance is small | same `finance` method | retain current standard path; a new strategy framework would increase depth |
| monster dispatch selector | ordinary queue, Nation target, spawn, event, failure lifecycle | selector chooses definition and cost | resolver called at several lifecycle points | shared option resolver and provenance fields | defer caching until call counts and historical selector-less behavior are proven |
| initial-island displacement | locked creation transaction, reservation/cell selection and batch occupancy | only authored `island_creation_displaceable` monster may be removed from changed cells | behavior resolution per encountered monster | current behavior resolver | retain; this is a separate operation and safety gate, not turn-loop duplication |
| Nation creation/abandonment locks | user membership serialization, World lock/current Ruleset/unresolved run guard | different owned rows, confirmation, island creation vs teardown | superficially similar preconditions | shared existing lock/guard services | do not merge transactions; lock ordering and row set differ |
| migration safety guards | publication checksum, unresolved runs, exact live references, fingerprints | version-specific forward conversion | some current-like validation names | publisher/validator only where already shared | applied v11 migration remains untouched; normal runtime must not absorb migration code |

## Test contract map

The table focuses on expensive or apparently overlapping suites rather than listing every test file.

| Test / group | Protected contract | DB/setup cost | Possible duplicate | Authoritative higher-level coverage | Decision |
|---|---|---|---|---|---|
| `RulesetAuthoringValidatorTest` | authored shapes, numeric/storage bounds, immutable deltas v1-v5 and all registered payloads | unit/config only; many DataProvider cases | some boundary methods share setup | formal version contract tests | retain invalid-shape breadth; consolidate only exact repeated setup/assertion with equal named datasets |
| `RulesetV11ContractTest` | exact v11 identity/checksum/counts and C1-C4 composition | unit/config only | overlaps validator success path, not exact checksum intent | none stronger for frozen v11 | retain |
| `SecretaryItemGameplayContractTest` | exact effect text/shape and fail-closed open-ended authoring | unit/config; 13 datasets | publisher validator also calls contract | `SecretaryItemEffectsTest` covers runtime, not all invalid authoring | retain; it is fast and gives focused feedback |
| `SecretaryItemEffectsTest` | prepare snapshot/query bound, Ring/Old Bow timing/capacity/RNG/rollback/retry | expensive DB World setup; 15 tests | individual Ring paths share fixture but protect distinct source/timing contracts | no single stronger test | retain first; add focused proof for one-time validation without duplicating gameplay |
| `C4NewMonstersTest` | dispatch provenance/retry, Aoi standard path, Zero action, displacement | expensive DB setup; 19 tests | three prohibited Aoi destinations vary only protected target type | later cases do not cover all target reasons | consolidation candidate only if one fixture preserves each terrain/facility/capital reason and random stream |
| `RuntimeMetadataFailureTest` | corrupt current DB metadata rolls back and API mutations fail closed | DB reset; assigned with performance shard | four tests cover distinct reclaim/oil/disaster/sale contracts | no stronger combined regression | retain; do not delete because publication validation exists |
| `TurnRuntimePerformanceTest` | query scaling at 1,024/4,096/9,216 cells, Nation scaling, forced disasters, coordinate lookup absence | dominant CI cost; creates expanded Worlds | measurement profiles overlap in setup, but different shape/scale contracts | none | retain critical bounds; review fixtures/profile list rather than drop by wall time alone |
| migration suites v2-v11 | upgrade, preflight, live reference conversion, rollback/second-run idempotency | high DB/migration cost | adjacent version tests may look repetitive | each applied migration is a distinct production contract | retain |
| TurnRunner/lock/queue concurrency suites | transaction rollback, advisory/row lock, unresolved run, idempotency, request bytes | DB and multi-connection cost | unit guards cannot replace concurrency | feature/concurrency tests are authoritative | retain |
| Monster occupancy/kill cycle suites | unique occupancy, killed/removed history, reward/stat idempotency | DB setup | API presentation overlaps only read shape | database/integration tests | retain |

Deletion proof rule for C3: no test is removed unless the rationalization document names the deleted identifier, protected contract, equal-or-stronger replacement, runtime result and risk. Green CI alone is not evidence.

## Baseline measurements

### GitHub Quality for ver 2.3.0 release head

Run `32494004603` tested exact release head `9cce5d8e85b4b681673fcd46ae59f8dba6c30298` and completed successfully.

| Measurement | Baseline |
|---|---:|
| Quality wall time | about 9m 34s (`14:47:03Z` to `14:56:37Z`) |
| documentation | 5s |
| frontend | 23s |
| Composer dependencies | 23s |
| shard plan | 3s |
| backend-static | 42s |
| slowest shard | 07/16, 539s job wall time |
| next slowest | 15/16, 307s |
| other notable shards | 13/16 191s; 09/16 180s |
| fastest shard | 05/16, 49s job wall time |
| backend aggregate | 4s after all shards |

Shard 07 contains `TurnRuntimePerformanceTest`, `RuntimeMetadataFailureTest`, `PostgresSecretaryEquipmentConcurrencyTest`, `MessageBoardMigrationTest`, `AwardSystemTest`, and two unit files. The filename round-robin planner assigns exactly seven files to every shard but has no duration weights. This is real imbalance, not duplicate execution.

### Discovery and local focused baselines

| Measurement | Baseline |
|---|---:|
| PHPUnit files discovered by shard planner | 112 |
| 16-shard union / duplicate / missing / unexpected | 112 / 0 / 0 / 0 |
| `RulesetV11ContractTest` | 0.27s PHPUnit duration; 2 tests, 29 assertions |
| `SecretaryItemGameplayContractTest` | 0.63s; 13 tests, 20 assertions |
| `SecretaryItemEffectsTest` | 21.53s; 15 tests, 157 assertions |
| `C4NewMonstersTest` | 27.28s; 19 tests, 150 assertions |
| formal v11 validator | success; local tool wall about 1.52s |
| serial full baseline | unknown; intentionally not executed locally |

An initial attempt to measure the two DB feature files concurrently against the same `hakoniwa_test` database was invalid because their `RefreshDatabase` schema operations competed. Those results are excluded; the table uses subsequent sequential green runs. No production database was involved.

Existing performance assertions already bound `process_cells` queries (12 for expanded empty Worlds, 20 for settlement-heavy 64x64), prohibit coordinate cell lookups in mature/special profiles, and bound several per-Nation phases. The baseline has no counter for PHP-level metadata validation calls; focused tests will add or use an injectable counter only if this can be done without production abstraction.

## C1-C3 decisions from the audit

1. Create a human-readable archive covering Pre-MVP, roadmap snapshots and formal v1-v11 without editing authored sources.
2. Document Core / Balance / Flavor as responsibility boundaries. Defer a physical config split because it would either duplicate source of truth or require payload/checksum/version changes.
3. Move Secretary Item full contract validation to one prepare boundary while preserving public/presentation validation and narrow snapshot guards.
4. Resolve monster behavior once per unique loaded definition at the turn batch boundary while retaining publication validation, occupancy checks and initial-island operation validation.
5. Keep Aoi, Zero, Ring and lock flows structurally as they are unless a smaller change demonstrably reduces calls without changing phase, RNG, event or lock order.
6. Do not remove historical runtime support or migration code: production zero-row/conversion/read-only history/backup evidence is absent.
7. Treat shard imbalance and repeated expensive fixture execution as test-maintenance evidence, but do not delete critical tests merely to shorten CI.

## Deferred or unsafe candidates

- current-only runtime support and removal of historical definitions or retry branches;
- physical `core.php` / `balance.php` / `flavor.php` split for v11;
- broad command-dispatch option caching across API/queue/turn lifecycles;
- combining Nation creation and abandonment transactions or changing lock order;
- deleting migration, rollback, concurrency, request provenance, RNG, checksum, DB integrity or production turn-stop regressions;
- changing `CompleteTurnEngine` phase order, event order, random draw population, or transaction boundary;
- any cleanup that requires v12, schema migration, live-data conversion, or a v1-v11 authored-source edit.
