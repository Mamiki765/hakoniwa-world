<?php

namespace Tests\Shared\Feature;

use App\Application\Ver440RulesetUpgrade;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

final class Release440MigrationTest extends TestCase
{
    use UsesIndividualTestWorld;

    public function test_populated_4_3_2_schema_upgrades_without_losing_secretary_assets_or_underground_progress(): void
    {
        // Build the supported source without the TestCase fresh-install listener
        // publishing the current Ruleset before the release migration runs.
        Event::forget(MigrationsEnded::class);
        Event::forget(NoPendingMigrations::class);
        $sourceMigrations = array_values(array_filter(
            glob(database_path('migrations/*.php')) ?: [],
            static fn (string $path): bool => basename($path) <= '2026_09_18_000000_allow_configured_trial_reward_lengths.php',
        ));
        $this->assertNotEmpty($sourceMigrations);
        $this->assertSame(0, Artisan::call('migrate:fresh', [
            '--path' => $sourceMigrations,
            '--realpath' => true,
            '--force' => true,
        ]));
        $this->assertDatabaseMissing('ruleset_versions', ['key' => Ver440RulesetUpgrade::TARGET_KEY]);

        $createdAt = '2026-09-20 00:00:00+00';
        DB::table('users')->insert([
            'id' => 1001, 'display_name' => 'Migration tester', 'visitor_code' => 'MIGR0001',
        ]);
        DB::table('secretaries')->insert([
            'id' => 1001, 'user_id' => 1001, 'name' => 'Tester', 'named_at' => $createdAt,
            'monster_experience' => 123, 'equipment_version' => 7,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        DB::table('secretary_item_instances')->insert([
            'id' => 1001, 'secretary_id' => 1001, 'item_key' => 'old_bow', 'level' => 4,
            'equipped_slot' => 1, 'grant_key' => 'migration-test', 'obtained_at' => $createdAt,
        ]);
        DB::table('underground_profiles')->insert([
            'id' => 1001, 'secretary_id' => 1001, 'combat_xp' => 17,
            'shard_balance' => 83, 'banked_shard_balance' => 29, 'unlocked_area_layers' => 2,
        ]);
        DB::table('user_skip_ticket_balances')->insert([
            'id' => 1001, 'user_id' => 1001, 'balance' => 12,
        ]);

        $this->assertSame(0, Artisan::call('migrate', [
            '--path' => [database_path('migrations/2026_09_23_030000_install_4_4_0.php')],
            '--realpath' => true,
            '--force' => true,
        ]));

        $this->assertDatabaseHas('secretary_surface_states', [
            'secretary_id' => 1001, 'monster_experience' => 123, 'equipment_version' => 7,
        ]);
        $this->assertFalse(Schema::hasColumn('secretaries', 'monster_experience'));
        $this->assertFalse(Schema::hasColumn('secretaries', 'equipment_version'));
        $this->assertDatabaseHas('secretary_item_instances', [
            'id' => 1001, 'secretary_id' => 1001, 'item_key' => 'old_bow',
            'level' => 4, 'equipped_slot' => 1,
        ]);
        $this->assertDatabaseHas('underground_profiles', [
            'id' => 1001, 'secretary_id' => 1001, 'combat_xp' => 17,
            'shard_balance' => 83, 'banked_shard_balance' => 29, 'unlocked_area_layers' => 2,
        ]);
        $this->assertDatabaseHas('user_skip_ticket_balances', [
            'user_id' => 1001, 'balance' => 12, 'lifetime_participation_count' => 0,
        ]);
        $this->assertDatabaseHas('ruleset_versions', [
            'key' => Ver440RulesetUpgrade::TARGET_KEY, 'version' => Ver440RulesetUpgrade::TARGET_VERSION,
        ]);
        $this->assertDatabaseHas('migrations', ['migration' => '2026_09_23_030000_install_4_4_0']);
    }

    protected function tearDown(): void
    {
        // The next fixture must rebuild its own baseline after this explicit rebuild.
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }
}
