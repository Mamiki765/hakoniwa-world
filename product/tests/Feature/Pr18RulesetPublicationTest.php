<?php

namespace Tests\Feature;

use App\Application\OceanWorldGenerator;
use App\Models\RulesetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Pr18RulesetPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pr18_publication_is_immutable_and_does_not_repoint_a_historical_world(): void
    {
        $pr15 = RulesetVersion::query()->where('key', 'roadmap-pr15-v1')->firstOrFail();
        $pr15Snapshot = $pr15->settings;
        $world = app(OceanWorldGenerator::class)->initialize();
        $world->update(['ruleset_version_id' => $pr15->id]);

        $migration = require database_path('migrations/2026_08_04_000000_publish_roadmap_pr18_ruleset.php');
        $migration->up();

        $pr18 = RulesetVersion::query()->where('key', 'roadmap-pr18-v1')->firstOrFail();
        $this->assertEquals(config('hakoniwa.published_rulesets.roadmap-pr18-v1'), $pr18->settings);
        $this->assertSame($pr15->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($pr15Snapshot, $pr15->fresh()->settings);
    }
}
