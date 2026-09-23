#!/bin/sh
set -eu

: "${HAKONIWA_PROJECT_DIR:?Set HAKONIWA_PROJECT_DIR to the deployed repository directory}"
cd -- "$HAKONIWA_PROJECT_DIR"

# Read-only reminder using the existing host cron / Compose operator path.
# Deliberately accepts no arguments and never forwards --apply.
exec docker compose exec -T --user www-data hakoniwa-web \
    php artisan hakoniwa:underground:receipts:purge --batch=100
