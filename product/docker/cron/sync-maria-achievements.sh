#!/bin/sh
set -eu

: "${HAKONIWA_PROJECT_DIR:?Set HAKONIWA_PROJECT_DIR to the deployed Compose directory}"

cd -- "$HAKONIWA_PROJECT_DIR"

exec docker compose exec -T --user www-data hakoniwa-web \
    php artisan hakoniwa:sync-maria-achievements
