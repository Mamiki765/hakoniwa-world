<?php

namespace Tests\Concerns;

use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ParallelTestDatabaseManager;
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
        if (ReusableSurfaceWorldState::$fixtureAvailable) {
            throw new RuntimeException(
                'The reusable surface baseline was invalidated during this process; aborting the worker database.',
            );
        }

        if (getenv('HAKONIWA_REUSABLE_SURFACE_TEMPLATE_MODE') === 'clone') {
            $this->loadReusableSurfaceTemplateClone();
            $this->writeReusableSurfaceFixtureMetrics();

            return;
        }

        $migrationStartedAt = hrtime(true);
        $this->migrateDatabasesWithoutReusableSurfaceWorld();
        ReusableSurfaceWorldState::$migrationSeconds = (hrtime(true) - $migrationStartedAt) / 1_000_000_000;
        $startedAt = hrtime(true);
        $world = $this->generateReusableSurfaceWorld();
        ReusableSurfaceWorldState::$generationCount = 1;
        ReusableSurfaceWorldState::$generationSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        ReusableSurfaceWorldState::$fixtureAvailable = true;
        ReusableSurfaceWorldState::$database = $this->currentDatabaseName();
        ReusableSurfaceWorldState::$worldKey = $world->key;
        if (getenv('HAKONIWA_REUSABLE_SURFACE_TEMPLATE_MODE') === 'build') {
            $this->writeReusableSurfaceTemplateMarker($world);
        }
        $this->writeReusableSurfaceFixtureMetrics();
    }

    protected function lightweightWorld(): World
    {
        $this->assertReusableSurfaceFixtureProcess();
        if (! ReusableSurfaceWorldState::$fixtureAvailable
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
            "fixture_profile\treusable_surface\nfixture_available\t1\nmigration_seconds\t%.6f\nmap_generation_count\t%d\nmap_generation_seconds\t%.6f\n",
            ReusableSurfaceWorldState::$migrationSeconds,
            ReusableSurfaceWorldState::$generationCount,
            ReusableSurfaceWorldState::$generationSeconds,
        );
        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write reusable surface fixture metrics.');
        }
    }

    private function loadReusableSurfaceTemplateClone(): void
    {
        $fingerprint = getenv('HAKONIWA_REUSABLE_SURFACE_TEMPLATE_FINGERPRINT');
        $database = $this->currentDatabaseName();
        if (! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1
            || ! ParallelTestDatabaseManager::isSafeDatabaseName($database)) {
            throw new RuntimeException('Reusable surface template clone identity is invalid.');
        }

        try {
            $marker = DB::table('hakoniwa_test_fixture_metadata')->where('singleton', 1)->sole();
        } catch (\Throwable $exception) {
            throw new RuntimeException('Reusable surface template clone has no verified marker.', 0, $exception);
        }
        if (! is_string($marker->fingerprint)
            || ! hash_equals($fingerprint, $marker->fingerprint)
            || $marker->profile !== 'debug-32x32'
            || (int) $marker->cell_count !== 1024
            || ! is_string($marker->world_key)) {
            throw new RuntimeException('Reusable surface template clone marker does not match this run.');
        }
        $world = World::query()->where('key', $marker->world_key)->sole();
        $cellCount = DB::table('map_cells')
            ->join('map_spaces', 'map_spaces.id', '=', 'map_cells.map_space_id')
            ->where('map_spaces.world_id', $world->id)
            ->count();
        if ($cellCount !== 1024) {
            throw new RuntimeException('Reusable surface template clone map baseline is incomplete.');
        }

        ReusableSurfaceWorldState::$fixtureAvailable = true;
        ReusableSurfaceWorldState::$generationCount = 0;
        ReusableSurfaceWorldState::$generationSeconds = 0.0;
        ReusableSurfaceWorldState::$migrationSeconds = 0.0;
        ReusableSurfaceWorldState::$database = $database;
        ReusableSurfaceWorldState::$worldKey = $world->key;
    }

    private function writeReusableSurfaceTemplateMarker(World $world): void
    {
        $fingerprint = getenv('HAKONIWA_REUSABLE_SURFACE_TEMPLATE_FINGERPRINT');
        if (! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new RuntimeException('Reusable surface template build fingerprint is invalid.');
        }
        DB::statement(<<<'SQL'
CREATE TABLE hakoniwa_test_fixture_metadata (
    singleton smallint PRIMARY KEY CHECK (singleton = 1),
    fingerprint char(64) NOT NULL,
    profile varchar(32) NOT NULL CHECK (profile = 'debug-32x32'),
    world_key varchar(64) NOT NULL,
    cell_count integer NOT NULL CHECK (cell_count = 1024),
    built_at timestamp(0) with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
        DB::table('hakoniwa_test_fixture_metadata')->insert([
            'singleton' => 1,
            'fingerprint' => $fingerprint,
            'profile' => 'debug-32x32',
            'world_key' => $world->key,
            'cell_count' => 1024,
        ]);
    }
}
