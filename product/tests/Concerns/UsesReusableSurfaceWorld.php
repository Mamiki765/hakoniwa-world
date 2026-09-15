<?php

namespace Tests\Concerns;

use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ReusableSurfaceWorldState;

trait UsesReusableSurfaceWorld
{
    use CreatesTestWorlds {
        lightweightWorld as private generateReusableSurfaceWorld;
    }
    use RefreshDatabase {
        migrateDatabases as private migrateDatabasesWithoutReusableSurfaceWorld;
    }

    protected function migrateDatabases(): void
    {
        $this->assertReusableSurfaceFixtureProcess();
        if (ReusableSurfaceWorldState::$generationCount !== 0) {
            throw new RuntimeException(
                'The reusable surface baseline was invalidated during this process; aborting the worker database.',
            );
        }

        $this->migrateDatabasesWithoutReusableSurfaceWorld();
        $startedAt = hrtime(true);
        $world = $this->generateReusableSurfaceWorld();
        ReusableSurfaceWorldState::$generationCount = 1;
        ReusableSurfaceWorldState::$generationSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        ReusableSurfaceWorldState::$database = $this->currentDatabaseName();
        ReusableSurfaceWorldState::$worldKey = $world->key;
        $this->writeReusableSurfaceFixtureMetrics();
    }

    protected function lightweightWorld(): World
    {
        $this->assertReusableSurfaceFixtureProcess();
        if (ReusableSurfaceWorldState::$generationCount !== 1
            || ReusableSurfaceWorldState::$database !== $this->currentDatabaseName()
            || ! is_string(ReusableSurfaceWorldState::$worldKey)) {
            throw new RuntimeException('The reusable surface baseline is unavailable for this worker database.');
        }
        if (! DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException(
                'The reusable surface test transaction ended early; aborting before the baseline can be mutated.',
            );
        }

        return World::query()->where('key', ReusableSurfaceWorldState::$worldKey)->firstOrFail();
    }

    private function assertReusableSurfaceFixtureProcess(): void
    {
        if (getenv('HAKONIWA_TEST_FIXTURE_PROFILE') !== 'reusable_surface') {
            throw new RuntimeException(
                'Reusable surface tests must run through the scoped test dispatcher.',
            );
        }
    }

    private function currentDatabaseName(): string
    {
        $connection = (string) config('database.default');

        return (string) config("database.connections.{$connection}.database");
    }

    private function writeReusableSurfaceFixtureMetrics(): void
    {
        $path = getenv('HAKONIWA_TEST_FIXTURE_METRICS');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Reusable surface fixture metrics path is missing.');
        }
        $payload = sprintf(
            "fixture_profile\treusable_surface\nmap_generation_count\t%d\nmap_generation_seconds\t%.6f\n",
            ReusableSurfaceWorldState::$generationCount,
            ReusableSurfaceWorldState::$generationSeconds,
        );
        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write reusable surface fixture metrics.');
        }
    }
}
