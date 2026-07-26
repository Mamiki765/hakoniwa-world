# Hakoniwa World product

Laravel API、OAuth callback、Vue 3 UI、世界・島生成domain serviceを含むMVPアプリケーションです。repository rootの `compose.yml` から本番相当imageをbuildします。

主要な入口:

- `php artisan hakoniwa:world:init`: 全面海の共有Worldを冪等初期化
- `/api/v1`: 認証済みJSON API
- `resources/js/app.ts`: Vue entrypoint
- `app/Application`: 世界生成、国家作成、配置、資源初期化
- `app/Domain/Hex`: signed axial座標とchunk計算

ホストで依存関係を用意して検証する場合:

```console
composer install
npm ci
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm run test
npm run lint
npm run typecheck
npm run build
```

通常の起動・DB操作・OAuth設定はrepository rootの運用文書を参照してください。実際のsecretや原作GIFをこのdirectoryへ追加しないでください。
