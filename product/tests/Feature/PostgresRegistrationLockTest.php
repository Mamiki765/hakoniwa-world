<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Domain\World\WorldMutationLock;
use App\Models\User;
use DomainException;
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
        DB::setDefaultConnection($this->primaryConnection);
        foreach ([$this->primaryConnection, self::PROBE_CONNECTION] as $connectionName) {
            $connection = DB::connection($connectionName);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $connection->selectOne('SELECT pg_advisory_unlock_all()');
        }
        DB::purge(self::PROBE_CONNECTION);
        parent::tearDown();
    }

    public function test_registration_acquires_the_common_world_mutation_lock_before_its_transaction(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $lock = app(WorldMutationLock::class);
        $probe = DB::connection(self::PROBE_CONNECTION);
        $acquired = $probe->selectOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired',
            [$lock->key($world)],
        );
        $this->assertTrue($acquired->acquired);

        try {
            app(NationCreationService::class)->create($user, $world, '競合登録国', '試験島主');
            $this->fail('Registration unexpectedly passed the World mutation lock.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('World', $exception->getMessage());
        } finally {
            $released = $probe->selectOne(
                'SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released',
                [$lock->key($world)],
            );
            $this->assertTrue($released->released);
        }

        $this->assertDatabaseMissing('nation_memberships', [
            'user_id' => $user->id,
            'world_id' => $world->id,
        ]);
        $this->assertDatabaseCount('nation_creation_requests', 0);
    }

    public function test_common_lock_keeps_the_legacy_turn_key_for_rolling_deploy_serialization(): void
    {
        $world = $this->lightweightWorld();
        $lock = new WorldMutationLock;

        $this->assertSame("hakoniwa.turn.world.{$world->id}", $lock->key($world));
    }
}
