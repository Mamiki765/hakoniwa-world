<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Models\MonsterDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class AwardMigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_forward_migration_preserves_existing_world_nation_kills_and_published_rulesets_without_backfill(): void
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
        $rulesetsBefore = DB::table('ruleset_versions')
            ->orderBy('id')->get(['id', 'key', 'version', 'settings'])->toJson();

        Schema::drop('nation_awards');
        Schema::drop('nation_monster_cycle_stats');
        $migration = require database_path(
            'migrations/2026_08_09_040000_create_nation_awards_and_monster_cycles.php',
        );
        $migration->up();

        $this->assertTrue(Schema::hasTable('nation_awards'));
        $this->assertTrue(Schema::hasTable('nation_monster_cycle_stats'));
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
        $this->assertSame($rulesetsBefore, DB::table('ruleset_versions')
            ->orderBy('id')->get(['id', 'key', 'version', 'settings'])->toJson());
    }
}
