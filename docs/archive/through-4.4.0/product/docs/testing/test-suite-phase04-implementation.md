# Test suite再設計 Phase 4 実装・検証記録

Date: 2026-09-15

Branch: `release/4.2.0`

Base: `origin/main` `00182bd0eaee52f34194c7b40bc5e98712108718`

Parent implementation: `7431e60d`

## 不要保証と負荷の削減

Phase 3の1,053 casesから22 casesを削減し、Phase 4終了時の集合を1,031 casesとした。削除件数を目的にせず、到達可能な故障と既存ownerをruntimeまで確認して次を処置した。

- missile radius=2の共通interceptionは全source missileの4 variantからcanonical 1件へ縮小した。source固有挙動、非迎撃、self/foreign、資産・rollbackの別故障は残した。
- `TurnRuntimePerformanceTest`は同じquery増加を測るspecial 3種、災害7種、missile 5点を代表へ縮小した。災害固有の被害やmissile固有の決算を担う機能testは残した。
- DBへ直接壊れたruntime metadataを作る4 casesと、無効なequipment schema version 0を直接書く1 caseを削除した。実write後のTurn rollback、fresh install、supported upgrade、schema identityは別ownerで残した。
- combat laboratoryのprototype総当たり4 casesと未使用future result shape 1 caseを削除した。current runtimeが使うcombat経路は残した。
- fresh installのcatalog件数、Ruleset値の巨大コピー、Dockerfile文字列・行末・cache記法の固定を除去し、stable identity/checksum/validator、install、実entrypoint順序へ絞った。
- 地底APIのcatalog全件loop、過大なhistory/recollection fixtureを代表へ縮小した。通常到達する宝物庫上限の同時購入は、498件から競合させて499→500を検証するPostgreSQL concurrency caseをownerとして維持した。

historical null/staged command queueとforward migrationはcurrent runtimeまたはsupported upgradeから到達するため残した。二重決算、asset、progress、authorization、transaction、retry、idempotency、lockを保護する代表を速度だけで削除していない。

## 巨大fileの責務分割

- 5,752行の`CommandAndMissileTest`をmissile defense、impact/settlement、KARMA/recovery、surface command/aidの4 concrete classへ分割した。共通helperはabstract support classへ1か所だけ置き、4 classすべてを再利用surface fixtureへ分類した。分割前後の68 test methodsを照合した。
- 3,209行の`UndergroundPlayerAccessTest`をaccess/projection、intro/playtest、equipment/runtime、history/AIの4 concrete classへ分割した。共通helperはabstract support classへ集約し、分割前後の28 test methodsを照合した。
- 4,308行の`App.test.ts`をshared shell、surface operations、undergroundの3 filesと共通harnessへ分割した。宣言した42 testsの本文集合は分割前後で一致し、parameter展開後44 testsを実行した。
- frontend全test fileへ`.shared.test.ts`、`.surface.test.ts`、`.underground.test.ts`のcanonical suffixを付けた。Vitest project設定と`test:surface`、`test:underground`、`test:all`を追加した。component/class名検索や別file mapで所属を重複管理しない。

PHPUnit discoveryは117 filesから121 filesへ変わった。2つの巨大PHP fileを各4 concrete classへ分割して+6、不要な2 filesを削除して-2であり、abstract support classはtestとして発見されない。frontendは20 filesから22 filesになった。

## focused確認

- 分割・縮小した地上4 class、地底4 class、性能1 classを4 workersで実行し、111 testsを全件PASSした。worker結果は19 / 21 / 29 / 42 tests、最長workerは198秒だった。
- 上記runのfixture別結果はすべてPASSし、executed / uniqueは111 / 111。再利用mapは各worker1回、合計4回だった。
- Appの分割3 filesは44 testsをPASSした。分割対象のESLintとfrontend typecheckをPASSした。
- tests全147 PHP filesのPintをPASSした。
- 最終Full4、frontend全件、lint/typecheck/buildの結果は[Phase 6記録](test-suite-phase06-verification.md)へ分離した。

確認終了時の`hakoniwa_parallel_%_test`は0件だった。
