# ver 2.3.1 runtime, Ruleset and test simplification

ver 2.3.1は新機能releaseではない。ver 2.3.0で確立したcorrectness、replay、production safetyとplayer-visible behaviorを維持し、immutable contractを解決する場所とtest contract ownershipを明確にするcleanup-only releaseである。

## Release identity

| Item | Result |
|---|---|
| application version | `2.3.1` |
| active Ruleset | `hakoniwa-2s-plus-v11`, version `11` |
| formal v11 checksum | `5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8` |
| gameplay / balance change | 0 |
| Ruleset payload / checksum change | 0 |
| migration / schema change | 0 |
| player-data conversion | 不要 |

formal v1-v11 authoring source、既存migration、published snapshot、request fingerprint/provenance、TurnRun retry、queue、monster history、Item/equipment、lock/guard、transaction、RNG、event順序は変更しない。production rollout状態は推測せず、formal v11と既存player dataをproduction historyになり得るものとして扱う。

## Scope and evidence map

- 実装前監査: [`docs/ver-2.3.1-simplification-audit.md`](ver-2.3.1-simplification-audit.md)
- Pre-MVPからv11までのarchive: [`docs/archive/ruleset-history.md`](archive/ruleset-history.md)
- Ruleset Core / Balance / Flavor: [`docs/architecture/ruleset-configuration-layers.md`](architecture/ruleset-configuration-layers.md)
- test consolidationとmeasurement: [`docs/ver-2.3.1-test-rationalization.md`](ver-2.3.1-test-rationalization.md)
- 任意のplayer announcement: [`docs/ver-2.3.1-announcement.md`](ver-2.3.1-announcement.md)

歴史資料はformal v1を起点とせず、Pre-MVP prototype、roadmap snapshots、migration dependency、test-only fixtureとformal v1-v11を分類している。historical PHPはcomment、format、renameを含めて変更していない。

Rulesetの物理splitは実施しない。v11のpayload/checksumを変えず、第二のsource of truthや追加lookupを作らず、実際にcode/testを単純化する条件を同時に満たせないためである。Core / Balance / Flavorは将来境界として文書化し、現在のTurnRunは引き続き単一のimmutable Ruleset versionを参照する。

## Runtime simplifications

### Secretary Item effects

`SecretaryItemGameplayContract`は、immutableなItem effect catalog全体をTurnRun preparationで1回検証し、Old BowとRingのcanonical effectを同時に解決する。`SecretaryTurnService`はそのcatalogから各装備levelのsnapshotを作る。

| Measure | Before | After |
|---|---|---|
| full immutable Item contract validation | TurnRun preparationの1回に加え、装備Itemごとに1回 | TurnRun preparationで1回 |
| callers | `SecretaryTurnService`から全体検証、各Itemを`resolvedEffects`で再検証 | `SecretaryTurnService`から`validatedEffectCatalog`を1回、各Itemは解決済みentryを使用 |
| classes/functions | 同じ4 class、per-Item resolution中心 | class追加なし、catalog boundaryを1 method追加 |
| queries | 変更なし | 変更なし |
| behavior proof | per-Item public resolution | catalog resultとの完全一致unit test、既存Secretary Item integration 15 tests / 157 assertions |

Item instance level、slot/equipment version、snapshot時のeffect key/value guard、Old Bow target eligibility、Ring credit/capacityはmutable stateまたはsafety-critical last resortなのでruntimeに残す。

### Monster behavior

`MonsterTurnService`はloaded definitionごとに`MonsterBehavior`を1回解決し、`MonsterTurnBatch`へtyped objectとして保持する。同じmonsterが移動後に再度action対象になっても、immutable definition metadataを再parseしない。

| Measure | Before | After |
|---|---|---|
| behavior resolve/validation | monster actionごと | TurnRunでloadされたunique definitionごと |
| callers | `processCell` hot pathがresolverを直接呼ぶ | load boundaryがresolverを呼び、hot pathはbatch lookup |
| classes/functions | resolver/service/batch | class追加・generic interface追加なし、batch lookupを1 method追加 |
| queries | 変更なし | 変更なし |
| behavior proof | existing monster integration | exact resolved objectを再利用するunit test、既存C4 integration 19 tests / 150 assertions |

occupancy、target legality、defense、damage、reward、RNG draw、event emissionはmutable gameplay stateなので従来のruntime pathに残す。Nation creation時のdefinition behavior validationも、別operationのfail-closed boundaryとして残す。

### Standard path, phase table of contents and guards

Aoiはver 2.3.0で既にordinary candidate/protection/occupancy/defense pathを共用し、legal destinationとfinal terrain/owner resultだけをdeltaとして持つ。2.3.1ではこの形を確認し、別engineやboolean-heavy frameworkへ書き換えていない。Zero、Old Bow、Ring、initial-island displacementも、差分が独立したphase/RNG/event contractを持つため無理に統合していない。

`CompleteTurnEngine::execute`は、`prepare_turn`から`finalize_turn`までcanonical 12 phaseを上から読める目次になっている。phase order、method dispatch、metrics、random population、event orderを変える大規模rewriteは利益よりriskが大きいため不要と判断した。

lock/guard codeは変更していない。User/Worldを含むlock ordering、advisory/row locks、unresolved TurnRun guard、transaction/rollback、same-target/same-ruleset/same-seed retry、request provenanceはruntime safety boundaryであり、重複を消す証拠より変更riskが大きい。migration-only serviceも改変せず、production evidenceのないhistorical compatibility removalを行わない。

## Validation boundary

publicationではauthoring shape、stable keys、selector/effect/policy、numeric bounds、display order、RNG identityを検証する。Turn preparationではpublished payloadからtyped/canonical Item catalogとmonster behaviorを一度解決する。hot pathはその解決済み値を使用する。

runtimeにはexternal/user input、mutable DB state、ownership/authorization、optimistic version、locks、occupancy、queue/World ruleset consistency、retry/provenance、snapshot value guardsを残す。DB constraint/triggerとcritical regression testsも維持する。したがってcorrupt mutable rowやpayload mismatchを検知するfail-closed boundaryは弱めていない。

## Test rationalization and measurements

test identifierは削除していない。Secretary contract test内で同じsuccess payloadを連続してfull validationしていた箇所と、formal v11 testが既に所有するauthoring validation/source/migration identityの重複だけを整理した。formal v11 source/migration count assertionは`RulesetV11ContractTest`へ移し、contract ownershipを一箇所にした。

| Measurement | Before | After |
|---|---:|---:|
| Secretary Item contract unit | 0.63s; 13 tests / 20 assertions | 0.62s; 13 tests / 19 assertions |
| Secretary Item integration | 21.53s | 21.42s; 15 tests / 157 assertions |
| C4 monster integration | 27.28s | 27.33s; 19 tests / 150 assertions |
| shard identifiers | 1,042 serial identifiers | 1,042 assigned / union; duplicate 0, missing 0, unexpected 0 |

wall-time差はnoiseであり速度改善とは主張しない。runtime workはvalidation/resolveのcall countで減少し、test maintenanceはv11 identity ownershipの集約で減少した。migration、rollback、concurrency、locks、unresolved TurnRun、retry/idempotency、fingerprint/provenance、RNG、checksum/immutability、DB integrity、MonsterOccupancy、capacity、production turn-stop regressionは削除していない。

ver 2.3.0 exact-head Quality `32494004603`は約9分34秒、slowest shard 07/16は539秒だった。CIにはserial full testの二重実行がなく、identifier enumerationはtest body executionではないためworkflowは変更しない。2.3.1のafter値はcandidate exact-head Qualityを正本とする。localではfocused suitesだけを実行し、serial full、16 shards全実行、Quality全再現を行わない。

## Deferred unsafe candidates

- historical Ruleset/current-only runtimeの削除は、unresolved historical TurnRun zero、live row conversion、historical presentation、production call graph、backup/restore rehearsalの証拠が揃うまで行わない。
- migration safety guardsとcompatibility servicesは適用済みproduction historyなので削除・統合しない。
- duration-aware shardingは安定したper-file CI timingとmaintenance cost比較が得られるまで行わない。
- heavy integration fileの分割やspecial actionの統合は、fixture/call depthを増やさず同じRNG/event contractを証明できる場合にだけ再検討する。
- Ruleset Core / Balance / Flavorの物理splitは、将来の新Ruleset authoring時にimmutable historical payloadを変えず単一source of truthを維持できる設計として扱う。

## Deployment statement

ver 2.3.1のdeployにgameplay-data migration、schema migration、World reset、queue/TurnRun変換、Ruleset publicationは不要である。deploy前の通常のrelease preflightとunresolved TurnRun guardは従来どおり必要であり、この文書はproduction操作の承認ではない。
