# hakoniwa-world 現在地：4.3.2完了／4.4.0設計開始

更新：2026-09-21 JST。Ownerの明示依頼によるWeb版ChatGPTの更新。

## 最初に読むもの

このファイルは**実装・確認済み状態と次の作業の入口**。会話や未実装案を現在のgameplay仕様として扱わない。

- 実装・運用の引継ぎ：[実装handoff](development-history-and-current-handoff.md)
- 今回の経緯・訂正：[会話記録](conversation-2026-09-21.md)
- 未実装のOwner案・未決事項：[アイデア帳](../plans/unimplemented-ideas.md)
- 4.4.0の設計：[実装計画](../releases/4.4.0-plan.md)を入口に、[ロックの初期ソース地図](../releases/4.4.0-data-boundary-design.md)、[30日集約blueprint](../releases/4.4.0-receipt-compaction-blueprint.md)、[Agent refine候補](../plans/4.4.0-agent-refine-proposal.md)を必要時に読む。初期メモ§8の未指定事項は最新アイデア帳で更新済み。存在する設計書＝実装済みではない。

## 1. 確認済みの開発状態

| 対象 | 状態・根拠 |
|---|---|
| 開発正本 | GitHub `Mamiki765/hakoniwa-world`。GitHub凍結中という過去前提を復活させない |
| 4.3.2 | PR #156 merge済み。レビューHEAD `374ee3c49c6abf49870969ad755e9e45e9e69d1c`、merge `3f49819fb764b5ac2e9fc49f95f6795ded7754d2` |
| R1〜R10 | 修正確認済み。R5は許可されたmigrationでSTP総量5/6固定式を整理。旧ガード復元案へ戻さない |
| 最終CI | exact-head Quality `35571774926`成功を本会話で確認済み。今回、新しいFullを回していない |
| 同期 | World #157 / MCP GitHub #1はmerge済み。GitHub→Forgejo main/tagsの片方向。4.3.2 merge後のWorld mirror `35573726878`成功確認済み。tags実照合は未確認 |
| 本番 | 4.3.2のdeploy手順は渡したが、完了出力は本会話にない。merge、checkout、image、DBを同じ確認として扱わない |
| Turn560 | Owner出力で復旧確認。maintenance下のmanual retryがcompleted、current_turn=560、attempts=4、`artisan up`済み。追加の手動進行は不要 |
| 4.4.0 | 既存の初期設計commit `7e4b5831e1eb1bd974a7f704975a090fde7e7442`を保持。main文書更新`17501b8ba42c7b6450f14f82764996e75a728c1d`を`8ae0447068b9e34783382c8aa7112d7c23857150`で統合し、同branchに計画・集約blueprint・Agent候補を追加。アプリ実装は未開始 |

refsはこの文書更新前の固定点。作業開始時だけ対象branchのHEADを確認し、既存実装との差分から進める。

## 2. 今回の文書作業と次の実装候補

Owner指定の **mainのhandoff更新 → release/4.4.0を用意 → 4.4.0計画・blueprint** を文書作業として実施。既存release branchと初期設計は保持し、更新済みmainを通常の統合で取り込んだ。ゲームコード・migration適用・本番操作は行っていない。

最初の実装候補は地上／地下のロック隔離とTurn deadlock耐性。30日receipt整理は「○番まで集約済み」を保存し、集約検証後に削除する方式。相手SQL未取得のTurn560を特定APIの確定バグとしない。

その後の4.4.0候補は管理（問い合わせ以外の入口、配布、理由付き島整理）、地上アイテム・チケット消費・個人記念碑、地下の異世界PTバトル・別軸強化・G消費。各機能のOwner指定と未決パラメータはアイデア帳を参照する。

## 3. 作業の止めどころ

約20人の個人運営ゲーム。資産・所有・進行・二重決算・全体Turn停止の具体的影響を優先し、指摘数やテスト件数を目標にしない。必要なfocusedが通れば同じ確認を反復せず、repository-wide PHPUnitは同じ集合を走らせるexact-head CIへ委譲する。

P0/P1がなければ進め、P2は内容次第で後続hotfixという#156のOwner判断は、今後のPRすべてへの無条件merge許可ではない。main変更・merge・deploy・本番DB・配布はその時点の明示許可を確認する。今回許可されたmain変更はhandoff関連文書のみ。

MCP本体改修のMCP-F1、Safari N19は別件として残る。4.2.1の固定cutoff整理・過去の補填を未実施へ戻さない。AGENTS refineは計画候補で、現行AGENTSやPCのモデル設定を変更済みとはしない。
