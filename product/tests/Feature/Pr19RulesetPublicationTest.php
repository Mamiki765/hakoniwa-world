<?php

namespace Tests\Feature;

use App\Application\OceanWorldGenerator;
use App\Models\RulesetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Pr19RulesetPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pr19_publication_updates_only_unit_catalog_and_never_repoints_a_historical_world(): void
    {
        $pr18 = RulesetVersion::query()->where('key', 'roadmap-pr18-v1')->firstOrFail();
        $pr18Snapshot = $pr18->settings;
        $world = app(OceanWorldGenerator::class)->initialize();
        $world->update(['ruleset_version_id' => $pr18->id]);
        DB::table('resource_definitions')->where('key', 'industrial_goods')->update([
            'unit' => 'unit', 'unit_label' => null,
        ]);
        DB::table('resource_definitions')->where('key', 'minerals')->update([
            'unit' => 'unit', 'unit_label' => null,
        ]);

        $migration = require database_path('migrations/2026_08_04_020000_publish_roadmap_pr19_ruleset.php');
        $migration->up();

        $pr19 = RulesetVersion::query()->where('key', 'roadmap-pr19-v1')->firstOrFail();
        $this->assertEquals(config('hakoniwa.published_rulesets.roadmap-pr19-v1'), $pr19->settings);
        $this->assertSame($pr18->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($pr18Snapshot, $pr18->fresh()->settings);
        $this->assertSame([
            'industrial_goods' => ['unit' => 'unit', 'unit_label' => 'ユニット'],
            'minerals' => ['unit' => 'ton', 'unit_label' => 'トン'],
        ], DB::table('resource_definitions')->whereIn('key', ['industrial_goods', 'minerals'])
            ->orderBy('key')->get()->mapWithKeys(fn (object $row): array => [
                $row->key => ['unit' => $row->unit, 'unit_label' => $row->unit_label],
            ])->all());
    }
}
