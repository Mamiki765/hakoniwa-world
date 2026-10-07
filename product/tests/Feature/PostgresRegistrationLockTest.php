<?php

namespace Tests\Feature;

use App\Application\NationAbandonmentService;
use App\Application\NationCreationService;
use App\Domain\World\WorldMutationLock;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
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

    public function test_name_reuse_upgrade_preserves_history_and_database_serializes_competing_names(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $archived = app(NationCreationService::class)->create($owner, $world, '再利用島', '旧島主');
        app(NationAbandonmentService::class)->abandon($owner, $archived, $archived->name);
        $before = $archived->fresh()->getAttributes();

        // Recreate the supported pre-upgrade constraint without changing historical data.
        DB::statement('DROP INDEX nations_world_id_name_unique');
        DB::statement('ALTER TABLE nations ADD CONSTRAINT nations_world_id_name_unique UNIQUE (world_id, name)');
        $migration = require database_path('migrations/2026_10_07_020000_allow_abandoned_nation_name_reuse.php');
        DB::transaction(fn () => $migration->up());
        $this->assertSame($before, $archived->fresh()->getAttributes());
        $this->assertDatabaseHas('nation_creation_requests', ['nation_id' => $archived->id, 'status' => 'completed']);

        // Bypass the application's World lock to exercise the independent DB guarantee.
        $row = $before;
        unset($row['id']);
        $row['state'] = 'active';
        $row['nation_number']++;
        $probe = DB::connection(self::PROBE_CONNECTION);
        $probe->statement("SET lock_timeout TO '100ms'");
        DB::beginTransaction();
        try {
            DB::table('nations')->insert($row);
            $row['nation_number']++;
            try {
                $probe->table('nations')->insert($row);
                $this->fail('A concurrent duplicate name must wait for the first registration.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
            DB::commit();
            try {
                $probe->table('nations')->insert($row);
                $this->fail('A committed non-abandoned name must reject the competing registration.');
            } catch (QueryException $exception) {
                $this->assertSame('23505', $exception->errorInfo[0]);
            }
            $this->assertSame(2, DB::table('nations')->where('world_id', $world->id)->where('name', $archived->name)->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $probe->statement('RESET lock_timeout');
        }
    }

    public function test_abandonment_fails_with_the_player_message_while_the_common_world_lock_is_held(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '破棄競合島', '試験島主');
        $lock = app(WorldMutationLock::class);
        $probe = DB::connection(self::PROBE_CONNECTION);
        $acquired = $probe->selectOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired',
            [$lock->key($world)],
        );
        $this->assertTrue($acquired->acquired);

        try {
            $this->actingAs($owner)
                ->postJson("/api/v1/nations/{$nation->id}/abandon", ['confirmation_name' => $nation->name])
                ->assertConflict()
                ->assertJsonPath('code', 'world_updating')
                ->assertJsonPath('message', 'このWorldは現在更新中です。後でもう一度実行してください。');
        } finally {
            $released = $probe->selectOne(
                'SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS released',
                [$lock->key($world)],
            );
            $this->assertTrue($released->released);
        }

        $this->assertSame('active', $nation->fresh()->state);
        $this->assertDatabaseHas('nation_memberships', ['nation_id' => $nation->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('nation_capitals', ['nation_id' => $nation->id]);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'nation.abandoned')->count());
    }

    public function test_common_lock_keeps_the_legacy_turn_key_for_rolling_deploy_serialization(): void
    {
        $world = $this->lightweightWorld();
        $lock = new WorldMutationLock;

        $this->assertSame("hakoniwa.turn.world.{$world->id}", $lock->key($world));
    }

    public function test_reentrant_common_lock_is_not_released_until_the_outer_owner_releases(): void
    {
        $world = $this->lightweightWorld();
        $lock = app(WorldMutationLock::class);
        $probe = DB::connection(self::PROBE_CONNECTION);

        $lock->acquire($world);
        $lock->acquire($world);
        $lock->release($world);
        $this->assertFalse($probe->selectOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired',
            [$lock->key($world)],
        )->acquired);

        $lock->release($world);
        $this->assertTrue($probe->selectOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired',
            [$lock->key($world)],
        )->acquired);
    }
}
