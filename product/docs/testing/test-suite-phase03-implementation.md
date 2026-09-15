# Test suite再設計 Phase 3 実装・検証記録

Date: 2026-09-15

Branch: `release/4.2.0`

Base: `origin/main` `00182bd0eaee52f34194c7b40bc5e98712108718`

Parent implementation: `0e6db357e603f42f92e0f18f4f6549775a600218`

## 実装したfixture境界

- `TestShardPlanner`がtest classのtrait使用を読み、`standard`、`reusable_surface`、`individual`へ分類する。別の手書きfile mapは持たない。
- runnerは各workerの同じ専用DB上で3区分を別PHP processとして順番に実行する。通常fixtureへcommitted mapを見せず、migration・別接続・resetを再利用fixtureへ入れない。
- `UsesReusableSurfaceWorld`はLaravelの`RefreshDatabase::migrateDatabases()`をaliasし、migration後かつcase transaction開始前に既存`OceanWorldGenerator`でDebug32x32を1回生成する。保存するstatic stateはDB名、world key、生成回数・時間だけで、各caseはworldをDBから読み直す。
- 再利用caseが外側transactionを失った場合は以後のbaseline利用を拒否する。raw PHPUnitなどdispatcher外のprocessも、専用fixture environmentとmetrics pathがないため案内付きで失敗する。
- JUnitはfixture processごとの結果をworker単位へmergeする。evidenceはfixture別のfile数・test数・時間・statusとmap生成回数・秒数を記録する。
- focused filterで一部workerが0件になることを許容し、run全体が0件の場合だけ失敗する。空workerにも空JUnitを作り、全workerのidentifier集計を維持する。
- Quality CIの各shardも同じplanner metadataを使い、`standard`、`reusable_surface`、`individual`を別PHP processで順次実行する。CI固有の手書きfile mapは追加していない。

## 分類結果

| scope | files | standard | reusable surface | individual | duplicate / missing / unexpected |
|---|---:|---:|---:|---:|---:|
| Full | 117 | 81 | 11 | 25 | 0 / 0 / 0 |
| Surface | 98 | 63 | 11 | 24 | 0 / 0 / 0 |
| Underground | 32 | 26 | 0 | 6 | 0 / 0 / 0 |

通常地上mapを全caseで使う11クラス、計108 identifiersを再利用区分へ移した。generator、world初期化・拡張・reset、production size性能、別接続concurrency、supported upgradeはindividualへ残した。`UsesForwardOnlyDatabaseMigrations`を使うclassもmetadataからindividualになる。

## map生成と時間

`DomesticCommandExecutionTest`の同じ33 identifiersは変更前後でidentifier hash `e3118f...0b74`が一致した。

| 状態 | runner wall | PHPUnit fixture | map生成 |
|---|---:|---:|---:|
| 変更前 | 188秒 | 186.096秒 | 各caseのhelper呼出し、33回 |
| 再利用後 | 155秒 | 153.003秒 | 1回、0.713383秒 |

単発測定なので固定の改善率は置かない。同じ集合でrunner wallは33秒短くなり、生成回数はcase数からworker数へ移った。

4 workersのpassing focused run `824c3c58`では、3クラス25 identifiersを3つのmap使用workerへ配置し、残る1 workerは0件で正常終了した。生成は各worker1回、合計3回（0.242661、0.245536、0.231634秒）、executed / uniqueは25 / 25だった。11クラス全108 identifiersも3つのmap使用workerで全件PASSし、生成は合計3回だった。この測定中に空workerをrun失敗とするrunner不具合を検出し、上記のrun全体判定へ修正してpassing runで再確認した。

## 独立性とfail-closed確認

- 同じbaselineで連続する2 caseは通常順で2 tests / 34 assertions、`--order-by reverse`でも2 / 34でPASSし、identifier hashも一致した。
- 同じ2 caseを各1件で実行してどちらも1 test / 17 assertionsでPASSした。
- 一時的なprobeでcase 1がworldの`current_turn`を変更して意図的に失敗し、続くcase 2が`current_turn=1`とnation 0件を読み直すことを確認した。fixture JUnitはcase 1だけfailure 1、case 2はfailure / errorとも0だった。probeは確認直後に削除し、test suiteへ残していない。
- focused選択がworker単位で0件の場合は成功し、run全体で0件の場合はexecuted / unique 0 / 0を記録してexit 1になった。

## 実行した確認

- planner・DB manager focused dispatcher: 30 tests / 112 assertions PASS。
- 3 fixtureを同じworker DBで順次通す代表: 11 tests / 83 assertions PASS。
- 4 workersの再利用代表: 25 tests / 314 assertions PASS、identifier重複0。
- 11 reusable classes: 108 tests / 3,421 assertions PASS。
- Bash syntax、空JUnit生成、tests全141 PHP filesのPintを確認した。
- 失敗run、passing run、全体0件runを含め、最終照合で`hakoniwa_parallel_%_test`は0件。

Phase 3はfixture基盤と、class単位で安全に移せる通常map caseまでを対象とした。複数責務と個別world caseが同居する巨大classは、Phase 4で責務ごとに分割してから再分類する。
