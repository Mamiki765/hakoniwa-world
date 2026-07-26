FROM node:24.18.0-bookworm-slim AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY tsconfig.json eslint.config.js vite.config.js ./
RUN npm run test \
    && npm run lint \
    && npm run typecheck \
    && npm run build

FROM composer:2.10.2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-interaction

FROM php:8.5.8-apache-bookworm

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install --no-install-recommends -y curl libicu-dev libpq-dev libzip-dev unzip \
    && docker-php-ext-install -j4 intl pdo_pgsql \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/hakoniwa-entrypoint
COPY --from=vendor /app /var/www/html
COPY --from=frontend /app/public/build /var/www/html/public/build

RUN chmod +x /usr/local/bin/hakoniwa-entrypoint \
    && mkdir -p /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

WORKDIR /var/www/html
ENTRYPOINT ["hakoniwa-entrypoint"]
CMD ["apache2-foreground"]
