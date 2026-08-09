#!/usr/bin/env bash
set -Eeuo pipefail

if (( EUID != 0 )); then
    printf 'This focused test must run as root, matching the production wrapper contract.\n' >&2
    exit 1
fi

test_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repository_root="$(cd -- "${test_directory}/../../../.." && pwd)"
wrapper="${repository_root}/product/docker/backup/run-production-backup.sh"
create_script="${repository_root}/product/docker/backup/create-encrypted-backup.sh"
prune_script="${repository_root}/product/docker/backup/prune-verified-local-backups.sh"
test_root="$(mktemp -d)"
trap 'rm -rf -- "${test_root}"' EXIT

passed=0

pass() {
    passed=$((passed + 1))
    printf 'ok %d - %s\n' "${passed}" "$1"
}

fail_test() {
    printf 'not ok - %s\n' "$1" >&2
    exit 1
}

assert_file() {
    [[ -f "$1" ]] || fail_test "expected file: $1"
}

assert_no_file() {
    [[ ! -e "$1" && ! -L "$1" ]] || fail_test "unexpected file: $1"
}

assert_output() {
    grep -F -- "$2" "$1" >/dev/null || fail_test "missing output '$2' in $1"
}

assert_empty_file() {
    [[ ! -s "$1" ]] || fail_test "expected empty file: $1"
}

file_sha256() {
    openssl dgst -sha256 "$1" | awk '{ print $2 }'
}

write_verified_marker() {
    backup="$1"
    backup_name="$(basename -- "${backup}")"
    backup_size="$(stat -c %s -- "${backup}")"
    backup_md5="$(openssl dgst -md5 -binary "${backup}" | openssl base64 -A)"
    printf 'object=%s\nbytes=%s\ncontent_md5=%s\nverified_at=2026-08-09T00:17:00Z\n' \
        "${backup_name}" "${backup_size}" "${backup_md5}" >"${backup}.uploaded"
}

fake_bin="${test_root}/bin"
mkdir -p "${fake_bin}"

cat >"${fake_bin}/docker" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

printf '%s\n' "$*" >>"${MOCK_DOCKER_LOG}"

if [[ "${1:-}" == 'compose' ]]; then
    if [[ "${MOCK_OCI_SCENARIO:-}" == 'dump_failure' ]]; then
        exit 41
    fi
    printf 'database-dump'
    exit 0
fi

case "${MOCK_OCI_SCENARIO:-success}" in
    docker_failure|auth_failure|object_storage_unreachable|upload_failure)
        exit 42
        ;;
esac

arguments=" $* "
if [[ "${arguments}" == *' os object put '* ]]; then
    if [[ "${MOCK_OCI_SCENARIO:-}" == 'duplicate_object' ]]; then
        printf 'None\n'
    else
        printf 'etag-created-by-test\n'
    fi
    exit 0
fi

if [[ "${arguments}" == *' os object head '* ]]; then
    [[ "${MOCK_OCI_SCENARIO:-}" != 'head_failure' ]] || exit 43
    if [[ "${arguments}" == *'content-length'* ]]; then
        if [[ "${MOCK_OCI_SCENARIO:-}" == 'size_mismatch' ]]; then
            printf '999\n'
        else
            printf '%s\n' "${MOCK_REMOTE_SIZE}"
        fi
    elif [[ "${arguments}" == *'content-md5'* ]]; then
        if [[ "${MOCK_OCI_SCENARIO:-}" == 'md5_mismatch' ]]; then
            printf 'AAAAAAAAAAAAAAAAAAAAAA==\n'
        else
            printf '%s\n' "${MOCK_REMOTE_MD5}"
        fi
    else
        exit 44
    fi
    exit 0
fi

exit 45
EOF
chmod +x "${fake_bin}/docker"

cat >"${fake_bin}/date" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

case "$*" in
    '-u +%Y%m%dT%H%M%SZ')
        printf '20260809T001700Z\n'
        ;;
    '-u +%Y-%m-%dT%H:%M:%SZ')
        printf '2026-08-09T00:17:00Z\n'
        ;;
    *)
        exec /usr/bin/date "$@"
        ;;
esac
EOF
chmod +x "${fake_bin}/date"

fake_create="${test_root}/fake-create.sh"
cat >"${fake_create}" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

backup="${HAKONIWA_BACKUP_DIRECTORY}/hakoniwa-20260809T001700Z.dump.enc"
case "${MOCK_CREATE_SCENARIO:-success}" in
    failure)
        exit 51
        ;;
    unsafe_output)
        printf 'encrypted-payload' >"${backup}"
        printf 'unexpected-output\nencrypted_backup=%s\n' "${backup}"
        ;;
    symlink)
        printf 'symlink-target' >"${backup}.target"
        ln -s -- "${backup}.target" "${backup}"
        printf 'encrypted_backup=%s\n' "${backup}"
        ;;
    dangling_symlink)
        ln -s -- "${backup}.missing" "${backup}"
        printf 'encrypted_backup=%s\n' "${backup}"
        ;;
    outside)
        outside="${HAKONIWA_BACKUP_DIRECTORY}/../outside-hakoniwa-20260809T001700Z.dump.enc"
        printf 'outside-payload' >"${outside}"
        printf 'encrypted_backup=%s\n' "${outside}"
        ;;
    zero_byte)
        : >"${backup}"
        printf 'encrypted_backup=%s\n' "${backup}"
        ;;
    report_existing)
        printf 'encrypted_backup=%s\n' "${backup}"
        ;;
    success)
        printf 'encrypted-payload' >"${backup}"
        printf 'encrypted_backup=%s\n' "${backup}"
        ;;
    *)
        exit 52
        ;;
esac
EOF
chmod +x "${fake_create}"

prune_copy="${test_root}/prune-verified-local-backups.sh"
cp -- "${prune_script}" "${prune_copy}"
chmod +x "${prune_copy}"

repository_stub="${test_root}/repository"
compose_stub="${test_root}/compose"
mkdir -p "${repository_stub}" "${compose_stub}"
passphrase="${test_root}/passphrase"
printf 'test-passphrase\n' >"${passphrase}"
chmod 0600 "${passphrase}"

payload_size="$(printf 'encrypted-payload' | wc -c | tr -d '[:space:]')"
payload_md5="$(printf 'encrypted-payload' | openssl dgst -md5 -binary | openssl base64 -A)"

run_wrapper() {
    scenario="$1"
    case_directory="$2"
    minimum_free_bytes="${3:-1}"
    selected_passphrase="${4:-${passphrase}}"
    selected_create="${5:-${fake_create}}"
    mkdir -p "${case_directory}/staging"
    : >"${case_directory}/docker.log"

    env \
        PATH="${fake_bin}:${PATH}" \
        MOCK_DOCKER_LOG="${case_directory}/docker.log" \
        MOCK_OCI_SCENARIO="${scenario}" \
        MOCK_CREATE_SCENARIO="${MOCK_CREATE_SCENARIO:-success}" \
        MOCK_REMOTE_SIZE="${payload_size}" \
        MOCK_REMOTE_MD5="${payload_md5}" \
        HAKONIWA_REPOSITORY_DIRECTORY="${repository_stub}" \
        HAKONIWA_COMPOSE_PROJECT_DIRECTORY="${compose_stub}" \
        HAKONIWA_BACKUP_DIRECTORY="${case_directory}/staging" \
        HAKONIWA_BACKUP_PASSPHRASE_FILE="${selected_passphrase}" \
        HAKONIWA_BACKUP_LOCK_FILE="${case_directory}/backup.lock" \
        HAKONIWA_BACKUP_MINIMUM_FREE_BYTES="${minimum_free_bytes}" \
        HAKONIWA_BACKUP_CREATE_SCRIPT="${selected_create}" \
        HAKONIWA_BACKUP_PRUNE_SCRIPT="${prune_copy}" \
        bash "${wrapper}"
}

run_canonical_create() {
    case_directory="$1"
    mkdir -p "${case_directory}/staging"
    : >"${case_directory}/docker.log"

    env \
        PATH="${fake_bin}:${PATH}" \
        MOCK_DOCKER_LOG="${case_directory}/docker.log" \
        MOCK_OCI_SCENARIO=success \
        HAKONIWA_PROJECT_DIR="${compose_stub}" \
        HAKONIWA_BACKUP_DIRECTORY="${case_directory}/staging" \
        HAKONIWA_BACKUP_PASSPHRASE_FILE="${passphrase}" \
        bash "${create_script}"
}

success_case="${test_root}/success"
run_wrapper success "${success_case}" >"${success_case}.out" 2>&1 \
    || fail_test 'success scenario returned non-zero'
success_backup="${success_case}/staging/hakoniwa-20260809T001700Z.dump.enc"
assert_file "${success_backup}"
assert_file "${success_backup}.uploaded"
assert_output "${success_case}.out" 'production_backup=ok object=hakoniwa-20260809T001700Z.dump.enc'
assert_output "${success_case}/docker.log" '--auth instance_principal os object put'
assert_output "${success_case}/docker.log" '--name hakoniwa-20260809T001700Z.dump.enc'
assert_output "${success_case}/docker.log" "--mount type=bind,source=${success_backup},target=/oracle/hakoniwa-20260809T001700Z.dump.enc,readonly"
assert_output "${success_case}/docker.log" '--file /oracle/hakoniwa-20260809T001700Z.dump.enc'
assert_output "${success_case}/docker.log" '--no-multipart'
assert_output "${success_case}/docker.log" '--no-overwrite'
assert_output "${success_backup}.uploaded" 'object=hakoniwa-20260809T001700Z.dump.enc'
assert_output "${success_backup}.uploaded" "bytes=${payload_size}"
assert_output "${success_backup}.uploaded" "content_md5=${payload_md5}"
head_calls="$(grep -F -- ' os object head ' "${success_case}/docker.log")"
[[ "${head_calls}" != *'--mount'* && "${head_calls}" != *'--volume'* ]] \
    || fail_test 'HEAD unexpectedly received a volume mount'
grep -F -- "${passphrase}" "${success_case}/docker.log" >/dev/null \
    && fail_test 'OCI CLI container unexpectedly received the passphrase path'
pass 'success mounts only the exact backup for upload, leaves HEAD unmounted, and binds marker metadata to content'

missing_passphrase="${test_root}/missing-passphrase"
if run_wrapper success "${missing_passphrase}" 1 "${test_root}/does-not-exist" >"${missing_passphrase}.out" 2>&1; then
    fail_test 'missing passphrase succeeded'
fi
assert_output "${missing_passphrase}.out" 'passphrase_file_missing_unreadable_or_empty'
pass 'missing passphrase fails before backup creation'

passphrase_symlink_case="${test_root}/passphrase-symlink"
passphrase_symlink_target="${test_root}/passphrase-symlink-target"
printf 'symlink-passphrase\n' >"${passphrase_symlink_target}"
chmod 0600 "${passphrase_symlink_target}"
passphrase_symlink_target_hash="$(file_sha256 "${passphrase_symlink_target}")"
ln -s -- "${passphrase_symlink_target}" "${test_root}/passphrase-symlink-file"
if run_wrapper success "${passphrase_symlink_case}" 1 "${test_root}/passphrase-symlink-file" \
    >"${passphrase_symlink_case}.out" 2>&1; then
    fail_test 'passphrase symlink succeeded'
fi
assert_output "${passphrase_symlink_case}.out" 'passphrase_file_is_not_a_safe_regular_file'
assert_empty_file "${passphrase_symlink_case}/docker.log"
assert_no_file "${passphrase_symlink_case}/staging/hakoniwa-20260809T001700Z.dump.enc"
assert_no_file "${passphrase_symlink_case}/staging/hakoniwa-20260809T001700Z.dump.enc.uploaded"
[[ "$(file_sha256 "${passphrase_symlink_target}")" == "${passphrase_symlink_target_hash}" ]] \
    || fail_test 'passphrase symlink target changed'
pass 'passphrase symlink fails before Docker without changing its target'

insecure_passphrase_case="${test_root}/insecure-passphrase-mode"
insecure_passphrase="${test_root}/insecure-passphrase"
cp -- "${passphrase}" "${insecure_passphrase}"
chmod 0640 "${insecure_passphrase}"
if run_wrapper success "${insecure_passphrase_case}" 1 "${insecure_passphrase}" \
    >"${insecure_passphrase_case}.out" 2>&1; then
    fail_test 'group-readable passphrase succeeded'
fi
assert_output "${insecure_passphrase_case}.out" 'passphrase_file_permissions_must_be_0600'
assert_empty_file "${insecure_passphrase_case}/docker.log"
assert_no_file "${insecure_passphrase_case}/staging/hakoniwa-20260809T001700Z.dump.enc"
assert_no_file "${insecure_passphrase_case}/staging/hakoniwa-20260809T001700Z.dump.enc.uploaded"
[[ "$(stat -c %a -- "${insecure_passphrase}")" == '640' ]] \
    || fail_test 'insecure passphrase mode changed'
pass 'group-readable passphrase fails before Docker and is not modified'

nonroot_passphrase_case="${test_root}/nonroot-passphrase-owner"
nonroot_passphrase="${test_root}/nonroot-passphrase"
cp -- "${passphrase}" "${nonroot_passphrase}"
chmod 0600 "${nonroot_passphrase}"
chown 1:1 "${nonroot_passphrase}"
if run_wrapper success "${nonroot_passphrase_case}" 1 "${nonroot_passphrase}" \
    >"${nonroot_passphrase_case}.out" 2>&1; then
    fail_test 'non-root-owned passphrase succeeded'
fi
assert_output "${nonroot_passphrase_case}.out" 'passphrase_file_is_not_owned_by_root'
assert_empty_file "${nonroot_passphrase_case}/docker.log"
assert_no_file "${nonroot_passphrase_case}/staging/hakoniwa-20260809T001700Z.dump.enc"
assert_no_file "${nonroot_passphrase_case}/staging/hakoniwa-20260809T001700Z.dump.enc.uploaded"
[[ "$(stat -c %u -- "${nonroot_passphrase}")" == '1' ]] \
    || fail_test 'non-root passphrase owner changed'
pass 'non-root-owned passphrase fails before Docker and is not modified'

capacity_case="${test_root}/capacity"
if run_wrapper success "${capacity_case}" 900000000000000000 >"${capacity_case}.out" 2>&1; then
    fail_test 'capacity preflight succeeded unexpectedly'
fi
assert_output "${capacity_case}.out" 'backup_directory_free_space_below_minimum'
pass 'insufficient staging capacity fails closed'

missing_staging="${test_root}/missing-staging"
mkdir -p "${missing_staging}"
if env HAKONIWA_PROJECT_DIR="${compose_stub}" \
    HAKONIWA_BACKUP_DIRECTORY="${missing_staging}/not-a-directory" \
    HAKONIWA_BACKUP_PASSPHRASE_FILE="${passphrase}" \
    bash "${create_script}" >"${missing_staging}.out" 2>&1; then
    fail_test 'missing staging directory succeeded'
fi
pass 'unwritable or missing staging fails closed in the canonical create script'

dump_case="${test_root}/dump-failure"
mkdir -p "${dump_case}/staging"
if env PATH="${fake_bin}:${PATH}" MOCK_DOCKER_LOG="${dump_case}/docker.log" MOCK_OCI_SCENARIO=dump_failure \
    HAKONIWA_PROJECT_DIR="${compose_stub}" \
    HAKONIWA_BACKUP_DIRECTORY="${dump_case}/staging" \
    HAKONIWA_BACKUP_PASSPHRASE_FILE="${passphrase}" \
    bash "${create_script}" >"${dump_case}.out" 2>&1; then
    fail_test 'pg_dump failure succeeded'
fi
if compgen -G "${dump_case}/staging/*.partial*" >/dev/null || compgen -G "${dump_case}/staging/*.dump.enc" >/dev/null; then
    fail_test 'pg_dump failure left a backup artifact'
fi
pass 'pg_dump failure is non-zero and partial output is removed'

actual_openssl="$(command -v openssl)"
openssl_fail_bin="${test_root}/openssl-fail-bin"
mkdir -p "${openssl_fail_bin}"
cat >"${openssl_fail_bin}/openssl" <<EOF
#!/usr/bin/env bash
if [[ "\${1:-}" == 'enc' ]]; then
    cat >/dev/null
    exit 52
fi
exec "${actual_openssl}" "\$@"
EOF
chmod +x "${openssl_fail_bin}/openssl"
openssl_case="${test_root}/openssl-failure"
mkdir -p "${openssl_case}/staging"
if env PATH="${openssl_fail_bin}:${fake_bin}:${PATH}" MOCK_DOCKER_LOG="${openssl_case}/docker.log" MOCK_OCI_SCENARIO=success \
    HAKONIWA_PROJECT_DIR="${compose_stub}" \
    HAKONIWA_BACKUP_DIRECTORY="${openssl_case}/staging" \
    HAKONIWA_BACKUP_PASSPHRASE_FILE="${passphrase}" \
    bash "${create_script}" >"${openssl_case}.out" 2>&1; then
    fail_test 'OpenSSL failure succeeded'
fi
if compgen -G "${openssl_case}/staging/*.partial*" >/dev/null || compgen -G "${openssl_case}/staging/*.dump.enc" >/dev/null; then
    fail_test 'OpenSSL failure left a backup artifact'
fi
pass 'OpenSSL failure is non-zero and partial output is removed'

collision_name='hakoniwa-20260809T001700Z.dump.enc'

unverified_collision="${test_root}/unverified-collision"
mkdir -p "${unverified_collision}/staging"
unverified_collision_file="${unverified_collision}/staging/${collision_name}"
printf 'existing-unverified-backup' >"${unverified_collision_file}"
unverified_before="$(file_sha256 "${unverified_collision_file}")"
if run_canonical_create "${unverified_collision}" >"${unverified_collision}.out" 2>&1; then
    fail_test 'pre-existing unverified backup was replaced'
fi
[[ "$(file_sha256 "${unverified_collision_file}")" == "${unverified_before}" ]] \
    || fail_test 'pre-existing unverified backup content changed'
assert_no_file "${unverified_collision_file}.uploaded"
assert_empty_file "${unverified_collision}/docker.log"
pass 'pre-existing same-name unverified backup fails before Docker and remains byte-for-byte unchanged'

verified_collision="${test_root}/verified-collision"
mkdir -p "${verified_collision}/staging"
verified_collision_file="${verified_collision}/staging/${collision_name}"
printf 'existing-verified-backup' >"${verified_collision_file}"
write_verified_marker "${verified_collision_file}"
verified_file_before="$(file_sha256 "${verified_collision_file}")"
verified_marker_before="$(file_sha256 "${verified_collision_file}.uploaded")"
if run_canonical_create "${verified_collision}" >"${verified_collision}.out" 2>&1; then
    fail_test 'pre-existing verified pair was replaced'
fi
[[ "$(file_sha256 "${verified_collision_file}")" == "${verified_file_before}" ]] \
    || fail_test 'pre-existing verified backup content changed'
[[ "$(file_sha256 "${verified_collision_file}.uploaded")" == "${verified_marker_before}" ]] \
    || fail_test 'pre-existing verification marker changed'
assert_empty_file "${verified_collision}/docker.log"
pass 'verified collision fails before Docker and preserves both local file and content-bound marker'

stale_creator_marker="${test_root}/stale-creator-marker"
mkdir -p "${stale_creator_marker}/staging"
stale_creator_file="${stale_creator_marker}/staging/${collision_name}"
printf 'stale-marker-sentinel\n' >"${stale_creator_file}.uploaded"
stale_marker_before="$(file_sha256 "${stale_creator_file}.uploaded")"
if run_canonical_create "${stale_creator_marker}" >"${stale_creator_marker}.out" 2>&1; then
    fail_test 'pre-existing stale marker allowed backup creation'
fi
assert_no_file "${stale_creator_file}"
[[ "$(file_sha256 "${stale_creator_file}.uploaded")" == "${stale_marker_before}" ]] \
    || fail_test 'pre-existing stale marker changed'
assert_empty_file "${stale_creator_marker}/docker.log"
pass 'pre-existing marker without a file fails before Docker and remains unchanged'

symlink_collision="${test_root}/symlink-collision"
mkdir -p "${symlink_collision}/staging"
symlink_collision_file="${symlink_collision}/staging/${collision_name}"
symlink_target="${symlink_collision}/staging/existing-target"
printf 'symlink-target-sentinel' >"${symlink_target}"
symlink_target_before="$(file_sha256 "${symlink_target}")"
ln -s -- "${symlink_target}" "${symlink_collision_file}"
if run_canonical_create "${symlink_collision}" >"${symlink_collision}.out" 2>&1; then
    fail_test 'pre-existing final symlink allowed backup creation'
fi
[[ -L "${symlink_collision_file}" ]] || fail_test 'pre-existing final symlink was changed'
[[ "$(file_sha256 "${symlink_target}")" == "${symlink_target_before}" ]] \
    || fail_test 'pre-existing symlink target changed'
assert_no_file "${symlink_collision_file}.uploaded"
assert_empty_file "${symlink_collision}/docker.log"
pass 'pre-existing final symlink fails before Docker without changing the link target'

same_second="${test_root}/same-second"
run_canonical_create "${same_second}" >"${same_second}-first.out" 2>&1 \
    || fail_test 'first same-second backup creation failed'
same_second_file="${same_second}/staging/${collision_name}"
same_second_before="$(file_sha256 "${same_second_file}")"
if run_canonical_create "${same_second}" >"${same_second}-second.out" 2>&1; then
    fail_test 'second same-second backup creation replaced the first'
fi
[[ "$(file_sha256 "${same_second_file}")" == "${same_second_before}" ]] \
    || fail_test 'same-second/clock-rollback backup content changed'
assert_no_file "${same_second_file}.uploaded"
assert_empty_file "${same_second}/docker.log"
pass 'same-second and clock-rollback-equivalent creation is atomic no-clobber'

actual_ln="$(command -v ln)"
link_race_bin="${test_root}/link-race-bin"
mkdir -p "${link_race_bin}"
cat >"${link_race_bin}/ln" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
destination="\${!#}"
printf 'concurrent-existing-backup' >"\${destination}"
exec "${actual_ln}" "\$@"
EOF
chmod +x "${link_race_bin}/ln"
atomic_race="${test_root}/atomic-final-race"
mkdir -p "${atomic_race}/staging"
: >"${atomic_race}/docker.log"
if env PATH="${link_race_bin}:${fake_bin}:${PATH}" \
    MOCK_DOCKER_LOG="${atomic_race}/docker.log" MOCK_OCI_SCENARIO=success \
    HAKONIWA_PROJECT_DIR="${compose_stub}" \
    HAKONIWA_BACKUP_DIRECTORY="${atomic_race}/staging" \
    HAKONIWA_BACKUP_PASSPHRASE_FILE="${passphrase}" \
    bash "${create_script}" >"${atomic_race}.out" 2>&1; then
    fail_test 'atomic final-link collision succeeded'
fi
atomic_race_file="${atomic_race}/staging/${collision_name}"
assert_file "${atomic_race_file}"
[[ "$(<"${atomic_race_file}")" == 'concurrent-existing-backup' ]] \
    || fail_test 'atomic no-clobber changed the concurrently-created file'
assert_no_file "${atomic_race_file}.uploaded"
if compgen -G "${atomic_race}/staging/*.partial*" >/dev/null; then
    fail_test 'atomic final-link collision left a partial file'
fi
pass 'atomic hard-link finalization cannot replace a destination created at the link race'

create_failure="${test_root}/create-failure"
if MOCK_CREATE_SCENARIO=failure run_wrapper success "${create_failure}" >"${create_failure}.out" 2>&1; then
    fail_test 'create/encryption failure succeeded'
fi
pass 'wrapper propagates encryption/create failure'

for scenario in docker_failure auth_failure object_storage_unreachable upload_failure; do
    failure_case="${test_root}/${scenario}"
    if run_wrapper "${scenario}" "${failure_case}" >"${failure_case}.out" 2>&1; then
        fail_test "${scenario} succeeded"
    fi
    assert_file "${failure_case}/staging/hakoniwa-20260809T001700Z.dump.enc"
    assert_no_file "${failure_case}/staging/hakoniwa-20260809T001700Z.dump.enc.uploaded"
    pass "${scenario} is non-zero and retains the unverified local backup"
done

duplicate_case="${test_root}/duplicate"
if run_wrapper duplicate_object "${duplicate_case}" >"${duplicate_case}.out" 2>&1; then
    fail_test 'duplicate object response succeeded'
fi
assert_output "${duplicate_case}.out" 'upload_did_not_create_a_new_object'
assert_file "${duplicate_case}/staging/hakoniwa-20260809T001700Z.dump.enc"
assert_no_file "${duplicate_case}/staging/hakoniwa-20260809T001700Z.dump.enc.uploaded"
pass 'duplicate object is not treated as a successful upload'

head_case="${test_root}/head-failure"
if run_wrapper head_failure "${head_case}" >"${head_case}.out" 2>&1; then
    fail_test 'HEAD failure succeeded'
fi
assert_file "${head_case}/staging/hakoniwa-20260809T001700Z.dump.enc"
assert_no_file "${head_case}/staging/hakoniwa-20260809T001700Z.dump.enc.uploaded"
pass 'HEAD failure is non-zero and retains the local backup'

size_case="${test_root}/size-mismatch"
if run_wrapper size_mismatch "${size_case}" >"${size_case}.out" 2>&1; then
    fail_test 'size mismatch succeeded'
fi
assert_output "${size_case}.out" 'remote_content_length_mismatch'
assert_no_file "${size_case}/staging/hakoniwa-20260809T001700Z.dump.enc.uploaded"
pass 'remote content-length mismatch fails closed'

md5_case="${test_root}/md5-mismatch"
if run_wrapper md5_mismatch "${md5_case}" >"${md5_case}.out" 2>&1; then
    fail_test 'MD5 mismatch succeeded'
fi
assert_output "${md5_case}.out" 'remote_content_md5_mismatch'
assert_no_file "${md5_case}/staging/hakoniwa-20260809T001700Z.dump.enc.uploaded"
pass 'remote content-md5 mismatch fails closed'

unsafe_case="${test_root}/unsafe-output"
if MOCK_CREATE_SCENARIO=unsafe_output run_wrapper success "${unsafe_case}" >"${unsafe_case}.out" 2>&1; then
    fail_test 'unsafe create output succeeded'
fi
assert_output "${unsafe_case}.out" 'create_script_returned_an_invalid_backup_path'
assert_empty_file "${unsafe_case}/docker.log"
assert_no_file "${unsafe_case}/staging/hakoniwa-20260809T001700Z.dump.enc.uploaded"
pass 'exact backup path output rejects extra stdout before Docker and marker creation'

symlink_case="${test_root}/reported-symlink"
if MOCK_CREATE_SCENARIO=symlink run_wrapper success "${symlink_case}" >"${symlink_case}.out" 2>&1; then
    fail_test 'reported symlink succeeded'
fi
[[ -L "${symlink_case}/staging/${collision_name}" ]] || fail_test 'reported symlink fixture changed'
[[ "$(<"${symlink_case}/staging/${collision_name}.target")" == 'symlink-target' ]] \
    || fail_test 'reported symlink target content changed'
assert_empty_file "${symlink_case}/docker.log"
assert_no_file "${symlink_case}/staging/${collision_name}.uploaded"
pass 'reported symlink is non-zero before Docker and marker creation without changing its target'

dangling_case="${test_root}/reported-dangling-symlink"
if MOCK_CREATE_SCENARIO=dangling_symlink run_wrapper success "${dangling_case}" >"${dangling_case}.out" 2>&1; then
    fail_test 'reported dangling symlink succeeded'
fi
[[ -L "${dangling_case}/staging/${collision_name}" ]] || fail_test 'dangling symlink fixture changed'
[[ "$(readlink -- "${dangling_case}/staging/${collision_name}")" == \
    "${dangling_case}/staging/${collision_name}.missing" ]] \
    || fail_test 'dangling symlink target changed'
assert_empty_file "${dangling_case}/docker.log"
assert_no_file "${dangling_case}/staging/${collision_name}.uploaded"
pass 'reported dangling symlink is non-zero before Docker and marker creation without changing the link'

outside_case="${test_root}/reported-outside"
if MOCK_CREATE_SCENARIO=outside run_wrapper success "${outside_case}" >"${outside_case}.out" 2>&1; then
    fail_test 'reported staging-external path succeeded'
fi
outside_file="${outside_case}/outside-hakoniwa-20260809T001700Z.dump.enc"
assert_file "${outside_file}"
[[ "$(<"${outside_file}")" == 'outside-payload' ]] \
    || fail_test 'staging-external file content changed'
assert_empty_file "${outside_case}/docker.log"
assert_no_file "${outside_file}.uploaded"
pass 'staging-external path is non-zero before Docker and marker creation without changing the file'

zero_case="${test_root}/reported-zero-byte"
if MOCK_CREATE_SCENARIO=zero_byte run_wrapper success "${zero_case}" >"${zero_case}.out" 2>&1; then
    fail_test 'reported zero-byte backup succeeded'
fi
[[ -f "${zero_case}/staging/${collision_name}" && ! -s "${zero_case}/staging/${collision_name}" ]] \
    || fail_test 'zero-byte fixture changed'
assert_empty_file "${zero_case}/docker.log"
assert_no_file "${zero_case}/staging/${collision_name}.uploaded"
pass 'zero-byte backup is non-zero before Docker and marker creation'

wrapper_marker_case="${test_root}/wrapper-pre-existing-marker"
mkdir -p "${wrapper_marker_case}/staging"
wrapper_marker_file="${wrapper_marker_case}/staging/${collision_name}"
printf 'existing-wrapper-file' >"${wrapper_marker_file}"
write_verified_marker "${wrapper_marker_file}"
wrapper_file_before="$(file_sha256 "${wrapper_marker_file}")"
wrapper_marker_before="$(file_sha256 "${wrapper_marker_file}.uploaded")"
if MOCK_CREATE_SCENARIO=report_existing run_wrapper success "${wrapper_marker_case}" >"${wrapper_marker_case}.out" 2>&1; then
    fail_test 'wrapper accepted a pre-existing marker'
fi
assert_output "${wrapper_marker_case}.out" 'verified_marker_already_exists'
[[ "$(file_sha256 "${wrapper_marker_file}")" == "${wrapper_file_before}" ]] \
    || fail_test 'wrapper changed the pre-existing backup'
[[ "$(file_sha256 "${wrapper_marker_file}.uploaded")" == "${wrapper_marker_before}" ]] \
    || fail_test 'wrapper changed the pre-existing marker'
assert_empty_file "${wrapper_marker_case}/docker.log"
pass 'wrapper rejects a pre-existing marker before Docker without changing the verified pair'

concurrent_case="${test_root}/concurrent"
mkdir -p "${concurrent_case}"
flock "${concurrent_case}/backup.lock" -c "touch '${concurrent_case}/ready'; sleep 5" &
holder_pid=$!
for _ in $(seq 1 100); do
    [[ -e "${concurrent_case}/ready" ]] && break
    sleep 0.02
done
[[ -e "${concurrent_case}/ready" ]] || fail_test 'lock holder did not start'
set +e
run_wrapper success "${concurrent_case}" >"${concurrent_case}.out" 2>&1
concurrent_status=$?
set -e
kill "${holder_pid}" 2>/dev/null || true
wait "${holder_pid}" 2>/dev/null || true
[[ "${concurrent_status}" -eq 75 ]] || fail_test "concurrent execution returned ${concurrent_status}, expected 75"
assert_output "${concurrent_case}.out" 'backup_already_running'
pass 'parallel backup is rejected by non-blocking flock before dump'

retention_case="${test_root}/retention"
mkdir -p "${retention_case}"
retention_timestamps=(
    20260808T000000Z
    20260808T060000Z
    20260808T120000Z
    20260808T180000Z
    20260809T000000Z
    20260809T060000Z
)
for timestamp in "${retention_timestamps[@]}"; do
    backup="${retention_case}/hakoniwa-${timestamp}.dump.enc"
    printf 'verified-%s' "${timestamp}" >"${backup}"
    write_verified_marker "${backup}"
done
unverified="${retention_case}/hakoniwa-20260807T000000Z.dump.enc"
printf 'unverified' >"${unverified}"
HAKONIWA_BACKUP_DIRECTORY="${retention_case}" HAKONIWA_LOCAL_BACKUP_GENERATIONS=4 \
    bash "${prune_script}" >"${retention_case}.out" 2>&1
assert_no_file "${retention_case}/hakoniwa-20260808T000000Z.dump.enc"
assert_no_file "${retention_case}/hakoniwa-20260808T060000Z.dump.enc"
assert_file "${retention_case}/hakoniwa-20260808T120000Z.dump.enc"
assert_file "${retention_case}/hakoniwa-20260809T060000Z.dump.enc"
assert_file "${unverified}"
pass 'local retention keeps four verified generations and never prunes an unverified backup'

stale_marker_case="${test_root}/retention-stale-marker"
mkdir -p "${stale_marker_case}"
stale_marker="${stale_marker_case}/hakoniwa-20260809T000000Z.dump.enc.uploaded"
printf 'object=hakoniwa-20260809T000000Z.dump.enc\nbytes=10\ncontent_md5=AAAAAAAAAAAAAAAAAAAAAA==\nverified_at=2026-08-09T00:17:00Z\n' \
    >"${stale_marker}"
stale_marker_hash="$(file_sha256 "${stale_marker}")"
if HAKONIWA_BACKUP_DIRECTORY="${stale_marker_case}" HAKONIWA_LOCAL_BACKUP_GENERATIONS=4 \
    bash "${prune_script}" >"${stale_marker_case}.out" 2>&1; then
    fail_test 'stale marker without a local backup succeeded'
fi
[[ "$(file_sha256 "${stale_marker}")" == "${stale_marker_hash}" ]] \
    || fail_test 'stale marker changed during failed prune'
pass 'stale marker without its local file is never treated as verified or deleted'

marker_mismatch_case="${test_root}/retention-marker-mismatch"
mkdir -p "${marker_mismatch_case}"
mismatch_files=()
for timestamp in 20260808T000000Z 20260808T060000Z 20260808T120000Z 20260808T180000Z 20260809T000000Z; do
    backup="${marker_mismatch_case}/hakoniwa-${timestamp}.dump.enc"
    printf 'marker-bound-%s' "${timestamp}" >"${backup}"
    write_verified_marker "${backup}"
    mismatch_files+=("${backup}")
done
mismatch_backup="${mismatch_files[0]}"
mismatch_backup_hash="$(file_sha256 "${mismatch_backup}")"
printf 'object=%s\nbytes=999\ncontent_md5=%s\nverified_at=2026-08-09T00:17:00Z\n' \
    "$(basename -- "${mismatch_backup}")" \
    "$(openssl dgst -md5 -binary "${mismatch_backup}" | openssl base64 -A)" \
    >"${mismatch_backup}.uploaded"
mismatch_marker_hash="$(file_sha256 "${mismatch_backup}.uploaded")"
if HAKONIWA_BACKUP_DIRECTORY="${marker_mismatch_case}" HAKONIWA_LOCAL_BACKUP_GENERATIONS=4 \
    bash "${prune_script}" >"${marker_mismatch_case}.out" 2>&1; then
    fail_test 'marker metadata mismatch succeeded'
fi
for backup in "${mismatch_files[@]}"; do
    assert_file "${backup}"
    assert_file "${backup}.uploaded"
done
[[ "$(file_sha256 "${mismatch_backup}")" == "${mismatch_backup_hash}" ]] \
    || fail_test 'marker mismatch changed the local backup'
[[ "$(file_sha256 "${mismatch_backup}.uploaded")" == "${mismatch_marker_hash}" ]] \
    || fail_test 'marker mismatch changed the marker'
pass 'marker metadata mismatch aborts before pruning and preserves every verified pair'

content_mismatch_case="${test_root}/retention-content-mismatch"
mkdir -p "${content_mismatch_case}"
content_mismatch_backup="${content_mismatch_case}/hakoniwa-20260809T001700Z.dump.enc"
printf 'content-A' >"${content_mismatch_backup}"
write_verified_marker "${content_mismatch_backup}"
content_marker_hash="$(file_sha256 "${content_mismatch_backup}.uploaded")"
printf 'content-B' >"${content_mismatch_backup}"
content_mismatch_hash="$(file_sha256 "${content_mismatch_backup}")"
if HAKONIWA_BACKUP_DIRECTORY="${content_mismatch_case}" HAKONIWA_LOCAL_BACKUP_GENERATIONS=4 \
    bash "${prune_script}" >"${content_mismatch_case}.out" 2>&1; then
    fail_test 'same-size local content mismatch succeeded'
fi
[[ "$(file_sha256 "${content_mismatch_backup}")" == "${content_mismatch_hash}" ]] \
    || fail_test 'content mismatch changed the local backup'
[[ "$(file_sha256 "${content_mismatch_backup}.uploaded")" == "${content_marker_hash}" ]] \
    || fail_test 'content mismatch changed the marker'
pass 'marker MD5 is bound to current local content and same-size replacement is never pruned'

printf '1..%d\n' "${passed}"
