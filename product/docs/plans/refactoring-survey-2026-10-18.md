# 挙動維持リファクタリング調査（2026-10-18）

調査基準はmain `7b312096e7ea7d9aa8be40baf932143621f9befb`（5.5.0 / Ruleset v40）。以下の行番号はこの固定コミットを指す。
古い管理ノートのmain表記は履歴として扱い、現行Gitとコードを正本にした。調査は非網羅であり、候補の不在や全経路の安全を保証するものではない。
調査時点では以下の整理は未実装。1を今回の実装対象として採用し、2〜6は後続判断の候補として残す。抽象化やファイルを増やす件数は目的にしない。

## 優先順位

| 順位 | 候補 | 判断と理由 |
|---|---|---|
| 1 | 地底の結果別XP・G算定 | 今回採用。3コピーを同一クラスのprivate算定へ集約でき、新ファイル・DB責務・RNGの移動が不要。 |
| 2 | 中央施設の上限判定 | 予告と実行の2経路に実利があるが、同セル除外・例外差・lockを保つ境界が必要。 |
| 3 | 集落のstage key解決 | 3経路の同じkey選択を共有できるが、identity・列挙順・保存差を分離する必要がある。 |
| 4 | 国の活動状態projection | 公開・認証済みAPIの同じ派生値を共有できる。表示差や認可まで統一しない。 |
| 5 | bulk skipのdrop容量・集計 | actual grant本体は共有済み。残りの重複に対し、抽選順を動かすリスクが大きい。 |
| 6 | 2並行testのprocess lifecycle | 2テスト間の補助処理整理。新helperを伴い、本体の重複解消より後順。 |

## 1. 地底の結果別XP・G算定（今回採用）

- 根拠: `app/Application/Underground/UndergroundRuntimeService.php:1955,2535,2902`に、勝利XP・撤退時1/4 XP・敗北時G半減の算定がある。
- 保守利益: 同じ結果別計算を変更する際の3箇所修正を1箇所にする。戦闘や決算の新しい共通基盤は作らない。
- 最小案: 同一クラス内のprivate methodに純粋な算定だけを置き、各呼出側が必要な入力を渡す。
- 保持する差: PTのotherworld撤退XPは0のまま。DB更新・RNG・lock・回復・初回clear等の責務と呼出順は呼出側に残す。
- 既存検証: `tests/Underground/Feature/UndergroundRuntimeTest.php:110,507,750,774,1722`等の代表caseをbefore/afterで比較する。結果別報酬・進行・資産の既存期待を変えずに通ることを確認する。
- 影響境界: 資産・進行に使う算定なので返却値の一致が必要。transaction・lock・RNG・Ruleset・migrationは変更しない。

## 2. 中央施設の上限判定

- 根拠: `app/Application/CommandQueueService.php:1995`と`app/Application/DomesticCommandExecutor.php:833`にsettings解決とCOUNT判定が重複している。
- 保守利益: 上限条件を変える際に、計画の予告とTurn実行の2箇所を別々に直す必要を減らす。
- 最小案: 現行のsettingsと既存DB状態に対する同じ判定だけを共有し、登録・実行の入口は残す。
- 保持する差: 同セル除外、例外の種類・文言、呼出位置、transaction・lockの取得順を維持する。
- 既存検証: `tests/Feature/DomesticCommandExecutionTest.php:709`を中心に、上限到達時と同セル操作の既存結果をbefore/afterで比較する。
- 影響境界: DB読取を含むためqueryとlockの境界に注意する。予約全件をCOUNTへ算入する変更は現行挙動を変えるので除外。

## 3. 集落のstage key解決

- 根拠: `app/Application/CompleteTurnEngine.php:1848`、`app/Application/NationRefugeeReceptionService.php:107`、`app/Application/SurfaceShipTurnService.php:558`に集落stage keyの解決がある。
- 保守利益: 同じstage選択を3経路で別々に保守する負担を減らす。
- 最小案: 入力からkeyを返す純粋な部分だけを共有し、施設変更や人口処理まで共通化しない。
- 保持する差: fixed identity・capital guard、stageの列挙順、event発行・saveの差を保持する。順序をsortし直す整理も含めない。
- 既存検証: `tests/Feature/TurnCellProcessingTest.php:548,683,949,1105,1298`と`tests/Feature/CompleteTurnIntegrationTest.php:403`でstage・人口・保存結果をbefore/after比較する。
- 影響境界: key解決以外の資産・人口更新、lock、RNG、event、Rulesetのauthorityを移動しない。

## 4. 国の活動状態projection

- 根拠: `app/Http/Resources/NationResource.php:55,107`と`app/Application/PublicWorldService.php:196,233`で復帰までの残Turn、生存Turn、資金繰りTurn、活動状態を重複算定している。
- 利用先: `PublicWorldService.php:82,119`のランキング・公開島詳細と、`NationResource`を使う自島・認証済み他島API等。
- 保守利益: 同じ派生値の変更で必要な2実装修正を1箇所にする。
- 最小案: 取得済みNation属性と`current_turn`を入力にする小さな純粋projectionへ共有する。追加queryを発生させない。
- 保持する差: `NationResource.php:88`のabandoned/未知stateラベルと`PublicWorldService.php:206`のdefault空文字は統一しない。休眠日数、設定閾値、認可、公開項目の選別は各呼出側に残す。
- 既存検証: `tests/Feature/NationDormancyTest.php:309`は公開・認証済みAPI両方のrecovery残Turn、`PublicLobbyApiTest.php:114`は生存・資金繰り・活動状態を検証する。`ApiAndAssetTest.php:317`は公開整合と秘匿、`resources/js/AppShell.shared.test.ts:123,200`は表示配線の代表。
- 検証の限界: active/dormantの全共有項目を両API間で比較する保証は不足するため、採用時は既存代表caseの比較項目を必要最小限拡張する。読取のみとし、lock・資産・RNG・lifecycle遷移は変更しない。

## 5. bulk skipのdrop容量・集計

- 根拠: `app/Application/Underground/UndergroundRuntimeService.php:802,954`に残り容量・集計の重複がある。一方、`app/Application/Underground/UndergroundEquipmentDropService.php:168`でactual grantは既に共有されている。
- 保守利益: 狩場と試練の2経路に残る容量消費・集計の修正漏れを減らす。drop grant本体の再抽象化はしない。
- 最小案: 既存grant呼出後の容量・集計だけを候補とし、抽選処理の一括移動は避ける。
- 保持する差: generator、Trialの石抽選から装備抽選への順序、source indexを維持する。
- 既存検証: `tests/Underground/Feature/UndergroundSkipSettlementTest.php:220,410,457,481`でdrop・容量・集計・再実行結果をbefore/after比較する。
- 影響境界: 資産とRNGが近接するため後順。乱数消費順、grant順、永続identity、transaction・lockを変更しない。

## 6. 2並行testのprocess lifecycle

- 根拠: `tests/Feature/PostgresSecretaryEquipmentConcurrencyTest.php:241,302,339`と`tests/Feature/PostgresRewardMutationConcurrencyTest.php:158,216,251`にworker起動・待機・終了処理がある。
- 保守利益: processの扱いを変更する際の2テスト間の修正漏れを減らす。
- 最小案: この2テストだけに使う小さなhelperへprocess lifecycleだけを共有する。並行テスト全般のframeworkにはしない。
- 保持する差: worker script、SQL lock、poll timeoutの15秒/30秒差、個別fixtureは各テストに残す。
- 既存検証: 上記2テストをbefore/afterで実行し、競合時のblock・解放後の結果・worker終了を比較する。
- 影響境界: test補助だけで本体の認可・lock・資産・RNGは変更しない。新helperが必要なため本体側の候補より後順。

## 除外対象と確認範囲

- Open DraftのPR305（`a7615e6`、売主受取計算）とPR306（`a585f891`、地底露店の無期限化・UI・Ruleset/migration）は重複・干渉を避ける。
- 現行Gitで整理済み: 難民PR276、港PR277、数量PR256、queue PR258 / `3b63cda3`、missile `72081f43`、Ruleset selection `4b18a582`、resource quote `336d0e5a`。
- 仕様変更として除外: 転生/EXP cap、fixed identityやstate_label差の統一、future metadataのRuleset削除、自律retry、equipment pending処理の統一。読込race修正やデザイン/UI仕様変更も混ぜない。
- 調査分担は本書の作成とコード読取に限定した。各候補の既存テストは比較方法の根拠であり、この調査一覧だけで実行済みを示すものではない。採用候補の実装・実行結果は対象PRに記録する。
