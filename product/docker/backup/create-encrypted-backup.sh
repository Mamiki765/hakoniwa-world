#!/usr/bin/env bash
set -Eeuo pipefail

: "${HAKONIWA_PROJECT_DIR:?Set HAKONIWA_PROJECT_DIR to the deployed repository directory}"
: "${HAKONIWA_BACKUP_DIRECTORY:?Set HAKONIWA_BACKUP_DIRECTORY to an off-host mounted directory}"
: "${HAKONIWA_BACKUP_PASSPHRASE_FILE:?Set HAKONIWA_BACKUP_PASSPHRASE_FILE to a root-readable secret file}"

database="${HAKONIWA_POSTGRES_DB:-hakoniwa}"
user="${HAKONIWA_POSTGRES_USER:-hakoniwa}"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
output="${HAKONIWA_BACKUP_DIRECTORY%/}/hakoniwa-${timestamp}.dump.enc"
temporary=''
marker="${output}.uploaded"

if [[ ! -d "${HAKONIWA_BACKUP_DIRECTORY}" || ! -w "${HAKONIWA_BACKUP_DIRECTORY}" ]]; then
    printf 'Backup directory is missing or not writable: %s\n' "${HAKONIWA_BACKUP_DIRECTORY}" >&2
    exit 1
fi
if [[ ! -r "${HAKONIWA_BACKUP_PASSPHRASE_FILE}" || ! -s "${HAKONIWA_BACKUP_PASSPHRASE_FILE}" ]]; then
    printf 'Backup passphrase file is missing, unreadable, or empty.\n' >&2
    exit 1
fi
if [[ -e "${output}" || -L "${output}" ]]; then
    printf 'Refusing to replace an existing backup file.\n' >&2
    exit 1
fi
if [[ -e "${marker}" || -L "${marker}" ]]; then
    printf 'Refusing to create a backup with an existing verification marker.\n' >&2
    exit 1
fi

umask 077
temporary="$(mktemp "${output}.partial.XXXXXX")"
trap 'if [[ -n "${temporary}" ]]; then rm -f -- "${temporary}"; fi' EXIT
cd -- "${HAKONIWA_PROJECT_DIR}"

docker compose exec -T hakoniwa-postgres \
    pg_dump --username="${user}" --dbname="${database}" --format=custom --no-owner --no-acl \
    | openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 \
        -pass "file:${HAKONIWA_BACKUP_PASSPHRASE_FILE}" \
        -out "${temporary}"

test -s "${temporary}"
if [[ -e "${output}" || -L "${output}" || -e "${marker}" || -L "${marker}" ]]; then
    printf 'Refusing to replace a backup file or verification marker created concurrently.\n' >&2
    exit 1
fi
ln -- "${temporary}" "${output}"
rm -- "${temporary}"
temporary=''
trap - EXIT
printf 'encrypted_backup=%s\n' "${output}"
