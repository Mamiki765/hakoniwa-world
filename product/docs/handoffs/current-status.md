# hakoniwa-world 現在地：4.4.0 / PR #161のCI・レビュー待ち

更新：2026-09-22 JST。Ownerの「skip ciでhandoffを更新して次チャットへ移行」依頼による文書更新。実装済み・マージ済み・本番適用済みを区別する。

## 0. 次のチャットはここから

**最優先はPR #161のCI赤1件と、進行中の固定HEAD独立レビューの結果確認。D2〜D4を作り直したり、E以降を自動開始したりしない。**

| 固定点 | 値 |
|---|---|
| Repository | `Mamiki765/hakoniwa-world`（GitHubが開発正本） |
| PR | [#161](https://github.com/Mamiki765/hakoniwa-world/pull/161)、open・未マージ |
| 作業branch | `codex/4.4.0-receipt-durable-dependencies` |
| base / release HEAD（今回観測） | `release/4.4.0` / `b2997b6d00af934159e959bc6f6748018e17a0ec` |
| 実装・独立レビューの固定HEAD | `94376c14980edfaeb5c07e2bc8058f94dc22e98e` |
| 実装CI | [Quality #575 / 35707991038](https://github.com/Mamiki765/hakoniwa-world/actions/runs/35707991038)、**failure** |
| CI内訳 | frontend・documentation・backend-static・PHPUnit 01〜15/16は成功。16/16のみ1テスト失敗、backend集約も赤 |
| 独立レビュー | Owner報告では進行中。今回取得したPRコメントは空。完了・追加P0〜P2なしとはまだ記録しない |

handoff更新commitはこの実装HEADへ文書のみを足した`[skip ci]`。PR HEADと実装CI対象HEADは以後異なる。次の担当はremote HEADと差分を確認し、文書commitを消さず作業する。文書更新だけで実装を再レビュー済み・新HEADのCI成功と呼ばない。

### CI赤の具体箇所（未修正・原因未確定）

`Tests\Underground\Feature\UndergroundRuntimeTest::test_trial_start_and_fight_reject_borrowed_members_before_runtime_execution`

- `product/tests/Underground/Feature/UndergroundRuntimeTest.php:1707`
- expected HTTP **422** / actual **409**
- job `106681601444`（PHPUnit 16/16）。標準fixture出力は87 tests / 857 assertions / 1 failure。
- まず新しい受付契約と既存テストの前提を切り分ける。ログだけでfixture不備／runtime不具合と断定しない。期待値を409へ変えるだけ、受付を迂回するだけ、Fullの無条件再実行はしない。

## 1. 4.4.0の実装進捗

| 区分 | 現在地 |
|---|---|
| A Agent/CI refine | 提案のみ。AGENTS全体の再構築・新しいCI分類は未採用 |
| B ロック隔離 | #158 merge済み。`8060bb0572d2de737c4b9516e3b2c9c22b55e835` |
| C1 同一起動retry | #159 merge済み。`62b2bc4075560f27ae30c89659524954d7ba3d09` |
| C2 後続cronでretry | **Owner判断で保留**。時刻とTurn番号の遅延・追い付き方針を未決のまま実装しない |
| D1 日誌rollup/verified境界 | #160 merge済み。release HEADは`b2997b6d00af934159e959bc6f6748018e17a0ec` |
| D2〜D4 恒久化/24h受付/手動purge | **#161に実装提出済み、CI修正・独立レビュー未完、未マージ** |
| E 管理 | 未実装。最新の対象選択・競売即決・公開重大ニュース方針はアイデア帳 |
| F 地上追加 | 未実装。わく/どきガチャ・三紋章・個人記念碑 |
| G 共鳴結晶/強化/竜武器 | 未実装。竜限定でない独立1枠、アクセ級に制限せずILを持つ |
| H 異世界固定PT | 未実装。バハムル、段階報酬、巨大数字5→1と1で大予告という最新案 |

これは追加PR数を指定する表ではない。D2/D3は各`[skip ci]`、D4完成時だけ通常CI、全体で一つのPRというOwner方針で既に提出された。

## 2. 必要時だけ読む資料

- [実装・運用handoff](development-history-and-current-handoff.md)：B/C1/D1の証拠、PR #161の保存/受付/削除の境界、運用残件。
- [9月22日の会話記録](conversation-2026-09-22.md)：CI過負荷を避ける進め方、Dの判断、企画の訂正経緯。
- [未実装アイデア帳](../plans/unimplemented-ideas.md)：**この会話末尾までのE/F/G/HのOwner指定**。数値試案と未決事項を区別。
- [D2〜D4実装メモ](../releases/4.4.0-receipt-durable-dependencies.md)：実装担当の説明。全体レビューの代わりにはしない。
- [D1実装メモ](../releases/4.4.0-receipt-rollup-d1.md)、[C1実装メモ](../releases/4.4.0-turn-resilience-c1.md)、[ロック正本](../architecture/secretary-lock-boundaries.md)。
- [初期計画](../releases/4.4.0-plan.md)・[30日blueprint](../releases/4.4.0-receipt-compaction-blueprint.md)は設計時点資料。「アプリ未着手」「受付未決」等は本書と最新Owner判断で更新済み。

E/F/G/Hの初期blueprint・HTMLは別branch `codex/4.4.0-content-blueprints`（最後に記録したHEAD `35e82a19cc94138400a9900633ce55dfc70f74bc`）。後続の名称・管理・カウントダウン・結晶ILの判断は今回のアイデア帳を優先する。別branchの全ファイルを今回mergeしたわけではない。

## 3. 運用と権限

GitHub→Forgejoはmain・一階層`release/*`・tagの片方向同期。#160 mergeの[mirror run 35692790817](https://github.com/Mamiki765/hakoniwa-world/actions/runs/35692790817)は今回**SUCCESS確認**。同期先へ直接接続して別途SHA照合はしていない。`codex/*`と別企画branchは自動同期対象外。小作業branchを`release/*`と名付けない。

`[skip ci]`はpush由来のmirrorもスキップし得る。タグ/branchがあるだけでバックアップ完了とせず、対象refの実行結果を確認する。自動同期と本番deployは別物。

4.4.0のmain merge・deploy・本番migration・purge・cron登録は未実施。Dの忘却防止はread-only wrapperとstatusが提出された段階で、定期実行や管理画面表示が稼働済みではない。

既存資産/進行を守る。User→Secretary→UndergroundProfileは島破棄・再作成でも保持する。未知を0にせず、古い互換性・月別統計・無限UUID台帳を勝手に復活させない。20人規模の個人運営で、無意味なPR分割とFull反復を避ける。

今回の許可はhandoff関連文書の更新・commitと引継ぎ資料生成。**#161のmerge許可ではない。** reviewやCIの結論を先取りせず、runtime変更/merge/deploy/本番操作は次のOwner指示と現在の状態に基づく。
