#!/usr/bin/env bash
set -Eeuo pipefail

repository_directory="${HAKONIWA_REPOSITORY_DIRECTORY:-/home/ubuntu/apps/hakoniwa-world}"
compose_project_directory="${HAKONIWA_COMPOSE_PROJECT_DIRECTORY:-/home/ubuntu/apps}"
backup_directory="${HAKONIWA_BACKUP_DIRECTORY:-/var/backups/hakoniwa-staging}"
passphrase_file="${HAKONIWA_BACKUP_PASSPHRASE_FILE:-/root/secrets/hakoniwa-backup-passphrase}"
bucket="${HAKONIWA_OCI_BUCKET:-hakoniwa-backup}"
lock_file="${HAKONIWA_BACKUP_LOCK_FILE:-/run/lock/hakoniwa-production-backup.lock}"
minimum_free_bytes="${HAKONIWA_BACKUP_MINIMUM_FREE_BYTES:-1073741824}"
local_generations="${HAKONIWA_LOCAL_BACKUP_GENERATIONS:-4}"
oci_image="${HAKONIWA_OCI_CLI_IMAGE:-ghcr.io/oracle/oci-cli:20260729@sha256:12ba572de6290354255e9d7ed0d387a428230a0a5b2b8969603ca8008f71734a}"
create_script="${HAKONIWA_BACKUP_CREATE_SCRIPT:-${repository_directory%/}/product/docker/backup/create-encrypted-backup.sh}"
prune_script="${HAKONIWA_BACKUP_PRUNE_SCRIPT:-${repository_directory%/}/product/docker/backup/prune-verified-local-backups.sh}"

succeeded=0
marker_temporary=''

on_exit() {
    status=$?
    if [[ -n "${marker_temporary}" && -e "${marker_temporary}" ]]; then
        rm -f -- "${marker_temporary}"
    fi
    if (( succeeded == 0 )); then
        printf 'production_backup=failed exit_code=%d\n' "${status}" >&2
    fi
}
trap on_exit EXIT

fail() {
    printf 'backup_error=%s\n' "$1" >&2
    exit "${2:-1}"
}

if (( EUID != 0 )); then
    fail 'root_required'
fi

for required_command in flock realpath stat df awk mktemp openssl docker; do
    command -v "${required_command}" >/dev/null 2>&1 || fail "missing_command:${required_command}"
done

[[ -d "${repository_directory}" ]] || fail 'repository_directory_missing'
[[ -d "${compose_project_directory}" ]] || fail 'compose_project_directory_missing'
[[ -x "${create_script}" ]] || fail 'create_script_missing_or_not_executable'
[[ -x "${prune_script}" ]] || fail 'prune_script_missing_or_not_executable'
[[ -d "${backup_directory}" && -w "${backup_directory}" ]] || fail 'backup_directory_missing_or_not_writable'
[[ -e "${passphrase_file}" || -L "${passphrase_file}" ]] \
    || fail 'passphrase_file_missing_unreadable_or_empty'
[[ -f "${passphrase_file}" && ! -L "${passphrase_file}" ]] \
    || fail 'passphrase_file_is_not_a_safe_regular_file'
[[ -r "${passphrase_file}" && -s "${passphrase_file}" ]] || fail 'passphrase_file_missing_unreadable_or_empty'
[[ "$(stat -c %u -- "${passphrase_file}")" == '0' ]] || fail 'passphrase_file_is_not_owned_by_root'
[[ "$(stat -c %a -- "${passphrase_file}")" == '600' ]] || fail 'passphrase_file_permissions_must_be_0600'
[[ "${bucket}" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'invalid_bucket_name'
[[ "${minimum_free_bytes}" =~ ^[1-9][0-9]{0,17}$ ]] || fail 'invalid_minimum_free_bytes'
[[ "${local_generations}" =~ ^[1-9][0-9]{0,2}$ ]] || fail 'invalid_local_generation_count'
[[ "${oci_image}" =~ ^ghcr\.io/oracle/oci-cli:[A-Za-z0-9._-]+@sha256:[a-f0-9]{64}$ ]] \
    || fail 'oci_cli_image_must_use_an_immutable_digest'

lock_parent="$(dirname -- "${lock_file}")"
[[ -d "${lock_parent}" && -w "${lock_parent}" ]] || fail 'lock_directory_missing_or_not_writable'
if [[ ! -e "${lock_file}" && ! -L "${lock_file}" ]]; then
    if ! (umask 077; set -o noclobber; : >"${lock_file}"); then
        fail 'lock_file_create_failed'
    fi
fi
[[ -f "${lock_file}" && ! -L "${lock_file}" ]] || fail 'lock_file_is_not_a_safe_regular_file'
[[ "$(stat -c %u -- "${lock_file}")" == '0' ]] || fail 'lock_file_is_not_owned_by_root'
if ! { exec 9>>"${lock_file}"; }; then
    fail 'lock_file_open_failed'
fi
if ! flock -n 9; then
    fail 'backup_already_running' 75
fi

backup_directory_real="$(realpath -e -- "${backup_directory}")"
write_probe="$(mktemp "${backup_directory_real}/.hakoniwa-backup-write-test.XXXXXX")"
rm -f -- "${write_probe}"

available_bytes="$(df -PB1 -- "${backup_directory_real}" | awk 'NR == 2 { print $4 }')"
[[ "${available_bytes}" =~ ^[0-9]+$ ]] || fail 'backup_directory_free_space_unknown'
if (( available_bytes < minimum_free_bytes )); then
    fail 'backup_directory_free_space_below_minimum'
fi

printf 'production_backup=started at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'backup_stage=encryption status=started\n'
create_output="$({
    HAKONIWA_PROJECT_DIR="${compose_project_directory}" \
    HAKONIWA_BACKUP_DIRECTORY="${backup_directory_real}" \
    HAKONIWA_BACKUP_PASSPHRASE_FILE="${passphrase_file}" \
        "${create_script}"
})"

[[ "${create_output}" != *$'\n'* && "${create_output}" == encrypted_backup=* ]] \
    || fail 'create_script_returned_an_invalid_backup_path'
reported_path="${create_output#encrypted_backup=}"
[[ -n "${reported_path}" && "${reported_path}" != *$'\r'* && ! -L "${reported_path}" ]] \
    || fail 'create_script_returned_an_unsafe_backup_path'
backup_path="$(realpath -e -- "${reported_path}")"
[[ -f "${backup_path}" && ! -L "${backup_path}" ]] || fail 'created_backup_is_not_a_regular_file'
[[ "$(dirname -- "${backup_path}")" == "${backup_directory_real}" ]] \
    || fail 'created_backup_is_outside_the_staging_directory'

backup_name="$(basename -- "${backup_path}")"
[[ "${backup_name}" =~ ^hakoniwa-[0-9]{8}T[0-9]{6}Z\.dump\.enc$ ]] \
    || fail 'created_backup_filename_is_invalid'
marker="${backup_path}.uploaded"
[[ ! -e "${marker}" && ! -L "${marker}" ]] || fail 'verified_marker_already_exists'

local_size="$(stat -c %s -- "${backup_path}")"
[[ "${local_size}" =~ ^[1-9][0-9]*$ ]] || fail 'created_backup_is_empty_or_has_invalid_size'
local_md5="$(openssl dgst -md5 -binary "${backup_path}" | openssl base64 -A)"
[[ "${local_md5}" =~ ^[A-Za-z0-9+/]{22}==$ ]] || fail 'local_md5_calculation_failed'
printf 'backup_stage=encryption status=ok object=%s bytes=%s\n' "${backup_name}" "${local_size}"

run_oci() {
    docker run --rm \
        --user 0:0 \
        "${oci_image}" \
        --auth instance_principal \
        "$@"
}

run_oci_with_backup() {
    docker run --rm \
        --user 0:0 \
        --mount "type=bind,source=${backup_path},target=/oracle/${backup_name},readonly" \
        "${oci_image}" \
        --auth instance_principal \
        "$@"
}

printf 'backup_stage=upload status=started object=%s\n' "${backup_name}"
upload_etag="$(run_oci_with_backup os object put \
    --bucket-name "${bucket}" \
    --name "${backup_name}" \
    --file "/oracle/${backup_name}" \
    --content-md5 "${local_md5}" \
    --no-multipart \
    --no-overwrite \
    --verify-checksum \
    --query etag \
    --raw-output)"
case "${upload_etag}" in
    ''|null|None|*'already exists'*|*'not overwritten'*|*$'\n'*)
        fail 'upload_did_not_create_a_new_object'
        ;;
esac
printf 'backup_stage=upload status=ok object=%s\n' "${backup_name}"

printf 'backup_stage=head status=started object=%s\n' "${backup_name}"
remote_size="$(run_oci os object head \
    --bucket-name "${bucket}" \
    --name "${backup_name}" \
    --query '"content-length"' \
    --raw-output)"
remote_md5="$(run_oci os object head \
    --bucket-name "${bucket}" \
    --name "${backup_name}" \
    --query '"content-md5"' \
    --raw-output)"
[[ "${remote_size}" =~ ^[0-9]+$ ]] || fail 'head_returned_invalid_content_length'
[[ "${remote_md5}" =~ ^[A-Za-z0-9+/]{22}==$ ]] || fail 'head_returned_invalid_content_md5'
printf 'backup_stage=head status=ok object=%s\n' "${backup_name}"

[[ "${remote_size}" == "${local_size}" ]] || fail 'remote_content_length_mismatch'
[[ "${remote_md5}" == "${local_md5}" ]] || fail 'remote_content_md5_mismatch'
printf 'backup_stage=verification status=ok object=%s bytes=%s md5=%s\n' \
    "${backup_name}" "${local_size}" "${local_md5}"

marker_temporary="$(mktemp "${marker}.partial.XXXXXX")"
printf 'object=%s\nbytes=%s\ncontent_md5=%s\nverified_at=%s\n' \
    "${backup_name}" "${local_size}" "${local_md5}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    >"${marker_temporary}"
chmod 0600 "${marker_temporary}"
ln -- "${marker_temporary}" "${marker}"
rm -- "${marker_temporary}"
marker_temporary=''

HAKONIWA_BACKUP_DIRECTORY="${backup_directory_real}" \
HAKONIWA_LOCAL_BACKUP_GENERATIONS="${local_generations}" \
    "${prune_script}"

succeeded=1
printf 'production_backup=ok object=%s bytes=%s completed_at=%s\n' \
    "${backup_name}" "${local_size}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
