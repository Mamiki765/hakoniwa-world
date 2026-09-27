# Productionの観測と残件

4.4.1開始時の運用入口。これは本番操作の実行指示ではない。証拠はOwnerが提示した2026-09-23のログと9/27の画面であり、今回Chatからproductionへ再照会・再適用していない。

## 最後に確認されたbaseline

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

9/27 06:31 JSTのOwner静止画はver4.4.0 / T628 / 正常を表示。これは上表のDB・health・cron結果を9/27にも再確認した証拠ではない。運用を再開するときは最新の未解決Turn等を別途確認する。

## 未復旧：Secretary production diagnostic（T01）

4.4.0の初回migrationは旧Secretary列への診断依存で失敗した。その後、診断の`hakoniwa_diagnostic.secretaries`と`fetch_secretary(bigint)`を限定撤去し、migrationの再実行で上表の適用結果に至った。ゲーム本体の`public.secretaries`を削除したのではなく、CASCADEも使用していない。対象2objectは最後の照会では未作成、他11viewは残存した。

`hakoniwa_diag_owner`はNOLOGIN・非superuser、exporterはLOGIN・非superuser。新tableの必要3列へのSELECTは未付与だった。通常のGitレビュー用MCPが読めても、Secretary診断snapshot生成が復旧したことにはならない。

`/home/ubuntu/apps/hakoniwa-mcp-exporter`の実際の正本・Git管理状況を確認し、view/function・必要な権限・PUBLIC権限剥奪を正本で再現する。JOIN方式と欠落rowの扱いは既存契約を調べる。実装とproduction適用は別承認で、DBへの手書きSQLだけで完了にしない。

## stories JSON（T03）

本番の応急対処は9/23に完了。対象dir700/JSON600を755/644へ直し、再build後にwww-dataでintro/lounge/otherworldの3件を読めることをOwnerが確認した。権限が狭まった原因は未特定であり、rootのcacheで隠れていたという説明は仮説。

4.4.1 branchでは`product/Dockerfile`で対象dirと直下JSONだけの権限を保証し、www-dataによる読取をbuild時に検査する。空globも失敗させる。Chatでの確認はLinux fixtureと同じPHP検査式までで、Docker image全体のbuild・本番反映は未実施。

## 記録上の確認限界

失敗直後のrollbackと整合する照会結果はTODOに挙がっているが、今回読んだ現行資料にはその逐語ログがない。最終適用結果から失敗直後の全table・全データ状態を推測せず、その詳細の追補はT02に残す。

## 繰り返さない操作

4.4.0の17→1統合、適用、T588の進行、予定起点設定を引き継ぎのために繰り返さない。古い4.3.2の台帳を現在の適用状態に戻さない。30日receipt purge・定期cronの開始はT04等の恒久保全とOwnerの別承認が先で、1時間の詳細ログ削除と混同しない。
