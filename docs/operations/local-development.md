# ローカル開発

## 前提

Docker EngineとDocker Compose v2を使用する。hostの80/443は使わず、既定では`127.0.0.1:8080`へ公開する。

## 初期設定

PowerShellでrepository rootから実行する。

```powershell
Copy-Item .env.example .env
docker run --rm php:8.5.8-cli-bookworm php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

出力をroot `.env`の`APP_KEY=`へ貼り、十分に長いランダムな`HAKONIWA_POSTGRES_PASSWORD`を設定する。secretをcommitしない。

```powershell
docker compose config
docker compose build
docker compose up -d
docker compose ps
docker compose logs --no-color
docker compose exec hakoniwa-web php artisan migrate --force
docker compose exec hakoniwa-web php artisan hakoniwa:world:init
```

`debug-32x32`へresetしたlocal Worldでも、通常の`hakoniwa:world:init`は既存boundsからprofileを判定して冪等に動作する。新規Worldの既定は引き続き60×60である。

`http://127.0.0.1:8080`を開く。世界初期化commandは二重実行してもWorldやCellを増やさない。

## 検証

新しいDB volumeではinit scriptが`hakoniwa_test`を作る。

PHP sourceとtestを編集しながら検証する場合は、production相当の`hakoniwa-web`とは別の`hakoniwa-dev` serviceを使う。最初に一度だけdevelopment imageをbuildして起動する。

```powershell
docker compose -f compose.yml -f compose.development.yml build hakoniwa-dev
docker compose -f compose.yml -f compose.development.yml up -d hakoniwa-dev
```

`hakoniwa-dev`はHTTP portを公開せず、`APP_ENV=testing`、`DB_DATABASE=hakoniwa_test`を明示したtooling専用containerである。checkoutの`app`、`config`、`database`、`docs`、`routes`、PHP view、`tests`とPHPUnit/PHPStan設定をread-only bind mountする。development imageはPSR-4 fallbackを使える非authoritative autoloaderを持つため、新しいPHP classを追加した場合も再buildは不要である。image内の`vendor`、`storage`、`bootstrap/cache`、`public/build`はbind mountで隠さず、rootまたはproductの`.env`もmountしない。

通常のPHP/test編集後はimageをbuildまたはcontainerを再作成せず、そのままfocused testとstatic analysisを再実行する。

```powershell
docker compose -f compose.yml -f compose.development.yml exec -T hakoniwa-dev composer test:surface -- --filter HakoniwaCalendarTest
docker compose -f compose.yml -f compose.development.yml exec -T hakoniwa-dev composer analyse
```

backend full suiteは検証対象ごとに分離する。Surface作業は`composer test:surface`（`tests/Shared`、`tests/Unit`、`tests/Feature`）、Underground作業は`composer test:underground`（`tests/Shared`と`tests/Underground`）を使用する。repository全体、release、rebaseline、または明示されたcross-cutting検証は`composer test:all`を使用し、`composer test`はその互換aliasとして残す。各commandは同じscope対応dispatcherと既存の512 MiB PHPUnit contractを使い、`composer analyse`は既存の1 GiB PHPStan contractを使用する。片側だけの通常作業で、もう片側のlocal full suiteを追加実行しない。

`composer.json`、`composer.lock`、Dockerfile、PHP extension、またはbaked frontend assetを変更した場合はdevelopment imageを再buildする。通常のPHP source/test/config/migration/viewだけの変更では再buildしない。Composer downloadはBuildKit cacheを使用するため、同じbuilderで失敗したbuildをretryすると取得済みpackageを再利用できる。

production相当imageと通常のlocal applicationは従来どおりbase Composeだけを使用する。

```powershell
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=hakoniwa_test hakoniwa-web php -d memory_limit=512M vendor/bin/phpunit --colors=never
docker compose exec hakoniwa-web ./vendor/bin/pint --test
docker compose exec hakoniwa-web ./vendor/bin/phpstan analyse --memory-limit=1G
```

本番設定を持つcontainer内では`php artisan test`を直接実行しない。PHP process起動時から`APP_ENV=testing`と`DB_DATABASE=hakoniwa_test`を明示し、`tests/TestCase.php`のguardでも本番DBへの接続を拒否する。

repository-wideのcanonical serial full suiteは`composer test:all`で実行でき、`composer test`も互換aliasとして同じ集合を実行する。ローカルでrepository-wide suiteを並列化する場合は、Windows hostのrepository rootから次を実行する。

```powershell
.\product\tests\scripts\run_parallel_tests.cmd 4 full
.\product\tests\scripts\run_parallel_tests.cmd 4 surface
.\product\tests\scripts\run_parallel_tests.cmd 4 underground
```

既定・推奨値は4 shards、scope省略時は`full`である。serial 15分41秒、2 shards 9分12秒、4 shards 5分46秒、8 shards 5分09秒という値は再設計前の別test集合・別時点の履歴であり、現在の所要時間として扱わない。PowerShell wrapperは`hakoniwa-dev`を起動し、bind mountされた現在checkoutのsource/testで既存の`tests/scripts/run_parallel_tests.sh`を呼ぶ。通常の編集ごとのDocker buildや`hakoniwa-web`再作成は行わない。Windows hostへComposerやGNU `xargs -P`を追加する必要はない。source checkoutでComposerとBashを直接利用する環境では`composer test:parallel -- 4 full`も同じrunnerを起動する。

parallel runnerはcanonical `phpunit.xml`から選択scopeのtest fileを自動検出し、run開始時に固定`shard-plan.json`を作る。過去のscope全件PASS evidenceにあるJUnitのfile別秒数があれば重いfileから最短workerへ割り当てるLPTを使う。Phase 2以前のfocused実行機能がなかったlegacy PASSも入力にできるが、現在の`selection_mode=focused`はfileの一部だけの場合があるため重みに使わない。新規fileは同じfixture profileの中央値、同profileの履歴もなければ全体中央値を使い、timingが1件もなければpath順のdeterministic fallbackを使う。失敗runのtimingは次回配分へ使わない。作成後にdiscoveryが変わったplanは拒否し、run途中で割当を再計算しない。

各workerは同じ専用DB上で`standard`、`reusable_surface`、`individual`を別PHP processとして順番に実行する。fixture所属はtest classが使うtraitから導出し、通常の地上map testはworker内で一度生成・commitしたDebug32x32 baselineをcase transactionでrollbackしながら再利用する。絞った実行はevidenceで`focused`として区別し、該当0件のworkerは許容するがrun全体の該当0件は失敗する。各workerには`hakoniwa_parallel_<run>_<shard>_test`という固定test-only prefix/suffixの独立DBを作成し、そのDB名だけを強制する一時PHPUnit configを使用する。全worker終了時、失敗時、またはinterrupt時にはchild processを停止してtest DBと一時configを可能な限りcleanupする。cleanupに失敗した場合はproduction DBへfallbackせず、manifestを残して安全なretry commandを表示する。evidenceには固定plan、割当方式、timing source数、fixture別結果、実行identifier集合、worker時間、cleanup結果を保存する。

GitHub Actionsも同じplannerを使用し、Fullの全test fileを独立runner・独立PostgreSQL service上のPHPUnit matrixへ自動配分する。各CI shardは実行開始時に固定planを作り、同じplanでcoverage確認・表示・fixture別実行を行う。各CI shardでもtrait metadataから3つのfixture profileへ分け、別PHP processで順番に実行する。workflow YAMLへtest file一覧は保持しない。各runはdiscoveryのunion、duplicate、missingを検証し、`backend-static`と全PHPUnit shardsを最終`backend` gateへ集約する。Quality CIはrepository-wide safety netである。

frontend testは次のdomain別commandを使う。`test:surface`と`test:underground`は共通testを含み、`test:all`は各fileを1回だけ実行する。

```powershell
cd product
npm run test:surface
npm run test:underground
npm run test:all
```

frontend lint、typecheck、production buildはそれぞれ`npm run lint`、`npm run typecheck`、`npm run build`で確認する。これらは`docker compose build`のNode stageでも実行される。既存volumeにtest DBがない場合は、PostgreSQL管理権限を持つ運用者がtest専用DBを追加するか、開発volumeを明示的に再作成する。本番DBをtestに使用しない。

OAuth portalの設定は [oauth-setup.md](oauth-setup.md)を参照する。secretなしでもroute、config validation、state、mock callback testを検証できる。
