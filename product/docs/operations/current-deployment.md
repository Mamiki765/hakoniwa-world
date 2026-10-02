# Productionの観測と残件

更新：2026-09-30。Owner提示の過去の操作ログと、読み取り専用MCPの観測を区別する。本書はproduction操作の実行指示ではない。

Owner承認の4.9.0／v27基準を初期構築へ一本化する変更のfresh／既存DB手順は
[baseline consolidation](../plans/4.9.1-v27-baseline-consolidation.md)に記載する。
そのPRのschema取得と検証は隔離した合成DBによるもので、下記の本番観測や適用証拠を更新するものではない。

## 最新の読み取り観測

MCP `production_status` の `generated_at=2026-09-30T03:10:14Z`（12:10:14 JST）、`stale=false`。

| 項目 | 取得値 |
|---|---|
| checkout SHA | `21357bb9f240650c28960b7b69ddf961b3fcaac0` |
| image ID | `sha256:784392e151186acbd2cddbc285e6562b87884aa7d8c98b2872373b6a7c3b0718` |
| application_version / deployed_sha | いずれもnull |
| deployment.status | unknown |
| DB / Web | healthy / healthy |
| World | shared-world |
| current_turn / unresolved_turn | 667 / false |
| Ruleset / DB ID | hakoniwa-2s-plus-v27 / 42 |
| migrations | pending_count=0、status=current |

同じcheckoutのrepository設定は4.6.1だが、**checkoutは稼働imageのversionの証拠ではない**。このsnapshotだけで「production versionも4.6.1」「エクスカリバー移行の対象全件が正しく変換済み」まで断定しない。個別migration記録・対象データの再照合は行っていない。

途中の12:00:01 JST観測はT666 / unresolved_turn=trueだったが、上記の後続観測ではT667 / falseへ進んでいる。前者だけをdeadlock・failed・停止と判定しない。今回retry・deploy・DB更新は実行していない。

9/30未明のINQ-000013用 `production_diagnostic` は `turn_audit` / `item_sale` とも `snapshot_unavailable_or_invalid` で個別データを取得できなかった。これは対象snapshotが読めなかったという事実であり、診断全機能が故障している証拠でも、T01のSecretary診断を再検査した結果でもない。

## Ownerが提示した過去の適用記録

| 対象 | 2026-09-23のOwnerログ |
|---|---|
| repo / Compose | `/home/ubuntu/apps/hakoniwa-world` / `/home/ubuntu/apps` |
| checkout | `d4bf02e5c46fc5fe69d25ea8c14e61a3474a0caa`、当時のgit statusは空 |
| migration | `2026_09_23_030000_install_4_4_0`、batch43、Ran |
| schema | `secretary_surface_states`あり。旧`public.secretaries.monster_experience/equipment_version`は削除済み |
| World | shared-world、Ruleset v27 / DB ID42 |
| 切替後 | web/postgres healthy、/up成功、optimize:clear・config:cache成功 |
| cron | T588 completed / attempts1、22:00:01〜22:00:28 JST |
| preflight | release_preflight=ok、next_target_turn=589 |
| 予定起点 | Turn1=`2026-08-06T00:00:00+09:00`、preview→apply成功。起点設定ではTurnを実行していない |

9/27 06:31 JSTのOwner静止画はver4.4.0 / T628 / 正常を表示した。この過去の記録を現在の稼働版へ戻さない。運用再開時は最新の未解決Turn等を別途確認する。

## 復旧証拠未確認：Secretary production diagnostic（T01）

4.4.0の初回migrationは旧Secretary列への診断依存で失敗した。その後、診断の`hakoniwa_diagnostic.secretaries`と`fetch_secretary(bigint)`を限定撤去し、migrationの再実行で上表の適用結果に至った。ゲーム本体の`public.secretaries`を削除したのではなく、CASCADEも使用していない。当時の最後の照会で対象2objectは未作成、他11viewは残存した。

`hakoniwa_diag_owner`はNOLOGIN・非superuser、exporterはLOGIN・非superuser。新tableの必要3列へのSELECTは当時未付与だった。通常のGitレビュー用MCPやproduction_statusが読めても、Secretary診断snapshot生成の復旧を証明しない。今回このview/function・権限を再照会していないため、現在も未作成と断定しない。

`/home/ubuntu/apps/hakoniwa-mcp-exporter` の実際の正本・Git管理状況を確認し、view/function・必要権限・PUBLIC権限剥奪を再現できる形にする。管理先を `hakoniwa-mcp` repoへ移すのはAssistantが出した案で、Owner決定ではない。JOIN方式と欠落rowの扱いは既存契約を調べる。実装とproduction適用は別承認で、DBへの手書きSQLだけで完了にしない。

## stories JSON（T03）

本番の応急対処は9/23に完了。対象dir700/JSON600を755/644へ直し、再build後にwww-dataでintro/lounge/otherworldの3件を読めることをOwnerが確認した。権限が狭まった原因は未特定であり、rootのcacheで隠れていたという説明は仮説。

4.4.1では`product/Dockerfile`へ対象dirと直下JSONの権限保証、www-dataでの読取・空glob検査を追加したという実装記録がある。4.4.1自体はmainへ取り込み済み。当時のChat検証はLinux fixtureまでであり、その確認限界を「現在も修正コードが未実装」というTODOに戻さない。本更新で完成image内部の個別権限を再検査してはいない。

## 30日receipt purge（T11）

機能・保護実装と、本番cronの開始は別。現在の有効化状況はこのsnapshotでは分からない。初回日時・恒久事実・統計・pin・進行中処理の保護を確認し、Ownerの別承認で運用開始を判断する。詳細戦闘ログ1時間保持とは別で、文書更新を理由に削除しない。

## 記録上の確認限界（T02）

4.4.0初回失敗直後のrollbackと整合する逐語照会は、現在の資料では未確認。最終適用結果から直後の全table・全データ状態を推測しない。追補は原資料の参照条件を確認して行い、過去操作を再実行しない。

## 問い合わせの最新扱い

**INQ-000013：Ownerが「資金上限です」と回答済み。ひとまず対応終了。** 当時残高のDB照合は未実施だが、追加調査・返信・補填・売却警告UI・伐採仕様変更を自動的な未完作業へ加えない。

## 繰り返さない操作

4.4.0の17→1統合、適用、T588の進行、予定起点設定を引き継ぎのために繰り返さない。旧4.3.2台帳を現在の適用状態に戻さない。repositoryのmerge、同期、productionの観測を分け、どの操作も文書の記載だけで実行許可と解釈しない。
