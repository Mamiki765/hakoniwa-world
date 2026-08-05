# PostgreSQL backupとrestore

## 初期production契約

- 暗号化したoff-host PostgreSQL backupを6時間ごとに取得する。
- 日次backupを30日保持する。
- deploy前には定期実行とは別に追加backupを取得する。
- 正式公開前に1回、公開後は月1回を目安にrestore rehearsalを行う。
- 初期目標はRPO 6時間以内、RTO 12時間以内とする。

continuous WAL archive、point-in-time recovery、RPO 15分以内は公開後の運用改善とする。player event、gameplay audit、moderation記録の保持とは別の契約であり、backup retentionを理由にapplication dataを削除しない。

## Secretとoff-host領域

backup passphraseはGit、環境設定file、cron行へ記載しない。rootだけが読めるfileを配置し、そのpathをHAKONIWA_BACKUP_PASSPHRASE_FILEで渡す。off-host backup領域は別machine、object storage mount、または同等にhost障害から独立した保管先をoperatorが用意する。repositoryは実在しない外部serviceを作成・偽装しない。

次を事前に確認する。

1. backup領域が実際にoff-hostであり、書き込み可能である。
2. passphrase fileがbackup hostとは別の復旧手順でも取得できる。
3. backup fileとpassphraseを同じ場所へ保管しない。
4. backup転送と容量監視は保管先の手順で確認する。

## 暗号化backup

create-encrypted-backup.shはpg_dumpのcustom formatを平文fileへ保存せず、AES-256-CBC、PBKDF2で暗号化する。pg_dump、暗号化、file確定のどこかが失敗すれば非ゼロ終了し、partial fileを残さない。

    HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world \
    HAKONIWA_BACKUP_DIRECTORY=/mnt/off-host/hakoniwa \
    HAKONIWA_BACKUP_PASSPHRASE_FILE=/root/secrets/hakoniwa-backup-passphrase \
    /opt/hakoniwa-world/product/docker/backup/create-encrypted-backup.sh

6時間ごとの例:

    17 */6 * * * /usr/bin/env HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world HAKONIWA_BACKUP_DIRECTORY=/mnt/off-host/hakoniwa HAKONIWA_BACKUP_PASSPHRASE_FILE=/root/secrets/hakoniwa-backup-passphrase /opt/hakoniwa-world/product/docker/backup/create-encrypted-backup.sh >> /var/log/hakoniwa-backup.log 2>&1

deploy前にも同じcommandを明示的に実行し、出力されたfileがoff-host領域に存在することを確認する。

## 30日retention

日次backupを確実に残す運用を前提に、対象directory内のhakoniwa-*.dump.encだけを30日経過後に削除する。

    HAKONIWA_BACKUP_DIRECTORY=/mnt/off-host/hakoniwa \
    /opt/hakoniwa-world/product/docker/backup/prune-encrypted-backups.sh

保管先側のversioningやretention lockがある場合は、その設定を優先し、二重削除の挙動を確認する。

## Restore rehearsal

restore scriptはproduction DBを上書きしない。末尾が_restoreの使い捨てdatabaseだけを削除・再作成し、完全復号、pg_restoreのエラー停止、World件数を確認する。

    HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world \
    HAKONIWA_BACKUP_FILE=/mnt/off-host/hakoniwa/hakoniwa-20260805T000000Z.dump.enc \
    HAKONIWA_BACKUP_PASSPHRASE_FILE=/root/secrets/hakoniwa-backup-passphrase \
    HAKONIWA_RESTORE_DATABASE=hakoniwa_rehearsal_restore \
    HAKONIWA_RESTORE_CONFIRM=RESTORE:hakoniwa_rehearsal_restore \
    /opt/hakoniwa-world/product/docker/backup/restore-rehearsal.sh

成功後は別databaseへ接続して、World、Nation、cell、command queue、TurnRun、eventの件数と、最新turnを記録する。必要なら一時Web containerをrestore DBへ向け、TOP、自島、マニュアル、1ターンdry runを確認する。確認後の使い捨てdatabase削除はoperatorが対象名を再確認して行う。

正式公開前の1回は日時、backup file、復元先、確認者、件数、所要時間を運用記録へ残す。公開後は月1回を目安に同じ記録を更新する。

## Failure対応

- backup commandが非ゼロ: 直前の正常backup時刻を確認し、disk、off-host mount、Docker、PostgreSQL、passphrase fileを順に調べる。成功するまでdeployを進めない。
- restoreが非ゼロ: 復元先だけを調べ、production DBへ切り替えない。backup破損またはpassphrase不一致なら、別世代のbackupでも再確認する。
- RPO 6時間を超えた: production変更を止め、backup経路を復旧してから運用を再開する。
- production restoreが必要: maintenanceへ移行し、対象環境、追加backup、復元時点、停止時間を明示承認してから別手順として実施する。
