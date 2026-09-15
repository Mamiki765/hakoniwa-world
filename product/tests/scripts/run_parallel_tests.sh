#!/usr/bin/env bash

set -Eeuo pipefail

shard_total="${1:-4}"
if [[ ! "$shard_total" =~ ^[1-9][0-9]*$ ]] || ((shard_total > 64)); then
    echo "Shard total must be in the range 1..64." >&2
    exit 2
fi

scope_argument="${2:-full}"
if (($# >= 2)); then
    shift 2
elif (($# == 1)); then
    shift
fi
phpunit_arguments=("$@")

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
source_tree_sha="$(php -r '
    $roots = [
        "app", "bootstrap/app.php", "bootstrap/providers.php", "config", "database",
        "resources", "routes", "tests", "artisan", "phpunit.xml", "phpstan.neon",
        "composer.json", "composer.lock", "package.json", "package-lock.json",
    ];
    $files = [];
    foreach ($roots as $root) {
        if (is_file($root)) {
            $files[] = $root;
            continue;
        }
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace(DIRECTORY_SEPARATOR, "/", $file->getPathname());
            }
        }
    }
    sort($files, SORT_STRING);
    $hash = hash_init("sha256");
    foreach ($files as $file) {
        hash_update($hash, $file."\0".(hash_file("sha256", $file) ?: "unknown")."\n");
    }
    echo hash_final($hash);
' 2>/dev/null || true)"
if [[ ! "$source_tree_sha" =~ ^[0-9a-f]{64}$ ]]; then
    source_tree_sha="unknown"
fi
working_tree_dirty="unknown"
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    if [[ -n "$(git status --porcelain --untracked-files=all 2>/dev/null)" ]]; then
        working_tree_dirty="true"
    else
        working_tree_dirty="false"
    fi
fi

declare -a child_pids=()
declare -a child_logs=()
declare -a child_evidence_logs=()
declare -a child_junits=()
declare -a child_completion_markers=()
declare -a child_profile_plans=()
declare -a child_started_epochs=()
declare -a child_end_epochs=()
declare -a child_test_file_counts=()
declare -a child_test_counts=()
declare -a child_exit_codes=()
declare -a child_durations=()
declare -a child_recorded=()
fixture_profiles=(standard reusable_surface individual)
declare -A fixture_file_counts=()
declare -A fixture_logs=()
declare -A fixture_evidence_logs=()
declare -A fixture_junits=()
declare -A fixture_metrics=()
declare -A fixture_completion_markers=()

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
        for key in "${!fixture_logs[@]}"; do
            if [[ -f "${fixture_logs[$key]}" ]] \
                && ! cp -- "${fixture_logs[$key]}" "${fixture_evidence_logs[$key]}"; then
                echo "Unable to preserve PHPUnit fixture log: ${fixture_evidence_logs[$key]}" >&2
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
shard_report="$(php tests/scripts/test_shards.php verify "$shard_total" "$scope_argument")"
scope="$(printf '%s\n' "$shard_report" | sed -n 's/^scope: \(full\|surface\|underground\)$/\1/p' | head -n 1)"
if [[ -z "$scope" ]]; then
    echo 'Unable to determine normalized test scope.' >&2
    exit 1
fi
discovered_test_files="$(printf '%s\n' "$shard_report" | sed -n 's/^total discovered files: \([0-9][0-9]*\)$/\1/p' | head -n 1)"
if [[ ! "$discovered_test_files" =~ ^[0-9]+$ ]]; then
    discovered_test_files=0
fi
run_token="$(php -r 'echo bin2hex(random_bytes(4));')"
manifest="storage/framework/testing/phpunit-parallel-$run_token/manifest.json"
php tests/scripts/parallel_test_databases.php prepare "$shard_total" "$scope" "$run_token"
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
selected_test_files="$(php tests/scripts/test_shards.php files 1 0 "$scope")"
selected_test_files_sha256="$(printf '%s\n' "$selected_test_files" | php -r 'echo hash("sha256", stream_get_contents(STDIN));')"
selection_mode="scope"
if ((${#phpunit_arguments[@]} != 0)); then
    selection_mode="focused"
fi
if ! {
    printf 'schema\thakoniwa.parallel-test-evidence.v1\n'
    printf 'run_token\t%s\n' "$run_token"
    printf 'started_at\t%s\n' "$run_started_at"
    printf 'tested_sha\t%s\n' "$tested_sha"
    printf 'working_tree_dirty\t%s\n' "$working_tree_dirty"
    printf 'source_tree_sha256\t%s\n' "$source_tree_sha"
    printf 'scope\t%s\n' "$scope"
    printf 'selection_mode\t%s\n' "$selection_mode"
    printf 'selected_test_files_sha256\t%s\n' "$selected_test_files_sha256"
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

assignment_metadata="$evidence_directory/assignment.tsv"
printf 'shard_index\ttest_file\n' >"$assignment_metadata"

for ((index = 0; index < shard_total; index++)); do
    test_file_output="$(php tests/scripts/test_shards.php files "$shard_total" "$index" "$scope")"
    test_files=()
    if [[ -n "$test_file_output" ]]; then
        mapfile -t test_files <<<"$test_file_output"
    fi
    if ((${#test_files[@]} == 0)); then
        printf '%d\t-\n' "$index" >>"$assignment_metadata"
        append_evidence_line shard "$index" skipped 0 0 0 0 - -
        printf 'Shard %02d/%02d is empty; no PHPUnit process started.\n' "$((index + 1))" "$shard_total"
        continue
    fi
    for test_file in "${test_files[@]}"; do
        printf '%d\t%s\n' "$index" "$test_file" >>"$assignment_metadata"
    done

    configuration="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" configuration)"
    database="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" database)"
    log="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" log)"
    evidence_log="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" evidence_log)"
    junit="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" junit)"
    profile_plan="${configuration%.xml}.profiles.tsv"
    php tests/scripts/test_shards.php profiles "$shard_total" "$index" "$scope" >"$profile_plan"
    if [[ "$(wc -l < "$profile_plan")" -ne "${#test_files[@]}" ]]; then
        echo "Fixture profile plan does not cover shard $index exactly once." >&2
        exit 1
    fi
    for profile in "${fixture_profiles[@]}"; do
        key="$index:$profile"
        fixture_file_counts[$key]="$(awk -F '\t' -v selected="$profile" '$1 == selected { count++ } END { print count + 0 }' "$profile_plan")"
        if [[ "${fixture_file_counts[$key]}" == "0" ]]; then
            continue
        fi
        fixture_logs[$key]="$(php tests/scripts/parallel_test_databases.php fixture "$manifest" "$index" "$profile" log)"
        fixture_evidence_logs[$key]="$(php tests/scripts/parallel_test_databases.php fixture "$manifest" "$index" "$profile" evidence_log)"
        fixture_junits[$key]="$(php tests/scripts/parallel_test_databases.php fixture "$manifest" "$index" "$profile" junit)"
        fixture_metrics[$key]="$(php tests/scripts/parallel_test_databases.php fixture "$manifest" "$index" "$profile" fixture_metrics)"
        fixture_completion_markers[$key]="$(php tests/scripts/parallel_test_databases.php fixture "$manifest" "$index" "$profile" completion)"
    done
    printf 'Starting shard %02d/%02d with %d files.\n' "$((index + 1))" "$shard_total" "${#test_files[@]}"
    child_started_epochs[$index]="$(date +%s)"
    child_logs[$index]="$log"
    child_evidence_logs[$index]="$evidence_log"
    child_junits[$index]="$junit"
    child_profile_plans[$index]="$profile_plan"
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

        : >"$log"
        child_exit_code=0
        profile_junit_inputs=()
        for profile in "${fixture_profiles[@]}"; do
            profile_files=()
            mapfile -t profile_files < <(awk -F '\t' -v selected="$profile" '$1 == selected { print $2 }' "$profile_plan")
            if ((${#profile_files[@]} == 0)); then
                continue
            fi
            key="$index:$profile"
            profile_log="${fixture_logs[$key]}"
            profile_junit="${fixture_junits[$key]}"
            profile_metrics="${fixture_metrics[$key]}"
            profile_completion="${fixture_completion_markers[$key]}"
            if ((${#phpunit_arguments[@]} != 0)); then
                list_exit_code=0
                HAKONIWA_TEST_FIXTURE_PROFILE="$profile" \
                APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE="$database" \
                php -d memory_limit=512M vendor/bin/phpunit \
                    --configuration "$configuration" \
                    --list-tests \
                    --colors=never \
                    "${phpunit_arguments[@]}" \
                    "${profile_files[@]}" >"$profile_log" 2>&1 || list_exit_code=$?
                if ((list_exit_code != 0)); then
                    printf '%d\t0\tfailed\n' "$list_exit_code" >"$profile_completion" 2>/dev/null || true
                    printf '\n===== Fixture %s list failure =====\n' "$profile" >>"$log"
                    cat "$profile_log" >>"$log"
                    child_exit_code="$list_exit_code"
                    break
                fi
                matching_identifier_count="$(sed -n '/^ - /p' "$profile_log" | wc -l)"
                if ((matching_identifier_count == 0)); then
                    printf '0\t0\tfiltered\n' >"$profile_completion" 2>/dev/null || true
                    continue
                fi
            fi
            profile_started_epoch="$(date +%s)"
            profile_exit_code=0
            HAKONIWA_TEST_FIXTURE_PROFILE="$profile" \
            HAKONIWA_TEST_FIXTURE_METRICS="$profile_metrics" \
            APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE="$database" \
            php -d memory_limit=512M vendor/bin/phpunit \
                --configuration "$configuration" \
                --colors=never \
                --log-junit "$profile_junit" \
                "${phpunit_arguments[@]}" \
                "${profile_files[@]}" >"$profile_log" 2>&1 &
            child_pid=$!
            wait "$child_pid" || profile_exit_code=$?
            profile_duration="$(( $(date +%s) - profile_started_epoch ))"
            printf '%d\t%d\tcompleted\n' "$profile_exit_code" "$profile_duration" >"$profile_completion" 2>/dev/null || true
            printf '\n===== Fixture %s =====\n' "$profile" >>"$log"
            cat "$profile_log" >>"$log"
            profile_junit_inputs+=("$profile_junit")
            if ((profile_exit_code != 0)); then
                child_exit_code="$profile_exit_code"
                break
            fi
        done
        if ((${#profile_junit_inputs[@]} == 0)); then
            if ! php tests/scripts/merge_junit.php "$junit"; then
                child_exit_code=1
            fi
        elif ! php tests/scripts/merge_junit.php "$junit" "${profile_junit_inputs[@]}"; then
            child_exit_code=1
        fi
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
    for profile in "${fixture_profiles[@]}"; do
        key="$index:$profile"
        if [[ "${fixture_file_counts[$key]:-0}" == "0" ]]; then
            continue
        fi
        profile_status="aborted"
        profile_exit_code="unknown"
        profile_duration="unknown"
        if [[ -f "${fixture_completion_markers[$key]}" ]]; then
            profile_completion_status=""
            IFS=$'\t' read -r profile_exit_code profile_duration profile_completion_status \
                <"${fixture_completion_markers[$key]}" || true
            if [[ "$profile_completion_status" == "filtered" ]]; then
                profile_status="filtered"
            elif [[ "$profile_exit_code" == "0" ]]; then
                profile_status="passed"
            elif [[ "$profile_exit_code" =~ ^[0-9]+$ ]]; then
                profile_status="failed"
            fi
        fi
        if [[ -f "${fixture_logs[$key]}" ]] \
            && ! cp -- "${fixture_logs[$key]}" "${fixture_evidence_logs[$key]}"; then
            echo "Unable to preserve PHPUnit fixture log: ${fixture_evidence_logs[$key]}" >&2
            failed=1
        fi
        profile_test_count="$(php -r '
            $document = new DOMDocument;
            if (! $document->load($argv[1], LIBXML_NONET)) {
                exit;
            }
            echo (new DOMXPath($document))->query("//testcase")?->length ?? 0;
        ' "${fixture_junits[$key]}" 2>/dev/null || true)"
        if [[ "$profile_status" == "filtered" ]]; then
            profile_test_count=0
        elif [[ ! "$profile_test_count" =~ ^[0-9]+$ ]]; then
            profile_test_count="unknown"
        fi
        append_evidence_line \
            "fixture:$profile" "$index" "$profile_status" "$profile_exit_code" "$profile_duration" \
            "${fixture_file_counts[$key]}" "$profile_test_count" \
            "$(basename "${fixture_evidence_logs[$key]}")" "$(basename "${fixture_junits[$key]}")"
        if [[ -f "${fixture_metrics[$key]}" ]]; then
            map_generation_count="$(sed -n 's/^map_generation_count\t//p' "${fixture_metrics[$key]}")"
            map_generation_seconds="$(sed -n 's/^map_generation_seconds\t//p' "${fixture_metrics[$key]}")"
            printf 'fixture_metric\t%d\t%s\t%s\t%s\n' \
                "$index" "$profile" "${map_generation_count:-unknown}" "${map_generation_seconds:-unknown}" \
                >>"$evidence_metadata"
        fi
    done
    child_exit_codes[$index]="$shard_exit_code"
    child_durations[$index]="$duration_seconds"
    child_test_counts[$index]="$(php -r '
        $document = new DOMDocument;
        if (! $document->load($argv[1], LIBXML_NONET)) {
            exit;
        }
        $xpath = new DOMXPath($document);
        echo $xpath->query("//testcase")?->length ?? 0;
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

identifier_summary="$(php -r '
    $identifiers = [];
    foreach (array_slice($argv, 1) as $path) {
        $document = new DOMDocument;
        if (! $document->load($path, LIBXML_NONET)) {
            fwrite(STDERR, "Unable to read JUnit identifier source: {$path}\n");
            exit(1);
        }
        $xpath = new DOMXPath($document);
        foreach ($xpath->query("//testcase") ?: [] as $testcase) {
            if ($testcase instanceof DOMElement) {
                $identifiers[] = $testcase->getAttribute("class")."::".$testcase->getAttribute("name");
            }
        }
    }
    sort($identifiers, SORT_STRING);
    echo count($identifiers)."\t".count(array_unique($identifiers))."\t".hash("sha256", implode("\n", $identifiers));
' "${child_junits[@]}" 2>/dev/null || true)"
if [[ "$identifier_summary" =~ ^([0-9]+)$'\t'([0-9]+)$'\t'([0-9a-f]{64})$ ]]; then
    executed_test_identifier_count="${BASH_REMATCH[1]}"
    printf 'executed_test_identifiers\t%s\n' "${BASH_REMATCH[1]}" >>"$evidence_metadata"
    printf 'unique_test_identifiers\t%s\n' "${BASH_REMATCH[2]}" >>"$evidence_metadata"
    printf 'executed_test_identifiers_sha256\t%s\n' "${BASH_REMATCH[3]}" >>"$evidence_metadata"
else
    printf 'executed_test_identifiers\tunknown\n' >>"$evidence_metadata"
    printf 'unique_test_identifiers\tunknown\n' >>"$evidence_metadata"
    printf 'executed_test_identifiers_sha256\tunknown\n' >>"$evidence_metadata"
fi

if ((${#phpunit_arguments[@]} != 0)) && [[ "${executed_test_identifier_count:-0}" == "0" ]]; then
    echo 'Focused PHPUnit selection matched no test identifiers.' >&2
    failed=1
fi

if ((failed != 0)); then
    echo "One or more PHPUnit shards failed." >&2
    exit 1
fi

echo "All PHPUnit shards passed."
