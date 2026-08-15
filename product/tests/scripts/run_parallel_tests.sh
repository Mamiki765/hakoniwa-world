#!/usr/bin/env bash

set -Eeuo pipefail

shard_total="${1:-4}"
if [[ ! "$shard_total" =~ ^[1-9][0-9]*$ ]] || ((shard_total > 64)); then
    echo "Shard total must be in the range 1..64." >&2
    exit 2
fi

manifest=""
declare -a child_pids=()

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
        if ! php tests/scripts/parallel_test_databases.php cleanup "$manifest"; then
            echo "Safe test database cleanup failed. Retry with:" >&2
            echo "php tests/scripts/parallel_test_databases.php cleanup $manifest" >&2
            exit_code=1
        fi
    fi

    exit "$exit_code"
}

trap cleanup EXIT
trap 'exit 130' INT TERM

php artisan config:clear --ansi
php tests/scripts/test_shards.php verify "$shard_total"
run_token="$(php -r 'echo bin2hex(random_bytes(4));')"
manifest="storage/framework/testing/phpunit-parallel-$run_token/manifest.json"
php tests/scripts/parallel_test_databases.php prepare "$shard_total" "$run_token"
echo "Parallel test manifest: $manifest"

for ((index = 0; index < shard_total; index++)); do
    test_file_output="$(php tests/scripts/test_shards.php files "$shard_total" "$index")"
    test_files=()
    if [[ -n "$test_file_output" ]]; then
        mapfile -t test_files <<<"$test_file_output"
    fi
    if ((${#test_files[@]} == 0)); then
        printf 'Shard %02d/%02d is empty; no PHPUnit process started.\n' "$((index + 1))" "$shard_total"
        continue
    fi

    configuration="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" configuration)"
    database="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" database)"
    log="$(php tests/scripts/parallel_test_databases.php shard "$manifest" "$index" log)"
    printf 'Starting shard %02d/%02d with %d files.\n' "$((index + 1))" "$shard_total" "${#test_files[@]}"
    APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE="$database" \
    php -d memory_limit=512M vendor/bin/phpunit \
        --configuration "$configuration" \
        --colors=never \
        "${test_files[@]}" >"$log" 2>&1 &
    child_pids[$index]=$!
done

failed=0
for ((index = 0; index < shard_total; index++)); do
    pid="${child_pids[$index]:-}"
    if [[ -z "$pid" ]]; then
        continue
    fi

    if ! wait "$pid"; then
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
