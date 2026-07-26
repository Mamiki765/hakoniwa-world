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

## 原作assetの任意mount

原作GIFはGitとimageに含まれない。必要な環境だけ、Git外directoryをread-only mountするlocal overrideを作る。

```yaml
services:
  hakoniwa-web:
    volumes:
      - /absolute/host/path:/srv/hakoniwa-assets/original:ro
```

`HAKONIWA_ORIGINAL_ASSET_PATH`とURLを環境に合わせる。mountがなくてもCSS fallbackで起動する。

## 将来の本番統合

本番ではNginx Proxy ManagerからDocker network上の`hakoniwa-web:80`へ接続する。repositoryのlocal port mappingを既存OCI Composeへそのままcopyせず、network、secret、backup、monitoringを別承認で決める。
