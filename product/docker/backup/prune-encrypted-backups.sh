#!/usr/bin/env bash
set -Eeuo pipefail

: "${HAKONIWA_BACKUP_DIRECTORY:?Set HAKONIWA_BACKUP_DIRECTORY to the off-host mounted directory}"

if [[ ! -d "${HAKONIWA_BACKUP_DIRECTORY}" || ! -w "${HAKONIWA_BACKUP_DIRECTORY}" ]]; then
    printf 'Backup directory is missing or not writable: %s\n' "${HAKONIWA_BACKUP_DIRECTORY}" >&2
    exit 1
fi

backup_directory="$(realpath -e -- "${HAKONIWA_BACKUP_DIRECTORY}")"
if [[ "${backup_directory}" == '/' ]]; then
    printf 'Refusing to prune the filesystem root.\n' >&2
    exit 1
fi

find "${backup_directory}" -maxdepth 1 -type f \
    -name 'hakoniwa-*.dump.enc' -mtime +30 -print -delete
