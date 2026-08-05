# Docker Compose運用

## services

| service | role |
|---|---|
| `hakoniwa-web` | Apache、PHP/Laravel API、production Vue assets |
| `hakoniwa-postgres` | 箱庭専用PostgreSQL 18.4 |

DBはhostへport公開せず、`hakoniwa_postgres_data` named volumeへ保存する。Web healthcheckは`/up`、DB healthcheckは`pg_isready`を使う。worker、scheduler、Redis、backup containerはMVPにない。

## 操作

```console
docker compose up -d
docker compose ps
docker compose logs --no-color
docker compose restart hakoniwa-web
docker compose down
```

`docker compose down`はcontainerとnetworkを削除するが、named volumeとDBデータを残す。再度`up -d`すれば同じDBを使用する。

```console
docker compose down -v
```

`down -v`は`hakoniwa_postgres_data`も削除し、ゲームデータを復元不能にする。初期開発DBの意図的な再作成または復元演習以外では実行しない。先にbackupを取得する。

## 外部tile assetの任意mount

原作GIFはGitとimageに含まれない。必要な環境だけ、Git外directoryをread-only mountするlocal overrideを作る。

```yaml
services:
  hakoniwa-web:
    volumes:
      - /absolute/host/path:/srv/hakoniwa-assets/tiles:ro
```

`HAKONIWA_TILE_ASSET_PATH`と`HAKONIWA_TILE_ASSET_BASE_URL`をroot `.env`で環境に合わせる。`compose.yml`は両方を`hakoniwa-web`へ明示転送する。mountがなくてもCSS fallbackで起動する。同名画像の置換は`mtime-size`付きURLへ反映され、image rebuildを必要としない。

首都画像を表示する環境は同じdirectoryへ`capital.gif`を配置する。旧名`capital.png`はmanifestで参照しない。GIFがない場合は首都のCSS fallbackを使い、API・map・healthcheckを失敗させない。

既存deploy向けの`HAKONIWA_ORIGINAL_ASSET_PATH`と`HAKONIWA_ORIGINAL_ASSET_BASE_URL`も当面転送する。新変数が未設定の場合だけ旧変数をfallbackとして使用し、新変数を優先する。新規設定では`HAKONIWA_TILE_ASSET_*`を使用する。

### PR21怪獣GIFの配置要件

怪獣overlayを原画像で表示する環境では、既存terrain/facilityと同じhost directoryを`/srv/hakoniwa-assets/tiles:ro`へmountし、次の原名GIF 9個を配置する。

```text
monster0.gif
monster1.gif
monster2.gif
monster3.gif
monster4.gif
monster5.gif
monster6.gif
monster7.gif
monster8.gif
```

`monster4.gif`はサンジラとクジラの硬化状態専用。他の8個は`product/docs/monster-audit-pr21.md`のkind対応を正本とする。subdirectory、rename、PNG変換、symlinkによる別source置換は行わない。ownerはmount前に全fileがregular/readableなGIFであることを確認する。

一部または全部がない場合もhealthcheck、API、map、Vite buildは失敗しない。resolverは該当URLを返さずUIが安全なCSS fallbackを表示する。missing fileはdeploy inventory mismatchとして記録し、別画像を代用しない。`_references`をmountまたはHTTP公開して配置を代替してはならない。

## Pre-release ruleset update

既存migration chainはcanonical schema rebaselineまで維持し、application更新時は先にPostgreSQL backupと明示的な`*_test` DBで`php artisan migrate --force`を検証する。ただしmigration適用後もhistorical ruleset Worldの継続実行は行わない。read-only表示を確認し、`docs/operations/world-reset.md`のbackup-first手順でconfigured Worldをcurrent rulesetへresetする。

World更新のためにcontainer volumeを再作成したり、`docker compose down -v`を実行したりしない。published historical ruleset recordsは監査用に保持する。

## 将来の本番統合

本番ではNginx Proxy ManagerからDocker network上の`hakoniwa-web:80`へ接続する。repositoryのlocal port mappingを既存OCI Composeへそのままcopyせず、network、secret、backup、monitoringを別承認で決める。
