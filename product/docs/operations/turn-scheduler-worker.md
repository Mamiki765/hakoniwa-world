# ホスト cron と永続ターン実行枠（5.8.0）

5.8.0 の起動機構は既存ホスト cron の定期呼出しとする。常駐 worker、Laravel scheduler、専用 scheduler container は追加しない。このファイル名は初期設計時の名称を残しているが、`hakoniwa:turn:work` は提供しない。本書は導入手順であり、本番操作の許可ではない。

## 起動と空振り

既存の `product/docker/cron/run-turn.sh` は薄い起動契機のまま、`php artisan hakoniwa:turn:run --source=cron --world=shared-world` を呼ぶ。日時の正否はアプリケーションが UTC の永続データと JST の通常枠から判定する。導入時には偶数時だけの登録を毎分の登録へ置き換える（このタスクでは登録しない）。実際の path、実行者、ログ管理を確認する。

```cron
* * * * * /usr/bin/flock -n /run/lock/hakoniwa-shared-world-turn.lock /usr/bin/env HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world HAKONIWA_WORLD_KEY=shared-world /opt/hakoniwa-world/product/docker/cron/run-turn.sh >> /var/log/hakoniwa-turn.log 2>&1
```

毎分なら1日1,440起動。通常ターンは JST 偶数時00分の12枠だけで、開催中は明示予約した追加枠のみ増える。空振りは exit 0 と `No eligible turn slot. No game state changed.` を返す。World・Ruleset・未解決 TurnRun・開催情報を読み、World advisory lock の取得/解放は行うが、TurnRun、実行枠、World、補填カーソルの INSERT/UPDATE は行わない。ホストログへの出力は発生する。

旧偶数時 cron と一時共存しても、World lock と `(world_id, scheduled_at)` 一意制約が二重決算を防ぐ。`flock` は同一ホストの重複起動を省く補助である。遅延・混雑時の1回の呼出しは最大1ターンだけ実行し、shell に retry loop を置かない。

## 予約枠と境界

通常の JST 偶数時00分は変更しない。時間加速は開始・終了・追加回数（1〜500）・間隔（5〜120分）を指定する。開始を含み終了を含まない。開始を基準とする間隔列から通常枠を除き、最初の指定回数を保存する。不足する期間は予約前に拒否する。時刻は分単位、期間は最大31日、時間加速は単発・未終了1予約に限定する。

追加枠の grace は設定値（既定15分）、間隔未満、次の通常枠より前、開催終了より前のすべてで制限する。最新の due 枠だけを候補にし、古い失効枠へ戻らない。補填済みなら残る追加枠を実行しない。回数は実行上限であり、停止・失敗・手動補填があれば実際の成功数は減る。

例（テスト用日時・本番予約ではない）: 日曜18:00〜23:00、追加7回、30分間隔 → 18:30 / 19:00 / 19:30 / 20:30 / 21:00 / 21:30 / 22:30。通常18:00 / 20:00 / 22:00は別枠で維持する。

手動更新と cron は同じ World lock・実行枠・未補填カーソルを使う。同じ枠の完了記録、カーソル更新、ゲーム決算、TurnRun 完了は同一 transaction。pending/running/failed/blocked は次の cron から自動再試行しない。既存の明示的な管理 retry と、同一起動内の限定 DB 競合 retry だけを維持する。maintenance 中は新規実行しない。

## 導入時の確認と未決事項

### 隔離プロセスでの空振り測定

2026-10-22、Windows の隔離 checkout / PHP 8.5.12 CLI、各回新規 PHP プロセスで Laravel Console Kernel を起動し、実際の Artisan コマンドを呼び出した。SQL は `Tests\Support\NoOpTurnDatabase` の読取り fixture へ置換し、PDO 接続・未知の SQL・永続書込みは失敗させた。World advisory lock は fixture で置換した。`.env` は読み込まない。各条件5回、ソフトウェアの `hrtime` / `getrusage` / `memory_get_peak_usage(true)` による測定値:

| 条件（いずれも空振り） | 経過時間の範囲 / 中央値 | CPU時間の範囲 / 中央値 | PHP peak | SELECT数 |
| --- | --- | --- | --- | --- |
| 予約なし・奇数時 | 618.81–666.68 / 645.87 ms | 468.75–515.63 / 500.00 ms | 42 MiB | 5 |
| 30分間隔予約あり・前枠 grace 後 | 595.24–676.78 / 633.02 ms | 453.13–515.63 / 515.63 ms | 42 MiB | 5 |

測定は PHP 内の計時開始からコマンド完了まで。OS のプロセス生成、Docker exec、DB接続・通信・実ロック、ホスト負荷を含まない。これはローカルの空振り経路の測定であり、本番の所要時間・OCPU使用率・最大同時数を保証しない。本番相当の環境で起動回数、CPU、DB接続、ロック待ち、ログ量を別途測る判断材料とする。

Owner が別途許可した導入時に停止・未解決 TurnRun を確認し、既存 runbook に沿って migration と cron の置換を行う。migration は旧 `origin + current_turn * 2h` を未補填カーソルへ一度だけ転記する。過去のターンや起点を再解釈せず、以後の通常予定は current_turn から計算しない。新規 World は初期化処理または既存 schedule-init でカーソルを初期化する。

長期停止では歴史的な未補填数が残る。自動的なカーソルの現在への移動、失効した追加枠の移動・再実行、無制限 catch-up は追加していない。次のいずれを採るかは Owner 判断を要する。

- 既存カーソルを維持し、明示した上限だけを新規予約で補填する。現在の実装はこちら。
- 再開時点の通常カレンダーへ基準を変更し、旧遅延分を別途補填対象として記録する。変更前後の数と監査の設計が必要。
- 開催中の取り逃しを残り期間へ再配置する、または新規予約で補填する。回数保証・通常枠との競合・管理者の再確認が必要で、現実装は自動再配置しない。

宝物庫そのものの XP を狩場ブースト対象に含めるかも未決。現実装は含めない。実開催日時、公開再開、本番 migration/cron 登録は別判断とする。
