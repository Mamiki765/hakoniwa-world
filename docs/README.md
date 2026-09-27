# 文書の入口

通常の再開で読むのは現在地と、今回の作業に必要な文書だけです。過去のrelease順に全docsを読む必要はありません。

## 再開と実装

| 目的 | 入口 |
|---|---|
| 現在のbranch、productionの観測、確認限界 | [current-status](../product/docs/handoffs/current-status.md) |
| 取り違えを防ぐ現行contract | [current-contracts](../product/docs/handoffs/current-contracts.md) |
| 採用済みの未完作業・今回の実装結果 | [4.4.1 TODO](../product/docs/plans/4.4.1-todo.md)、[実装・検証記録](../product/docs/releases/4.4.1-chat-implementation.md) |
| 未決の構想と別枠の課題 | [Owner判断・構想](../product/docs/plans/post-4.4.0-decisions-and-ideas.md) |
| 実装前の設計gate | [open-questions](open-questions.md) |
| 文書整理の進捗と未分類範囲 | [documentation-inventory](documentation-inventory.md) |

## 対象ごとの現行資料

- Rulesetを変更する場合は[authoring](../product/docs/architecture/ruleset-authoring.md)と実際のconfig・validator・consumerを確認します。過去版の調整値を永久不変の契約にしません。
- 地上と地下のlockは[Secretary lock boundaries](../product/docs/architecture/secretary-lock-boundaries.md)、管理操作は[管理運用](../product/docs/operations/4.4.0-admin-operations.md)、本番の観測と未復旧事項は[deployment status](../product/docs/operations/current-deployment.md)を入口にします。
- ローカル環境は[local development](operations/local-development.md)と[Compose](operations/docker-compose.md)、データ保護は[backup/restore](../product/docs/operations/database-backup-and-restore.md)を確認します。
- プレイヤー向けの説明は[地底](../product/docs/manual/underground.md)・[装備](../product/docs/manual/equipment.md)など`product/docs/manual/`を使います。開発経緯や提案書をゲーム内の説明へ流用しません。
- T09に着手するときだけ[E監査原本](../product/docs/plans/reference/e-test-and-constraint-audit.md)を読みます。これは未処理候補を含む調査原本で、全候補の未修正や採用を宣言するものではありません。

対象のarchitecture・accepted ADR・operationsは実装済みでも現役文書です。ここに列挙しなかった文書を一律に無効扱いせず、必要な範囲のcodeと設計gateから確認してください。

## 検索とarchive

通常の検索はcode・schema・testsと上記の現行／予定資料へ絞ります。`archive/`配下はOwnerの明示指示なしに閲覧・検索・一括読込せず、旧リンクも自動追跡しません。旧統合handoffやMVP索引を必読へ戻さないでください。

文書の全棚卸しは進行中です。未分類のrelease計画・停止チェックポイント・レビュー記録には、現行仕様と過去の案が混在し得ます。日付やファイル名だけで採用・完了を判断せず、T12で抽出・分類してから移動します。
