#!/usr/bin/env bash

set -Eeuo pipefail

shard_total="${1:-4}"
if [[ ! "$shard_total" =~ ^[1-9][0-9]*$ ]] || ((shard_total > 64)); then
    echo "Shard total must be in the range 1..64." >&2
    exit 2
fi

manifest=""
evidence_directory=""
evidence_metadata=""
discovered_test_files=0
run_started_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
supplied_tested_sha="${HAKONIWA_TESTED_SHA:-}"
if [[ -n "$supplied_tested_sha" && ! "$supplied_tested_sha" =~ ^[0-9a-f]{40}$ ]]; then
    echo 'HAKONIWA_TESTED_SHA must be a 40-character lowercase commit SHA.' >&2
    exit 2
fi
git_tested_sha="$(git rev-parse HEAD 2>/dev/null || true)"
if [[ "$git_tested_sha" =~ ^[0-9a-f]{40}$ ]]; then
    if [[ -n "$supplied_tested_sha" && "$supplied_tested_sha" != "$git_tested_sha" ]]; then
        echo 'HAKONIWA_TESTED_SHA does not match the current git HEAD.' >&2
        exit 2
    fi
    tested_sha="$git_tested_sha"
elif [[ -n "$supplied_tested_sha" ]]; then
    tested_sha="$supplied_tested_sha"
else
    tested_sha="unknown"
fi

sha256_of() {
    local path="$1"
    local hash=""
    if [[ -f "$path" ]]; then
        hash="$(php -r 'echo hash_file("sha256", $argv[1]) ?: "";' "$path" 2>/dev/null || true)"
    fi
    if [[ "$hash" =~ ^[0-9a-f]{64}$ ]]; then
        printf '%s' "$hash"
    else
        printf 'unknown'
    fi
}

php_version="$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"
if [[ ! "$php_version" =~ ^[0-9A-Za-z._+-]+$ ]]; then
    php_version="unknown"
fi
composer_json_sha="$(sha256_of composer.json)"
composer_lock_sha="$(sha256_of composer.lock)"
package_json_sha="$(sha256_of package.json)"
package_lock_sha="$(sha256_of package-lock.json)"

declare -a child_pids=()
declare -a child_logs=()
declare -a child_evidence_logs=()
declare -a child_junits=()
declare -a child_completion_markers=()
declare -a child_started_epochs=()
declare -a child_end_epochs=()
declare -a child_test_file_counts=()
declare -a child_test_counts=()
declare -a child_exit_codes=()
declare -a child_durations=()
declare -a child_recorded=()

append_evidence_line() {
    if [[ -z "$evidence_metadata" ]]; then
        return 0
    fi

    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$@" >>"$evidence_metadata"
}

record_shard_evidence() {
    local index="$1"
    local exit_code="${child_exit_codes[$index]}"
    local duration="${child_durations[$index]}"
    local test_file_count="${child_test_file_counts[$index]}"
    local test_count="${child_test_counts[$index]:-unknown}"
    local status="failed"
    if [[ "$exit_code" == "0" ]]; then
        status="passed"
    fi

    if ! append_evidence_line \
        shard "$index" "$status" "$exit_code" "$duration" "$test_file_count" "$test_count" \
        "phpunit-$(printf '%02d' "$((index + 1))").log" \
        "phpunit-$(printf '%02d' "$((index + 1))").junit.xml"; then
        return 1
    fi
    child_recorded[$index]=1
}

cleanup() {
    local exit_code=$?
    trap - EXIT INT TERM

    for pid in "${child_pids[@]:-}"; do
        if kill -0 "$pid" 2>/dev/null; then
            kill "$pid" 2>/dev/null || true
        fi
    done
    for pid in "${child_pids[@]:-}"; do
        wait "$pid" 2>/dev/null || true
    done

    if [[ -n "$manifest" && -f "$manifest" ]]; then
        for ((index = 0; index < shard_total; index++)); do
            if [[ -z "${child_logs[$index]:-}" || -z "${child_evidence_logs[$index]:-}" ]]; then
                continue
            fi
            if [[ -f "${child_logs[$index]}" ]] && ! cp -- "${child_logs[$index]}" "${child_evidence_logs[$index]}"; then
                echo "Unable to preserve PHPUnit shard log: ${child_evidence_logs[$index]}" >&2
                exit_code=1
            fi
        done

        if [[ -n "$evidence_metadata" ]]; then
            for ((index = 0; index < shard_total; index++)); do
                if [[ -z "${child_pids[$index]:-}" || -n "${child_recorded[$index]:-}" ]]; then
                    continue
                fi
                if ! append_evidence_line \
                    shard "$index" aborted unknown unknown "${child_test_file_counts[$index]:-0}" unknown \
                    "phpunit-$(printf '%02d' "$((index + 1))").log" \
                    "phpunit-$(printf '%02d' "$((index + 1))").junit.xml"; then
                    echo "Unable to preserve aborted PHPUnit shard evidence for shard $index." >&2
                    exit_code=1
                fi
            done
        fi

        cleanup_exit_code=0
        if ! php tests/scripts/parallel_test_databases.php cleanup "$manifest"; then
            echo "Safe test database cleanup failed. Retry with:" >&2
            echo "php tests/scripts/parallel_test_databases.php cleanup $manifest" >&2
            cleanup_exit_code=1
        fi

        final_exit_code=""
        if ! final_exit_code="$(php tests/scripts/parallel_test_databases.php finalize \
            "$run_token" "$exit_code" "$cleanup_exit_code" "$discovered_test_files")" \
            || [[ ! "$final_exit_code" =~ ^[01]$ ]]; then
            echo "Unable to finalize PHPUnit run evidence." >&2
            exit_code=1
        else
            exit_code="$final_exit_code"
        fi
    fi

    exit "$exit_code"
}

trap cleanup EXIT
trap 'exit 130' INT TERM

php artisan config:clear --ansi
shard_report="$(php tests/scripts/test_shards.php verify "$shard_total")"
discovered_test_files="$(printf '%s\n' "$shard_report" | sed -n 's/^total discovered files: \([0-9][0-9]*\)$/\1/p' | head -n 1)"
if [[ ! "$discovered_test_files" =~ ^[0-9]+$ ]]; then
    discovered_test_files=0
fi
run_token="$(php -r 'echo bin2hex(random_bytes(4));')"
manifest="storage/framework/testing/phpunit-parallel-$run_token/manifest.json"
php tests/scripts/parallel_test_databases.php prepare "$shard_total" "$run_token"
echo "Parallel test manifest: $manifest"
evidence_directory="$(php tests/scripts/parallel_test_databases.php evidence "$manifest" directory)"
echo "Parallel test evidence: $evidence_directory"
evidence_metadata="$evidence_directory/run.tsv"
if [[ -e "$evidence_directory" || -L "$evidence_directory" || ! -d "$(dirname "$evidence_directory")" ]]; then
    echo "Parallel test evidence directory already exists or has an unsafe parent: $evidence_directory" >&2
    exit 1
fi
mkdir -p -- "$evidence_directory"
chmod 700 "$evidence_directory"
if ! {
    printf 'schema\thakoniwa.parallel-test-evidence.v1\n'
    printf 'run_token\t%s\n' "$run_token"
    printf 'started_at\t%s\n' "$run_started_at"
    printf 'tested_sha\t%s\n' "$tested_sha"
    printf 'php_version\t%s\n' "$php_version"
    printf 'composer_json_sha256\t%s\n' "$composer_json_sha"
    printf 'composer_lock_sha256\t%s\n' "$composer_lock_sha"
    printf 'package_json_sha256\t%s\n' "$package_json_sha"
    printf 'package_lock_sha256\t%s\n' "$package_lock_sha"
    printf 'artifact_directory\tstorage/framework/testing/test-evidence/phpunit-parallel-%s\n' "$run_token"
    printf 'shard_total\t%d\n' "$shard_total"
    printf 'discovered_test_files\t%d\n' "$discovered_test_files"
    printf 'event\tindex\tstatus\texit_code\tduration_seconds\ttest_file_count\ttest_count\tlog\tjunit\n'
} >"$evidence_metadata"; then
    echo "Unable to initialize PHPUnit evidence at $evidence_metadata." >&2
    exit 1
fi

for ((index = 0; index < shard_total; index++)); do
    test_file_output="$(php tests/scripts/test_shards.php files "$shard_total" "$index")"
    test_files=()
    if [[ -n "$test_file_output" ]]; then
        mapfile -t test_files <<<"$test_file_output"
    fi
    if ((${#test_files[@]} == 0)); then
        append_evidence_line shard "$index" skipped 0 0 0 0 - -
        printf 'Shard %02d/%02d is empty; no PHPUnit process started.\n' "$((index + 1))" "$shard_total"
        continue
    fi

    configuration="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" configuration)"
    database="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" database)"
    log="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" log)"
    evidence_log="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" evidence_log)"
    junit="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" junit)"
    printf 'Starting shard %02d/%02d with %d files.\n' "$((index + 1))" "$shard_total" "${#test_files[@]}"
    child_started_epochs[$index]="$(date +%s)"
    child_logs[$index]="$log"
    child_evidence_logs[$index]="$evidence_log"
    child_junits[$index]="$junit"
    completion_marker="${log}.completed"
    child_completion_markers[$index]="$completion_marker"
    child_test_file_counts[$index]="${#test_files[@]}"
    (
        child_pid=""
        on_child_signal() {
            if [[ -n "$child_pid" ]]; then
                kill "$child_pid" 2>/dev/null || true
            fi
            exit 143
        }
        trap on_child_signal INT TERM

        child_exit_code=0
        APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE="$database" \
        php -d memory_limit=512M vendor/bin/phpunit \
            --configuration "$configuration" \
            --colors=never \
            --log-junit "$junit" \
            "${test_files[@]}" >"$log" 2>&1 &
        child_pid=$!
        wait "$child_pid" || child_exit_code=$?
        printf '%s\n' "$(date +%s)" >"$completion_marker" 2>/dev/null || true
        exit "$child_exit_code"
    ) &
    child_pids[$index]=$!
done

failed=0
for ((index = 0; index < shard_total; index++)); do
    pid="${child_pids[$index]:-}"
    if [[ -z "$pid" ]]; then
        continue
    fi

    shard_exit_code=0
    wait "$pid" || shard_exit_code=$?
    if ((shard_exit_code != 0)); then
        failed=1
    fi

    child_end_epoch=""
    if [[ -f "${child_completion_markers[$index]}" ]]; then
        child_end_epoch="$(<"${child_completion_markers[$index]}")"
    fi
    if [[ ! "$child_end_epoch" =~ ^[0-9]+$ ]]; then
        child_end_epoch="$(date +%s)"
    fi
    child_end_epochs[$index]="$child_end_epoch"
    duration_seconds=$(( child_end_epochs[$index] - child_started_epochs[$index] ))
    if ((duration_seconds < 0)); then
        duration_seconds=0
    fi
    if [[ -f "${child_logs[$index]}" ]] && ! cp -- "${child_logs[$index]}" "${child_evidence_logs[$index]}"; then
        echo "Unable to preserve PHPUnit shard log: ${child_evidence_logs[$index]}" >&2
        failed=1
    fi
    child_exit_codes[$index]="$shard_exit_code"
    child_durations[$index]="$duration_seconds"
    child_test_counts[$index]="$(php -r '
        $document = new DOMDocument;
        if (! $document->load($argv[1], LIBXML_NONET)) {
            exit;
        }
        $xpath = new DOMXPath($document);
        $total = 0;
        foreach ($xpath->query("//testsuite[not(.//testsuite)]") ?: [] as $node) {
            if ($node instanceof DOMElement && preg_match("/^[0-9]+$/", $node->getAttribute("tests")) === 1) {
                $total += (int) $node->getAttribute("tests");
            }
        }
        echo $total;
    ' "${child_junits[$index]}" 2>/dev/null || true)"
    if [[ ! "${child_test_counts[$index]}" =~ ^[0-9]+$ ]]; then
        child_test_counts[$index]=""
    fi
    if ! record_shard_evidence "$index"; then
        echo "Unable to update PHPUnit evidence for shard $index." >&2
        failed=1
    fi

    log="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" log)"
    printf '\n===== PHPUnit shard %02d/%02d =====\n' "$((index + 1))" "$shard_total"
    cat "$log"
done

if ((failed != 0)); then
    echo "One or more PHPUnit shards failed." >&2
    exit 1
fi

echo "All PHPUnit shards passed."
