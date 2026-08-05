#!/usr/bin/env bash
set -Eeuo pipefail

: "${HAKONIWA_PROJECT_DIR:?Set HAKONIWA_PROJECT_DIR to the deployed repository directory}"
: "${HAKONIWA_BACKUP_DIRECTORY:?Set HAKONIWA_BACKUP_DIRECTORY to an off-host mounted directory}"
: "${HAKONIWA_BACKUP_PASSPHRASE_FILE:?Set HAKONIWA_BACKUP_PASSPHRASE_FILE to a root-readable secret file}"

database="${HAKONIWA_POSTGRES_DB:-hakoniwa}"
user="${HAKONIWA_POSTGRES_USER:-hakoniwa}"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
output="${HAKONIWA_BACKUP_DIRECTORY%/}/hakoniwa-${timestamp}.dump.enc"
temporary="${output}.partial"

if [[ ! -d "${HAKONIWA_BACKUP_DIRECTORY}" || ! -w "${HAKONIWA_BACKUP_DIRECTORY}" ]]; then
    printf 'Backup directory is missing or not writable: %s\n' "${HAKONIWA_BACKUP_DIRECTORY}" >&2
    exit 1
fi
if [[ ! -r "${HAKONIWA_BACKUP_PASSPHRASE_FILE}" || ! -s "${HAKONIWA_BACKUP_PASSPHRASE_FILE}" ]]; then
    printf 'Backup passphrase file is missing, unreadable, or empty.\n' >&2
    exit 1
fi

umask 077
trap 'rm -f -- "$temporary"' EXIT
cd -- "${HAKONIWA_PROJECT_DIR}"

docker compose exec -T hakoniwa-postgres \
    pg_dump --username="${user}" --dbname="${database}" --format=custom --no-owner --no-acl \
    | openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 \
        -pass "file:${HAKONIWA_BACKUP_PASSPHRASE_FILE}" \
        -out "${temporary}"

test -s "${temporary}"
mv -- "${temporary}" "${output}"
trap - EXIT
printf 'encrypted_backup=%s\n' "${output}"
