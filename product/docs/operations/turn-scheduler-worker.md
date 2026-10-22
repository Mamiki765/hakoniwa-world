# ホスト cron と永続ターン実行枠（5.8.0）

5.8.0 は通常更新と障害補填を別のホストcron・commandで起動する。通常はJST偶数時00分の既存登録を維持し、補填だけJST奇数時00分に単発起動する。毎分poll、常駐worker、Laravel scheduler、専用scheduler containerは追加しない。このファイル名は初期設計時の名称を残しているが、`hakoniwa:turn:work` は提供しない。本書は導入手順であり、本番操作の許可ではない。

## 起動と空振り

既存の `product/docker/cron/run-turn.sh` は `php artisan hakoniwa:turn:run --source=cron --world=shared-world` を呼ぶ。通常commandはJST偶数時の通常枠だけを扱い、補填予約や未補填数に依存せず通常の進行を維持する。追加枠を実行しない。通常12ターン=箱庭歴1年=実時間1日を保つ。

新しい `product/docker/cron/run-compensation-turn.sh` は `php artisan hakoniwa:turn:compensate --world=shared-world` を呼ぶ。これは予約した障害補填の追加枠だけを扱い、通常枠を実行しない。補填側の起動は1日12回、通常側と合わせて24回で、毎分1,440回の起動には変更しない。実際のpath、実行者、cronのtimezone対応、ログ管理は導入時に確認する。

次はcronが `CRON_TZ` を扱える環境でのJST指定例。通常行は既存登録との照合用であり、二重登録しない。`CRON_TZ` 非対応の環境ではホストtimezoneに合わせて時刻を変換する。今回は登録も既存登録の変更も行わない。

```cron
CRON_TZ=Asia/Tokyo
0 0-22/2 * * * /usr/bin/flock -n /run/lock/hakoniwa-shared-world-turn.lock /usr/bin/env HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world HAKONIWA_WORLD_KEY=shared-world /opt/hakoniwa-world/product/docker/cron/run-turn.sh >> /var/log/hakoniwa-turn.log 2>&1
0 1-23/2 * * * /usr/bin/flock -n /run/lock/hakoniwa-shared-world-turn.lock /usr/bin/env HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world HAKONIWA_WORLD_KEY=shared-world /opt/hakoniwa-world/product/docker/cron/run-compensation-turn.sh >> /var/log/hakoniwa-compensation-turn.log 2>&1
```

補填の受付は予約時刻の00分内（起動秒のずれ59秒まで）だけ。01分以降、偶数時、未予約、期間外、上限後、未補填ゼロならexit 0と `No eligible compensation slot. No game state changed.` で終了する。TurnRun、実行枠、World、補填カーソルのINSERT/UPDATEを行わない。必要な読取りやWorld lockの取得/解放、ホストログへの出力は発生し得る。通常側の空振りは `No eligible turn slot. No game state changed.` を返す。障害や未解決TurnRunは空振り成功と分けて扱う。

どちらのcommandもWorld lockと `(world_id, scheduled_at)` 一意制約を共用し、手動更新との二重決算も防ぐ。`flock` は同一ホストの重複起動を省く補助である。1回の呼出しは最大1ターンだけ実行し、shellにretry loopや過去枠のcatch-upを置かない。

## 予約枠と境界

時間加速は大規模障害の補填用途に限り、通常のお祭り用途には使わない。開始・終了・追加回数（1〜500）を指定し、`[開始, 終了)` に入る先頭N個のJST奇数時00分を保存する。補填側だけの間隔は120分固定で、追加枠のある間は通常枠と合わせて1時間ごとの予定になる。不足する期間はpreviewを409で拒否する。時刻は分単位、期間は最大31日、時間加速は単発・未終了1予約に限定する。

追加枠の `grace_seconds` は59固定で、予定の00分を超えて受け付けない。例えば09:00:59は候補、09:01:00は候補外。開催終了時刻は含まない。古い失効枠へ戻らず、上限後や未補填解消後は残期間があっても追加しない。回数は成功保証ではなく上限であり、停止・失敗・手動補填があれば実際の成功数は減る。通常側の既存の受付・失敗処理は補填用59秒へ変更しない。

例（テスト用日時・本番予約ではない）: 日曜09:00〜23:00、追加7回 → 09:00 / 11:00 / 13:00 / 15:00 / 17:00 / 19:00 / 21:00。短い18:00〜23:00には2枠しかなく、7回は入らない。ゴールデンタイムだけに7回を収める案は固定奇数時仕様と両立せず、実予約前に時間帯または回数の判断を要する。

手動更新と cron は同じ World lock・実行枠・未補填カーソルを使う。同じ枠の完了記録、カーソル更新、ゲーム決算、TurnRun 完了は同一 transaction。pending/running/failed/blocked は次の cron から自動再試行しない。既存の明示的な管理 retry と、同一起動内の限定 DB 競合 retry だけを維持する。maintenance 中は新規実行しない。

## 導入時の確認と未決事項

### 旧毎分案の空振り測定（参考履歴）

以下は廃止した毎分poll・可変間隔案の値で、新しい `hakoniwa:turn:compensate` は未計測。2026-10-22、Windowsの隔離checkout / PHP 8.5.12 CLI、各回新規PHPプロセスでLaravel Console Kernelを起動し、旧Artisan経路を呼び出した。SQLは `Tests\Support\NoOpTurnDatabase` の読取りfixtureへ置換し、PDO接続・未知のSQL・永続書込みは失敗させた。World advisory lockはfixtureで置換した。`.env` は読み込まない。各条件5回、ソフトウェアの `hrtime` / `getrusage` / `memory_get_peak_usage(true)` による測定値:

| 条件（いずれも空振り） | 経過時間の範囲 / 中央値 | CPU時間の範囲 / 中央値 | PHP peak | SELECT数 |
| --- | --- | --- | --- | --- |
| 予約なし・奇数時 | 618.81–666.68 / 645.87 ms | 468.75–515.63 / 500.00 ms | 42 MiB | 5 |
| 30分間隔予約あり・前枠 grace 後 | 595.24–676.78 / 633.02 ms | 453.13–515.63 / 515.63 ms | 42 MiB | 5 |

測定はPHP内の計時開始から旧コマンド完了まで。OSのプロセス生成、Docker exec、DB接続・通信・実ロック、ホスト負荷を含まない。旧経路の参考値から新commandや本番の所要時間・OCPU使用率・最大同時数を断言しない。新commandの測定結果は条件と限界を添えて別途記録する。

Ownerが別途許可した導入時に停止・未解決TurnRunを確認し、既存runbookに沿ってmigrationと補填側cronの追加を行う。通常の偶数時cronは維持する。migrationは旧 `origin + current_turn * 2h` を未補填カーソルへ一度だけ転記する。過去のターンや起点を再解釈せず、以後の通常予定はcurrent_turnから計算しない。新規Worldは初期化処理または既存schedule-initでカーソルを初期化する。今回の作業では本番cron登録・DB変更・実予約・マージを行わない。

長期停止では歴史的な未補填数が残る。自動的なカーソルの現在への移動、失効した追加枠の移動・再実行、無制限 catch-up は追加していない。次のいずれを採るかは Owner 判断を要する。

- 既存カーソルを維持し、明示した上限だけを新規予約で補填する。現在の実装はこちら。
- 再開時点の通常カレンダーへ基準を変更し、旧遅延分を別途補填対象として記録する。変更前後の数と監査の設計が必要。
- 開催中の取り逃しを残り期間へ再配置する、または新規予約で補填する。回数保証・通常枠との競合・管理者の再確認が必要で、現実装は自動再配置しない。

宝物庫そのものの XP を狩場ブースト対象に含めるかも未決。現実装は含めない。実開催日時、公開再開、本番 migration/cron 登録は別判断とする。
