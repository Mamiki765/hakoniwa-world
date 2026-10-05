# 文書の分類：4.4.1

2026-09-27。対象は通常検索に入る`docs/`・`product/docs/`のMarkdown全件。起点の非archive 217件を確認し、過去の開発本文125件を`docs/archive/through-4.4.0/`へ元の相対pathを保って移動した。通常側に残る37件の旧pathは既存リンク向けの短い案内であり、過去本文ではない。先行して移動済みの8件は`pre-4.4.1/`にあり、今回読み直していない。今回もarchiveの本文は閲覧・検索していない。

## 現行

| 対象 | 扱い |
|---|---|
| `product/docs/handoffs/current-status.md` | 作業引継ぎはprivate `peridot-notes`へ移行。同pathの短い入口で既存参照を維持。旧本文の固定原文・移行対応は外部管理庫のmanifestに保全 |
| `product/docs/handoffs/current-contracts.md` | 現役の所有・再送・保持・retry等の技術契約。本体同pathに残す |
| `docs/README.md`、`docs/open-questions.md`、本一覧 | 通常の入口と設計gate。過去の資料名が残る場合も現行仕様への自動昇格ではない |
| `docs/architecture/`、`product/docs/architecture/`の本文 | 現行設計・継続利用する技術境界。実装済みという理由だけでは移動しない。実際のcode・schemaと照合する |
| `docs/decisions/` | 採用済みADR。古い決定の現行適用範囲は後続決定とcodeで確認する |
| `docs/assets/`、`docs/reference-analysis/license-and-provenance.md`、`source-inventory.md` | 素材対応と出典・ライセンスの参照。古いgameplay値の正本ではない |
| `docs/operations/`の現行手順、`product/docs/operations/`の現行手順 | 開発、Compose、Turn、OAuth、同期、backup、4.4.0管理、本番観測。production操作は別許可 |
| `product/docs/manual/`、`items/current-item-catalog.md`、`community-guidelines.md` | プレイヤー向けと現行item資料 |
| `product/docs/releases/4.4.1-chat-implementation.md` | 今回のrelease作業・検証記録。履歴化時は再分類する |

旧pathの短い案内や`docs/architecture/mvp-implementation.md`、旧会話handoffは本文に含めない。`docs/architecture/underground-combat-laboratory.md`など長期更新文書は、その記述だけで4.4.1の実装仕様を確定せずcodeと照合する。

## 採用済み・未完

- `product/docs/plans/4.4.1-todo.md`：T01〜T12の作業と未完境界。実装済み項目はrelease記録とcodeで確認する。
- `product/docs/plans/reference/e-test-and-constraint-audit.md`：T09の原本。候補を現在も未修正と決めつけない。
- `product/docs/plans/4.4.1-ruleset-simplification-investigation.md`：T10の依存調査。最終構造は未決。
- `product/docs/plans/post-4.4.0-decisions-and-ideas.md`の「採用された変更方針」：残る作業だけに適用。実装済みになった箇所はこの分類から外す。

## 未決・将来の構想

- `docs/future-systems/`、`docs/operations/existing-server-context.md`：将来案。古い初回公開前提や実装済み項目を含み得るため、着手時に現行code・Owner判断を確認する。
- `product/docs/plans/post-4.4.0-decisions-and-ideas.md`の「構想・留保・別件」：採用済みの実装指示ではない。

## 過去の開発記録

今回移動した125本文は、旧版のrelease計画・レビュー・停止チェックポイント・テスト再設計記録、旧運用チェックリスト、旧roadmap・要件、第三者実装の解析、実装前の目標構成を含む。4.4.0 D/Cの現行保持・retry契約は`current-contracts.md`と現行code/運用へ残した。E/F/G/Hの古い混在計画は、未決の将来案を`post-4.4.0-decisions-and-ideas.md`に残したうえで移動した。

旧本文へは通常の索引からリンクせず、AgentはOwnerの明示指示なしにarchiveを閲覧・検索・旧リンク追跡しない。元pathの短い案内は既存の参照を壊さないためのもので、過去本文を読む許可ではない。Git履歴をこの制限の迂回に使わない。
