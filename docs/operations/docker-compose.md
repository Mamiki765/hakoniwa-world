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

`docker compose down`はcontainerとnetworkを削除するが、named volume、DBデータ、問い合わせ添付を残す。再度`up -d`すれば同じデータを使用する。

```console
docker compose down -v
```

`down -v`は`hakoniwa_postgres_data`と`hakoniwa_inquiry_attachments`を削除し、ゲームデータと問い合わせ添付を復元不能にする。初期開発DBの意図的な再作成または復元演習以外では実行しない。DB backupは問い合わせ添付を含まないため、添付消失を許容できない環境では別のbackupが必要である。

## 外部tile assetの任意mount

原作GIFはGitとimageに含まれない。必要な環境だけ、Git外directoryをread-only mountするlocal overrideを作る。

```yaml
services:
  hakoniwa-web:
    volumes:
      - /absolute/host/path:/srv/hakoniwa-assets/tiles:ro
```

`HAKONIWA_TILE_ASSET_PATH`と`HAKONIWA_TILE_ASSET_BASE_URL`をroot `.env`で環境に合わせる。`compose.yml`は両方を`hakoniwa-web`へ明示転送する。mountがなくてもCSS fallbackで起動する。同名画像の置換は`mtime-size`付きURLへ反映され、image rebuildを必要としない。

## 問い合わせ添付のwritable mount

ver 2.2.0の問い合わせ画像は既定で`/srv/bot-assets/hakoniwa-inquiries`へ書き込む。base Composeはwritableな`hakoniwa_inquiry_attachments` named volumeを同pathへmountし、container再作成後もfileを残す。Web containerのApacheは`/hakoniwa-inquiries/`をこのdirectoryへ直接対応させ、directory listing、`.htaccess`、CGI/include実行を無効にしたstatic routeとして配信する。

この既定値はlocal/default stack用であり、productionの外部assets基盤を置き換えない。productionでは`HAKONIWA_INQUIRY_ATTACHMENT_PATH`と`HAKONIWA_INQUIRY_ATTACHMENT_BASE_URL`を実mountとassets originへ合わせ、writableなbind mountをCompose overrideで指定する。assets nginxを使う場合は`product/docker/nginx/hakoniwa-inquiries.conf`のlocationと`autoindex off`を維持する。security/backup/upload-limit/operator手順は`product/docs/ver-2.2.0-secretary-inventory-and-inquiries.md`を正本とする。

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

## Production data boundary

PR23のgo-live前は`docs/operations/world-reset.md`の限定手順で開発Worldと仮データをfresh生成できる。production Worldの最終fresh生成、一般Nation登録開放、初回正式turn開始の3条件が揃った後はresetを禁止する。

application更新時は暗号化off-host backupとdeploy前backupを取得し、明示的な`*_test` DBで`php artisan migrate --force`を検証する。go-live後のschemaとgameplay data変更はforward migrationまたは明示的な変換経路を必須とし、World更新のためにcontainer volumeを再作成したり、`docker compose down -v`を実行したりしない。published ruleset recordsは監査用に保持する。

## Production integration

実運用のreverse proxyはDocker network上の`hakoniwa-web:80`へ接続する。repositoryのlocal port mappingをOCI Composeへそのままcopyせず、実在するnetwork、secret、off-host backup、monitoringに合わせてoperatorが設定する。turn cronは`docs/operations/turn-cron.md`、backupとrestore rehearsalは`product/docs/operations/database-backup-and-restore.md`を正本とする。
