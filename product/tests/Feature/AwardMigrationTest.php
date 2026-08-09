<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Models\MonsterDefinition;
use App\Models\Nation;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class AwardMigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_forward_migration_preserves_existing_data_and_requires_explicit_partial_cycle_seeds(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '既存migration国',
            '既存島主',
        );
        $inora = MonsterDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'inora')->firstOrFail();
        DB::table('nation_monster_kill_stats')->insert([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'monster_definition_id' => $inora->id,
            'kill_count' => 1,
            'first_killed_turn' => 1,
            'last_killed_turn' => 1,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $boundaryZero = $this->worldAt($world, 'migration-boundary-zero', 0);
        $boundaryZeroNation = $this->rawNation($boundaryZero, 1, '境界0国');
        $boundaryHundred = $this->worldAt($world, 'migration-boundary-hundred', 100);
        $boundaryHundredNation = $this->rawNation($boundaryHundred, 1, '境界100国');
        $nextPartial = $this->worldAt($world, 'migration-next-partial', 101);
        $nextPartialNation = $this->rawNation($nextPartial, 1, '次区間既存国');
        $rulesetsBefore = DB::table('ruleset_versions')
            ->orderBy('id')->get(['id', 'key', 'version', 'settings'])->toJson();

        Schema::drop('nation_monster_cycle_seed_requirements');
        Schema::drop('nation_awards');
        Schema::drop('nation_monster_cycle_stats');
        $migration = require database_path(
            'migrations/2026_08_09_040000_create_nation_awards_and_monster_cycles.php',
        );
        $migration->up();

        $this->assertTrue(Schema::hasTable('nation_awards'));
        $this->assertTrue(Schema::hasTable('nation_monster_cycle_stats'));
        $this->assertTrue(Schema::hasTable('nation_monster_cycle_seed_requirements'));
        $this->assertDatabaseHas('worlds', ['id' => $world->id, 'ruleset_version_id' => $world->ruleset_version_id]);
        $this->assertDatabaseHas('nations', ['id' => $nation->id, 'world_id' => $world->id]);
        $this->assertDatabaseHas('nation_monster_kill_stats', [
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'monster_definition_id' => $inora->id,
            'kill_count' => 1,
        ]);
        $this->assertSame(0, DB::table('nation_awards')->count());
        $this->assertSame(0, DB::table('nation_monster_cycle_stats')->count());
        $this->assertDatabaseHas('nation_monster_cycle_seed_requirements', [
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'cycle_start_turn' => 1,
            'cycle_end_turn' => 100,
            'completed_at' => null,
        ]);
        $this->assertDatabaseMissing('nation_monster_cycle_seed_requirements', [
            'world_id' => $boundaryZero->id,
            'nation_id' => $boundaryZeroNation->id,
        ]);
        $this->assertDatabaseMissing('nation_monster_cycle_seed_requirements', [
            'world_id' => $boundaryHundred->id,
            'nation_id' => $boundaryHundredNation->id,
        ]);
        $this->assertDatabaseHas('nation_monster_cycle_seed_requirements', [
            'world_id' => $nextPartial->id,
            'nation_id' => $nextPartialNation->id,
            'cycle_start_turn' => 101,
            'cycle_end_turn' => 200,
            'completed_at' => null,
        ]);
        $this->assertSame(2, DB::table('nation_monster_cycle_seed_requirements')->count());

        $newNation = $this->rawNation($nextPartial, 2, '移行後新規国');
        $newNationToken = "SEED-{$nextPartial->key}-N{$newNation->id}-101-200-0";
        $this->assertSame(1, Artisan::call('hakoniwa:awards:seed-monster-cycle', [
            '--world' => $nextPartial->key,
            '--nation' => (string) $newNation->id,
            '--kills' => '0',
            '--confirm' => $newNationToken,
        ]));
        $this->assertStringContainsString('no legacy seed requirement', Artisan::output());
        $this->assertDatabaseMissing('nation_monster_cycle_stats', [
            'world_id' => $nextPartial->id,
            'nation_id' => $newNation->id,
            'cycle_start_turn' => 101,
        ]);
        $this->assertSame($rulesetsBefore, DB::table('ruleset_versions')
            ->orderBy('id')->get(['id', 'key', 'version', 'settings'])->toJson());
    }

    private function worldAt(World $source, string $key, int $currentTurn): World
    {
        return World::query()->create([
            'key' => $key,
            'name' => $key,
            'ruleset_version_id' => $source->ruleset_version_id,
            'current_turn' => $currentTurn,
        ]);
    }

    private function rawNation(World $world, int $nationNumber, string $name): Nation
    {
        return Nation::query()->create([
            'world_id' => $world->id,
            'nation_number' => $nationNumber,
            'registered_turn' => max(1, (int) $world->current_turn),
            'name' => $name,
            'owner_name' => '移行監査島主',
            'profile_comment' => '',
            'money' => 100,
            'state' => 'active',
            'idle_counter' => 100,
        ]);
    }
}
