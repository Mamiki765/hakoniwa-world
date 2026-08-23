<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionConfigCacheEntrypointTest extends TestCase
{
    public function test_config_cache_runs_only_for_the_production_apache_entrypoint_after_runtime_environment_exists(): void
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 2).'/docker/entrypoint.sh');
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('[ "${APP_ENV:-}" = "production" ]', $entrypoint);
        $this->assertStringContainsString('[ "${1:-}" = "apache2-foreground" ]', $entrypoint);
        $this->assertStringContainsString("php artisan config:clear\n    php artisan config:cache", $entrypoint);
        $this->assertLessThan(
            strpos($entrypoint, 'exec "$@"'),
            strpos($entrypoint, 'php artisan config:cache'),
        );
        $this->assertIsString($dockerfile);
        $this->assertStringNotContainsString('config:cache', $dockerfile);
        $this->assertStringContainsString(
            'RUN --mount=type=cache,id=hakoniwa-composer-cache,target=/tmp/composer-cache,sharing=locked',
            $dockerfile,
        );
        $this->assertStringContainsString('COMPOSER_CACHE_DIR=/tmp/composer-cache', $dockerfile);
        $this->assertStringContainsString('FROM runtime AS development', $dockerfile);
        $this->assertStringContainsString(
            'COPY --from=vendor /usr/bin/composer /usr/local/bin/composer',
            $dockerfile,
        );
        $this->assertStringEndsWith("FROM runtime AS production\n", str_replace("\r\n", "\n", $dockerfile));
    }
}
