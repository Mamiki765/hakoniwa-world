#!/bin/sh
set -eu

: "${HAKONIWA_PROJECT_DIR:?Set HAKONIWA_PROJECT_DIR to the deployed repository directory}"

HAKONIWA_WORLD_KEY="${HAKONIWA_WORLD_KEY:-shared-world}"
cd -- "$HAKONIWA_PROJECT_DIR"

exec docker compose exec -T --user www-data hakoniwa-web \
    php artisan hakoniwa:turn:run \
    --world="$HAKONIWA_WORLD_KEY" \
    --source=cron
