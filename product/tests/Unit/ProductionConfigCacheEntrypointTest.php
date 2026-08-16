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
    }
}
