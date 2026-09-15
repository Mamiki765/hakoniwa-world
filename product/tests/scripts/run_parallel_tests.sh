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
run_started_ns="$(date +%s%N)"

elapsed_seconds() {
    awk -v started="$1" -v ended="$2" 'BEGIN { printf "%.6f", (ended - started) / 1000000000 }'
}

selection_report="$(php tests/scripts/test_shards.php selection-classify "${phpunit_arguments[@]}")"
selection_mode="$(printf '%s\n' "$selection_report" | sed -n 's/^selection_mode: \(scope\|focused\)$/\1/p')"
has_test_inputs="$(printf '%s\n' "$selection_report" | sed -n 's/^has test inputs: \(yes\|no\)$/\1/p')"
if [[ -z "$selection_mode" || -z "$has_test_inputs" ]]; then
    echo 'Unable to classify PHPUnit selection arguments.' >&2
    exit 2
fi
phpunit_passthrough_arguments=()
mapfile -d '' -t phpunit_passthrough_arguments \
    < <(php tests/scripts/test_shards.php selection-passthrough "${phpunit_arguments[@]}")
argument_classification_seconds="$(elapsed_seconds "$run_started_ns" "$(date +%s%N)")"
metadata_started_ns="$(date +%s%N)"

manifest=""
plan_file=""
scope_plan_file=""
focused_plan_file=""
selection_xml=""
selection_log=""
evidence_directory=""
evidence_metadata=""
template_enabled="no"
template_fingerprint=""
template_database=""
template_cache_hit=""
template_input_count=""
template_inputs_sha256=""
template_build_database=""
template_build_seconds=""
template_build_migration_seconds=""
template_build_map_generation_count=""
template_build_map_generation_seconds=""
template_build_log=""
template_build_metrics=""
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
source_tree_sha="unknown"
if [[ "$selection_mode" == "scope" ]]; then
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
fi
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
metadata_seconds="$(elapsed_seconds "$metadata_started_ns" "$(date +%s%N)")"

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

        cleanup_started_ns="$(date +%s%N)"
        cleanup_exit_code=0
        if ! php tests/scripts/parallel_test_databases.php cleanup "$manifest"; then
            echo "Safe test database cleanup failed. Retry with:" >&2
            echo "php tests/scripts/parallel_test_databases.php cleanup $manifest" >&2
            cleanup_exit_code=1
        fi
        cleanup_seconds="$(elapsed_seconds "$cleanup_started_ns" "$(date +%s%N)")"
        append_evidence_line stage cleanup "$cleanup_seconds"
        append_evidence_line stage total_through_cleanup \
            "$(elapsed_seconds "$run_started_ns" "$(date +%s%N)")"

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

    for temporary_path in "$plan_file" "$scope_plan_file" "$focused_plan_file" "$selection_xml" "$selection_log"; do
        if [[ -n "$temporary_path" && -f "$temporary_path" && ! -L "$temporary_path" ]]; then
            rm -- "$temporary_path"
        fi
    done

    exit "$exit_code"
}

trap cleanup EXIT
trap 'exit 130' INT TERM

config_clear_started_ns="$(date +%s%N)"
if [[ -f bootstrap/cache/config.php && ! -L bootstrap/cache/config.php ]]; then
    php artisan config:clear --ansi
else
    echo 'Laravel configuration cache is absent; config:clear skipped.'
fi
config_clear_seconds="$(elapsed_seconds "$config_clear_started_ns" "$(date +%s%N)")"
run_token="$(php -r 'echo bin2hex(random_bytes(4));')"
scope_plan_file="storage/framework/testing/phpunit-parallel-$run_token.scope-plan.json"
plan_file="$scope_plan_file"
scope_plan_started_ns="$(date +%s%N)"
shard_report="$(
    HAKONIWA_PLAN_SOURCE_TREE_SHA256="$source_tree_sha" \
    HAKONIWA_PLAN_COMPOSER_LOCK_SHA256="$composer_lock_sha" \
    HAKONIWA_PLAN_SKIP_HISTORICAL_TIMING="$([[ "$selection_mode" == "focused" ]] && printf 1 || printf 0)" \
    php tests/scripts/test_shards.php plan "$shard_total" "$scope_argument" "$scope_plan_file"
)"
scope_plan_seconds="$(elapsed_seconds "$scope_plan_started_ns" "$(date +%s%N)")"
printf '%s\n' "$shard_report"
scope="$(printf '%s\n' "$shard_report" | sed -n 's/^scope: \(full\|surface\|underground\)$/\1/p' | head -n 1)"
if [[ -z "$scope" ]]; then
    echo 'Unable to determine normalized test scope.' >&2
    exit 1
fi
if [[ "$selection_mode" == "focused" ]]; then
    scope_test_file_output="$(php tests/scripts/test_shards.php plan-list "$scope_plan_file")"
    scope_test_files=()
    if [[ -n "$scope_test_file_output" ]]; then
        mapfile -t scope_test_files <<<"$scope_test_file_output"
    fi
    selection_xml="storage/framework/testing/phpunit-parallel-$run_token.selection.xml"
    selection_log="storage/framework/testing/phpunit-parallel-$run_token.selection.log"
    phpunit_list_started_ns="$(date +%s%N)"
    list_exit_code=0
    if [[ "$has_test_inputs" == "yes" ]]; then
        APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=hakoniwa_test \
        php -d memory_limit=512M vendor/bin/phpunit \
            --configuration phpunit.xml \
            --list-tests-xml "$selection_xml" \
            --colors=never \
            "${phpunit_arguments[@]}" >"$selection_log" 2>&1 || list_exit_code=$?
    else
        APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=hakoniwa_test \
        php -d memory_limit=512M vendor/bin/phpunit \
            --configuration phpunit.xml \
            --list-tests-xml "$selection_xml" \
            --colors=never \
            "${phpunit_arguments[@]}" \
            "${scope_test_files[@]}" >"$selection_log" 2>&1 || list_exit_code=$?
    fi
    if ((list_exit_code != 0)); then
        cat "$selection_log" >&2
        exit "$list_exit_code"
    fi
    phpunit_list_seconds="$(elapsed_seconds "$phpunit_list_started_ns" "$(date +%s%N)")"
    focused_plan_file="storage/framework/testing/phpunit-parallel-$run_token.focused-plan.json"
    plan_file="$focused_plan_file"
    focused_plan_started_ns="$(date +%s%N)"
    shard_report="$(php tests/scripts/test_shards.php plan-focus \
        "$scope_plan_file" "$selection_xml" "$focused_plan_file")"
    focused_plan_seconds="$(elapsed_seconds "$focused_plan_started_ns" "$(date +%s%N)")"
    printf '%s\n' "$shard_report"
    printf 'Focused setup: arguments=%ss metadata=%ss config_clear=%ss scope_plan=%ss phpunit_list=%ss focused_plan=%ss\n' \
        "$argument_classification_seconds" "$metadata_seconds" "$config_clear_seconds" \
        "$scope_plan_seconds" "$phpunit_list_seconds" "$focused_plan_seconds"
fi
discovered_test_files="$(printf '%s\n' "$shard_report" | sed -n 's/^total discovered files: \([0-9][0-9]*\)$/\1/p' | head -n 1)"
if [[ ! "$discovered_test_files" =~ ^[0-9]+$ ]]; then
    discovered_test_files=0
fi
manifest="storage/framework/testing/phpunit-parallel-$run_token/manifest.json"
selection_seconds="$(elapsed_seconds "$run_started_ns" "$(date +%s%N)")"
database_prepare_started_ns="$(date +%s%N)"
php tests/scripts/parallel_test_databases.php prepare "$shard_total" "$scope" "$run_token" "$plan_file"
database_prepare_seconds="$(elapsed_seconds "$database_prepare_started_ns" "$(date +%s%N)")"
echo "Parallel test manifest: $manifest"
template_report="$(php tests/scripts/parallel_test_databases.php template "$manifest")"
template_enabled="$(printf '%s\n' "$template_report" | sed -n 's/^enabled: \(yes\|no\)$/\1/p')"
if [[ "$template_enabled" == "yes" ]]; then
    template_fingerprint="$(printf '%s\n' "$template_report" | sed -n 's/^fingerprint: \([a-f0-9]\{64\}\)$/\1/p')"
    template_database="$(printf '%s\n' "$template_report" | sed -n 's/^database: \(hakoniwa_surface_fixture_[a-f0-9]\{16\}_template\)$/\1/p')"
    template_cache_hit="$(printf '%s\n' "$template_report" | sed -n 's/^cache_hit: \(yes\|no\)$/\1/p')"
    template_input_count="$(printf '%s\n' "$template_report" | sed -n 's/^input_count: \([1-9][0-9]*\)$/\1/p')"
    template_inputs_sha256="$(printf '%s\n' "$template_report" | sed -n 's/^inputs_sha256: \([a-f0-9]\{64\}\)$/\1/p')"
    template_build_database="$(printf '%s\n' "$template_report" | sed -n 's/^build_database: \(.*\)$/\1/p')"
    template_build_seconds="$(printf '%s\n' "$template_report" | sed -n 's/^build_seconds: \([0-9][0-9]*\.[0-9][0-9]*\)$/\1/p')"
    template_build_migration_seconds="$(printf '%s\n' "$template_report" | sed -n 's/^build_migration_seconds: \([0-9][0-9]*\.[0-9][0-9]*\)$/\1/p')"
    template_build_map_generation_count="$(printf '%s\n' "$template_report" | sed -n 's/^build_map_generation_count: \([0-9][0-9]*\)$/\1/p')"
    template_build_map_generation_seconds="$(printf '%s\n' "$template_report" | sed -n 's/^build_map_generation_seconds: \([0-9][0-9]*\.[0-9][0-9]*\)$/\1/p')"
    template_build_log="$(printf '%s\n' "$template_report" | sed -n 's/^build_log: \(.*\)$/\1/p')"
    template_build_metrics="$(printf '%s\n' "$template_report" | sed -n 's/^build_metrics: \(.*\)$/\1/p')"
    if [[ -z "$template_fingerprint" || -z "$template_database" || -z "$template_cache_hit" \
        || -z "$template_input_count" || -z "$template_inputs_sha256" \
        || -z "$template_build_seconds" || -z "$template_build_migration_seconds" \
        || -z "$template_build_map_generation_count" || -z "$template_build_map_generation_seconds" ]]; then
        echo 'Reusable surface template report is invalid.' >&2
        exit 1
    fi
elif [[ "$template_enabled" != "no" ]]; then
    echo 'Unable to determine reusable surface template mode.' >&2
    exit 1
fi
evidence_directory="$(php tests/scripts/parallel_test_databases.php evidence "$manifest" directory)"
echo "Parallel test evidence: $evidence_directory"
evidence_metadata="$evidence_directory/run.tsv"
if [[ -e "$evidence_directory" || -L "$evidence_directory" || ! -d "$(dirname "$evidence_directory")" ]]; then
    echo "Parallel test evidence directory already exists or has an unsafe parent: $evidence_directory" >&2
    exit 1
fi
mkdir -p -- "$evidence_directory"
chmod 700 "$evidence_directory"
cp -- "$plan_file" "$evidence_directory/shard-plan.json"
selected_test_files_sha256="$(php tests/scripts/test_shards.php plan-files-sha256 "$plan_file")"
assignment_strategy="$(printf '%s\n' "$shard_report" | sed -n 's/^assignment strategy: \(.*\)$/\1/p' | head -n 1)"
historical_timing_files="$(printf '%s\n' "$shard_report" | sed -n 's/^historical timing files: \([0-9][0-9]*\)$/\1/p' | head -n 1)"
historical_timing_sources="$(printf '%s\n' "$shard_report" | sed -n 's/^historical timing sources: \([0-9][0-9]*\)$/\1/p' | head -n 1)"
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
    printf 'assignment_strategy\t%s\n' "$assignment_strategy"
    printf 'historical_timing_files\t%s\n' "$historical_timing_files"
    printf 'historical_timing_sources\t%s\n' "$historical_timing_sources"
    printf 'shard_plan\tshard-plan.json\n'
    printf 'php_version\t%s\n' "$php_version"
    printf 'composer_json_sha256\t%s\n' "$composer_json_sha"
    printf 'composer_lock_sha256\t%s\n' "$composer_lock_sha"
    printf 'package_json_sha256\t%s\n' "$package_json_sha"
    printf 'package_lock_sha256\t%s\n' "$package_lock_sha"
    printf 'artifact_directory\tstorage/framework/testing/test-evidence/phpunit-parallel-%s\n' "$run_token"
    printf 'shard_total\t%d\n' "$shard_total"
    printf 'discovered_test_files\t%d\n' "$discovered_test_files"
    printf 'stage\tcommand_and_selection\t%s\n' "$selection_seconds"
    printf 'stage\tdatabase_prepare\t%s\n' "$database_prepare_seconds"
    printf 'reusable_surface_template_enabled\t%s\n' "$template_enabled"
    if [[ "$template_enabled" == "yes" ]]; then
        printf 'reusable_surface_template_fingerprint\t%s\n' "$template_fingerprint"
        printf 'reusable_surface_template_database\t%s\n' "$template_database"
        printf 'reusable_surface_template_cache_hit\t%s\n' "$template_cache_hit"
        printf 'reusable_surface_template_input_count\t%s\n' "$template_input_count"
        printf 'reusable_surface_template_inputs_sha256\t%s\n' "$template_inputs_sha256"
        printf 'reusable_surface_template_build_database\t%s\n' "$template_build_database"
        printf 'reusable_surface_template_build_seconds\t%s\n' "$template_build_seconds"
        printf 'reusable_surface_template_build_migration_seconds\t%s\n' "$template_build_migration_seconds"
        printf 'reusable_surface_template_build_map_generation_count\t%s\n' "$template_build_map_generation_count"
        printf 'reusable_surface_template_build_map_generation_seconds\t%s\n' "$template_build_map_generation_seconds"
    fi
    printf 'event\tindex\tstatus\texit_code\tduration_seconds\ttest_file_count\ttest_count\tlog\tjunit\n'
} >"$evidence_metadata"; then
    echo "Unable to initialize PHPUnit evidence at $evidence_metadata." >&2
    exit 1
fi
if [[ "$template_enabled" == "yes" && "$template_cache_hit" == "no" ]]; then
    if [[ ! -f "$template_build_log" || -L "$template_build_log" \
        || ! -f "$template_build_metrics" || -L "$template_build_metrics" ]]; then
        echo 'Reusable surface template build evidence is missing or unsafe.' >&2
        exit 1
    fi
    cp -- "$template_build_log" "$evidence_directory/reusable-surface-template.log"
    cp -- "$template_build_metrics" "$evidence_directory/reusable-surface-template.fixture.tsv"
fi

assignment_metadata="$evidence_directory/assignment.tsv"
printf 'shard_index\ttest_file\n' >"$assignment_metadata"

for ((index = 0; index < shard_total; index++)); do
    test_file_output="$(php tests/scripts/test_shards.php plan-files "$plan_file" "$index")"
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
    printf 'shard_database\t%d\t%s\n' "$index" "$database" >>"$evidence_metadata"
    log="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" log)"
    evidence_log="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" evidence_log)"
    junit="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" junit)"
    profile_plan="${configuration%.xml}.profiles.tsv"
    php tests/scripts/test_shards.php plan-profiles "$plan_file" "$index" >"$profile_plan"
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
            profile_started_ns="$(date +%s%N)"
            profile_exit_code=0
            reusable_surface_template_mode=""
            reusable_surface_template_fingerprint=""
            if [[ "$profile" == "reusable_surface" && "$template_enabled" == "yes" ]]; then
                reusable_surface_template_mode="clone"
                reusable_surface_template_fingerprint="$template_fingerprint"
            fi
            HAKONIWA_TEST_FIXTURE_PROFILE="$profile" \
            HAKONIWA_TEST_FIXTURE_METRICS="$profile_metrics" \
            HAKONIWA_REUSABLE_SURFACE_TEMPLATE_MODE="$reusable_surface_template_mode" \
            HAKONIWA_REUSABLE_SURFACE_TEMPLATE_FINGERPRINT="$reusable_surface_template_fingerprint" \
            APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE="$database" \
            php -d memory_limit=512M vendor/bin/phpunit \
                --configuration "$configuration" \
                --colors=never \
                --log-junit "$profile_junit" \
                "${phpunit_passthrough_arguments[@]}" \
                "${profile_files[@]}" >"$profile_log" 2>&1 &
            child_pid=$!
            wait "$child_pid" || profile_exit_code=$?
            profile_duration="$(elapsed_seconds "$profile_started_ns" "$(date +%s%N)")"
            printf '%d\t%s\tcompleted\n' "$profile_exit_code" "$profile_duration" >"$profile_completion" 2>/dev/null || true
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
            migration_seconds="$(sed -n 's/^migration_seconds\t//p' "${fixture_metrics[$key]}")"
            map_generation_count="$(sed -n 's/^map_generation_count\t//p' "${fixture_metrics[$key]}")"
            map_generation_seconds="$(sed -n 's/^map_generation_seconds\t//p' "${fixture_metrics[$key]}")"
            printf 'fixture_metric\t%d\t%s\t%s\t%s\t%s\n' \
                "$index" "$profile" "${migration_seconds:-unknown}" \
                "${map_generation_count:-unknown}" "${map_generation_seconds:-unknown}" \
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

if [[ "$selection_mode" == "focused" ]] && [[ "${executed_test_identifier_count:-0}" == "0" ]]; then
    echo 'Focused PHPUnit selection matched no test identifiers.' >&2
    failed=1
fi

if ((failed != 0)); then
    echo "One or more PHPUnit shards failed." >&2
    exit 1
fi

echo "All PHPUnit shards passed."
