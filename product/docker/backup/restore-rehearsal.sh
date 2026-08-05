#!/usr/bin/env bash
set -Eeuo pipefail

: "${HAKONIWA_PROJECT_DIR:?Set HAKONIWA_PROJECT_DIR to the deployed repository directory}"
: "${HAKONIWA_BACKUP_FILE:?Set HAKONIWA_BACKUP_FILE to an encrypted backup}"
: "${HAKONIWA_BACKUP_PASSPHRASE_FILE:?Set HAKONIWA_BACKUP_PASSPHRASE_FILE to the backup secret file}"
: "${HAKONIWA_RESTORE_DATABASE:?Set HAKONIWA_RESTORE_DATABASE to a disposable database ending in _restore}"
: "${HAKONIWA_RESTORE_CONFIRM:?Set HAKONIWA_RESTORE_CONFIRM to RESTORE:<database>}"

database="${HAKONIWA_RESTORE_DATABASE}"
user="${HAKONIWA_POSTGRES_USER:-hakoniwa}"
expected_confirmation="RESTORE:${database}"

if [[ ! "${database}" =~ ^[a-zA-Z][a-zA-Z0-9_]*_restore$ ]]; then
    printf 'Restore database must be an identifier ending in _restore.\n' >&2
    exit 1
fi
if [[ "${HAKONIWA_RESTORE_CONFIRM}" != "${expected_confirmation}" ]]; then
    printf 'Refusing restore. Set HAKONIWA_RESTORE_CONFIRM=%s\n' "${expected_confirmation}" >&2
    exit 1
fi
if [[ ! -r "${HAKONIWA_BACKUP_FILE}" || ! -s "${HAKONIWA_BACKUP_FILE}" ]]; then
    printf 'Encrypted backup is missing, unreadable, or empty.\n' >&2
    exit 1
fi
if [[ ! -r "${HAKONIWA_BACKUP_PASSPHRASE_FILE}" || ! -s "${HAKONIWA_BACKUP_PASSPHRASE_FILE}" ]]; then
    printf 'Backup passphrase file is missing, unreadable, or empty.\n' >&2
    exit 1
fi

cd -- "${HAKONIWA_PROJECT_DIR}"
docker compose exec -T hakoniwa-postgres dropdb --username="${user}" --if-exists "${database}"
docker compose exec -T hakoniwa-postgres createdb --username="${user}" "${database}"

openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
    -pass "file:${HAKONIWA_BACKUP_PASSPHRASE_FILE}" \
    -in "${HAKONIWA_BACKUP_FILE}" \
    | docker compose exec -T hakoniwa-postgres \
        pg_restore --username="${user}" --dbname="${database}" --no-owner --no-acl --exit-on-error

worlds="$(docker compose exec -T hakoniwa-postgres \
    psql --username="${user}" --dbname="${database}" --tuples-only --no-align \
    --command='SELECT count(*) FROM worlds')"
if [[ ! "${worlds}" =~ ^[1-9][0-9]*$ ]]; then
    printf 'Restore verification failed: restored database has no World.\n' >&2
    exit 1
fi

printf 'restore_rehearsal=ok database=%s worlds=%s\n' "${database}" "${worlds}"
