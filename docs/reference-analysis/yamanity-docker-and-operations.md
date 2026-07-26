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

今回composeは変更しない。将来候補:

- hakoniwa-web: stateless HTTP/API、Vue assets。
- hakoniwa-worker: outbox通知、非turn background jobs。
- hakoniwa-scheduler: due turnをlease取得してdispatch。
- hakoniwa-postgres: game DB、JSONBとtransaction/advisory lock候補。

worker/schedulerをWebと分け、healthcheck、restart、least privilege、readiness、secret file/store、DB backup、migration jobを明示する。Mariachangは内部APIだけを公開し、DB volumeやgame schemaを共有しない。
