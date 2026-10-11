<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionConfigCacheEntrypointTest extends TestCase
{
    public function test_production_apache_entrypoint_builds_runtime_config_before_exec(): void
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 2).'/docker/entrypoint.sh');
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

        $this->assertIsString($entrypoint);
        $clear = strpos($entrypoint, 'php artisan config:clear');
        $cache = strpos($entrypoint, 'php artisan config:cache');
        $exec = strpos($entrypoint, 'exec "$@"');
        $this->assertIsInt($clear);
        $this->assertIsInt($cache);
        $this->assertIsInt($exec);
        $this->assertLessThan($cache, $clear);
        $this->assertLessThan($exec, $cache);
        $this->assertIsString($dockerfile);
        $this->assertStringNotContainsString('config:cache', $dockerfile);
    }
}
