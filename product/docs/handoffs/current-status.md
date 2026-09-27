# 現在地：4.4.0公開後 / 4.4.1開発中

更新：2026-09-27。Ownerの「release/4.4.1を作り、T項目を進める」依頼による更新。GitHubの確認、Owner提示ログ、静止画の表示、未確認を区別する。

## 開発の固定点

- 開発正本：`Mamiki765/hakoniwa-world` / GitHub。
- 4.4.0のmain：`d4bf02e5c46fc5fe69d25ea8c14e61a3474a0caa`。PR #167はmerge済み。
- 作業branch：Ownerの明示指示で上記mainから`release/4.4.1`を作成した。次に再開するときはbranchの実HEADと[実装・検証記録](../releases/4.4.1-chat-implementation.md)を照合する。
- この初回変更ではapplication_versionは4.4.0、Rulesetはv27のまま。release完了・version更新・本番反映を先取りしていない。

## Productionの最後の証拠

2026-09-27 06:31 JSTにOwnerが提示した静止画では、**ver 4.4.0 / T628 / 正常**、最終更新9/27 06:00、次回予定08:00、24島、総人口10,761,185人と表示されている。残り時間の表示は01:28:39。静止画だけでは、カウントダウンの進行、各Turnの実行元、手動ボタンの動作、最新DB・healthを独立確認できない。

2026-09-23のOwner提示ログでは、4.4.0切替、統合migration batch43、Ruleset v27 / DB ID42、T588のcron完走（attempts1）、予定起点設定が完了した。今回DBへ再照会して得た値ではない。詳細と未復旧のSecretary診断は[deployment status](../operations/current-deployment.md)を参照。

4.4.0適用・T588・予定起点設定をやり直さない。30日receipt purgeは運用開始未確認・未実施扱いで、T04等の保全とOwnerの別承認が先。1時間の詳細ログ保持とは別。

## 今回の実装と残件

[4.4.1 TODO](../plans/4.4.1-todo.md)を作業一覧、[実装・検証記録](../releases/4.4.1-chat-implementation.md)を今回の成果とCodexへの入口にする。T02の記録、T03の権限対策、T06の手引き修正、T09の一部、T12の初回整理を実施した。T01・T04・T05・T07・T08・T10・T11とT09/T12の残りを完了扱いしない。

トロフィーの名称・画像、Ruleset簡素化の最終境界などは未決のまま。全体独立レビューはOwnerの最新指示で余力があれば行う別枠であり、この実装着手を止める必須gateではない。

## 読む範囲と権限

現行仕様は[current-contracts](current-contracts.md)、未決の構想は[予定側](../plans/post-4.4.0-decisions-and-ideas.md)、対象設計への入口は[docs](../../../docs/README.md)。旧統合handoff・会話記録・MVP説明の本文はarchiveへ分離し、通常の読込対象から外した。T12はrepo全体を対象とし、今回の一部移動だけでは完了しない。

mainへの直接更新、merge、production deploy、DB変更、purge、cron登録、補填は今回実行していない。GitHubのbranch更新とForgejo同期、本番反映は区別する。handoffの再編集はOwnerの明示指示に従う。
