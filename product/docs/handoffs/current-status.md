# hakoniwa-world 現在地：4.3.2 独立レビュー後

更新：2026-09-21 JST。Owner指定の2026-09-19差分MD、その後のレビュー・Owner指示、今回のGitHub読取を統合。

このファイルは再開用の短い入口。詳細と根拠は [統合handoff](development-history-and-current-handoff.md) を必要な節だけ読む。旧本文の4.2.0未merge・Forgejo正本・未実施状態は現在地ではない。

## 1. 最後に確認したref

| 対象 | 確認した状態 |
|---|---|
| 開発の入口 | GitHub `Mamiki765/hakoniwa-world`。凍結中を現在の前提にしない |
| main / PR #156 base | `a45c313979e34ef40c4b93819423eecc2fdf9760`、4.3.1 |
| PR #156 | `release/4.3.2 → main`、open・未merge |
| 最後の実装レビューHEAD | `66bfa5b4ab42ad3303f7667156c2b5ea4458550f`。この文書更新commitより前のcode固定点 |
| 既存CI | 上記実装HEADのQuality run `35398745823`（#562）は成功確認済み。文書commit後のHEADへ証拠を付け替えない |
| World同期PR #157 | open・未merge、HEAD `6b4cbcee2aefe2964f6bca1477c11c32ce526232` |
| MCP同期GitHub PR #1 | open・未merge、HEAD `90734d7d1a786f9de5574d9a89fa4f6f1853fefc`。MCP本体改修とは別 |
| production / PC作業状態 | 今回は再観測していない。GitHubのSHAを稼働image・DB・stashの証明にしない |

PR URL: <https://github.com/Mamiki765/hakoniwa-world/pull/156>

## 2. R1〜R10の状態

- R1〜R5は`66bfa5b`で修正確認済み。各review threadへ確認返信し、resolvedにした。初回HEAD `ad21ffe24cd4bebb5404153133811ab9bf8847ce`の未修正状態へ戻さない。
- R6〜R10は追加の横断レビューで見つかった設定変更時の不整合。未修正だが、現行設定のリリースを一律に止める条件とする判定はOwner方針を受けて撤回した。未修正を修正済みにはしていない。
- 最新Owner依頼は、修正済みR1〜R5も含めR1〜R10を再整理する実装プロンプトを作ること。**migrationを1世代許可**。本番適用・mergeの許可ではない。
- R5はDBのSTP総量式5/6固定を維持する以外に、今回許可されたmigrationで整理する選択肢がある。具体案と実装済み状態は分ける。
- SQL migration本数、Surface Ruleset世代、地下combat/equipment identityは別。制約整理だけで自動的に全部を更新しない。許可は今回の移行単位の上限であり、過去全migrationの一括retire承認ではない。

## 3. Ownerの開発・レビュー方針

約20人の個人運営ゲーム。Ownerは30日以内の復元に備えたバックアップを用意していると説明している。過去再現は必要ならGitを使い、current treeへ全世代の互換コード・移行テストを永久保持しない。

具体的な通常操作の実害、資産・所有・進行、二重決算、全体のTurn停止を優先する。横断して読むことと、全経路にテストを新設することは別。将来設定の注意・局所不具合・release停止条件を区別する。

必要なfocused確認が通ったら終了する。同じ故障のmatrix、local Fullと同じCI Fullの重複、修正ごとのFull反復を要求しない。既存Qualityは文書だけのPR更新でも起動し得る設定であり、AGENTSの文章だけでは自動起動を減らせない。今回workflowは変更していない。

## 4. 別件として残すもの

MCP改善（Forgejo MCP #4の子プロセス終了漏れMCP-F1）、30日battle receipt整理、同期PR、N19のSafari地図消失、討伐・レイド等。今回のR1〜R10へ自動的に混ぜない。

4.2.1の約2.63GB論理整理は実行済み、4.3.0はOwner報告でmerge済み、4.3.1の320px重なりは修正確認済み。古い未実施記録から再実行しない。

AGENTSのゼロベース再構築はOwner精査用の提案段階。今回AGENTS・skills・モデル設定を変更していない。

## 5. 再開

対象PRのHEADを一度確認し、最後に読んだ実装HEADとの差分から進める。handoffはOwner／Web版ChatGPT管理で、Codexの通常作業ではread-only。通常のbranch作業の権限は現行AGENTSと最新の明示指示に従い、main直接変更・merge・deploy・本番DB操作・補填は勝手に行わない。
