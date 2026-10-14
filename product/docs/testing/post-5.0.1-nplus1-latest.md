# DB N+1調査の追加追跡: release更新・遷都クールダウン

2026-10-14追記。[元の固定SHA監査](nplus1-audit.md)を再実行せず、新しいproduct/app変更経路だけを確認した。テスト整理とは別成果物であり、この担当はアプリ本体・テスト・DBを変更していない。

## 現HEADと追加範囲

追記時に`git rev-parse github/release/5.2.0`を実行し、結果は`35c96749c078a20f855837544078372303ba6911`だった。

| 対象 | 固定SHA | 旧監査からの差分 |
|---|---|---|
| PR262取り込み時release | `53303b989ce3ae6b9e91f0342943208427cfdd26` | product/appの17変更ファイルは既報PR262 `6e64f55b196b09faae066b0db4ed0f47356671e4`と完全一致。Git比較が空なので本文を無意味に再読していない |
| PR267遷都クールダウン | `31a9b96d48f1bc4fb37e741f53f35bf33c7ea5ca` | 53303基準とのproduct/app全7変更ファイルを追加確認 |
| 最新release・PR267 merge | `35c96749c078a20f855837544078372303ba6911` | 53303→35c967のproduct/app差分が上の7件。PR267の変更は取り込み済み |
| PR266 frontend | `21615415cabca8806fef8b9d96dad033bfabb4f5` | 最新releaseとのmerge-base以降のproduct/app差分0件 |

元台帳の35 distinct appファイルに新規の3ファイル(CapitalRelocationCooldown、CommandFailureReason、Nation)が加わり、通算38 distinct appファイルを確認した。追加7箇所だけを以下に記録する。PR188、PR265、範囲外の全体性能監査へ拡張していない。

## 現在の候補の状態

| ID | 最新releaseでの状態 | 実測・修正状態 |
|---|---|---|
| NQ-01: 工作船検証のRuleset遅延ロード | **現HEADの課題**。PR262が取り込まれ、queue予測のdistinct definitionごとの追加Ruleset SELECT、Turn工作船itemごとの追加Ruleset SELECTが本体に存在する | 未測定・未修正。元台帳のnested eager/context snapshot供給案を保持。ID照合/provenanceを守る |
| NQ-02: 工作船検証の重複Ship SELECT | **現HEADの課題**。一覧のvalidation/費用/projection、Turnのvalidation/費用に同じ船lookupが存在する | 未測定・未修正。単command内の検証結果引渡しが局所候補。跨command/Turn cacheや過大先読みは保留 |
| NQ-03: 停止軍艦の重複Nation lock SELECT | 現HEADでは旧経路を維持。親担当がpatch候補を隔離PGで実測済み | 対象SELECT **2→1**、既存case **1 test / 11 assertions成功**をbefore/afterで維持。repoへの適用・commit・CI・release取り込みは未確認 |
| NQ-04: queue圧縮の反復UPDATE | 旧基準から残るwrite amplification。今回追加で悪化しない | bulk update案は保留、未測定 |
| NQ-05: 陸地距離検証の重複 | この最新releaseにPR260の改善はまだ含まれていない。元台帳のPR260固定差分には改善がある | PR260の採用/統合時にmemo・existsとPR262引数変更を再確認。先読み拡張はしない |
| NQ-06: 探索船の宝箱再読取 | 旧基準から残る関連経路。今回追加で悪化しない | 取得順・視界変化の意味を維持する設計と実測が必要。cacheは保留 |

NQ-01/02の[現在のcostMultiplier](https://github.com/Mamiki765/hakoniwa-world/blob/35c96749c078a20f855837544078372303ba6911/product/app/Domain/Command/UnderseaConstructionPolicy.php#L27)は、工作船指定時にcommand.rulesetVersionを読み、owner/world/active/target/HPを絞ったShip SELECTを毎回行う。PR267はこの実装やload設定を変更しない。元台帳の参照SHAは再現根拠として保持し、未マージPR候補という状態表記だけを上表で更新した。

## 追加7変更経路の全件台帳

| product/app以下の箇所 | 追加経路・件数増加の確認 | 分類/根拠 | 修正・未確認 |
|---|---|---|---|
| Application/CurrentDatabaseBaseline.php | applied marker→capital relocation column検査→既存structure取得 | migration適用済みなら**固定+1 SELECT**。column情報を一度取得し、max(attnum)は同一SQL内のsubquery。Nation/row数のloopへ問い合わせを追加しない | N+1候補なし。PG実行計画・所要時間は未測定 |
| Application/DomesticCommandExecutor.php | Nation lock済みのqueue validation→cooldown計算。成功applyCapitalRelocation→Nation update | [validation:529](https://github.com/Mamiki765/hakoniwa-world/blob/35c96749c078a20f855837544078372303ba6911/product/app/Application/DomesticCommandExecutor.php#L529)は既存Nation attributeとtargetTurnだけ、追加SELECT **0**。[成功時:1652](https://github.com/Mamiki765/hakoniwa-world/blob/35c96749c078a20f855837544078372303ba6911/product/app/Application/DomesticCommandExecutor.php#L1652)のUPDATE **+1/成功遷都**は履歴永続化に必要。失敗時は追加UPDATEなし。別Nation再照会なし | N+1候補なし。履歴更新の仕様/境界テストは親のテスト棚卸し担当へ委ねる |
| Application/PlayerIslandEventService.php | commandFailureMessageのcooldown case追加→event map | enum reason/constantをsprintfするだけ、追加SQL **0/event**。private/public filtering経路を変更しない | N+1候補なし。runtime測定なし |
| Domain/Command/CapitalRelocationCooldown.php | remainingTurns(lastSuccessfulTurn,turn) | nullable整数と固定期間の算術のみ。DB/relationアクセス **0** | N+1候補なし。アプリ仕様変更はしない |
| Domain/Command/CommandFailureReason.php | cooldown enum case追加 | 値の定義のみ。DBアクセス **0** | N+1候補なし |
| Http/Controllers/Api/CommandQueueController.php | definition map前に残期間算出→relocate_capitalだけwarning/remaining出力 | [map前:198](https://github.com/Mamiki765/hakoniwa-world/blob/35c96749c078a20f855837544078372303ba6911/product/app/Http/Controllers/Api/CommandQueueController.php#L198)で既存route Nation/World attributesを使用。definition数に比例するSELECT増分 **0**。map中は算出済み整数を参照 | 新N+1候補なし。既報NQ-01/02の呼出は引き続き存在する |
| Models/Nation.php | last_capital_relocation_turn fillable/cast/property追加 | attributeのinteger castだけ、accessor/遅延relation追加なし | N+1候補なし |

PR266はbackend差分0件なので、frontend描画や既存API全体の性能監査へ範囲を広げない。

## NQ-03隔離PG実測の証拠

実測は親担当が隔離開発PGで行い、この担当は既存の証拠ファイルとreadonly overrideを確認した。再実行はしていない。

| 証拠 | before | after |
|---|---|---|
| 実行metadata | [qa/nplus1-before.json](qa/nplus1-before.json) | [qa/nplus1-after.json](qa/nplus1-after.json) |
| 対象query | [qa/nplus1-before-queries.json](qa/nplus1-before-queries.json): 2件 | [qa/nplus1-after-queries.json](qa/nplus1-after-queries.json): 1件 |
| PHPUnit結果 | [qa/nplus1-before.log](qa/nplus1-before.log): exit 0、1 test / 11 assertions | [qa/nplus1-after.log](qa/nplus1-after.log): exit 0、1 test / 11 assertions |

実行filterは同じ既存`test_blocked_last_step_clears_without_arrival_and_warship_still_attacks`。beforeは既存testにSQL listenerを足した一時readonly override、afterはさらに[qa/ship-nation-reuse.php](qa/ship-nation-reuse.php)をserviceへreadonly overrideしている。repo本体を上書きした測定ではない。このoverrideのdestinationNation再利用は[別patch候補](blocked-warship-nation-reuse.patch)と一致する。

保存ログの2件/1件はいずれも次のSQLとbindingsだった。

```sql
select * from "nations"
where "nations"."id" = ? and "state" = ?
limit 1 for update
-- bindings: [1, "active"]
```

listenerはfixture準備後のtest内で`from "nations"`と`for update`を拾う。実際の保存対象が上のstate=active/ID=1に一致していることも確認した。停止・目的地解除・fuel不消費・海賊damage・支出・owner event・public秘匿を含む元の11assertが同時に成功する。総SQL数や所要時間の高速化はこの一回比較から断定しない。

最新releaseにはSurfaceShipTurnServiceの追加変更がないので、この局所案の根拠は変わらない。baseline2→候補1という実測は修正案の支持証拠であり、releaseへ既に反映されたという証拠ではない。

## 残件

- NQ-01/02は現在releaseの課題として、テスト整理とは別の性能差分で扱う。対処時は既存適切なRuleset snapshotのID照合、Ship権限/World/target/active/HP、mutation lockと最新状態検証を保持する。
- NQ-03は実測済み局所候補。必要な既存functional確認は成功している。親担当の適用・review・CI・commit/PR/mergeの結果で状態を更新する。
- NQ-04/06は既存由来・意味が変わり得る最適化を保留。NQ-05はPR260側改善の統合時確認が必要。
- 今回新しく確認したPR267に追加のSELECT N+1は見つからなかった。PG plan・大規模Worldの時間/メモリ、NQ-01/02の実測は未実施。性能保証や全repository監査の完了とは扱わない。
