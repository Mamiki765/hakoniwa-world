#!/usr/bin/env bash
set -Eeuo pipefail

: "${HAKONIWA_BACKUP_DIRECTORY:?Set HAKONIWA_BACKUP_DIRECTORY to the local staging directory}"

keep="${HAKONIWA_LOCAL_BACKUP_GENERATIONS:-4}"
[[ "${keep}" =~ ^[1-9][0-9]{0,2}$ ]] || {
    printf 'Local backup generation count must be an integer from 1 to 999.\n' >&2
    exit 1
}

if [[ ! -d "${HAKONIWA_BACKUP_DIRECTORY}" || ! -w "${HAKONIWA_BACKUP_DIRECTORY}" ]]; then
    printf 'Backup directory is missing or not writable: %s\n' "${HAKONIWA_BACKUP_DIRECTORY}" >&2
    exit 1
fi

backup_directory="$(realpath -e -- "${HAKONIWA_BACKUP_DIRECTORY}")"
[[ "${backup_directory}" != '/' ]] || {
    printf 'Refusing to prune the filesystem root.\n' >&2
    exit 1
}

export LC_ALL=C
shopt -s nullglob
markers=("${backup_directory}"/hakoniwa-*.dump.enc.uploaded)

marker_failure() {
    printf 'Verification marker does not match its local backup: %s\n' "$1" >&2
    return 1
}

validate_verified_pair() {
    local marker="$1"
    local marker_name backup backup_name line
    local object='' bytes='' content_md5='' verified_at=''
    local object_seen=0 bytes_seen=0 content_md5_seen=0 verified_at_seen=0
    local current_bytes current_md5

    marker_name="$(basename -- "${marker}")"
    [[ "${marker_name}" =~ ^hakoniwa-[0-9]{8}T[0-9]{6}Z\.dump\.enc\.uploaded$ ]] \
        || marker_failure "${marker_name}"
    backup="${marker%.uploaded}"
    if [[ ! -f "${marker}" || -L "${marker}" || ! -f "${backup}" || -L "${backup}" ]]; then
        marker_failure "${marker_name}"
    fi

    while IFS= read -r line || [[ -n "${line}" ]]; do
        case "${line}" in
            object=*)
                (( object_seen == 0 )) || marker_failure "${marker_name}"
                object="${line#object=}"
                object_seen=1
                ;;
            bytes=*)
                (( bytes_seen == 0 )) || marker_failure "${marker_name}"
                bytes="${line#bytes=}"
                bytes_seen=1
                ;;
            content_md5=*)
                (( content_md5_seen == 0 )) || marker_failure "${marker_name}"
                content_md5="${line#content_md5=}"
                content_md5_seen=1
                ;;
            verified_at=*)
                (( verified_at_seen == 0 )) || marker_failure "${marker_name}"
                verified_at="${line#verified_at=}"
                verified_at_seen=1
                ;;
            *)
                marker_failure "${marker_name}"
                ;;
        esac
    done <"${marker}"

    backup_name="$(basename -- "${backup}")"
    [[ "${object}" == "${backup_name}" ]] || marker_failure "${marker_name}"
    [[ "${bytes}" =~ ^[1-9][0-9]*$ ]] || marker_failure "${marker_name}"
    [[ "${content_md5}" =~ ^[A-Za-z0-9+/]{22}==$ ]] || marker_failure "${marker_name}"
    [[ "${verified_at}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] \
        || marker_failure "${marker_name}"

    current_bytes="$(stat -c %s -- "${backup}")"
    current_md5="$(openssl dgst -md5 -binary "${backup}" | openssl base64 -A)"
    [[ "${current_bytes}" == "${bytes}" ]] || marker_failure "${marker_name}"
    [[ "${current_md5}" == "${content_md5}" ]] || marker_failure "${marker_name}"
}

for marker in "${markers[@]}"; do
    validate_verified_pair "${marker}"
done

remove_count=$((${#markers[@]} - keep))
if (( remove_count <= 0 )); then
    printf 'local_retention=ok kept=%d pruned=0\n' "${#markers[@]}"
    exit 0
fi

for ((index = 0; index < remove_count; index++)); do
    marker="${markers[index]}"
    backup="${marker%.uploaded}"
    printf 'local_backup_pruned=%s\n' "$(basename -- "${backup}")"
    rm -- "${backup}" "${marker}"
done

printf 'local_retention=ok kept=%d pruned=%d\n' "${keep}" "${remove_count}"
