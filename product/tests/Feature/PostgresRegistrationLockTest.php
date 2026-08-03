<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesForwardOnlyDatabaseMigrations;
use Tests\TestCase;

class PostgresRegistrationLockTest extends TestCase
{
    use CreatesTestWorlds;
    use DatabaseMigrations, UsesForwardOnlyDatabaseMigrations {
        UsesForwardOnlyDatabaseMigrations::runDatabaseMigrations insteadof DatabaseMigrations;
        UsesForwardOnlyDatabaseMigrations::refreshTestDatabase insteadof DatabaseMigrations;
    }

    private const PROBE_CONNECTION = 'pgsql-registration-lock-probe';

    private string $primaryConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryConnection = DB::getDefaultConnection();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific advisory lock test.');
        }
        config([
            'database.connections.'.self::PROBE_CONNECTION => config(
                'database.connections.'.$this->primaryConnection,
            ),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->primaryConnection, self::PROBE_CONNECTION] as $connectionName) {
            $connection = DB::connection($connectionName);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        DB::purge(self::PROBE_CONNECTION);
        parent::tearDown();
    }

    public function test_second_connection_cannot_acquire_the_same_world_registration_lock(): void
    {
        $world = $this->lightweightWorld();
        $primary = DB::connection($this->primaryConnection);
        $primary->beginTransaction();
        $primary->select('SELECT pg_advisory_xact_lock(?, ?)', [NationCreationService::REGISTRATION_LOCK_NAMESPACE, $world->id]);

        try {
            $result = DB::connection(self::PROBE_CONNECTION)->selectOne(
                'SELECT pg_try_advisory_xact_lock(?, ?) AS acquired',
                [NationCreationService::REGISTRATION_LOCK_NAMESPACE, $world->id],
            );

            $this->assertFalse($result->acquired);
        } finally {
            $primary->rollBack();
        }
    }

    public function test_serialized_concurrent_registration_allocates_distinct_per_world_numbers(): void
    {
        $world = $this->lightweightWorld();
        $primary = DB::connection($this->primaryConnection);
        $probe = DB::connection(self::PROBE_CONNECTION);
        $now = now();

        $primary->beginTransaction();
        $primary->select('SELECT pg_advisory_xact_lock(?, ?)', [
            NationCreationService::REGISTRATION_LOCK_NAMESPACE, $world->id,
        ]);
        $this->insertNextNation($primary, $world->id, 'First registration', $now);

        $probe->beginTransaction();
        $blocked = $probe->selectOne('SELECT pg_try_advisory_xact_lock(?, ?) AS acquired', [
            NationCreationService::REGISTRATION_LOCK_NAMESPACE, $world->id,
        ]);
        $this->assertFalse($blocked->acquired);

        $primary->commit();
        $probe->select('SELECT pg_advisory_xact_lock(?, ?)', [
            NationCreationService::REGISTRATION_LOCK_NAMESPACE, $world->id,
        ]);
        $this->insertNextNation($probe, $world->id, 'Second registration', $now);
        $probe->commit();

        $this->assertSame(
            [1, 2],
            DB::table('nations')->where('world_id', $world->id)->orderBy('id')->pluck('nation_number')->all(),
        );
    }

    private function insertNextNation(
        ConnectionInterface $connection,
        int $worldId,
        string $name,
        mixed $now,
    ): void {
        $next = (int) $connection->table('nations')->where('world_id', $worldId)->max('nation_number') + 1;
        $connection->table('nations')->insert([
            'world_id' => $worldId,
            'nation_number' => $next,
            'name' => $name,
            'money' => 100,
            'state' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
