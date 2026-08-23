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
docker compose -f compose.yml -f compose.development.yml exec -T hakoniwa-dev composer test -- --filter HakoniwaCalendarTest
docker compose -f compose.yml -f compose.development.yml exec -T hakoniwa-dev composer analyse
```

`composer test`は既存の512 MiB PHPUnit contract、`composer analyse`は既存の1 GiB PHPStan contractを使用する。`composer.json`、`composer.lock`、Dockerfile、PHP extension、またはbaked frontend assetを変更した場合はdevelopment imageを再buildする。通常のPHP source/test/config/migration/viewだけの変更では再buildしない。Composer downloadはBuildKit cacheを使用するため、同じbuilderで失敗したbuildをretryすると取得済みpackageを再利用できる。

production相当imageと通常のlocal applicationは従来どおりbase Composeだけを使用する。

```powershell
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=hakoniwa_test hakoniwa-web php -d memory_limit=512M vendor/bin/phpunit --colors=never
docker compose exec hakoniwa-web ./vendor/bin/pint --test
docker compose exec hakoniwa-web ./vendor/bin/phpstan analyse --memory-limit=1G
```

本番設定を持つcontainer内では`php artisan test`を直接実行しない。PHP process起動時から`APP_ENV=testing`と`DB_DATABASE=hakoniwa_test`を明示し、`tests/TestCase.php`のguardでも本番DBへの接続を拒否する。

canonicalなserial full suiteは従来どおり`composer test`で実行できる。ローカルで並列化する場合は、Windows hostのrepository rootから次を実行する。

```powershell
.\product\tests\scripts\run_parallel_tests.cmd 4
```

既定・推奨値は4 shardsである。この開発機での同一suiteの実測はserial 15分41秒、2 shards 9分12秒、4 shards 5分46秒、8 shards 5分09秒だった。8 shardsは最速だが4 shardsとの差は約37秒で、container群の推定ピークメモリは約723 MiBから約1.13 GiBへ増えたため、通常は4、CPU・メモリに余裕があり最短時間を優先するときだけ8を使う。PowerShell wrapperは`hakoniwa-dev`を起動し、bind mountされた現在checkoutのsource/testで既存の`tests/scripts/run_parallel_tests.sh`を呼ぶ。通常の編集ごとのDocker buildや`hakoniwa-web`再作成は行わない。Windows hostへComposerやGNU `xargs -P`を追加する必要はない。source checkoutでComposerとBashを直接利用する環境では`composer test:parallel -- 4`も同じrunnerを起動する。parallel runnerはcanonical `phpunit.xml`からtest fileを自動検出し、CIと共通のdeterministic plannerで各fileを1回だけ割り当てる。各processには`hakoniwa_parallel_<run>_<shard>_test`という固定test-only prefix/suffixの独立DBを作成し、そのDB名だけを強制する一時PHPUnit configを使用する。全process終了時、失敗時、またはinterrupt時にはchild processを停止してtest DBと一時configを可能な限りcleanupする。cleanupに失敗した場合はproduction DBへfallbackせず、manifestを残して安全なretry commandを表示する。

GitHub Actionsも同じplannerを使用し、独立runner・独立PostgreSQL service上のPHPUnit matrixへ自動検出したfileを分配する。workflow YAMLへtest file一覧は保持しない。各runはdiscoveryのunion、duplicate、missingを検証し、`backend-static`と全PHPUnit shardsを最終`backend` gateへ集約する。

frontend test、lint、typecheck、production buildは`docker compose build`のNode stageで実行される。既存volumeにtest DBがない場合は、PostgreSQL管理権限を持つ運用者がtest専用DBを追加するか、開発volumeを明示的に再作成する。本番DBをtestに使用しない。

OAuth portalの設定は [oauth-setup.md](oauth-setup.md)を参照する。secretなしでもroute、config validation、state、mock callback testを検証できる。
