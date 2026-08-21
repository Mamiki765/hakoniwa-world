# ver 2.3.1 test rationalization

## Outcome

ver 2.3.1はtest file数やassertion数を削減目標にしない。監査したQuality workflowはfull PHPUnitをserialとshardで二重実行していなかったため、CI execution topologyを変更しない。16 shardsが一度だけ全fileを実行し、`backend-static`のserial/shard equivalenceは`--list-tests`によるidentifier列挙であってtest実行ではない。

今回のtest変更は、ver 2.3.0 checkpoint中にSecretary Item testへ集まったformal Ruleset identity確認をauthoritative v11 contract testへ戻し、同じpayloadを一つのtest method内で重複validationしていた呼出しを除く。critical test identifierは削除しない。

## Critical contracts retained first

| Contract | Authoritative coverage retained |
|---|---|
| v11 checksum / immutable payload | `RulesetV11ContractTest`, authoring validator and validation command |
| migration upgrade and exact second-run idempotency | `RulesetV11MigrationTest` and historical v2-v10 migration suites |
| forced rollback / transaction atomicity | TurnRunner, Item effect retry, v11 injected-failure and command tests |
| concurrency / advisory and row locks | PostgreSQL registration, equipment, OAuth and World lock suites |
| unresolved TurnRun | release/migration guards and TurnRunner regressions for pending/running/failed/blocked |
| request key/fingerprint/provenance | queue tests plus C4/v11 historical selector-less retry and rebind tests |
| RNG labels/population | `TurnRandomStreamTest`, monster contract, Secretary Item and C4 integrations |
| DB trigger/constraint | migration/integrity, MonsterOccupancy, queue consistency and capacity suites |
| killed/removed and terminal history | monster kill cycle, reward, v11 migration and queue history tests |
| production turn-stop prevention | runtime metadata rollback, release preflight and TurnRunner suites |

## Consolidation map and replacement proof

| Removed or moved coverage | Protected contract | Equal or stronger replacement | Why replacement is sufficient | Runtime effect | Risk |
|---|---|---|---|---|---|
| direct `SecretaryItemGameplayContract::validate` immediately before `validatedEffectCatalog` | Item effect shape fails closed | `validatedEffectCatalog` itself performs the exact full contract validation; 12 invalid datasets still call `validate` directly | no branch or invalid case is lost; only the same success payload's second validation is removed | one full contract walk removed from the success test | low |
| full `RulesetAuthoringValidator` call on the test-key v11 fixture inside Secretary Item unit test | fixture is a complete valid v11-shaped payload | `RulesetV11ContractTest::test_formal_v11_is_the_current_immutable_c1_through_c4_payload` validates formal v11 and proves fixture equality after restoring only the formal key | formal source is the production authority; validating a byte-equivalent test identity again adds no contract | one full authoring validation removed from the focused Secretary test | low |
| v11 source-file and migration-count assertions in Secretary Item test | formal v11 source/migration identity exists | same assertions moved to the authoritative v11 identity test | keeps assertions but narrows the files changed when release identity changes | no assertion loss; better ownership | low |
| redundant `assertArrayHasKey` for formal v11 | formal payload is registered | v11 contract reads it, asserts array, key, version, checksum and configured-current identity | direct lookup plus exact identity/checksum is stronger than key presence | one redundant assertion removed | low |
| no test identifier deleted | all existing contracts | all 112 files and all discovered identifiers remain assigned exactly once | no deletion lacked a stronger replacement | none | none |

C2 also adds two narrow replacement checks: a validated Item effect catalog must equal the public per-Item resolution, and `MonsterTurnBatch` must return the exact behavior object resolved at its definition boundary. These checks protect the new trust boundaries without duplicating DB gameplay scenarios.

## Expensive suite review

| File/group | Baseline | Duplicate finding | Decision |
|---|---:|---|---|
| `TurnRuntimePerformanceTest` shard group | Quality shard 07/16: 8m31.857s test process, 73 tests/486 assertions across seven assigned files | filename round-robin imbalance is real; log does not expose safe per-file timing | retain all performance/migration/concurrency contracts; do not split helpers or invent maintained duration weights without exact evidence |
| `SecretaryItemEffectsTest` | 21.53s before C2 | shared setup, but Ring source/timing/capacity/retry and Bow target/RNG/order contracts are distinct | retain 15 identifiers |
| `C4NewMonstersTest` | 27.28s before C2 | protected destinations share a helper but Capital has a distinct fixture and each reason protects authored target rules | retain 19 identifiers; grouping would reduce failure isolation without meaningful setup savings |
| `RuntimeMetadataFailureTest` | part of slow shard 07 | four corrupt metadata cases are different mutable/runtime contracts | retain; publication validation does not replace rollback proof |
| v2-v11 migration files | high database setup | repeated migration shape is version-specific production history | retain upgrade, preflight, rollback and idempotency cases |

## CI topology and shard evidence

Baseline exact-head Quality `32494004603` for ver 2.3.0 release head completed in about 9m34s. The slowest jobs were shard 07/16 at 539s and shard 15/16 at 307s; the fastest shard job was 05/16 at 49s. The planner assigns 112 files as 7 per shard with union 112, duplicate 0, missing 0 and unexpected 0.

After the local C3 edit, identifier-only verification reports serial 1,042, assigned 1,042, union 1,042, duplicate 0, missing 0 and unexpected 0. This command enumerates identifiers and does not execute test bodies.

No CI change is made in 2.3.1 because:

- there is no serial full PHPUnit execution to remove;
- identifier equivalence is validation, not a second test run;
- test file coverage, duplicate assignment and critical skip policy are already explicit;
- moving one heavy file by filename would not prove a shorter critical path, while a duration manifest would add a new maintained source of truth;
- exact-head candidate Quality will provide the only authoritative after measurement.

## Focused local measurement

| Measurement | Before | After / current | Interpretation |
|---|---:|---:|---|
| `SecretaryItemGameplayContractTest` | 0.63s; 13 tests, 20 assertions | 0.62s; 13 tests, 19 assertions | 0.01s is noise; one redundant presence assertion was removed while identity checks were centralized |
| `SecretaryItemEffectsTest` | 21.53s | 21.42s; 15 tests, 157 assertions | 0.11s is noise; DB setup dominates, while Item contract calls change from `1 + equipped item count` to 1 per TurnRun |
| `C4NewMonstersTest` | 27.28s | 27.33s; 19 tests, 150 assertions | 0.05s is noise; behavior resolution call count changes from actions to unique loaded definitions |
| focused unit pair after C2 | not measured as a pair | 1.66s; 38 tests, 80 assertions | includes monster and Item contract boundaries |
| local serial full | not run | not run | prohibited by the Goal |
| local full 16-shard | not run | not run | exact-head GitHub Quality is the full gate |

The first concurrent attempt to measure two DB feature files against one local `hakoniwa_test` database was invalid because their schema reset lifecycles competed. It is excluded from all measurements. Valid DB focused runs were executed sequentially afterward.

## Deferred candidates

- Duration-aware or method-aware sharding requires stable per-file CI measurements and a maintenance-cost comparison before implementation.
- Splitting `TurnRuntimePerformanceTest` could parallelize work, but extracting its shared probe/fixtures solely for shard placement would add abstractions and file coupling.
- Aoi protected-destination tests remain separate until a shared fixture can preserve exact terrain/facility/Capital failure reasons and improve measured runtime.
- Migration, concurrency, rollback, retry, provenance, RNG, checksum, DB integrity and turn-stop tests are not deletion candidates based only on wall time.

## Local workflow

Routine local validation for this cleanup is changed/related focused tests, changed-file Pint, relevant PHPStan, documentation validator, Ruleset validator and `git diff --check`. The complete 16-shard correctness gate runs once in GitHub Quality at the exact candidate HEAD. A failed CI job is reproduced only with its relevant file or focused selector.
