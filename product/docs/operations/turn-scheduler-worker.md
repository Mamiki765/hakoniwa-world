# 常駐ターンスケジューラー

5.8.0 は cron を必要とせず `php artisan hakoniwa:turn:work --world=shared-world` で10秒ごとに永続 due 判定する。`--once` は1回確認して終了。外部 supervision で異常終了後に再起動する。これは起動手順の記録であり本番起動の許可ではない。

通常はJST偶数時00分、時間加速中は開催期間に含まれる奇数時00分にも追加する。起動の遅れは既存の grace（既定15分）以内のみ受け付け、過ぎた枠を連続 catch-up しない。既存 `hakoniwa:turn:run --source=cron` と共存可能。両者と手動管理操作は同じ World advisory lock、`world_id + scheduled_at` 一意枠、未補填カーソルを使う。枠完了・カーソル更新・ゲーム決算・TurnRun 完了は同一 transaction。failed/blocked/pending/running は後続起動で自動再実行しない。既存の明示的な手動retryと同一起動内の限定DB競合retryを維持する。

旧 cron の even-hour 式を変更する必要はない。常駐プロセスが奇数時を検出する。cron 単独運用では奇数時の起動は保証されない。二重常駐も同じ枠の処理は一度だけ。maintenance 中は新規実行しない。

例（本番では実行しない）:

```ini
[Unit]
Description=Hakoniwa persistent turn scheduler
After=network.target

[Service]
WorkingDirectory=/path/to/release/product
ExecStart=/usr/bin/php artisan hakoniwa:turn:work --world=shared-world
Restart=on-failure
RestartSec=30
User=application-user

[Install]
WantedBy=multi-user.target
```

初回移行はアプリ停止・旧 worker の停止と未解決Turnの確認後に行う。新 migration は旧 `origin + current_turn * 2h` を初期カーソルへ一度だけ転記し、過去のターンや本番の起点を再解釈しない。既存バックログは維持する。以後は current_turn の増加で通常暦を再計算しない。新規 World は初回実行時、または既存 schedule-init でカーソルを初期化する。
