<?php

namespace Tests\Feature;

use App\Application\OceanWorldGenerator;
use App\Models\RulesetVersion;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RulesetValidationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_command_loads_only_current_authoring_while_normal_config_stays_current_only(): void
    {
        config(['hakoniwa' => require config_path('hakoniwa.php')]);
        $currentKeys = ['hakoniwa-2s-plus-v19'];

        $this->assertSame($currentKeys, array_keys(config('hakoniwa.published_rulesets')));

        $this->artisan('hakoniwa:ruleset:validate', ['--key' => 'hakoniwa-2s-plus-v19'])
            ->expectsOutputToContain('Ruleset hakoniwa-2s-plus-v19 is valid: version=19')
            ->assertSuccessful();

        $this->assertSame($currentKeys, array_keys(config('hakoniwa.published_rulesets')));
    }

    public function test_validation_command_reports_summary_without_mutating_database_or_world_ruleset(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $before = $this->databaseSnapshot($world);

        $this->artisan('hakoniwa:ruleset:validate')
            ->expectsOutputToContain('Ruleset hakoniwa-2s-plus-v19 is valid: version=19')
            ->assertSuccessful();

        $this->assertSame($before, $this->databaseSnapshot($world->fresh()));
    }

    public function test_validation_command_fails_for_unknown_key_without_mutating_database(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $before = $this->databaseSnapshot($world);

        $this->artisan('hakoniwa:ruleset:validate', ['--key' => 'does-not-exist'])
            ->expectsOutputToContain('does-not-exist is not the current key hakoniwa-2s-plus-v19')
            ->assertFailed();

        $this->assertSame($before, $this->databaseSnapshot($world->fresh()));
    }

    /** @return array<string, mixed> */
    private function databaseSnapshot(World $world): array
    {
        return [
            'ruleset_count' => RulesetVersion::query()->count(),
            'command_count' => DB::table('command_definitions')->count(),
            'production_count' => DB::table('production_definitions')->count(),
            'world_count' => World::query()->count(),
            'world_ruleset_version_id' => $world->ruleset_version_id,
            'world_current_turn' => $world->current_turn,
            'rulesets' => RulesetVersion::query()->orderBy('id')
                ->get(['id', 'key', 'version', 'settings', 'updated_at'])
                ->toArray(),
        ];
    }
}
