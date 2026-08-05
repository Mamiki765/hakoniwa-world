<?php

namespace Tests\Feature;

use App\Application\RulesetPublisher;
use App\Models\MonumentDefinition;
use App\Models\RulesetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class Pr22MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_pr22_migration_publishes_command_event_state_without_repointing_a_historical_world(): void
    {
        $this->assertTrue(Schema::hasColumn('nations', 'idle_counter'));
        $this->assertTrue(Schema::hasColumn('facility_definitions', 'disguise_ownership_policy'));
        $this->assertTrue(Schema::hasTable('monument_definitions'));
        $this->assertTrue(Schema::hasColumn('map_cells', 'monument_definition_id'));
        $this->assertTrue(Schema::hasColumns('audit_events', [
            'world_id', 'turn', 'nation_id', 'x', 'y', 'message', 'visibility', 'severity',
        ]));
        $this->assertSame(
            ['peace', 'prosperity', 'victory'],
            MonumentDefinition::query()->orderBy('sort_order')->pluck('key')->all(),
        );
        $this->assertSame('tile.scorched', DB::table('terrain_definitions')
            ->where('key', 'scorched')->value('asset_key'));
        $this->assertSame([
            'decoy' => 'build_decoy',
            'defense' => 'build_defense_facility',
            'monument' => 'build_monument',
            'seabed_base' => 'build_seabed_base',
        ], DB::table('facility_definitions')->whereIn('key', [
            'defense', 'seabed_base', 'monument', 'decoy',
        ])->orderBy('key')->pluck('build_command_key', 'key')->all());
        $this->assertSame(
            ['display_as_facility_key' => 'defense'],
            json_decode((string) DB::table('facility_definitions')->where('key', 'decoy')->value('metadata'), true, 512, JSON_THROW_ON_ERROR),
        );

        $pr21 = RulesetVersion::query()->where('key', 'roadmap-pr21-v1')->firstOrFail();
        $pr22 = RulesetVersion::query()->where('key', 'roadmap-pr22-v1')->firstOrFail();
        $historicalPayload = $pr21->settings;
        $world = $this->lightweightWorld();
        $world->update(['ruleset_version_id' => $pr21->id]);

        app(RulesetPublisher::class)->publish(config('hakoniwa.published_rulesets.roadmap-pr22-v1'));

        $this->assertSame($pr21->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($historicalPayload, $pr21->fresh()->settings);
        $this->assertSame(25, DB::table('command_definitions')
            ->where('ruleset_version_id', $pr22->id)->count());
        $this->assertSame('build_missile_base', DB::table('facility_definitions')
            ->where('key', 'missile_base')->value('build_command_key'));
        $this->assertSame('neutral', DB::table('facility_definitions')
            ->where('key', 'seabed_base')->value('disguise_ownership_policy'));
        $this->assertNull(DB::table('facility_definitions')
            ->where('key', 'missile_base')->value('disguise_ownership_policy'));
        $this->assertSame([
            'audit_events_severity_check', 'audit_events_visibility_check', 'nations_idle_counter_check',
        ], DB::table('pg_constraint')->whereIn('conname', [
            'audit_events_severity_check', 'audit_events_visibility_check', 'nations_idle_counter_check',
        ])->orderBy('conname')->pluck('conname')->all());
    }

    public function test_pr22_schema_and_ruleset_publication_are_forward_only(): void
    {
        $migration = require database_path(
            'migrations/2026_08_05_010000_add_pr22_command_event_state_and_publish_ruleset.php',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forward-only');
        $migration->down();
    }
}
