# やまにてぃ Docker・運用構成

## ローカルcompose

docker-compose.ymlは5サービスを定義する。

| service | image/build | 責務 | 主な依存・volume |
| --- | --- | --- | --- |
| app | infra/app/Dockerfile | Nginx + PHP-FPM + Laravel、log tail | db healthy、app bind、sqlite/node_modules |
| composer | infra/composer/Dockerfile | Composer用shell | app bind、node_modules |
| db | mysql:8 | 開発DB | db-data、my.cnf、init.sql、healthcheck |
| db-testing | mysql:8 | test DB | db-testing-data、my.cnf、init.sql |
| frontend | node:18.15.0-bullseye | Vite dev server | app bind、node_modules |

appはhost APP_PORT既定54380から80、DBは54306、test DBは54316、Viteは54373を公開する（docker-compose.yml:3-69）。appだけがdb healthcheckへdepends_onする。db-testing/frontend/appには自身のhealthcheckがなく、restart policyもない。

composeにはvendor volume定義があるがserviceでmountされない。appはprivileged=trueで、通常のWeb開発コンテナには広い権限である（docker-compose.yml:10-18,70-80）。

## app image

PHP 8.2.4-fpmにNginx、Supervisor、cron、bcmath、PDO MySQL、mysqli、zip、opcache、Xdebug等を入れる（infra/app/Dockerfile:1-29）。Supervisorがphp-fpm、nginx、laravel.log tailを起動するがcron/queue workerは起動しない（infra/app/supervisor-app.conf:6-34）。

PHP-FPM pm.max_children=1、PHP memory_limit=2048M、max_execution_time=300、display_errors=onである（php-fpm.conf:11-15、php.ini:5-16）。開発用途としては単純だが、本番の並行Web負荷とhardeningには適さない。

## schedulerとqueue

infra/app/cronには毎分 php artisan schedule:run がある。しかし:

- Console Kernelのscheduleは空（app/app/Console/Kernel.php:16-19）。
- Dockerfileはcron fileをcopy/crontab登録しない。
- Supervisorはcron daemonを起動しない。
- composeにscheduler/worker serviceはない。
- QUEUE_CONNECTION設定はあるがJob classとqueue consumerはない。

したがってlocal composeだけで自動turnが進むとは確認できない。Makefileのnext-turnは手動繰返しである（Makefile:53-56）。

### cronからturnまでを追跡した結果

| 確認項目 | source上の事実 | 結論 |
|---|---|---|
| cron設定 | `infra/app/cron:1`は`* * * * * www-data cd /app && /usr/local/bin/php artisan schedule:run >> /var/log/app/schedule.log 2>&1` | 毎分schedulerを呼ぶ意図 |
| cronから呼ぶcommand | `schedule:run` | turn commandを直接は呼ばない |
| Laravel schedule | `app/app/Console/Kernel.php:16-19`は空 | `execute:turn`への接続なし |
| turn command | `ExecuteTurn::$signature = execute:turn` (`ExecuteTurn.php:29-56`) | 手動またはrepository外の起動元が必要 |
| turn間隔 | `TURN_UPDATE_MINUTES`既定240分 (`app/config/app.php:225-227`) | 次回予定時刻の表示用。command自身はdue判定しない |
| timezone | Laravelとphp.iniが`Asia/Tokyo`、MySQL containerも`TZ=Asia/Tokyo` | calendar表示はJST |
| Docker組込み | imageはcron packageをinstallするがcron fileをcopyせずdaemonも起動しない | local composeでcronは稼働しない |
| log | cron案は`/var/log/app/schedule.log`、Supervisorは`storage/logs/laravel.log`をstdoutへtail | 実際のcron log directory作成も未確認 |
| 成功・失敗 | `execute:turn`は成功時`Command::SUCCESS`、例外は再throw | process exit codeで判定可能 |
| 二重実行防止 | turn番号uniqueのみ。mutex、`withoutOverlapping`、DB lock、run rowなし | 並行計算を事前拒否しない |
| 長時間処理 | PHP `max_execution_time=300`だがCLIへの実効性は保証にならず、timeout/lease/checkpointなし | 次回起動との重複を防げない |
| 再実行 | 例外時DB transactionはrollbackするがretry/backoff/attempt記録なし | 運用者またはrepository外scheduler依存 |

`ExecuteTurn::handle`は最新turn取得、新turn row作成、全島snapshot読込から保存までを1つの`DB::transaction`に入れる（`ExecuteTurn.php:56-360`）。成功後に`prune:logs`を別実行するため、その失敗は確定済みturnを戻さない。例外時はwebhookへ同期POST後に再throwする（`ExecuteTurn.php:361-379`）。外部通知例外が元のturn失敗を覆う可能性があるため、新作では通知をturn transactionと失敗記録から分離する。

Cloud Buildは同じimageでCloud Run Job `hakoniwa-develop-exec-turn`を更新し、task数を1にする（`infra/cloudbuild/develop.yaml:99-118`）。ただしJobのcommand/args、Cloud Scheduler設定、起動interval、retry policyはrepositoryにない。image既定CMDはSupervisorなので、このfileだけではJobが`execute:turn`を実行することも確認できない。

### Hakoniwa Worldへの採用判断

採用する考え方は「cronはthin triggerで、同じArtisan/Application Serviceを手動・定期実行から共有する」「turn全体をDB transactionで囲む」「CLI exit codeと標準出力を運用監視へ渡す」である。

現行OCI/ComposeはWebとPostgreSQLだけで、1時間に1回の単一World triggerにqueue workerや常駐scheduler containerは過剰である。PR #7は調査結果と次の運用案だけを残す。wrapper、Artisan command、TurnRunner、advisory lock、一意制約の実装は、分割後のstacked Draft PR #8（`codex/turn-runner-scaffold`）に含まれるため、PR #7単体ではこのcommandを実行できない。

```text
PR #8で実装するOCI host cron案 (Asia/Tokyo、毎時0分)
→ optional host flock
→ docker compose exec -T --user www-data hakoniwa-web
→ php artisan hakoniwa:turn:run --world=shared-world --source=cron
→ TurnRunner
→ PostgreSQL advisory lock + turn transaction
```

PR #8の案では、`flock`は同一host上の不要なprocess生成を減らす補助であり、正本排他はLaravel側のWorld advisory lockと`(world_id,target_turn)`一意制約である。既存`hakoniwa-web`へcron daemonを同居させず、production OCIへの実登録もPR #8では行わない。World数、実行時間、outbox量が常駐processを正当化した時点で専用scheduler/worker containerを再評価する。

## DB・volume・backup

DBはutf8mb4_binで初期化する。compose/init.sqlには固定の開発credentialが平文で入るため、公開本番秘密として再利用してはならない。DB volumeはlocal driver。dump、point-in-time recovery、backup service、restore rehearsalはrepoにない。

ゲーム内PruneLogsはDB backupではなくsnapshot間引きである。DB/volume消失や破損からの復旧には使えない。

## CI

GitHub ActionsはMySQL serviceでmigration後、PHP testのみ実行する。frontend build、Docker build、security scan、lint/static analysisはない（.github/workflows/laravel.yml:9-65）。

## Cloud Build / Cloud Run

infra/cloudbuild/develop.yamlは以下を行う。

1. cache imageをpull。
2. Cloud SQL Proxyを起動。
3. Node stageでfrontend build、PHP stageでLaravel image build。
4. Secret Manager値をbuild arg/envへ渡す。
5. Artifact Registryへpush。
6. imageを一時実行してmigrationとseed。
7. Cloud Run serviceとCloud Run Jobを更新。

根拠: develop.yaml:23-107。Cloud Runはus-central1、Cloud SQL接続名はasia-northeast1で、cross-region latency/egressの意図確認が必要である。

deploy imageはcomposer installをno-devなしで実行し、APP_ENVと秘密をbuild arg経由でimage buildへ渡して.envを生成する構成が示唆される。build history/layerへの秘密残留有無を確認すべきである。zero-downtime migrationのexpand/contract規則、rollback、Job schedule、backupはrepo内にない。

## 新作で必要なサービス境界

今回composeへcron daemonは追加しない。将来候補:

- hakoniwa-web: stateless HTTP/API、Vue assets。
- hakoniwa-worker: outbox通知、非turn background jobs。
- hakoniwa-scheduler: due turnをlease取得してdispatch。
- hakoniwa-postgres: game DB、JSONBとtransaction/advisory lock候補。

worker/schedulerをWebと分け、healthcheck、restart、least privilege、readiness、secret file/store、DB backup、migration jobを明示する。Mariachangは内部APIだけを公開し、DB volumeやgame schemaを共有しない。
