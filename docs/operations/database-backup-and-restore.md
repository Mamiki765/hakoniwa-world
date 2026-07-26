# PostgreSQL backupとrestore

ゲームデータの正本は`hakoniwa-postgres`の`hakoniwa` DBである。backupはcontainerの一時fileへcustom formatで作成し、hostへcopyする。

## backup

```console
docker compose exec hakoniwa-postgres pg_dump -U hakoniwa -d hakoniwa -Fc -f /tmp/hakoniwa.dump
docker compose cp hakoniwa-postgres:/tmp/hakoniwa.dump ./hakoniwa.dump
```

backup fileはGitへ追加しない。暗号化、保管先、保持期間、RPO/RTOは本番公開前に決める。

## restore

restoreは対象DBを上書きする破壊的操作である。正しい環境、backup、停止時間を確認し、必要なら直前backupを取得してから行う。

```console
docker compose cp ./hakoniwa.dump hakoniwa-postgres:/tmp/hakoniwa.dump
docker compose exec hakoniwa-web php artisan down
docker compose exec hakoniwa-postgres pg_restore -U hakoniwa -d hakoniwa --clean --if-exists --no-owner /tmp/hakoniwa.dump
docker compose exec hakoniwa-web php artisan migrate --force
docker compose exec hakoniwa-web php artisan up
```

restore後はWorld 1件、MapSpace 1件、期待するCell数、generation run、Nation/Capital/resource残高、Web healthを確認する。復元演習は本番とは別volumeで行う。
