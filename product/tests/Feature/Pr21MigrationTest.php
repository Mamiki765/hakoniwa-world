<?php

namespace Tests\Feature;

use App\Application\RulesetPublisher;
use App\Models\MonsterDefinition;
use App\Models\RulesetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class Pr21MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_pr21_migration_publishes_monster_schema_without_repointing_a_historical_world(): void
    {
        foreach (['monster_definitions', 'monster_instances', 'monster_occupancies', 'monster_kill_records'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $pr19 = RulesetVersion::query()->where('key', 'roadmap-pr19-v1')->firstOrFail();
        $pr21 = RulesetVersion::query()->where('key', 'roadmap-pr21-v1')->firstOrFail();
        $historicalPayload = $pr19->settings;
        $world = $this->lightweightWorld();
        $world->update(['ruleset_version_id' => $pr19->id]);

        app(RulesetPublisher::class)->publish(config('hakoniwa.published_rulesets.roadmap-pr21-v1'));

        $this->assertSame($pr19->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($historicalPayload, $pr19->fresh()->settings);
        $this->assertSame(8, MonsterDefinition::query()->where('ruleset_version_id', $pr21->id)->count());
        $this->assertSame(
            ['monster_instance_world_ruleset_guard', 'monster_kill_record_guard', 'monster_kill_record_immutable', 'monster_occupancy_guard'],
            DB::table('pg_trigger')->whereIn('tgname', [
                'monster_instance_world_ruleset_guard',
                'monster_kill_record_guard',
                'monster_kill_record_immutable',
                'monster_occupancy_guard',
            ])->orderBy('tgname')->pluck('tgname')->all(),
        );
    }

    public function test_pr21_monster_schema_migration_is_forward_only(): void
    {
        $migration = require database_path(
            'migrations/2026_08_05_000000_create_monster_system_and_publish_roadmap_pr21_ruleset.php',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forward-only');
        $migration->down();
    }
}
