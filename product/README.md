# Hakoniwa World product

Laravel API、OAuth callback、Vue 3 UI、世界・島生成、command queue、施設stateを含むアプリケーションです。repository rootの `compose.yml` から本番相当imageをbuildします。

主要な入口:

- `php artisan hakoniwa:world:init`: 全面海の共有Worldを冪等初期化
- `php artisan hakoniwa:world:reset --world=shared-world --profile=debug-32x32 --confirm=RESET-shared-world`: `local` / `testing` 専用の32×32開発Worldへ明示的にreset（既定の`default`は60×60）
- `php artisan hakoniwa:turn:run --world=shared-world --dry-run`: turn pipelineとruleset snapshotを確認
- `php artisan hakoniwa:turn:status --world=shared-world`: Worldとturn run履歴を確認
- `/api/v1`: 認証済みJSON API
- `resources/js/app.ts`: Vue entrypoint
- `app/Application`: 世界生成、国家作成、配置、資源初期化
- `app/Domain/Map`: staggered x/yの6近傍、距離、projectionとchunk計算
- `app/Application/CommandQueueService.php`: 未実行commandの予約・並べ替え・取消
- `app/Services/MapCellPresenter.php`: viewer別の公開cell表現
- `config/hakoniwa.php`: versioned rulesetのcommand・施設・生産定義

外部tileはroot `.env`で指定した`HAKONIWA_TILE_ASSET_PATH`のread-only directoryへ置き、`HAKONIWA_TILE_ASSET_BASE_URL`から配信します。root `compose.yml`は新変数をcontainerへ転送します。旧`HAKONIWA_ORIGINAL_ASSET_*`は既存deploy向けfallbackだけに使用します。Gitやimageへ画像を含めず、欠落時はCSS fallbackを使います。

ホストで依存関係を用意して検証する場合:

```console
composer install
npm ci
php vendor/bin/phpunit
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm run test
npm run lint
npm run typecheck
npm run build
```

通常の起動・DB操作・OAuth設定はrepository rootの運用文書を参照してください。実際のsecretや原作GIFをこのdirectoryへ追加しないでください。

production turnは必須phaseがstubの間は進みません。cron例と手動retry手順は
`docs/operations/turn-cron.md`を参照してください。
