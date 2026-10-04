# 文書の入口

通常の再開で読むのは現在地と、今回の作業に必要な文書だけです。過去のrelease順に全docsを読む必要はありません。

## 再開と実装

| 目的 | 入口 |
|---|---|
| 4.10.0の採用PR・統合状況・検証・残る判断 | [4.10.0 release](../product/docs/releases/4.10.0.md) |
| 4.11.0 電力・ピザの仕様表・決算・移行・画像の残件 | [4.11.0 power/pizza](../product/docs/releases/4.11.0-power-pizza.md) |
| 4.11.1 資源予測の簡素化・ピザ金銭維持費削除・秘書条件表示 | [4.11.1 power/secretary](../product/docs/releases/4.11.1-power-secretary.md) |
| 現在のmain、完了release、直近TODO、回答済み・見送り | [current-status](../product/docs/handoffs/current-status.md) |
| 取り違えを防ぐ現行contract | [current-contracts](../product/docs/handoffs/current-contracts.md) |
| IL200超の装備計算・研磨 | [地底装備の倍率とrating](../product/docs/architecture/underground-equipment-scaling.md)、[プレイヤー向け装備手引き](../product/docs/manual/equipment.md) |
| 採用済みの未完作業と会話の構想 | [Owner判断・構想](../product/docs/plans/post-4.4.0-decisions-and-ideas.md) |
| 地上アイテムの合成・育成・圧縮 | [地上アイテム拡張](../product/docs/plans/surface-item-expansion.md) |
| 地上の配置・電力・上位産業・属性魔力 | [地上発展拡張](../product/docs/plans/surface-development-expansion.md) |
| T01〜T12の元の範囲・途中記録 | [4.4.1予定表](../product/docs/plans/4.4.1-todo.md)、[実装・検証記録](../product/docs/releases/4.4.1-chat-implementation.md)。古いcheckboxを現在の未実装判定に使わない |
| 本番の観測、証拠の限界、運用残件 | [deployment status](../product/docs/operations/current-deployment.md) |
| v27基準のfresh構築・既存DB採用・撤去した互換性 | [4.9.1 baseline consolidation](../product/docs/plans/4.9.1-v27-baseline-consolidation.md)、[本番構造差の修正](../product/docs/plans/4.9.2-baseline-structure-fix.md) |
| 実装前の設計gate | [open-questions](open-questions.md)。全Open/Deferredを現在のTODOへ昇格させない |
| 文書整理の進捗と未分類範囲 | [documentation-inventory](documentation-inventory.md) |

## 対象ごとの現行資料

- Rulesetを変更する場合は[authoring](../product/docs/architecture/ruleset-authoring.md)と実際のconfig・validator・consumerを確認します。過去版の調整値を永久不変の契約にしません。
- 地上と地下のlockは[Secretary lock boundaries](../product/docs/architecture/secretary-lock-boundaries.md)、管理操作は[管理運用](../product/docs/operations/4.4.0-admin-operations.md)を入口にします。Git merge、checkout、稼働image、production DBの適用状態は別の証拠です。
- ローカル環境は[local development](operations/local-development.md)と[Compose](operations/docker-compose.md)、データ保護は[backup/restore](../product/docs/operations/database-backup-and-restore.md)を確認します。
- プレイヤー向けの説明は[地底](../product/docs/manual/underground.md)・[装備](../product/docs/manual/equipment.md)など`product/docs/manual/`を使います。開発経緯や提案書をゲーム内の説明へ流用しません。
- T09に着手するときだけ[E監査原本](../product/docs/plans/reference/e-test-and-constraint-audit.md)を読みます。これは未処理候補を含む調査原本で、全候補の未修正や採用を宣言するものではありません。9/28の全suite固定値一覧化依頼の完了成果物とも同一視しません。

対象のarchitecture・accepted ADR・operationsは実装済みでも現役文書です。ここに列挙しなかった文書を一律に無効扱いせず、必要な範囲のcodeと設計gateから確認してください。

## 検索とarchive

通常の検索はcode・schema・testsと上記の現行／予定資料へ絞ります。`archive/`配下はOwnerの明示指示なしに閲覧・検索・一括読込せず、旧リンクも自動追跡しません。旧統合handoffやMVP索引を必読へ戻さないでください。

通常文書の分類と過去本文の移動結果は[文書一覧](documentation-inventory.md)にまとめました。将来案や古いarchitectureの記述は、日付やファイル名だけで現行仕様と扱わず、対象のcodeとOwner判断に照らして確認します。
