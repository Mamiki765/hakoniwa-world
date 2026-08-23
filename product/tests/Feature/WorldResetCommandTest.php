<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Application\CommandQueueService;
use App\Application\CurrentCatalogInstaller;
use App\Application\ExternalIdentityData;
use App\Application\MapSpaceCoveragePreflight;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\RulesetPublisher;
use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\World\WorldGenerationProfile;
use App\Models\IslandMessage;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationMonsterKillStat;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
        $user->forceFill(['visitor_code' => 'RESET001'])->save();
        $boardMessage = $this->boardMessage($world, $user);
        $nationCount = Nation::query()->count();
        $cellCount = MapCell::query()->count();

        $this->artisan('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--dry-run' => true,
        ])->expectsOutputToContain('island_messages')
            ->expectsOutputToContain('No data was changed.')
            ->assertSuccessful();
        $this->artisan('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--confirm' => 'RESET-wrong-world',
        ])->expectsOutputToContain('Confirmation must exactly equal')->assertFailed();

        $this->assertSame($nationCount, Nation::query()->count());
        $this->assertSame($cellCount, MapCell::query()->count());
        $this->assertNotNull(User::query()->find($user->id));
        $this->assertNotNull(IslandMessage::query()->find($boardMessage->id));
        $this->assertSame('RESET001', $user->fresh()->visitor_code);
    }

    public function test_reset_isolated_world_preserves_users_identities_and_other_worlds(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        [$world, $user] = $this->populatedWorld(WorldGenerationProfile::Production);
        $user->forceFill(['visitor_code' => 'RESET002'])->save();
        $boardMessage = $this->boardMessage($world, $user);
        $otherWorld = World::query()->create([
            'key' => 'other-world',
            'name' => '別世界',
            'ruleset_version_id' => $world->ruleset_version_id,
            'current_turn' => 7,
        ]);
        $otherNation = Nation::query()->create([
            'world_id' => $otherWorld->id,
            'nation_number' => 1,
            'registered_turn' => 1,
            'name' => '別世界島',
            'owner_name' => '別世界島主',
            'profile_comment' => '',
            'money' => 500,
            'state' => 'active',
            'idle_counter' => 0,
        ]);
        $otherWorldMessage = IslandMessage::query()->create([
            'public_id' => (string) Str::uuid(),
            'world_id' => $otherWorld->id,
            'target_nation_id' => $otherNation->id,
            'author_user_id' => $user->id,
            'author_kind' => IslandMessage::AUTHOR_VISITOR,
            'author_nation_id' => null,
            'secret_sender_nation_id' => null,
            'message_type' => IslandMessage::TYPE_PUBLIC,
            'body' => 'other World message must survive',
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
        $this->assertNotNull(IslandMessage::query()->find($otherWorldMessage->id));
        $this->assertSame(0, Nation::query()->where('world_id', $resetWorld->id)->count());
        $this->assertSame(3600, MapCell::query()->where('map_space_id', $mapSpace->id)->count());
        $this->assertSame(60, DB::table('map_cells')->where('map_space_id', $mapSpace->id)
            ->select('y')->groupBy('y')->havingRaw('COUNT(*) = 60')->get()->count());
        $this->assertSame(0, MapCell::query()->where('map_space_id', $mapSpace->id)->min('x'));
        $this->assertSame(59, MapCell::query()->where('map_space_id', $mapSpace->id)->max('x'));
        $this->assertNotNull(User::query()->find($user->id));
        $this->assertNull(IslandMessage::query()->find($boardMessage->id));
        $this->assertSame('RESET002', $user->fresh()->visitor_code);
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
        $messageDeleteIndex = null;
        $queueItemDeleteIndex = null;
        foreach ($resetQueries as $index => $sql) {
            if ($worldLockIndex === null && str_contains($sql, 'from "worlds"') && str_contains($sql, 'for update')) {
                $worldLockIndex = $index;
            }
            if ($queueItemDeleteIndex === null && str_contains($sql, 'delete from "nation_command_queue_items"')) {
                $queueItemDeleteIndex = $index;
            }
            if ($messageDeleteIndex === null && str_contains($sql, 'delete from "island_messages"')) {
                $messageDeleteIndex = $index;
            }
        }
        $this->assertIsInt($worldLockIndex);
        $this->assertIsInt($messageDeleteIndex);
        $this->assertIsInt($queueItemDeleteIndex);
        $this->assertLessThan($messageDeleteIndex, $worldLockIndex);
        $this->assertLessThan($queueItemDeleteIndex, $worldLockIndex);
    }

    public function test_generator_failure_rolls_back_deletion_and_never_reports_success(): void
    {
        [$world, $user] = $this->populatedWorld();
        $boardMessage = $this->boardMessage($world, $user);
        $nationCount = Nation::query()->count();
        $cellCount = MapCell::query()->count();
        $this->app->bind(OceanWorldGenerator::class, fn () => new class(app(ChunkCoordinateService::class), app(RulesetPublisher::class), app(CurrentRulesetGuard::class), app(MapSpaceCoveragePreflight::class), app(CurrentCatalogInstaller::class)) extends OceanWorldGenerator
        {
            public function initialize(
                WorldGenerationProfile $profile = WorldGenerationProfile::Production,
            ): World {
                throw new RuntimeException('injected reset failure');
            }
        });

        $this->artisan('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--confirm' => 'RESET-'.$world->key,
        ])->expectsOutputToContain('World reset failed: injected reset failure')->assertFailed();

        $this->assertNotNull(World::query()->find($world->id));
        $this->assertSame($nationCount, Nation::query()->count());
        $this->assertSame($cellCount, MapCell::query()->count());
        $this->assertNotNull(IslandMessage::query()->find($boardMessage->id));
    }

    public function test_reset_reports_and_cascades_only_the_target_world_turn_runs(): void
    {
        [$world] = $this->populatedWorld();
        $otherWorld = World::query()->create([
            'key' => 'other-turn-history-world',
            'name' => '別ターン履歴世界',
            'ruleset_version_id' => $world->ruleset_version_id,
            'current_turn' => 2,
        ]);
        $targetRuns = collect([
            $this->createTurnRun($world, 2),
            $this->createTurnRun($world, 3),
        ]);
        $world->update(['current_turn' => 3]);
        $otherRun = $this->createTurnRun($otherWorld, 2);
        $userCount = User::query()->count();
        $identityCount = DB::table('auth_identities')->count();

        $this->assertSame(0, Artisan::call('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--dry-run' => true,
        ]));
        $this->assertMatchesRegularExpression(
            '/\|\s*turn_runs\s*\|\s*2\s*\|/',
            Artisan::output(),
        );
        $this->assertNotNull(World::query()->find($world->id));
        $this->assertSame(
            $targetRuns->pluck('id')->all(),
            TurnRun::query()->where('world_id', $world->id)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertNotNull(TurnRun::query()->find($otherRun->id));

        $this->assertSame(0, Artisan::call('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--profile' => 'debug-32x32',
            '--confirm' => 'RESET-'.$world->key,
        ]));
        $this->assertNull(World::query()->find($world->id));
        $this->assertSame(0, TurnRun::query()->where('world_id', $world->id)->count());
        $this->assertNotNull(World::query()->find($otherWorld->id));
        $this->assertNotNull(TurnRun::query()->find($otherRun->id));
        $this->assertSame($userCount, User::query()->count());
        $this->assertSame($identityCount, DB::table('auth_identities')->count());
    }

    public function test_reset_reports_and_cascades_world_owned_monster_and_award_state(): void
    {
        [$world] = $this->populatedWorld();
        $nation = Nation::query()->where('world_id', $world->id)->firstOrFail();
        $definition = MonsterDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'inora')
            ->firstOrFail();
        $cell = MapCell::query()
            ->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', $nation->capital()->select('map_cell_id'))
            ->firstOrFail();
        $killed = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => 0,
            'spawned_max_hp' => 1,
            'state' => 'killed',
            'spawned_target_turn' => 1,
            'version' => 2,
            'removal_reason' => 'monster_missile',
            'removed_at' => now(),
        ]);
        $killStat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'nation_id' => $nation->id,
            'kill_count' => 1,
            'first_killed_turn' => 1,
            'last_killed_turn' => 1,
            'version' => 1,
        ]);
        $awardId = DB::table('nation_awards')->insertGetId([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'award_key' => 'award.prosperity',
            'awarded_turn' => 1,
            'award_occurrence_key' => 'once',
            'created_at' => now(),
        ]);
        $cycleStatId = DB::table('nation_monster_cycle_stats')->insertGetId([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'cycle_start_turn' => 1,
            'cycle_end_turn' => 100,
            'kill_count' => 1,
            'version' => 1,
            'seeded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $seedRequirementId = DB::table('nation_monster_cycle_seed_requirements')->insertGetId([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'cycle_start_turn' => 1,
            'cycle_end_turn' => 100,
            'completed_at' => now(),
            'created_at' => now(),
        ]);
        $alive = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 1,
        ]);
        $occupancy = MonsterOccupancy::query()->create([
            'monster_instance_id' => $alive->id,
            'map_cell_id' => $cell->id,
        ]);

        $this->assertSame(0, Artisan::call('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--dry-run' => true,
        ]));
        $dryRunOutput = Artisan::output();
        $this->assertMatchesRegularExpression('/\|\s*nation_awards\s*\|\s*1\s*\|/', $dryRunOutput);
        $this->assertMatchesRegularExpression('/\|\s*nation_monster_cycle_stats\s*\|\s*1\s*\|/', $dryRunOutput);
        $this->assertMatchesRegularExpression(
            '/\|\s*nation_monster_cycle_seed_requirements\s*\|\s*1\s*\|/',
            $dryRunOutput,
        );
        $this->assertMatchesRegularExpression('/\|\s*nation_monster_kill_stats\s*\|\s*1\s*\|/', $dryRunOutput);
        $this->assertMatchesRegularExpression('/\|\s*monster_instances\s*\|\s*2\s*\|/', $dryRunOutput);
        $this->assertMatchesRegularExpression('/\|\s*monster_occupancies\s*\|\s*1\s*\|/', $dryRunOutput);
        $this->assertNotNull($killStat->fresh());
        $this->assertTrue(DB::table('nation_awards')->where('id', $awardId)->exists());
        $this->assertTrue(DB::table('nation_monster_cycle_stats')->where('id', $cycleStatId)->exists());
        $this->assertTrue(DB::table('nation_monster_cycle_seed_requirements')
            ->where('id', $seedRequirementId)->exists());
        $this->assertNotNull($occupancy->fresh());

        $this->assertSame(0, Artisan::call('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--profile' => 'debug-32x32',
            '--confirm' => 'RESET-'.$world->key,
        ]));

        $this->assertNull(World::query()->find($world->id));
        $this->assertFalse(DB::table('nation_awards')->where('id', $awardId)->exists());
        $this->assertFalse(DB::table('nation_monster_cycle_stats')->where('id', $cycleStatId)->exists());
        $this->assertFalse(DB::table('nation_monster_cycle_seed_requirements')
            ->where('id', $seedRequirementId)->exists());
        $this->assertNull(NationMonsterKillStat::query()->find($killStat->id));
        $this->assertNull(MonsterInstance::query()->find($killed->id));
        $this->assertNull(MonsterInstance::query()->find($alive->id));
        $this->assertNull(MonsterOccupancy::query()->find($occupancy->id));
        $this->assertNotNull(MonsterDefinition::query()->find($definition->id));
    }

    public function test_explicit_debug_profile_resets_to_32_by_32_and_restarts_nation_numbers(): void
    {
        [$world, $user] = $this->populatedWorld(WorldGenerationProfile::Production);

        $this->artisan('hakoniwa:world:reset', [
            '--world' => $world->key,
            '--profile' => 'debug-32x32',
            '--confirm' => 'RESET-'.$world->key,
        ])->expectsOutputToContain('verified 32 x 32 staggered x/y ocean (debug-32x32)')
            ->assertSuccessful();

        $resetWorld = World::query()->where('key', $world->key)->firstOrFail();
        $mapSpace = MapSpace::query()->where('world_id', $resetWorld->id)->firstOrFail();
        $nation = app(NationCreationService::class)->create($user->fresh(), $resetWorld, 'Debug Nation', '試験島主');

        $this->assertSame(
            ['min_x' => 0, 'max_x' => 31, 'min_y' => 0, 'max_y' => 31],
            $mapSpace->only(['min_x', 'max_x', 'min_y', 'max_y']),
        );
        $this->assertSame(1024, $mapSpace->cells()->count());
        $this->assertSame(4, $mapSpace->chunks()->count());
        $this->assertSame(1, $nation->nation_number);
    }

    public function test_production_reset_is_rejected_with_default_profile_and_correct_confirmation_without_mutation(): void
    {
        [$world] = $this->populatedWorld(WorldGenerationProfile::Production);
        $this->createTurnRun($world, 2);
        $before = $this->gameplaySnapshot();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->app['env'] = 'production';

        try {
            $this->artisan('hakoniwa:world:reset', [
                '--world' => $world->key,
                '--profile' => 'default',
                '--confirm' => 'RESET-'.$world->key,
            ])->expectsOutputToContain('World reset is disabled in production')
                ->assertFailed();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame([], $queries);
        $this->assertSame($before, $this->gameplaySnapshot());
    }

    public function test_production_reset_rejects_bypass_arguments_and_dry_run_without_mutation(): void
    {
        [$world] = $this->populatedWorld();
        $this->createTurnRun($world, 2);
        $before = $this->gameplaySnapshot();
        $this->app['env'] = 'production';

        try {
            $this->artisan('hakoniwa:world:reset', [
                '--world' => 'not-the-configured-world',
                '--profile' => 'debug-32x32',
                '--confirm' => 'RESET-not-the-configured-world',
            ])->expectsOutputToContain('World reset is disabled in production')
                ->assertFailed();
            $this->artisan('hakoniwa:world:reset', [
                '--world' => $world->key,
                '--profile' => 'default',
                '--dry-run' => true,
            ])->expectsOutputToContain('World reset is disabled in production')
                ->assertFailed();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame($before, $this->gameplaySnapshot());
    }

    /** @return array{World, User} */
    private function populatedWorld(
        WorldGenerationProfile $profile = WorldGenerationProfile::Debug32x32,
    ): array {
        $world = app(OceanWorldGenerator::class)->initialize($profile);
        $user = app(AuthIdentityService::class)->authenticate(
            'discord',
            new ExternalIdentityData('reset-user', 'Reset User'),
        );
        app(NationCreationService::class)->create($user, $world, 'リセット国', '試験島主');

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

    private function createTurnRun(World $world, int $targetTurn): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $targetTurn,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_pad(dechex($targetTurn), 64, '0', STR_PAD_LEFT),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_COMPLETED,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'started_at' => now(),
            'completed_at' => now(),
            'failure_context' => [],
        ]);
    }

    private function boardMessage(World $world, User $user): IslandMessage
    {
        $nation = Nation::query()->where('world_id', $world->id)->firstOrFail();

        return IslandMessage::query()->create([
            'public_id' => (string) Str::uuid(),
            'world_id' => $world->id,
            'target_nation_id' => $nation->id,
            'author_user_id' => $user->id,
            'author_kind' => IslandMessage::AUTHOR_NATION,
            'author_nation_id' => $nation->id,
            'secret_sender_nation_id' => null,
            'message_type' => IslandMessage::TYPE_PUBLIC,
            'body' => 'reset integration message',
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function gameplaySnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'worlds',
            'nations',
            'map_cells',
            'nation_command_queues',
            'nation_command_queue_items',
            'turn_runs',
            'audit_events',
        ] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        }

        return $snapshot;
    }
}
