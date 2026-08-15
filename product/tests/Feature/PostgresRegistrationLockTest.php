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
