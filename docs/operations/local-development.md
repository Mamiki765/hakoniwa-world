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

```powershell
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=hakoniwa_test hakoniwa-web php vendor/bin/phpunit --colors=never
docker compose exec hakoniwa-web ./vendor/bin/pint --test
docker compose exec hakoniwa-web ./vendor/bin/phpstan analyse --memory-limit=1G
```

本番設定を持つcontainer内では`php artisan test`を直接実行しない。PHP process起動時から`APP_ENV=testing`と`DB_DATABASE=hakoniwa_test`を明示し、`tests/TestCase.php`のguardでも本番DBへの接続を拒否する。

frontend test、lint、typecheck、production buildは`docker compose build`のNode stageで実行される。既存volumeにtest DBがない場合は、PostgreSQL管理権限を持つ運用者がtest専用DBを追加するか、開発volumeを明示的に再作成する。本番DBをtestに使用しない。

OAuth portalの設定は [oauth-setup.md](oauth-setup.md)を参照する。secretなしでもroute、config validation、state、mock callback testを検証できる。
