# hakoniwa-world

PHP/LaravelとVueで実装する、全プレイヤーが一つの地上世界を共有する箱庭ゲームです。最初のMVPは `product/` にあります。

## MVPの動作

```text
Docker Composeを起動
→ DiscordまたはGoogleでログイン
→ 国家を作成
→ 全面海の共有世界から空き海域を選択
→ 旧作を基礎にした初期島・首都・初期領土を同一transactionで生成
→ Vueで首都周辺のchunkを表示
```

世界初期化直後の `x=0..59, y=0..59` は3,600セルすべて海です。各rowは60セルで、偶数rowを16px右へずらすstaggered square-tile gridとして表示します。島はWorld初期化時ではなく、国家登録時に初めて生成されます。新作では旧作の村2つのうち1つを中心のCapitalへ置き換え、通常Villageを1つ残します。

初期資源は固定food列ではありません。`resource_definitions` と `nation_resources` を使い、国家作成時に小麦100、魚0、肉0を設定します。生産・消費は未実装です。

## 技術構成

- PHP 8.5.8 / Laravel 13.22.0
- Node.js 24.18.0 LTS / Vue 3.5.40 / TypeScript 6.0.2 / Vite 8
- PostgreSQL 18.4
- Apache + PHPの単一Web image（document rootは `public/`）
- Docker Compose services: `hakoniwa-web`, `hakoniwa-postgres`

ローカル起動、APP_KEY生成、OAuth設定は [local-development.md](docs/operations/local-development.md)、Compose操作は [docker-compose.md](docs/operations/docker-compose.md)、backup/restoreは [database-backup-and-restore.md](docs/operations/database-backup-and-restore.md)を参照してください。

## データと秘密情報

ゲームデータの正本は箱庭専用PostgreSQLです。ソースはGitHub、OAuth secret・DB password・APP_KEYはGit外のroot `.env`、DB backupは `pg_dump` で管理します。Nextcloud用MariaDBや他サービスのPostgreSQLを共有しません。

原作GIFはGitやDocker imageへ含めません。Git外のasset directoryをread-only mountでき、未配置時はCSS fallbackを表示します。Capitalは新作のplaceholderです。出典は [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)を参照してください。

## 未実装

ゲーム内コマンド、queue、turn処理、資源生産・消費、人口自然増加、災害、怪獣、ミサイル、国境侵食、休眠遷移、地下・宇宙、WebSocket、本番OCI Compose統合はMVP外です。

設計判断と残るgateは [docs/open-questions.md](docs/open-questions.md)、実装構成は [mvp-implementation.md](docs/architecture/mvp-implementation.md)を参照してください。
