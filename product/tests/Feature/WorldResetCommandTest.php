<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Application\CommandQueueService;
use App\Application\ExternalIdentityData;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\RulesetPublisher;
use App\Domain\Map\ChunkCoordinateService;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class WorldResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_and_wrong_confirmation_do_not_change_world(): void
    {
        [$world, $user] = $this->populatedWorld();
        $nationCount = Nation::query()->count();
        $cellCount = MapCell::query()->count();

        $this->artisan('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--dry-run' => true,
        ])->expectsOutputToContain('No data was changed.')->assertSuccessful();
        $this->artisan('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--confirm' => 'RESET-wrong-world',
        ])->expectsOutputToContain('Confirmation must exactly equal')->assertFailed();

        $this->assertSame($nationCount, Nation::query()->count());
        $this->assertSame($cellCount, MapCell::query()->count());
        $this->assertNotNull(User::query()->find($user->id));
    }

    public function test_reset_isolated_world_preserves_users_identities_and_other_worlds(): void
    {
        [$world, $user] = $this->populatedWorld();
        $otherWorld = World::query()->create([
            'key' => 'other-world',
            'name' => '別世界',
            'ruleset_version_id' => $world->ruleset_version_id,
            'current_turn' => 7,
        ]);
        $identityCount = DB::table('auth_identities')->count();
        $identityAuditCount = DB::table('audit_events')
            ->where('event_type', 'auth.identity_registered')
            ->count();
        $resetQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$resetQueries): void {
            $resetQueries[] = strtolower($query->sql);
        });

        $this->artisan('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--confirm' => 'RESET-'.$world->key,
            '--preserve-users' => true,
            '--preserve-auth-identities' => true,
        ])->expectsOutputToContain('verified 60 x 60 staggered x/y ocean')->assertSuccessful();

        $resetWorld = World::query()->where('key', $world->key)->firstOrFail();
        $mapSpace = MapSpace::query()->where('world_id', $resetWorld->id)->firstOrFail();
        $this->assertNotSame($world->id, $resetWorld->id);
        $this->assertNotNull(World::query()->find($otherWorld->id));
        $this->assertSame(7, World::query()->findOrFail($otherWorld->id)->current_turn);
        $this->assertSame(0, Nation::query()->where('world_id', $resetWorld->id)->count());
        $this->assertSame(3600, MapCell::query()->where('map_space_id', $mapSpace->id)->count());
        $this->assertSame(60, DB::table('map_cells')->where('map_space_id', $mapSpace->id)
            ->select('y')->groupBy('y')->havingRaw('COUNT(*) = 60')->get()->count());
        $this->assertSame(0, MapCell::query()->where('map_space_id', $mapSpace->id)->min('x'));
        $this->assertSame(59, MapCell::query()->where('map_space_id', $mapSpace->id)->max('x'));
        $this->assertNotNull(User::query()->find($user->id));
        $this->assertSame($identityCount, DB::table('auth_identities')->count());
        $this->assertSame($identityAuditCount, DB::table('audit_events')
            ->where('event_type', 'auth.identity_registered')
            ->count());
        $this->assertSame(0, DB::table('audit_events')
            ->where('event_type', 'nation.created')
            ->count());
        $this->assertSame(0, DB::table('audit_events')
            ->where('event_type', 'command.queued')
            ->count());

        $worldLockIndex = null;
        $queueItemDeleteIndex = null;
        foreach ($resetQueries as $index => $sql) {
            if ($worldLockIndex === null && str_contains($sql, 'from "worlds"') && str_contains($sql, 'for update')) {
                $worldLockIndex = $index;
            }
            if ($queueItemDeleteIndex === null && str_contains($sql, 'delete from "nation_command_queue_items"')) {
                $queueItemDeleteIndex = $index;
            }
        }
        $this->assertIsInt($worldLockIndex);
        $this->assertIsInt($queueItemDeleteIndex);
        $this->assertLessThan($queueItemDeleteIndex, $worldLockIndex);
    }

    public function test_generator_failure_rolls_back_deletion_and_never_reports_success(): void
    {
        [$world] = $this->populatedWorld();
        $nationCount = Nation::query()->count();
        $this->app->bind(OceanWorldGenerator::class, fn () => new class(app(ChunkCoordinateService::class), app(RulesetPublisher::class)) extends OceanWorldGenerator
        {
            public function initialize(): World
            {
                throw new RuntimeException('injected reset failure');
            }
        });

        $this->artisan('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--confirm' => 'RESET-'.$world->key,
        ])->expectsOutputToContain('World reset failed: injected reset failure')->assertFailed();

        $this->assertNotNull(World::query()->find($world->id));
        $this->assertSame($nationCount, Nation::query()->count());
        $this->assertSame(3600, MapCell::query()->count());
    }

    /** @return array{World, User} */
    private function populatedWorld(): array
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = app(AuthIdentityService::class)->authenticate(
            'discord',
            new ExternalIdentityData('reset-user', 'Reset User'),
        );
        app(NationCreationService::class)->create($user, $world, 'リセット国');

        $nation = Nation::query()->where('world_id', $world->id)->firstOrFail();
        $mapSpace = MapSpace::query()->where('world_id', $world->id)->firstOrFail();
        $target = MapCell::query()
            ->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        app(CommandQueueService::class)->add(
            $user,
            $nation,
            $mapSpace,
            'land_clear',
            $target->x,
            $target->y,
            (string) Str::uuid(),
            1,
        );

        return [$world, $user];
    }
}
