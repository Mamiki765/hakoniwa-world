# テストをゼロベースで組み直す設計図

Status: Phase 0a～Phase 5を`release/4.2.0`で実装・focused確認済み。Phase 6の最終Full4だけ未実施。[Phase 0a・1記録](test-suite-phase01-implementation.md)、[Phase 2記録](test-suite-phase02-implementation.md)、[Phase 3記録](test-suite-phase03-implementation.md)、[Phase 4記録](test-suite-phase04-implementation.md)、[Phase 5記録](test-suite-phase05-implementation.md)を参照。

以下の棚卸しはmain `00182bd`に固定した**設計baseline**。実装後の件数・確認結果と区別する。

調査日・差分更新日: 2026-09-15。更新対象: fetchで確認した`origin/main`の`00182bd0eaee52f34194c7b40bc5e98712108718`。
前回baseline: `671752244d7a2d28e87a42fdd80592c9c51398cd`、116 files / 1,039 cases。mainまでの差分だけを追加精査した。
設計調査では作業branchを`codex/backup-zstd-compression`のまま保持し、mainのGit archiveを別directoryへ展開して読んだ。実装開始前にForgejo APIで最新mainが同じSHAと再確認し、そこから`release/4.2.0`を作成した。

**更新要点:** 現在は116 files / **1,058 cases**（20追加・1削除）。PHPテスト17ファイルとfrontendテスト5ファイルの変更を精査した。[main差分の処置表](test-suite-main-delta.md)と、[機械的な差分一覧](test-suite-main-delta.json)を参照。新しい不要テストの増殖防止は§10へ追加した。

## Ownerの目的

- 既存テストの維持を前提にせず、今のplayer/operatorに必要な保証から組み直す。
- 既存テストの削除・再構成による高速化と、新しい不要テストを作らせないことを同じ目標にする。AGENTS.mdの解釈を広げられる余地と改訂案を設計に残す。
- データ破損、資産消失、二重決算を防ぐ重要なテストには必要な実行コストを許容する（今回Owner確認済み）。
- UIで許可しない極端値や、DBを直接改竄して21億を入れた状態のためのテストは削減する。
- マップは繰り返し生成せず、独立性を保ちながら再利用する。
- ローカル4 shardでFull／地上（箱庭）／地底（RPG）を選べる構成を設計する。
- usageのリセットを行わない。Ownerの継続指示によりPhase 0a～5とdraft PR更新まで実施し、Phase 6の最終確認を残す。

## 現時点の主要な判断

1. `TestShardPlanner`と既存parallel runnerを拡張する。対象集合を選んでから4分割する。
2. `CreatesTestWorlds::lightweightWorld()`も毎回32×32のマップを生成している。通常のDBテストは再利用できるbaselineと各caseのrollbackへ移す。
3. マップ生成自体、migration、別接続のconcurrencyは通常fixtureと分ける。既存データを壊す失敗の再現は残す。
4. 過去の全identifier維持や「削除には必ず代替test」という規則を今回へ持ち込まない。不要な保証は代替なしで削除する。
5. 既存計測は過去runとして扱う。現行HEADの速度とPASSは未測定。
6. 新規test、provider、loop、別layerのassertionも、到達可能な故障と既存確認の不足がある場合だけ増やす。件数を減らしても一件の中へ同じmatrixを隠さない。

## 1. 調査の根拠と観測限界

| 現行集合 | ファイル | 宣言されたtest method | data provider展開後のケース |
|---|---:|---:|---:|
| 地上側の既存配置 `tests/Unit` + `tests/Feature` | 97 | 713 | 836 |
| 地底 `tests/Underground` | 19 | 208 | 222 |
| Full | 116 | 921 | 1,058 |

- 上表は既存開発imageへmainのsourceをread-only mountした一時containerでの`phpunit --list-tests`による列挙。networkは無効、テスト本体は未実行。地上側の既存配置には認証などの共通機能も含む。
- [全ファイル棚卸し](test-suite-inventory.csv)には現行scope、行数、case数、fixture呼出し箇所数、DB lifecycle、ファイルhashを記録した。[識別子一覧](test-suite-identifiers.txt)は削減前の比較用資料であり、永久維持する集合ではない。
- [採取結果](test-suite-audit.json)にmainのSHA、件数、列挙環境と検証結果を保存した。前回の[採取結果](baseline-6717522/test-suite-audit.json)、[inventory](baseline-6717522/test-suite-inventory.csv)、[1,039件のidentifier](baseline-6717522/test-suite-identifiers.txt)は変更せず保存した。
- PHPテストの追加・削除ファイルは0、変更17、source不変99。変更のない99ファイルを一から再評価せず、関連runtime・migration・configの変更が前回の分類へ与える影響を確認した。source不変はPASSの証拠ではない。
- `RefreshDatabase`を使うファイルは72、`UsesForwardOnlyDatabaseMigrations`を使うファイルは9。後者はcaseごとに`migrate:fresh`する。ほかにLaravel appを使う15ファイル、PHPUnitだけを使う20ファイルがある（静的分類）。
- `lightweightWorld()`の呼出し箇所は56ファイル・271箇所（+3）。直接の`OceanWorldGenerator::initialize()`呼出しは40箇所（不変）、`NationCreationService::create()`は165箇所（+4）。増分は`InquiryConcurrencyFailureTest`に集中する。helperの間接呼出し・loop・providerによる実行回数はこの数字に含めていない。
- frontendは20 testファイル。PHPUnitの4 shardとVitestのworkerは別物で、現行`npm test`にはdomain選択がない。
- mainの`docs/open-questions.md`ではparty AoE覚醒配布（UG-06）は2026-09-13にDecided、案内人決闘（UG-07）は2026-09-14にDecided。前回のAoE未決扱いを撤回する。実際にHPまたはbarrierへ被害を受けた各人が敵の1 actionにつき一度だけ覚醒を得る契約を残す。market等の残るOpenは今回決定しない。
- current codeはapplication 4.1.2、combat v5、skill tree v2。追加のSP還元・rental party・案内人決闘migrationを確認した。`current-status.md`の未merge表記より確認済みGit refを優先するが、productionへの適用状態は今回確認していない。
- `ver-2.3.1-test-rationalization.md`はhistorical文書。「全identifierを残す」「削除に必ず同等以上の代替を置く」という当時の制約は今回のOwner指示へ継承しない。

### 時間の根拠

前回、既存開発container内の`storage/framework/testing/test-evidence/`を読み取った。[前回採取結果](baseline-6717522/test-suite-audit.json)に保存した歴史的な測定であり、今回のmainのベンチマークではない。新しい競合テスト等の増分時間は未測定。

| 過去run | 結果・SHA binding | 4 shardのprocess秒数 | 最長 |
|---|---|---|---:|
| `f2a64992` / 2026-09-10 UTC | PASS、`tested_sha=unknown` | 1,111 / 589 / 481 / 735 | 18分31秒 |
| `ca5dd215` / 2026-09-12 UTC | FAIL、`e632391521084557be45e01a94d06db432040ac6` | 1,263 / 733 / 567 / 943 | 21分03秒 |

後者のJUnit case時間合計は約3,503秒。重い順の一部は以下。失敗やfixture時間を含み、現行コードの速度保証には使わない。

| ファイル | 過去JUnit case秒数合計 | 主な改修方向 |
|---|---:|---|
| `CommandAndMissileTest` | 339.3 | 5,753行・75ケース。通常fixture再利用、責務ごとの分割、同じ防御pathのvariant重複削減 |
| `CommandQueueAndSalePolicyTest` | 163.9 | queueの資産・順序・retryへ集中。将来専用parameterを削る |
| `TurnRuntimePerformanceTest` | 129.8 | 毎回大mapを作る観測matrixを代表的な回帰へ縮める |
| `DomesticCommandExecutionTest` | 128.0 | 33箇所のmap helper呼出しを再利用fixtureへ移す |
| `PostgresUndergroundRuntimeConcurrencyTest` | 125.6 | 二重決算・資産競合を維持。migration・worker起動時間を分離計測 |
| `MonsterSystemTest` | 124.7 | kill/報酬/rollbackを残し、全catalogの報酬再実行を削る |
| `DisasterAndOilTurnTest` | 105.3 | 災害固有の被害は残す。性能suiteとの大型fixture重複を除く |

現行plannerはpathをsortしてround-robinで配るだけ。29ファイルずつでも時間は均等にならない。
local-development文書の「4 shards 5分46秒」は別時点の測定である。今回の所要時間として使わない。
hostの`.phpunit.result.cache`には現存しないclassも混在するため、速度配分の正本にしない。
また過去`run.tsv`の`test_count`合計とJUnitのcase件数が一致しないrunがある。新runnerの件数はJUnitと列挙集合で照合する。

## 2. 残すものを先に決める

既存methodを一件ずつ温存するのではなく、下表の故障を検出する最小の独立シナリオを設計する。

| 保証のowner | 必要な代表シナリオ | 削る重複 |
|---|---|---|
| 認証・ownership | 他人の資産を触れない、同時初回認証でもidentityが分裂しない | 同じmiddlewareの401/403を全endpointで反復するmatrix |
| command queue | 同じrequestの再送、stale version、取消・並替、target固定、危険操作の確認、資産を消費する前の拒否 | UIで送れない型/極端値の下位service・DBでの再検証、同じpayloadの全field差分matrix |
| Turn | 実データを変更した後の例外でrollback、同seedのmanual retry、二重実行防止、releaseを跨ぐ未解決Turn拒否 | 各commandごとの同一transaction機構の全面再検証。ただし異なるwrite経路・副作用は区別する |
| 地上経済 | 売却・消費・overflowの順序、escrow、資産上限、取引・補填の一度限り決算 | 同じ計算をAPI、service、DB、frontendの全layerで再計算して照合すること |
| map/島 | 登録atomicity、候補の継続探索、既存島非破壊、拡張で既存cell維持、所有者・占有整合性 | 通常commandの前準備で島生成そのものを毎回検証すること |
| 怪獣・missile・船 | kill報酬一度限り、正しい所有者、同一turn中の最新状態、移動/消滅/被害の実在する差分 | catalog全件を同じengineで繰り返すこと、同一防御policyの全source×type総当たり |
| 地底決算 | XP/G/鍵/宝物庫/装備の原子的変更、失敗・再送・同時実行で二重報酬なし、借用元保護、trial進行保持 | 同じ純粋戦闘計算をDB/APIで再実行すること、全story画面の通過を各機能fixtureに使うこと |
| main追加の地底永続状態 | 借用側にだけrental HP/覚醒を保存、明示更新だけで覚醒reset、案内人決闘で両owner資源保持・Gram一度限り、SP還元後の既存進行/retry保持 | 各武器・各仲間人数・勝敗ごとに同じ決算matrixを追加すること |
| 地上・地底の競合 | 問合せ/Turn/partyの実際のlock順序でdeadlockなし、同時加算のlost updateなし、同じ送信の添付保存一度限り | class名を理由に地底scopeから除くこと、同じlock順序を全endpointで再現すること |
| 戦闘core | action順序、seed replay、死亡target除外、挑発、回復target、AoEの現行確定契約 | 全enemy×build×tier×seedを通常回帰にすること、未決仕様を固定すること |
| schema/install/運用 | fresh install、現在supportedなupgrade、既存資産・terminal履歴保持、production破壊command guard | unsupportedな旧runtime chain、migrationファイル数・配置だけのsnapshot |
| frontend | retry中の要求identity保持、二重送信抑止、正しい送信先/target、操作可否、失敗後の回復 | DOMの子要素順、class並び、catalogボタン総数、長い説明文の完全一致 |
| 公開文書の安全な表示 | 管理者が貼ったMarkdownの危険HTML/URLを無害化し、編集用sourceを保持 | rendererを通る全画面で同じXSS payloadを再実行、HTML全文snapshot |

「データ破損に関係する」というラベルだけで全異常系を温存しない。通常のplayer/operator経路からどのデータが壊れるのかを説明できる代表だけ残す。
HTTPの他人ID差替えは実行可能なauthorization攻撃なので代表を残す。一方、DBへ非現実的な巨大値を直接保存するテストを同じ分類にしない。
正常操作で上限へ到達する経済overflow、残高不足、HP0、最後のslot、通信断再送は削減対象の「あり得ない外れ値」には含めない。

## 3. 具体的な削減・再構成候補

`削除`、`縮小`、`到達確認`は設計時点の処置。実装した結果と残したownerは[Phase 4記録](test-suite-phase04-implementation.md)を正本とする。

| 優先 | 現行箇所 | 処置と残す意味 |
|---|---|---|
| A | `ApiAndAssetTest::test_nation_creation_classifies_player_conflicts_and_hides_internal_failures`の`nation_number=2_147_483_647` | **その末尾ブロックを削除**。改行、重複名、request conflict、二重所属は実在する拒否として必要な代表を残す。巨大島番号を別の人工異常へ置換しない |
| A | `UndergroundPlayerAccessTest`の銀行case末尾`banked_shard_balance=PHP_INT_MAX-500` | **巨大残高のoverflowブロックを削除**。通常入出金、残高不足、再送、他人の残高保護は維持 |
| A | 同ファイルの銀行payloadへ常時付ける`PHP_INT_MAX`、`stats`/`weapon_power`の巨大値 | **極端値の反復を削除**。server authoritativeな資産/能力を保護する必要がある箇所は、通常範囲の偽値を持つ代表1件へ集約。銀行全actionへ繰り返さない |
| A | `UndergroundCombatBuildTest::test_combat_value_scaling_rejects_an_actual_operand_product_that_cannot_fit_an_integer` | **削除**。21億level×巨大攻撃力のための保証を通常suiteから外す |
| A | 同`test_level_scaling_rejects_only_non_positive_or_unrepresentable_values`の`PHP_INT_MAX`等 | **縮小**。現行の合法level scalingを維持。未到達の整数限界を独自の恒久contractにしない |
| A | `UndergroundBalanceSimulatorTest`のinteger headroom / canonical enemy overflow case | **削除**。不可能な巨大levelをsimulationの通常回帰へ持ち込まない |
| A | `CommandQueueAndSalePolicyTest::test_future_special_parameter_api_distinguishes_omitted_defaults_from_explicit_null` | **削除候補**。架空の`design_id`/`optional_variant`をdefinitionへ追加している。現行catalogに同じ意味の利用者が存在しないことを確認して代替なしで削除 |
| A | `App.test.ts`のranking badge子要素順、monster mark全件数 | **該当assertionを削除/意味へ縮小**。対象操作が選べる、誤ったtargetへ送らないことを確認。地下入口ボタン総数は再確認で現行にも旧baselineにもassertionがなく、元の候補記載を訂正する。既存の探索/試練の操作確認は維持 |
| A | `SecretaryItemEffectsTest::test_no_equipped_ring_preserves_the_exact_legacy_finance_metadata_shape` | **削除候補**。必要なfinance結果は現行の代表で確認。旧metadataの完全形を維持する現在のconsumerがなければ代替しない |
| A | `MonsterSystemTest::test_all_eight_definitions_use_their_exact_wreckage_reward_values` | **全definitionのDB再実行を削除**。通常分配、奇数端数、同一owner、hostless等の異なる決算policyだけ残す。値はcurrent catalogの入力として扱う |
| B | `CommandAndMissileTest::test_v8_radius_two_defense_intercepts_every_source_missile_kind` | **共通interception経路の代表へ縮小**。source選択・特殊な非intercept・self/foreign判定が違うものは残す。名前の`v8`だけを理由に削除しない |
| B | `TurnRuntimePerformanceTest`の7災害×大型map | **通常回帰は代表的なquery増加検知へ縮小**。災害ごとの被害意味は小fixtureで既存`DisasterAndOilTurnTest`へ集約。全7種の大型測定は必要な性能調査時だけ実行 |
| B | 同fileの3 special profile、nation数、missile数provider | **同じ増加傾向ならsmall/largeの2点へ縮小**。単なるquery>0やreport出力目的の反復は通常suiteから除く。source走査が別ならその代表を残す |
| B | `RuntimeMetadataFailureTest`の4つの破損metadata | **破損fixtureのmatrixを廃止方向**。実際に書いた後のTurn rollbackは`TurnRunnerTest`等にownerを置く。configuration破損がsupported operator経路から起きるものだけ1代表を維持 |
| B | `CurrentRulesetContractTest`のcatalog全値・順序assertion | **immutable identity/checksum/validatorを1箇所に集中**。値のコピーやUI sort順まで別testで固定しない。semanticな差分は当該domainで代表確認 |
| B | `FreshInstallRebaselineTest`のcommand/production/monster件数 | **件数snapshotを削減**。installerが必要なstable keyを供給し、資産を保持して起動できることへ寄せる |
| B | `SecretaryEquipmentSchemaTest`のversion=0直接書込だけのcase | **単独fileを廃止する候補**。productionで無効versionを作るwriteがあるか確認。必要ならschema整合性owner内の代表へ統合 |
| B | `ProductionConfigCacheEntrypointTest`のDockerfile文字列・行末・cache記法 | **source textの固定を削減**。必要な起動境界は残す。Dockerの実buildを全suiteへ追加して埋め合わせない |
| C | `CommandQueueAndSalePolicyTest`のhistorical null fingerprint / staged position | **到達確認**。現行supported upgradeでそのpersisted状態が残るなら資産/retry保護を残す。過去Agentが作った状態だけなら削除 |
| C | laboratory/prototype関連 | **runtime参照単位で選別**。tutorialが再利用するcombatやoperatorが使うsimulationは残す。旧prototype全variantの役割・未使用future report形は通常suiteから除く |
| C | migration/forward upgrade群 | **現在のsupported sourceからの1経路をownerにする**。古いclass名だけで除外しない。production baselineの追加確認が必要な互換性削除は今回の設計だけで決定しない |

数字検索の誤削除防止: `TurnRandomStreamTest`の`integer(0, 2_147_483_647)`は固定seedのRNG vectorであり、DB改竄ではない。`NationAutomaticExpansionTest::rawCells`の整数min/maxは検索範囲のdefault。frontendのparameter metadataのmaximumも実値を21億へ書くcaseとは限らない。数字を一括置換/削除しない。

### 大きなファイルの切り分け

- `CommandAndMissileTest`: queue/生活commandとの混在を解き、missile impact、defense、KARMA/recovery、資産・rollbackの単位で再編する。既存の`DomesticCommandExecutionTest`等で同じ実行契約を扱う部分はそこへ移す。単に同じhelperを4コピーしてshardを増やさない。
- `UndergroundPlayerAccessTest`: API ownership/request境界、銀行・shop・装備の決算、intro/main projectionに分ける。`UndergroundRuntimeTest`の決算と重複する部分はAPI配線確認だけにする。
- `App.test.ts`: shell/共通、地上操作、地底操作へ分け、専用componentで検証済みの表示細部を削る。app全体mountはnavigationと接続に必要な代表だけにする。
- rename前後の全identifier同一性は要求しない。残した故障の検出先と、捨てた保証の理由を変更単位で残す。

## 4. マップの再利用設計

### 現状のコスト

`CreatesTestWorlds::lightweightWorld()`は名前にかかわらず`OceanWorldGenerator::initialize(Debug32x32)`を実行する。
generatorはcatalog install/publish、coverage確認、chunk/cell作成を含む。`RefreshDatabase`のcase transaction内で作ったmapはcase終了時にrollbackされるため、次のcaseで再生成する。
Laravelの`RefreshDatabase`自体は通常、process内初回のmigrationとcaseごとのtransactionを使い分けている。**全caseがmigrationしていると誤診しない。**

### 採用する最小構成

| fixture | 用途 | map作成 |
|---|---|---|
| pure | 計算、selector、RNG、combat | DBなし |
| mapなしDB | 認証、Secretary、地底profile、装備、銀行等 | worldを作らない。既存の`secretaryUser()`等を小さく再利用 |
| 再利用32×32 | 通常地上のqueue、economy、missile、monster等 | shard内の専用processで空の海worldを初回1回だけ生成・commitし、各caseの変更をrollback |
| 個別world | generator、拡張、reset、productionサイズ性能、別接続concurrency | 検証対象に必要なworldをそのcaseで生成。縮小できるfixtureは縮小する |

再利用32×32は**空の海mapのみ**を共有する。全caseへ2国・装備・資産を先置きせず、actor/必要cellの差分だけcase transaction内で作る。地底へ共通地上worldを強制しない。
地上の「地下施設」は箱庭側。RPGで使う「地底」と文字列だけで混同しない。RPGと島の接続を確認する少数caseだけ地上mapを許容する。

### 初期化・rollbackの順序

```text
workerごとの安全な *_test DB
  ├─ mapなしprocess: 従来のRefreshDatabase。通常caseはtransaction/rollback
  ├─ 再利用map process:
  │    migrate:fresh → current catalog install/publish → Debug32x32生成 → COMMIT
  │    case A: BEGIN → actor/cell差分 → 本体 → ROLLBACK
  │    case B: BEGIN → actor/cell差分 → 本体 → ROLLBACK
  └─ 個別lifecycle process: migration/reset/concurrencyを既存の実接続で確認
全process終了 → worker DB削除 → 残存照合
```

- 3区分はrunner内部のfixture管理であり、Fullから重いテストを隠す選択肢ではない。各workerは区分を順番に実行する。4 workers以外に追加のDB suiteを並列起動しない。
- normal→再利用→個別は**別PHP process**にする。committed baselineを、world不存在を前提とする既存caseに見せない。各区分の最初はschema baselineを作るので、異なるbaselineや`RefreshDatabaseState::$migrated`を引き継がない。
- `UsesReusableSurfaceWorld`（仮名）は`RefreshDatabase::migrateDatabases()`をaliasし、既存migrationが済んでからgeneratorを呼ぶ。既存`beginDatabaseTransaction()`より前に実行する。`afterRefreshingDatabase()`は既にtransaction開始後なので使わない。
- trait使用をclass単位のfixture分類の正本にし、plannerはclass metadataから区分を導出する。caseごとに異なるbaselineが必要なclassは先に分ける。別JSONへ同じ所属情報を手書きしない。
- processごとのmap生成回数を測定する。通常地上case数に比例せず、mapを使うworker数以下になることを初回の受入条件にする。
- baselineではcurrent設定と既存generatorを使う。別Ruleset、fixture専用schema、production由来dump、固定されたDB IDを新設しない。
- static cacheにはDB keyとbaselineの識別情報だけを置く。Eloquent model、PDO、container、TurnState、mutable settingsは共有しない。各caseはworldをDBから読み直す。
- SQL sequenceはrollbackしても値が戻らない。IDや採番順に依存する期待値をやめ、明示seed、stable key、fixtureが返すIDで検証する。厳密な初回採番のcaseは個別worldに置く。
- Carbon、config override、fake storage、mock、event listener、query logger、RNG/サービスcacheはcase境界で解放する。DB rollbackだけで独立性が成立したとは扱わない。
- commit/別接続/workerが必要なテストへ外側transactionを付けない。未commit fixtureがworkerに見えず、lockやafterCommitの検証が偽になるため。
- 通常テストの途中で外側transactionが消えた場合は失敗として中止し、そのworker DBを廃棄する。汚れたbaselineで後続caseを続行しない。

### 再利用に向かないものの扱い

`WorldInitializationTest`、`WorldExpansion*`、`WorldReset*`、`NationAutomaticExpansionTest`、`FreshInstallRebaselineTest`、supported upgrade、`Postgres*`等は先に共有worldへ押し込まない。
mainの`InquiryConcurrencyFailureTest`はTurnと地底partyを跨ぐ実接続テストとして個別lifecycleに残す。追加workerも含め、外側transactionやmock lockへ置き換えない。
`UndergroundRuntimeTest::test_skill_refund_preserves_earned_progress_and_historical_retry_until_loadout_is_saved`は実migrationを呼ぶ。通常のmapなしDB caseから切り離し、既存caseをSharedのupgrade担当へ移す案とする。列名や旧identityを含むという理由で人工破損テストと扱わない。
`UsesForwardOnlyDatabaseMigrations`の9ファイルにはcaseごとのschema生成費用がある。実接続の競合を守る方が優先であり、まず通常fixture削減後に残った時間を測る。migrationそのものを見ていない前処理の短縮は個別に検討し、全tableの汎用TRUNCATEやtemplate DB増殖を先行導入しない。

## 5. Full／地上／地底の4 shard

### 対象集合

domainのcanonical配置を使い、`--filter Underground`のようなclass名検索で分けない。

| scope | 対象 |
|---|---|
| `full` | Shared + Surface + Undergroundを各1回 |
| `surface` | Shared + Surface |
| `underground` | Shared + Underground |

- Surfaceは現行`tests/Unit`、`tests/Feature`を基本的に継続。Undergroundは現行`tests/Underground`を継続。
- 本当に共通な認証/identity、Secretary identity/画像保持、current Ruleset/install/upgrade、DB安全、test runner、地上・地底を跨ぐlockの検証を`tests/Shared/{Unit,Feature}`へ集約する。全Secretary装備・地上経済を機械的にSharedにしない。
- `SecretaryPersistenceTest`のような混在は、共通Secretary保護をShared、島登録との接続を小さな統合caseに切り分ける。地底scopeにmapが必要になる箇所はこの接続等に限定する。
- 各fileの所属は一つ。Sharedを含む両scopeを別々に走らせればSharedは2回になるが、Fullはset unionなので1回だけ。日常は該当scope、横断変更はFullを一度選ぶ。
- `phpunit.xml`の非重複testsuiteをdiscoveryの正本とし、`TestShardPlanner::discover(scope)`で選択する。同じdirectoryをFull/Surface/Undergroundの3定義へ重複登録しない。
- Shared抽出の最初の対象は`AuthIdentityTest` / `AuthIdentityConcurrencyTest` / `ApplicationDatabaseTimezoneTest` / `CurrentRulesetContractTest` / `RulesetAuthoringValidatorTest` / `RulesetImmutabilityTest` / `FreshInstallRebaselineTest` / `Ver390RulesetUpgradeTest` / `ProductionDestructiveDatabaseCommandGuardTest` / `TestShardPlannerTest` / `ParallelTestDatabaseManagerTest`。これは移動候補であり、各file内の不要保証は§2・§3に従い別に削減する。`Ver390RulesetUpgradeTest`は名前ではなく現行upgradeの担当範囲を読んで扱う。
- 現在の97/19ファイルという分割は移行前の数字。Shared抽出後の期待件数を永久に固定しない。
- main差分から`InquiryConcurrencyFailureTest`もSharedへ移す。名前がInquiryでも地底partyとTurnのdeadlockを検出するため、地底だけの実行でも必要。workerは既存`tests/Support/inquiry_concurrency_worker.php`を再利用し、各scopeへ同じテストを複製しない。SP還元の既存migration caseもSharedのupgrade担当へ移す。

### 実装した操作インターフェース

```powershell
# repository root / Windows
.\product\tests\scripts\run_parallel_tests.cmd 4 full
.\product\tests\scripts\run_parallel_tests.cmd 4 surface
.\product\tests\scripts\run_parallel_tests.cmd 4 underground
```

```sh
# product / 開発container
composer test:parallel -- 4 full
composer test:parallel -- 4 surface
composer test:parallel -- 4 underground
```

- 引数順は既存の`4`を保持し、scopeを第2引数に追加。省略は`4 full`。`all`は既存Composer命名とのaliasとして`full`へ正規化する。不正scopeはDB作成前に失敗する。
- `composer test:surface` / `test:underground` / `test:all`は同じdispatcherの1-worker実行にする。これにより直列でもfixture区分が分離される。`composer test`は`test:all`のaliasを継続。
- focused実行も同じdispatcherからfile/filterを渡す。再利用fixtureと通常fixtureを生のPHPUnit一発で混在させる操作は許可しない。区分専用の一時config/environmentをfixture側で検証し、案内付きで失敗する。
- Docker wrapper→shell runner→planner→DB manager→実行→evidenceの全段でscopeを渡す。wrapperだけで対象を変え、DB準備や証拠はFullのままにする実装を避ける。
- filterなどのPHPUnit引数は配列として渡し、shell command文字列へ連結しない。絞った実行の証拠をscope全体PASSとして記録しない。

### 配分

1. canonical configからscopeのfile集合を列挙する。
2. JUnitからfile単位の重みを読む。まず現在fileとの対応を確認し、消えたfileは除外する。
3. 重いfileから順に、累積予測秒数が最小のworkerへ配る（LPT）。同値時はpathとworker番号で決める。
4. worker内ではfixture区分へ分ける。profile別初期化費用をreportへ別記する。
5. 割当planをrun開始時に固定し、途中でtimingを再読込して割当を変更しない。

- file単位を基本にする。data providerの一部だけを切り出すmethod regex runnerは作らない。巨大fileは先に責務で分割する。
- timingがない新規fileは同じfixture区分の中央値を仮重みにする。全timingがない場合は既存の決定的配分へfallbackする。timingの欠落や古さでテストを落とさない。
- timingは実行計画のヒントでありcoverage authorityではない。手書き秒数manifestを維持せず、run artifactにsource hash、dependency hash、worker数、測定値を残す。旧hashの重みは古い推定として表示する。
- pytest等の別runnerや外部queueを導入しない。現在の`TestShardPlanner`、`test_shards.php`、`run_parallel_tests.*`、`ParallelTestDatabaseManager`を改修する。
- 単一heavy file時間、総時間÷4、DB I/O競合が短縮限界になる。LPT計算結果を実測短縮として報告しない。

### DB・evidence契約

- 既存の`hakoniwa_parallel_<run>_<shard>_test`、一時PHPUnit configによるDB強制、`APP_ENV=testing`、DB名guardを継続する。4 workersで同一DBを共有しない。
- workerは複数fixture区分を直列実行する。区分別JUnit/logを保存し、総和からworkerの結果を構成する。どこかの区分の失敗・未完了・cleanup失敗でrunをPASSにしない。
- 非空scopeで列挙0件は失敗。4分割のうち空workerは空として記録し、PHPUnitを無引数起動しない。
- `scope`、HEAD/dirty状態またはtree fingerprint、実行image/vendorのhash、選択identifier集合hash、割当、case結果、process wall time、bootstrap/fixture時間、cleanupを記録する。containerへ渡したSHAだけでbind mount内容まで証明したと扱わない。
- 最新source/dependencyとdevelopment imageの対応を確認する。`compose.development.yml`はsource/testsをmountするが、Composer設定と依存はimage内にある。Composer script/依存、Dockerfile、その他image内に固定されたファイルが変わった時はbuildし、通常source/test編集では繰り返しbuildしない。
- interrupt/失敗時も全child終了を確認してからDBを削除する。残存があれば対象manifestとcleanup失敗を表示し、広いprefix削除やproduction DBへのfallbackをしない。
- CIが利用できる場合は同じplannerと識別子集合を使用する。現在のGitHub Actions利用可否は今回検証していないので、利用可能を前提にローカル設計を止めない。

## 6. frontendと補助テスト

- Vitestの全20ファイルもゼロベースで整理する。`App.test.ts`を共通/地上/地底へ分け、shared componentの詳細テストをAppで重ねない。
- `npm run test:surface` / `test:underground` / `test:all`を追加する案とし、実際のファイル所属からincludeを生成する。Fullは両者のunion。component名の大文字小文字検索で所属を推測しない。
- `undergroundExplorationPending.test.ts`の確定409・通信失敗・不明エラーのretry保持は優先して残す。表示差分より要求identityと二重決算を防ぐ操作経路に予算を使う。
- mainで確定409に追加されたrental party変更・skill再設定要求は既存の同じ分類pathを使う。error codeが増えた数だけ新規caseを作らず、固有の分岐や未検出故障がある場合に既存代表へ最小差分を加える。
- 新しい宝物庫sortのpage reset/保存、server確定partyの復帰、案内人決闘の同一UUID retry、Gramの操作不可は必要なUI境界。barrierはprojectorの符号変換、componentの表示、語りのactor/targetを区別して担当を一つずつ置く。app全体の重複mountや台詞全文一致は増やさない。5ファイルの処置は[main差分表](test-suite-main-delta.md)に記載した。
- `HexMap.test.ts` / `mapState.test.ts`は座標、viewport、selection、drag中のcell消滅を代表確認。全画面snapshotやtile全件renderへ広げない。
- 4 shardの必須設計はPostgreSQL PHPUnit runner。Vitestは短い別工程として1回実行し、PHPUnit4 workersの横でさらに4重にapp mountを走らせない。統合verificationを提供するならbackend終了後に選択domainのVitestを走らせる。
- PHP static analysis、frontend typecheck/lint/build、backup shell test、docs validatorはPHPUnitの集合ではない。Fullの名前で実行済みと誤記せず、変更対象に応じて別に結果を記録する。

## 7. 実装順序と完了条件

| 段階 | 実施内容 | その段階の証拠 |
|---|---|---|
| 0: 今回 | main差分とAGENTSの過剰テスト解釈余地を確認し、この設計を更新する | 116ファイル/1,058 identifier、旧1,039との差分、17ファイルの処置、§10の改訂案 |
| 0a: 増殖防止 | §10の方針をAGENTS §8の重複する規則へ統合する | 実装記録参照。追加・拡張の必要条件、不要保証の廃止、確認を終了する条件を明確化 |
| 1: 不要保証を削る | §3のAを小さな変更単位で処理。削除/縮小理由を記録 | 対象focusedのみ。削ったケースが消えたことと残存代表の意味を確認 |
| 2: scope配線 | Sharedの最小抽出、planner/shell/cmd/Composer/evidenceへscopeを通す | 3 scope×4 shardのidentifier列挙でmissing/duplicate/unexpected=0。不正scope・空workerも少数代表で検証 |
| 3: map再利用 | 通常map caseを専用fixture区分へ移し、初回のみ生成 | 生成回数とfixture時間、同じbaselineで連続2 caseの独立性、失敗後のrollback、focused/単独/逆順で同じ結果 |
| 4: DB負荷削減 | API/service重複の統合、巨大file分割、性能matrix縮小 | transaction・asset・現行regressionのownerが残っていること。変更domain focusedで確認 |
| 5: 配分改善 | JUnit重みのLPT、過去値fallback、runごとのplan固定 | 同一集合が一度ずつ実行されること。実4 shardの最長workerと総case時間を比較 |
| 6: 最終確認 | Full4を1回。frontend/staticは変更に応じて実行 | exact treeの結果、選択集合全件の終了、重要ケースのskipなし、cleanup完了 |

### baselineを取る際の順序

1. 実装開始時にHEAD、dirty差分、vendor、現在走っているtest processを再確認する。
2. まず`DomesticCommandExecutionTest`等の代表を、同じ環境・同じ内容で変更前後測定する。map生成、nation生成、test body、schema準備、rollbackを分ける。SQL全件logは性能測定本体へ常時持ち込まない。
3. 現行HEADに紐づくFull証拠がなければ、必要なcheckpointでFull4 baselineを一度だけ取得する。保存ログが同一treeを証明できるなら再利用する。
4. fixture変更後は同じfocusedを比較する。差がノイズ程度ならその部分だけ繰り返し測る。削除後のcase件数減少と、残存case自体の高速化を別々に報告する。
5. 最終Full4で`max(worker wall)`、`sum(case seconds)`、fixture生成回数、schema準備回数、失敗/skip、メモリを報告する。古い18～21分のrunとの単純比較で改善率を断定しない。

### 全体の受入条件

- 3 scopeが選択でき、各scopeを4 workersで実行できる。Fullは残す全testのunionで、重いものを暗黙に除かない。
- serial/parallelの同一性は**再設計後の集合**で保証する。削減前1,039やmainの1,058ケース維持をgateにしない。providerのdataset名を含むidentifierまで照合する。
- retained故障のownerを§2に対応させる。二重決算、transaction、ownership、資産/進行破壊、重要な実regressionを削除前のラベルだけで捨てていない。
- UIで許可しない極端値の反復とDB改竄の21億ケースが残っていない。通常に到達する経済境界や内部RNG定数は機械的に消していない。
- 通常map caseではmap生成回数がcase数に比例しない。各caseの資産、cell、audit、queue、時刻、cacheが前のcaseから漏れない。
- migration/commit/concurrencyの検証は実際の接続可視性とlockを維持する。
- focused最適化と最終Full4に実測短縮の根拠がある。速度目標は初回実測後に置く。未測定の「半分になる」「5分以内」を成果にしない。
- 通常の開発でFull・Surface・Undergroundを続けて全実行しない。影響domainを選び、最終checkpointでFullを一度実行する。
- 新規case・provider・loop・別layerへの拡張は§10の必要条件を満たす。既存caseへ詰め直しただけの件数減少を高速化や増殖防止の達成としない。

## 8. 改修予定ファイル

| 対象 | 変更 |
|---|---|
| `product/tests/Support/TestShardPlanner.php` | scope discovery、fixture区分、時間配分、coverage report |
| `product/tests/scripts/test_shards.php` | scope/固定planのCLI、describe/verify/files |
| `product/tests/scripts/run_parallel_tests.sh` / `.cmd` | scope伝達、worker内区分の直列実行、focused、区分別evidence |
| `product/tests/Support/ParallelTestDatabaseManager.php` | 既存DB安全を維持し区分別artifact/evidenceに対応 |
| `product/tests/scripts/verify_test_identifier_equivalence.sh` | 選択scopeと再設計後集合を比較。全domainを余計に実行しない |
| `product/phpunit.xml` / `composer.json` | 非重複Shared suiteと同じdispatcherのserial alias |
| `product/tests/Concerns/CreatesTestWorlds.php`と再利用fixture trait | generated fixtureとreused fixtureの呼出しを明確に区別 |
| §3のtest / `resources/js`のtest | 不要な保証の削除、owner集約、巨大file分割 |
| `product/package.json` / `vite.config.js` | frontend domain選択 |
| `docs/operations/local-development.md` | 実装後の実コマンドと測定結果。現行値を設計段階で書き換えない |
| `.github/workflows/quality.yml` | CI利用時に同じplanner呼出しへ追従。CI利用可否を別確認 |
| `AGENTS.md` §8 | 不要テスト増殖を防ぐ必要条件と停止条件を既存規則へ統合。設計時の文案は§10、実装本文はAGENTS参照 |

Owner追加目的によりAGENTSの改訂案を含める。handoff、runtime、Ruleset、schema、migration、productionをテスト短縮のために変更する必要はない。実装中にその変更が必要になったら、テスト再設計の境界を超える理由を報告する。

## 9. 設計調査時点で実施した確認

以下はPhase 0a・Phase 1実装前の記録。実装時の操作と確認結果は[実装・検証記録](test-suite-phase01-implementation.md)に分ける。

- current code、Composer/PHPUnit、runner、DB manager、fixture、Laravelの実際のtransaction順序、関連Open/handoffを読んだ。
- fetchで`origin/main=00182bd`と確認した。旧HEADから16 commits進んでおり、作業branchの切替やmergeは行っていない。
- mainのPHPUnit識別子1,058件を列挙し、116ファイルのinventory/hashと対応させた。前回から20追加・1削除、変更17・不変99ファイルを照合した。
- Composer/PHPUnit設定、runner、共通fixtureはmain差分で不変。前回のmap再利用・4 shard設計を維持し、cross-domain競合とSP migrationの所属だけ補正した。
- 列挙に使った既存開発imageはPHP 8.5.8、Laravel v13.22.0、PHPUnit 12.5.32。`composer.lock` SHA-256は`9e5f38fb63afefa8bd8f51ec85b40bbf19e721c05a4b4f7e7b80636b43a4344d`。依存を今回更新していない。
- 既存containerの過去JUnit/run記録を読んだ。DB作成・migration・テスト本体・production操作を今回実行していない。
- mainの列挙は既存imageを使うnetwork無効の一時containerで行い、main sourceをread-only mountした。既存依存と一致するlockを確認し、一時containerは終了時に削除した。
- 設計中にAGENTS、テスト、runtime、handoffを編集していない。ファイル削除、commit、push、usage resetも行っていない。
- この節は設計調査時点の記録である。実装結果は各Phase記録へ分離し、Phase 6でexact treeのFull4とfrontend/staticを最終確認する。

## 10. 新しい不要テストを作らせないためのAGENTS監査

### 結論と対象

**解釈を広げる余地は残る。** main `00182bd`の`AGENTS.md` §8は既に、偶然の構造、未到達の異常、全variant重複、小修正ごとの機械的追加を禁止している。しかし「何が既存確認に欠けている時だけ追加するか」と「十分な確認で終了する条件」が弱い。過剰実装を明示的に命じているわけではなく、Agentの必要性判断を制約し切れていないという指摘である。

ユーザーがこの会話に提示したAGENTS本文とmainの差分も確認した。mainにはpush/CIコスト分離の追記があるが、下記の追加判断に関する曖昧さは残っていた。設計時点では監査と文案のみを作成し、続くPhase 0aでAGENTS §8へ統合した。目的は指摘を残すだけでなく、Agentが必要性を勝手に広げる余地を作らせないこと。

### 原文と広げられる解釈

行番号はmain `00182bd`の`AGENTS.md`。旧作業branchの行番号へ読み替えない。

| 箇所・原文 | 過剰実装につながる解釈 | 設計上の修正 |
|---|---|---|
| §8 L128–135「故障時の影響に比例」「transaction、retry、idempotency、concurrency、lock」 | 名前に該当するだけで全異常系・全組合せを必要とする | 高影響であることに加え、具体的な到達経路と既存確認の不足を特定する。重要な保護には必要コストを認める |
| §8 L142「既存の代表testへregressionを追加することを優先する」 | 追加が常に必要という前提、既存methodへ大量loopを詰めれば適合という解釈 | 必要性の判断を先に置き、その後で既存代表の拡張/置換を選ぶ。追加不要も正常な結論にする |
| §8 L143「同じinvariantを複数layerや全variantで重複検証しない」 | 全variantでなければ部分matrix可、別layerの方が安心という例外を設ける | 別caseには別の未検出故障が必要。配線と純粋計算など実際に異なる責務は区別する |
| §8 L144「production pathのない理論上の異常状態」 | 起きないと証明されなければ追加する、DB直書きで作れば到達可能とする | supported player/operator、外部入力境界、supported upgradeからの経路を根拠にする。fixtureへ保存できるだけでは足りない |
| §8 L145「総当たりmatrixを安易に作らない」 | 『慎重に考えた』と言えば総当たりやprovider大量追加が許される | distinctな分岐・故障がない組合せを増やさない。loop/providerも同じ基準にする |
| §8 L146–147「必要性を判断」「変更domainに必要なsuiteとstatic check」 | 小変更でもdomain全suiteを必要とし、確認済みでも追加確認を続ける | 変更の影響と具体的な未解消懸念で選び、必要な確認が通ったら終了する |
| §8 L150「CIだけでは不足する確認を追加する」 | 環境が違う/不安というだけで恒久testやlocal Fullを追加する | CIのどの具体的な不足を確認するかを特定する。一時調査と恒久regressionの追加を分ける |
| §4 L65「変換、retry、idempotency、auditへの影響を確認」、§7 L122「読む全経路を横断確認」 | 全経路の調査を全経路への新規テスト追加と読み替える | 横断して影響を調べる義務は維持し、追加testの必要性は§8で判定する |
| §8 L139–140の既存testは恒久contractではないという規則 | ゼロベース見直し時以外は、一度増えたテストを無条件に温存する | 依頼範囲内で仕様が廃止/置換された時は古い保証も削る。無関係なcleanupへ拡張しない |

現行review規則もproduction到達性を要求している。これを「テスト不足」という指摘にも適用し、具体的な未検出故障を示さない追加要求を採用しない。

### §8へ統合する設計時の文案

優先する故障影響のリスト、exact-head CIの重複実行回避、push権限との分離は維持する。既存の追加・重複・matrix・確認範囲に関する重なったbulletを、以下へ置換・統合する。§4/§7の横断調査義務を弱めない。数字、固有機能、今回のSHAはAGENTSへ入れない。

> - Testは要求された意味と具体的な故障影響を検証する。既存testやcommentをOwner承認済みの恒久contractと扱わず、偶然の要素数・DOM構造・内部名・catalog全件を独自に固定しない。
> - Testの新設・拡張には、到達可能な操作またはsupported upgrade、そこで起こる具体的な故障、既存の代表確認で検出できない理由が必要である。新機能も同じ基準で判断する。過去事故の発生は必須条件ではない。
> - 上記の不足がなければtestを追加しない。不足がある場合は既存代表の最小限の拡張・置換を優先し、異なる責務または独立した失敗条件がある場合だけ別caseにする。
> - この基準は新規file/methodだけでなく、provider、loop、assertion、別layerでの再検証にも適用する。同じ故障しか検出しない組合せやvariantを増やさない。
> - UIを迂回できる外部入力の認可・安全性、正常操作の境界値、supported upgradeの既存データを、到達不能な異常と混同しない。個々の追加には上記の必要条件を適用し、DB直書きでのみ作る到達不能状態、unsupported history、理論上の整数限界だけのためにtestを増やさない。
> - player data・資産・進行の破壊や重要な実regressionを検出する代表には必要なコストを認める。高影響の分野という理由だけで全異常系を必要扱いしない。sourceの横断確認も新規testを全経路へ追加する義務とは解釈しない。
> - 依頼範囲の仕様変更で不要になった保証は削除・置換する。不要な保証を削るためだけの代替testは作らない。件数の維持・減少だけを品質の根拠にしない。
> - focusedから変更の影響に必要な確認を選ぶ。必要な確認が通ったら終了し、新たな変更・失敗・具体的な未解消懸念がない限り拡大・反復しない。一時調査を恒久testへ残す必要性は別に判断し、未実施の確認は報告する。
> - Reviewでtest追加を求める場合も、到達経路・故障・既存確認の不足を示す。推測だけの不足を理由にtestを増やさない。

### 今回の差分での適用例

- **残す:** Turn/party/問合せの実deadlock、SP還元でearned progressとretryを壊さないこと、借用元を更新しないこと、Gramを二重付与しないこと。純粋計算testではDBの競合・永続状態を検出できない。
- **縮める:** 全武器loopを、武器制限が復活した故障を検出する代表へ縮める。宝物庫はpage境界を維持しつつ小さいpage設定でfixtureを減らす。台詞全文・固定catalog個数・HTML全文を捨てる。
- **新設しない:** 新しい確定409 codeごとのコピー、台詞変更ごとのsnapshot、同じbarrier符号確認を全画面へ展開、既存counterを全敵×全武器で繰り返すこと。
- **仕様変更へ追従:** 武器でskillをfilterする旧caseはmainで既に削除されている。旧identifier維持のために戻さない。AoE覚醒はOwner決定済みになったため、未決を理由に代表caseを除かない。

この方針のために全PR共通の申請書、承認gate、case件数上限、汎用test管理frameworkは新設しない。実装・reviewの説明に必要な根拠を簡潔に残し、実際のfixture回数と実行時間で肥大化を確認する。
