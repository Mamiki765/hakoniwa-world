# PostgreSQL backupとrestore

## Production契約（ver 1.3.2）

ver 1.3.2はgameplay、public API contract、ruleset、database schema、World/Nation gameplay stateを変更せず、production PostgreSQL backupの取得、暗号化、off-host保存、検証、local保持だけを自動化する。

- repository: `/home/ubuntu/apps/hakoniwa-world`
- Compose project directory: `/home/ubuntu/apps`
- local staging: `/var/backups/hakoniwa-staging`
- passphrase file: `/root/secrets/hakoniwa-backup-passphrase`
- private Object Storage bucket: `hakoniwa-backup`
- Object Storage lifecycle: object作成から30日後に削除
- Object Storage compartment quota: `ap-osaka-1` / compartment `mariachang4`全体で`10,000,000,000` bytes
- production trigger: 6時間ごと、およびdeploy前の明示実行
- initial target: RPO 6時間以内、RTO 12時間以内

すべての手順はproduction hostの`root`で実行する。passphrase file、staging、lock、log、root crontab、Docker socketへ必要な権限を持たせるためである。passphraseの値をshell、cron、log、environment dumpへ出力しない。

VMのInstance Principalには`OBJECT_CREATE`、`OBJECT_INSPECT`、`OBJECT_READ`だけを付与する。`OBJECT_OVERWRITE`と`OBJECT_DELETE`は付与しない。wrapperも`--no-overwrite`を指定し、既存objectを成功扱いしない。30日後の削除はbucket lifecycle policyが行い、VMへdelete権限を追加しない。bucketはprivateのままとする。

continuous WAL archive、point-in-time recovery、RPO 15分以内は別の公開後改善とする。backup retentionを理由にapplication data、event、audit recordを削除しない。

## Production wrapper

正本は`product/docker/backup/run-production-backup.sh`である。wrapperは次を順に行う。

1. root、directory、secret、command、最低空き容量1 GiBをpreflightする。
2. `/run/lock/hakoniwa-production-backup.lock`をnon-blocking `flock`し、cron、manual、deploy前backupの並行実行を拒否する。
3. 既存`create-encrypted-backup.sh`をCompose project directory `/home/ubuntu/apps`で実行する。
4. stdoutの唯一の`encrypted_backup=...`を解析し、実体がstaging直下のregular fileかつ正規のtimestamp filenameであることを検証する。
5. local byte sizeとbase64 MD5を計算する。
6. OCI CLI containerへ対象backup fileだけをread-only mountし、Instance Principalでlocal filenameと同じobject名へ`--no-multipart --no-overwrite --content-md5 --verify-checksum`付きでuploadする。passphraseはmountしない。
7. upload responseに新規objectのETagがあることを要求する。duplicate objectを示す正常exit風の応答も失敗にする。
8. volume mountなしのOCI CLI containerからObject StorageへHEADし、`content-length`と`content-md5`を取得する。
9. local/remote sizeとMD5の完全一致を要求する。
10. 全検証後だけobject名・local bytes・local MD5を含む`.uploaded` markerをatomic no-clobberで作り、検証済みlocal世代のretentionを行う。

暗号化、upload、HEAD、size/MD5検証、marker、retentionのどこかが失敗すればwrapperは非ゼロ終了する。remote検証成功前にlocal encrypted backupを削除しない。`.partial`、markerのないupload失敗品、検証失敗品を正常backupとして数えない。

`create-encrypted-backup.sh`はrunごとにunique partial fileを作り、同じdirectory内のhard link作成をatomic no-clobberのfinal確定として使う。同じtimestampのfinal file、symlink、または`.uploaded` markerが既にあればdump前に拒否し、final確定直前のraceでも既存fileを置換しない。verification markerも同じno-clobber方式で確定し、既存markerを上書きしない。

## OCI CLI image pin

production既定値は次のimmutable multi-architecture digestである。

```text
ghcr.io/oracle/oci-cli:20260729@sha256:12ba572de6290354255e9d7ed0d387a428230a0a5b2b8969603ca8008f71734a
```

Oracleの例は`latest`を使うが、`latest`は同じcron設定のまま内容が変わり、未検証CLIがbackup経路へ入る。そのためproduction wrapperはtagだけ、特に`latest`を拒否し、digestを必須とする。このpinはOracleの[公式GHCR package](https://github.com/oracle/docker-images/pkgs/container/oci-cli)で公開された`20260729` manifest digestである。OCI CLI container、Instance Principal、bind mountの基本形は[Oracle公式手順](https://docs.oracle.com/en-us/iaas/Content/API/SDKDocs/clicontainer.htm)に従う。

pin更新は通常deployへ混ぜず、review済みの独立したoperations変更として行う。候補digestをpullし、`--version`、新規object upload、duplicate拒否、HEAD size/MD5、download、restore rehearsalをすべて再検証してから既定値を変更する。

初回設定時にimageを明示pullし、digest解決を確認する。

```console
docker pull ghcr.io/oracle/oci-cli:20260729@sha256:12ba572de6290354255e9d7ed0d387a428230a0a5b2b8969603ca8008f71734a
docker image inspect ghcr.io/oracle/oci-cli:20260729@sha256:12ba572de6290354255e9d7ed0d387a428230a0a5b2b8969603ca8008f71734a --format '{{json .RepoDigests}}'
docker run --rm ghcr.io/oracle/oci-cli:20260729@sha256:12ba572de6290354255e9d7ed0d387a428230a0a5b2b8969603ca8008f71734a --version
```

## Secret、staging、容量

passphrase fileはGit、`.env`、cron、logへ書かず、secret値をenvironment dumpや運用記録へ出力しない。production host上のcopyはrootだけが読める既存fileを使う。

VM全損時にも復号できるよう、production hostとOCI bucket `hakoniwa-backup`の双方から独立したoperator-controlled secret保管先へrecovery copyを保持することを必須とする。passphraseをbackup objectと同じbucket、同じpath、または同じstaging directoryへ保存しない。保管先のidentifier、access承認者、最終取得確認日時は記録してよいが、secret値は記録しない。月次restore rehearsalではObject Storage backupだけでなく、この独立保管先からpassphraseをDR取得できることも実証する。

```console
test -s /root/secrets/hakoniwa-backup-passphrase
test "$(stat -c '%a:%U:%G' /root/secrets/hakoniwa-backup-passphrase)" = '600:root:root'
install -d -o root -g root -m 0700 /var/backups/hakoniwa-staging
df -h /var/backups/hakoniwa-staging
```

wrapperの既定最低空き容量は1 GiBである。backup自体の書込中に容量不足、`pg_dump`失敗、またはOpenSSL失敗が起きた場合も既存scriptの`set -o pipefail`とpartial cleanupにより非ゼロとなる。`10,000,000,000` bytesはbucket単独の上限ではなく、`ap-osaka-1`のcompartment `mariachang4`全体に対するObject Storage compartment quotaである。同じcompartmentの別bucketを含む使用量とbackup sizeの増加をoperatorの管理identityまたはOCI Consoleで監視する。quota超過はupload失敗としてlocal fileを保持する。

## Local staging retention

Object Storageは30日lifecycleを正本とする。host stagingはdisk圧迫を避けるため、remote size/MD5検証済みの最新4世代（通常約24時間）だけを保持する。`prune-verified-local-backups.sh`はmarkerのobject名・bytes・MD5を厳密に読み、現在のencrypted fileからsizeとMD5を再計算して完全一致した世代だけを古い順に削除する。

upload、HEAD、検証に失敗してmarkerがないfileは自動削除しない。operatorは失敗原因を解消し、remote objectの有無と整合性を確認するまで保持する。markerのないfileが増えた場合は正常retentionと見なさず、cronを直してdiskを確認する。remote未確認fileを容量確保のために自動削除する変更は行わない。

## Logrotate

production logは`/var/log/hakoniwa-backup.log`で、repositoryの`product/docker/backup/hakoniwa-backup.logrotate`をhostへinstallする。application/web server logの既存方針と同じく日次rotate、30世代、圧縮、rootのみreadableとする。

```console
touch /var/log/hakoniwa-backup.log
chown root:root /var/log/hakoniwa-backup.log
chmod 0600 /var/log/hakoniwa-backup.log
install -o root -g root -m 0644 \
  /home/ubuntu/apps/hakoniwa-world/product/docker/backup/hakoniwa-backup.logrotate \
  /etc/logrotate.d/hakoniwa-backup
logrotate --debug /etc/logrotate.d/hakoniwa-backup
```

`--debug`は実際にはrotateしない。初回強制rotateが必要な場合だけ、backup processが動いていないことを`flock`で確認してからoperator判断で実行する。logへpassphrase、environment dump、database URLを書かない。

## 初回manual実行と確認

production deploy後、cron登録前にrootで同じwrapperを1回実行する。deploy前manual backupも常にこのwrapperを使い、内部lock、upload、HEAD、検証を省略しない。

```console
backup_status=0
/home/ubuntu/apps/hakoniwa-world/product/docker/backup/run-production-backup.sh \
  >> /var/log/hakoniwa-backup.log 2>&1 || backup_status=$?
tail -n 40 /var/log/hakoniwa-backup.log
test "${backup_status}" -eq 0
```

次をすべて確認する。

1. 最後の実行に`production_backup=ok object=hakoniwa-... bytes=...`が1行ある。
2. 同じ実行の`encryption`、`upload`、`head`、`verification`がすべて`status=ok`である。
3. `production_backup=failed`または`backup_error=`が同じ実行にない。
4. `/var/backups/hakoniwa-staging/<object>.uploaded`が存在し、対応するencrypted fileが残っている。
5. private bucketにlocal filenameと完全に同じobject名が1個あり、sizeとMD5がlogの値に一致する。
6. Object Storage lifecycle ruleが30日後削除でenabled、`ap-osaka-1` / compartment `mariachang4`全体のObject Storage quotaが10,000,000,000 bytes、VM IAMにoverwrite/deleteがない。

非ゼロならcronやdeployを進めない。`backup_status`へ保存したwrapperの終了codeを確認し、後続の`tail`をbackup成功判定に使わない。

## Root crontab

`crontab -e`をrootで開き、次のexact 1行を登録する。

```cron
17 */6 * * * /home/ubuntu/apps/hakoniwa-world/product/docker/backup/run-production-backup.sh >> /var/log/hakoniwa-backup.log 2>&1
```

turn cronはAsia/Tokyoの偶数時`00`分に動く。backupは`17`分開始なので通常のturn実行と重なりにくく、backup同士はwrapper内flockで拒否される。DB dumpはonline取得であり、turnを停止・retry・変更しない。初回cron後にrootのcron log、`/var/log/hakoniwa-backup.log`、Object Storage object、local markerを確認する。

## Object Storageからのdownloadとrestore rehearsal

月1回およびpin変更時は、local stagingに残るfileをそのまま使わず、private Object Storageから専用temporary directoryへdownloadしたobjectを正式な復元入力にする。passphraseもproduction host上の常用copyではなく、独立したoperator-controlled secret保管先からDR取得したcopyを使う。code blockを始める前に、承認済みDR取得手順でそのcopyを`/run/hakoniwa-backup-passphrase.rehearsal`へroot所有mode 0600で配置する。secretの取得値はshell historyやlogへ出さない。次はrootで実行し、`object_name`はObject Storageで確認した正確なbasenameへ置き換える。

```console
set -Eeuo pipefail
umask 077

oci_image='ghcr.io/oracle/oci-cli:20260729@sha256:12ba572de6290354255e9d7ed0d387a428230a0a5b2b8969603ca8008f71734a'
object_name='hakoniwa-20260809T000000Z.dump.enc'
restore_parent='/var/backups/hakoniwa-staging'
restore_work_directory="$(mktemp -d "${restore_parent}/restore-rehearsal.XXXXXX")"
dr_passphrase_file='/run/hakoniwa-backup-passphrase.rehearsal'
download_file="${restore_work_directory}/${object_name}"

[[ "${object_name}" =~ ^hakoniwa-[0-9]{8}T[0-9]{6}Z\.dump\.enc$ ]]
chmod 0700 "${restore_work_directory}"
test "$(stat -c '%a:%U:%G' "${restore_work_directory}")" = '700:root:root'
test -s "${dr_passphrase_file}"
test "$(stat -c '%a:%U:%G' "${dr_passphrase_file}")" = '600:root:root'
test ! -e "${download_file}"

remote_size="$(docker run --rm --user 0:0 \
  "${oci_image}" --auth instance_principal os object head \
  --bucket-name hakoniwa-backup --name "${object_name}" \
  --query '"content-length"' --raw-output)"
remote_md5="$(docker run --rm --user 0:0 \
  "${oci_image}" --auth instance_principal os object head \
  --bucket-name hakoniwa-backup --name "${object_name}" \
  --query '"content-md5"' --raw-output)"
[[ "${remote_size}" =~ ^[0-9]+$ ]]
[[ "${remote_md5}" =~ ^[A-Za-z0-9+/]{22}==$ ]]

docker run --rm --user 0:0 \
  --mount "type=bind,source=${restore_work_directory},target=/oracle/restore" \
  "${oci_image}" --auth instance_principal os object get \
  --bucket-name hakoniwa-backup --name "${object_name}" \
  --file "/oracle/restore/${object_name}"

local_size="$(stat -c %s -- "${download_file}")"
local_md5="$(openssl dgst -md5 -binary "${download_file}" | openssl base64 -A)"
test "${local_size}" = "${remote_size}"
test "${local_md5}" = "${remote_md5}"

HAKONIWA_PROJECT_DIR=/home/ubuntu/apps \
HAKONIWA_BACKUP_FILE="${download_file}" \
HAKONIWA_BACKUP_PASSPHRASE_FILE="${dr_passphrase_file}" \
HAKONIWA_RESTORE_DATABASE=hakoniwa_rehearsal_restore \
HAKONIWA_RESTORE_CONFIRM=RESTORE:hakoniwa_rehearsal_restore \
  /home/ubuntu/apps/hakoniwa-world/product/docker/backup/restore-rehearsal.sh
```

成功出力は少なくとも次の形である。

```text
restore_rehearsal=ok database=hakoniwa_rehearsal_restore worlds=1
```

続けてproductionではなく`hakoniwa_rehearsal_restore`へ接続し、World、Nation、cell、command queue、TurnRun、eventの件数と最新turnを運用記録へ残す。必要なvisual確認も復元DBを向いた一時環境だけで行う。production DB、volume、World/Nation stateを変更しない。

確認後、日時、object名、remote/local size、MD5、復元先database、World件数、所要時間、確認者、DR secret保管先identifierと取得確認結果を記録する。secret値は記録しない。download専用directory、`/run`上のDR passphrase copy、使い捨て`*_restore` databaseの削除は、記録と対象を再確認したoperatorが別操作として行う。remote objectはVMから削除できず、30日lifecycleに任せる。

## Failure pathと対応

| Failure path | Fail-closed contract | Operator action |
|---|---|---|
| passphrase missing/unreadable/empty | dump前に非ゼロ | root所有、mode 0600、production host/bucketから独立したDR保管先と取得経路を確認 |
| DB dump失敗 | `pipefail`で非ゼロ、partial削除 | PostgreSQL health、Compose service、DB/userを確認 |
| OpenSSL失敗 | 非ゼロ、partial削除 | OpenSSL、disk、passphrase fileを確認 |
| staging書込不可 | probeまたは既存scriptで非ゼロ | ownership、mode、mountを確認 |
| staging容量不足 | 1 GiB preflightまたは書込で非ゼロ | markerのないfileを勝手に消さず原因とremote状態を確認 |
| Docker失敗 | 非ゼロ、local encrypted file保持 | daemon、socket、Compose、imageを確認 |
| OCI auth failure | upload/HEAD非ゼロ、local保持 | dynamic group、policy、Instance Principalを確認 |
| Object Storage unreachable | upload/HEAD非ゼロ、local保持 | OCI service/network/DNSを確認 |
| duplicate object name | `--no-overwrite`かETag検証で非ゼロ | 既存objectとlocal fileを別々に照合し、上書きしない |
| upload失敗 | markerなし、local保持、非ゼロ | compartment全体のquota、IAM、network、bucketを確認してから再取得を判断 |
| HEAD失敗 | markerなし、local保持、非ゼロ | object visibility、IAM、networkを確認 |
| size不一致 | markerなし、local保持、非ゼロ | objectを正常backupとして扱わず、download照合 |
| MD5不一致/欠落 | markerなし、local保持、非ゼロ | objectを正常backupとして扱わず、原因調査 |
| 並行実行 | 二つ目はexit 75、dumpを開始しない | 先行processとlogを確認し、自動retryを足さない |
| local retention失敗 | remote検証済みでもwrapper全体は非ゼロ | marker/file pair、permission、diskを確認 |

RPO 6時間を超えた場合はproduction変更とdeployを止め、backup経路を復旧してから運用を再開する。production restoreが必要な場合はmaintenance、対象環境、追加backup、復元時点、停止時間を明示承認した別手順とし、このrehearsal手順をproduction DBへ向けない。

## ver 1.3.2 announcement

deployと初回manual backup/restore確認後、`product/docs/ver-1.3.2-announcement.md`のtitle/bodyを既存のお知らせ管理画面から1回だけ公開する。migrationやseederでannouncement rowを作らず、既存production announcementを上書きしない。
