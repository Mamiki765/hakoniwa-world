# 文書整理の進捗：4.4.1

2026-09-27。T12はrepo全体のdocs棚卸しであり、**未完**。旧一覧の「168文書」「3.0.0時点の分類」を現在の全件監査結果として使わない。

## 初回に分離した本文

起点は`d4bf02e5c46fc5fe69d25ea8c14e61a3474a0caa`。次の旧本文8件を同一blobのままarchiveへ移し、元pathには現行の入口または短い移動案内を置いた。入口を壊さず、通常の読込で旧仕様が展開されないようにする。古い名称のfileが残っていることは旧本文を維持した意味ではない。

| 元path | 本文の保管先（通常は読まない） |
|---|---|
| `README.md` | `docs/archive/pre-4.4.1/README.md` |
| `docs/README.md` | `docs/archive/pre-4.4.1/docs/README.md` |
| `docs/documentation-inventory.md` | `docs/archive/pre-4.4.1/docs/documentation-inventory.md` |
| `docs/architecture/mvp-implementation.md` | `docs/archive/pre-4.4.1/docs/architecture/mvp-implementation.md` |
| `product/docs/handoffs/current-status.md` | `docs/archive/pre-4.4.1/product/docs/handoffs/current-status.md` |
| `product/docs/handoffs/development-history-and-current-handoff.md` | `docs/archive/pre-4.4.1/product/docs/handoffs/development-history-and-current-handoff.md` |
| `product/docs/handoffs/conversation-2026-09-21.md` | `docs/archive/pre-4.4.1/product/docs/handoffs/conversation-2026-09-21.md` |
| `product/docs/handoffs/conversation-2026-09-22.md` | `docs/archive/pre-4.4.1/product/docs/handoffs/conversation-2026-09-22.md` |

旧root README・MVP記録にあった生産/Turn/地下等の未実装記述、旧handoffの#167未merge・本番4.3.2という現在地を通常入口から外した。現役の実行・所有契約は`product/docs/handoffs/current-contracts.md`、未完・未決は予定側へ残す。会話中の旧Relic30%等を採用仕様へ戻さない。

## 現役として残した範囲

architecture・accepted ADR・operations・プレイヤーmanualは、実装済みであることを理由に移動しない。新しい通常索引は`docs/README.md`。E監査原本は`product/docs/plans/reference/`へ保存し、未処理候補を消さない。

AGENTSの旧統合handoff必読を置換し、archiveの検索・読込・旧リンク追跡をOwnerの明示指示に限定した。release/*の新規作成もOwnerの明示指示が必要とした。今回、既存archive本文の閲覧はしていない。

## 続ける作業

`docs/`・`product/docs/`の残るrelease計画、checkpoint、レビュー、旧版別メモ、future-systems、索引を内容ごとに分類する。現在も必要な仕様と未完事項を先に抽出し、その後に履歴本文を移す。未分類文書をまとめて失効扱いしない。

今回の8件分離は全docsの棚卸し完了ではない。残る本文中の旧handoff必読・古いリンク、repository-wideな通常検索範囲、移動後リンクの横断確認はCodex側の続きに残る。archiveの内部リンクは原文保存のため当時のままで、読む許可がある場合は起点SHAと元pathで解釈する。現在の仕様への導線として自動追跡しない。
