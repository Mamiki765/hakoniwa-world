#!/usr/bin/env bash

set -Eeuo pipefail

shard_total="${1:-16}"
if [[ ! "$shard_total" =~ ^[1-9][0-9]*$ ]]; then
    echo "Shard total must be a positive integer." >&2
    exit 2
fi

temporary_directory="$(mktemp -d)"
cleanup() {
    rm -rf -- "$temporary_directory"
}
trap cleanup EXIT

list_tests() {
    php -d memory_limit=512M vendor/bin/phpunit --list-tests --colors=never "$@" \
        | sed -n '/^ - /s/^ - //p'
}

list_tests | sort > "$temporary_directory/serial"
: > "$temporary_directory/assigned"

for ((index = 0; index < shard_total; index++)); do
    assigned_output="$(php tests/scripts/test_shards.php files "$shard_total" "$index")"
    assigned_files=()
    if [[ -n "$assigned_output" ]]; then
        mapfile -t assigned_files <<<"$assigned_output"
    fi
    if ((${#assigned_files[@]} == 0)); then
        continue
    fi

    list_tests "${assigned_files[@]}" >> "$temporary_directory/assigned"
done

sort "$temporary_directory/assigned" > "$temporary_directory/assigned-sorted"
sort -u "$temporary_directory/assigned" > "$temporary_directory/union"

serial_count="$(wc -l < "$temporary_directory/serial")"
assigned_count="$(wc -l < "$temporary_directory/assigned")"
union_count="$(wc -l < "$temporary_directory/union")"
duplicate_count="$((assigned_count - union_count))"
missing_count="$(comm -23 "$temporary_directory/serial" "$temporary_directory/union" | wc -l)"
unexpected_count="$(comm -13 "$temporary_directory/serial" "$temporary_directory/union" | wc -l)"

if ((serial_count == 0 || assigned_count == 0)); then
    echo "PHPUnit test identifier discovery returned zero tests." >&2
    exit 1
fi

echo "serial identifiers: $serial_count"
echo "assigned identifiers: $assigned_count"
echo "union identifiers: $union_count"
echo "duplicate identifiers: $duplicate_count"
echo "missing identifiers: $missing_count"
echo "unexpected identifiers: $unexpected_count"

if ! cmp -s "$temporary_directory/serial" "$temporary_directory/assigned-sorted"; then
    echo "Serial and sharded PHPUnit test identifiers differ." >&2
    exit 1
fi

echo "Serial and sharded PHPUnit test identifiers are identical."
