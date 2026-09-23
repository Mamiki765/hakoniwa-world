<?php

namespace Tests\Feature;

use App\Models\RulesetVersion;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RulesetValidationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_command_reports_summary_without_mutating_database(): void
    {
        config(['hakoniwa' => require config_path('hakoniwa.php')]);
        $currentKeys = ['hakoniwa-2s-plus-v27'];
        $before = $this->databaseSnapshot();

        $this->assertSame($currentKeys, array_keys(config('hakoniwa.published_rulesets')));

        $this->artisan('hakoniwa:ruleset:validate')
            ->expectsOutputToContain('Ruleset hakoniwa-2s-plus-v27 is valid: version=27')
            ->assertSuccessful();

        $this->assertSame($currentKeys, array_keys(config('hakoniwa.published_rulesets')));
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_validation_command_fails_for_unknown_key_without_mutating_database(): void
    {
        $before = $this->databaseSnapshot();

        $this->artisan('hakoniwa:ruleset:validate', ['--key' => 'does-not-exist'])
            ->expectsOutputToContain('does-not-exist is not the current key hakoniwa-2s-plus-v27')
            ->assertFailed();

        $this->assertSame($before, $this->databaseSnapshot());
    }

    /** @return array<string, mixed> */
    private function databaseSnapshot(): array
    {
        return [
            'ruleset_count' => RulesetVersion::query()->count(),
            'command_count' => DB::table('command_definitions')->count(),
            'production_count' => DB::table('production_definitions')->count(),
            'world_count' => World::query()->count(),
            'rulesets' => RulesetVersion::query()->orderBy('id')
                ->get(['id', 'key', 'version', 'settings', 'updated_at'])
                ->toArray(),
        ];
    }
}
