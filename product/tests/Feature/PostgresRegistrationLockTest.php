<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresRegistrationLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_connection_cannot_acquire_the_same_world_registration_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific advisory lock test.');
        }

        $world = app(OceanWorldGenerator::class)->initialize();
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [NationCreationService::REGISTRATION_LOCK_NAMESPACE, $world->id]);

        $connectionName = 'pgsql-registration-lock-probe';
        config(["database.connections.{$connectionName}" => config('database.connections.pgsql')]);

        try {
            $result = DB::connection($connectionName)->selectOne(
                'SELECT pg_try_advisory_xact_lock(?, ?) AS acquired',
                [NationCreationService::REGISTRATION_LOCK_NAMESPACE, $world->id],
            );

            $this->assertFalse($result->acquired);
        } finally {
            DB::purge($connectionName);
        }
    }
}
