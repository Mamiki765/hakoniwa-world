# やまにてぃ静的解析 概要

## 調査範囲

解析対象は _references/yamanity/repository のコミット ac4edce07784eb391ab7a56f1a833ca25e3597c8 である。調査時点で HEAD はこの値と一致し、参照リポジトリの作業ツリーは clean だった。依存導入、Docker起動、DB接続、migration、seed、テスト、外部認証は実行していない。

本書群は「やまにてぃ単体」の静的解析である。C版との採否比較は comparison-matrix.md 以降へ分離した。

## 確定した全体像

やまにてぃは Laravel 10系、PHP 8.2.4系、Vue 3、Pinia、Vite、Tailwind CSS、MySQL 8を想定した島別マップ型のWebアプリケーションである。各プレイヤーは共通世界上の領土を持つのではなく、独立した15×15の六角形島マップを1つ持つ。

- Laravel側の入口は app/routes/web.php と app/routes/api.php。
- ターン更新入口は Artisan の execute:turn（app/app/Console/Commands/ExecuteTurn.php:29-51）。
- ゲーム状態は Eloquent Model からPHP Entityへ復元し、処理後に次ターンのスナップショットとしてDBへ保存する。
- 島地形は island_terrains.terrain のJSONに、島1つ・ターン1つにつき225セルをまとめて保存する（migration 2023_03_30_112920_create_island_terrains_table.php:16-24）。
- 計画は island_plans.plan のJSONに最大30件をまとめて保存する（Plans::MAX_PLANS、app/app/Entity/Plan/Plans.php:16-35）。
- 状態集計値、ランキングキー、所有者やターン番号は通常カラムで保存する。
- UIはBladeが初期データを埋め込み、Vueが全225セルをimg要素で描画する（IslandViewer.vue:1-34）。
- Google Socialite、Yahoo! JAPAN OpenID Connect、local/debug限定のIDログインがある。通常のメール/パスワード登録画面はない。
- 専用管理画面、管理者ロール、管理APIは存在しない。

## 技術バージョン

| 項目 | 確定事項 | 根拠 |
| --- | --- | --- |
| PHP | 8.2.4以上の8.2系 | app/composer.json:7-16、infra/app/Dockerfile:1 |
| Laravel | 制約は10系。lock上は10.9.0 | app/composer.json:12、app/composer.lock |
| Vue | 3.3.4系 | app/package.json:20-31 |
| Pinia | 2.0.36系 | app/package.json:30 |
| Vite | 4.3.8系 | app/package.json:18 |
| Node.js | 開発・buildイメージは18.15.0 bullseye | infra/frontend/Dockerfile:1、infra/cloudbuild/Dockerfile:1 |
| DB | MySQL 8、CIは8.0.19 | docker-compose.yml:27-40、.github/workflows/laravel.yml:14-19 |
| 文字コード | PHP/NginxはUTF-8、DBはutf8mb4_bin | infra/app/php.ini:16、infra/app/nginx-default.conf:12、infra/db/init.sql:4 |

## 主な長所

- HTTP層とゲームEntityを概ね分け、地形、計画、災害、ログを小さな型へ分解している。
- 1ターンの主要更新を単一DBトランザクションに含め、ファイル一括更新より原子性が高い（ExecuteTurn::handle、ExecuteTurn.php:56-365）。
- セル属性を共通フラグとして扱い、災害対象を型名の巨大分岐だけに依存させていない（Cell::ATTRIBUTE、Cell.php:15-31）。
- Vueではログ本文を補間で描画し、v-htmlを使っていない（LogViewer.vue:23-33）。
- 登録時にトランザクションとロックを使い、最大島数の同時超過を抑えようとしている（Register IndexController::post、IndexController.php:47-70）。

## 主な制約・問題候補

- 共有世界ではなく島ごとの固定15×15であり、新作の共通世界・チャンク取得へそのまま転用できない。
- ターン処理は全島と全地形をメモリへ一括ロードし、batch、chunk、queueを使わない（ExecuteTurn.php:66-104）。
- execute:turn に排他的なターンロック、schedulerの withoutOverlapping、冪等性キーがない。
- ゲーム設定の多くはPHP定数または環境変数で、管理UI・変更履歴・適用ターンがない。
- JSON復元は未定義type、欠損セル、不正JSONに対する明示的schema検証がない。
- Repository、Action、Job、Policy、API Resource、Form Requestなどの境界がなく、保存処理がControllerやConsole Commandへ集中する。
- リポジトリ直下にLICENSE/NOTICEがなく、composer.jsonのMIT表記を独自コードや画像へ当然に拡張できない。

## 文書一覧

構成、DB、JSON、責務、ターン、コマンド、施設、UI、API、認証、管理、ログ、テスト、運用、ライセンス、未解決事項を各 yamanity-*.md に分離した。推測は各文書で明示し、根拠のない動作確認結果は記載していない。
