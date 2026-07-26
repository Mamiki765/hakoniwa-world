# やまにてぃ ディレクトリ構成

## 主要構成

| パス | 責務 |
| --- | --- |
| .github/workflows/laravel.yml | PHPテスト用GitHub Actions |
| app/ | Laravelアプリ本体。composer、artisan、Vue/Viteも内包 |
| app/app/Console/Commands/ | execute:turn、prune:logs |
| app/app/Entity/ | ゲームルール、地形、計画、災害、怪獣、ログ、実績 |
| app/app/Http/Controllers/ | Blade画面とJSON API |
| app/app/Models/ | Eloquent永続化モデル |
| app/app/Services/Hakoniwa/ | 15×15寸法、所有島取得、計画メタデータ |
| app/config/ | Laravel設定とhakoniwa環境変数の束ね |
| app/database/migrations/ | DB schema |
| app/database/factories/ | User、Island、Status、Terrain、Turnのテスト生成 |
| app/database/seeders/ | 初期Turn、開発用島登録 |
| app/resources/js/ | Vue、Pinia、TypeScript |
| app/resources/views/ | Bladeのページ・レイアウト |
| app/routes/ | Web/API/console route |
| app/tests/ | Controller中心のFeature testと1本のターンscenario |
| infra/app/ | PHP-FPM、Nginx、Supervisor、cron用設定 |
| infra/cloudbuild/ | Cloud Build、Cloud Run/Job向けimage |
| infra/composer/ | Composer実行用image |
| infra/db、infra/db-testing | MySQL初期化と認証設定 |
| infra/frontend/ | Node 18 Vite開発用image |
| docker-compose.yml | app、composer、db、db-testing、frontend |
| Makefile | ローカル構築、migration、seed、test、手動turn |

## Laravel内の分布

app/app/Entity は最も大きな独自領域である。Cellは人口、食料、資金、資源、基地、怪獣、船、公園等のサブ名前空間へ分割され、Planは自島計画と他島対象処理、Eventは災害と他島イベントへ分割される。ログは67個のLogRow派生クラスを持つ。

HTTP層はWebとApiで分けられている。一方、app/app/Http/Requests、Resources、Policiesは存在せず、validationとレスポンス組立はController内で行う。app/app/Repositories、Actions、Jobs、Observersも存在しない。

## 入口

| 種類 | 入口 | 根拠 |
| --- | --- | --- |
| 公開Web | GET /、GET /islands/{id} | app/routes/web.php:26-33 |
| 所有島操作画面 | GET /islands/{id}/plans | app/routes/web.php:35-38 |
| 登録・設定 | /register、/settings | app/routes/web.php:40-47 |
| 認証 | /auth/google/*、/auth/yahoo/*、debug login | app/routes/web.php:65-77 |
| JSON API | /api/islands/{id} 以下5系統 | app/routes/api.php:30-47 |
| ターン | php artisan execute:turn | ExecuteTurn.php:29-43 |
| ログ整理 | php artisan prune:logs | PruneLogs.php:8-22 |
| 管理画面 | 存在しない | route/controller一覧 |

## フロントエンド

Vueアプリは app/resources/js/bootstrap.ts で生成され、app.tsから#appへmountする。Bladeがページ単位componentへサーバー取得済みデータをpropsとして渡す（pages/islands/plans.blade.php:4-12）。PiniaのMainStoreが地形、状態、計画、選択座標、API状態、テーマ、掲示板を集中管理する（MainStore.ts:23-99）。

Viteはapp/package.jsonのdev/build script、Tailwind/Sass/PostCSSはdevDependenciesと設定ファイルで管理する。root package.jsonにはTypeScriptとts-loaderだけがあり、実際のVue build定義はapp/package.json側である。

## ローカルと本番相当

ローカルはdocker-composeでNginx/PHP-FPMを同一appコンテナに置き、MySQLとViteを別サービスにする。本番相当のinfra/cloudbuildはNode build stageとPHP deploy stageを統合し、Cloud SQL Proxyでmigration/seedを実行後、Cloud RunとCloud Run Jobへ同一imageを配備する（infra/cloudbuild/develop.yaml:23-107）。

ローカルapp imageにはXdebugが入る（infra/app/Dockerfile:28-29）。Cloud build imageにはXdebugはないが、composer installがno-devではなく、appのphp.iniはdisplay_errors=onである。これは本番hardeningの不足候補である。

## 調査上の注意

infra/app/cronは毎分 schedule:run を想定するが、Console Kernelのscheduleは空であり、Dockerfileもcronファイルをcopy/registerせず、Supervisorもcronを起動しない（infra/app/cron:1、app/app/Console/Kernel.php:16-19、infra/app/supervisor-app.conf:6-34）。Cloud Run Jobの起動スケジュールはこのリポジトリ内で確認できない。
