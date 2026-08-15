#!/bin/sh
set -eu

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

if [ "${APP_ENV:-}" = "production" ] && [ "${1:-}" = "apache2-foreground" ]; then
    php artisan config:clear
    php artisan config:cache
fi

exec "$@"
