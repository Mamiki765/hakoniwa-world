<?php

namespace Tests\Feature;

use App\Models\Secretary;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class Release380MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_3_7_3_schema_upgrades_forward_without_rewriting_existing_secretary_or_profile(): void
    {
        $user = User::factory()->create();
        $secretary = Secretary::query()->create([
            'user_id' => $user->id,
            'name' => '既存秘書',
            'named_at' => now(),
        ]);
        $profile = UndergroundProfile::query()->create(['secretary_id' => $secretary->id]);
        $secretaryBefore = DB::table('secretaries')->where('id', $secretary->id)
            ->first(['id', 'user_id', 'name', 'named_at', 'profile_biography']);
        $profileBefore = DB::table('underground_profiles')->where('id', $profile->id)->first();

        $this->returnToExact373Schema();

        $this->assertFalse(Schema::hasColumn('secretaries', 'nickname'));
        $this->assertFalse(Schema::hasColumn('underground_battles', 'underground_party_id'));
        $this->assertFalse(Schema::hasTable('underground_parties'));
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_09_08_010000_add_secretary_nickname_and_image_slots.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_09_08_100000_add_underground_party_lending_persistence.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_09_08_110000_add_underground_skip_consumption.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_09_08_120000_add_secretary_lending_build_cache.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('secretaries', 'nickname'));
        $this->assertTrue(Schema::hasColumn('secretaries', 'portrait_preference'));
        $this->assertTrue(Schema::hasColumn('underground_battles', 'underground_party_id'));
        foreach ([
            'secretary_images', 'underground_parties', 'underground_party_members',
            'secretary_lending_settings', 'secretary_lending_participations',
            'secretary_lending_daily_rewards', 'user_skip_ticket_balances',
            'user_skip_ticket_ledger', 'underground_content_clear_progress',
            'underground_skip_settlements', 'secretary_lending_build_snapshots',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertEquals($secretaryBefore, DB::table('secretaries')->where('id', $secretary->id)
            ->first(['id', 'user_id', 'name', 'named_at', 'profile_biography']));
        $this->assertNull(Secretary::query()->findOrFail($secretary->id)->nickname);
        $this->assertSame('full_body', Secretary::query()->findOrFail($secretary->id)->portrait_preference);
        $this->assertEquals($profileBefore, DB::table('underground_profiles')->where('id', $profile->id)->first());
    }

    private function returnToExact373Schema(): void
    {
        if (Schema::hasColumn('underground_owned_equipment', 'source_skip_settlement_id')) {
            DB::statement('ALTER TABLE underground_owned_equipment DROP CONSTRAINT underground_owned_equipment_instance_check');
            Schema::table('underground_owned_equipment', function (Blueprint $table): void {
                $table->dropForeign(['source_skip_settlement_id']);
                $table->dropUnique('underground_equipment_source_skip_reward_unique');
                $table->dropColumn(['source_skip_settlement_id', 'source_reward_index']);
            });
            DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment
  ADD CONSTRAINT underground_owned_equipment_instance_check
  CHECK (
    (
      instance_kind = 'fixed'
      AND instance_identity IS NULL
      AND generator_identity IS NULL
      AND generated_payload IS NULL
      AND source_battle_id IS NULL
    )
    OR
    (
      instance_kind = 'generated'
      AND instance_identity IS NOT NULL
      AND generator_identity IS NOT NULL
      AND generated_payload IS NOT NULL
      AND source_battle_id IS NOT NULL
      AND grant_key IS NOT NULL
    )
  )
SQL);
        }
        if (Schema::hasColumn('user_skip_ticket_ledger', 'underground_skip_settlement_id')) {
            Schema::table('user_skip_ticket_ledger', function (Blueprint $table): void {
                $table->dropForeign(['underground_skip_settlement_id']);
                $table->dropUnique('user_skip_ticket_ledger_skip_unique');
                $table->dropColumn('underground_skip_settlement_id');
            });
        }
        foreach ([
            'secretary_lending_build_snapshots',
            'underground_content_clear_progress',
            'underground_skip_settlements',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('underground_battles', function (Blueprint $table): void {
            $table->dropForeign(['underground_party_id']);
            $table->dropUnique(['underground_party_id']);
            $table->dropColumn('underground_party_id');
        });
        foreach ([
            'user_skip_ticket_ledger',
            'secretary_lending_participations',
            'secretary_lending_daily_rewards',
            'user_skip_ticket_balances',
            'underground_party_members',
            'secretary_lending_settings',
            'underground_parties',
            'secretary_images',
        ] as $table) {
            Schema::drop($table);
        }
        DB::statement('ALTER TABLE secretaries DROP CONSTRAINT secretaries_nickname_check');
        DB::statement('ALTER TABLE secretaries DROP CONSTRAINT secretaries_portrait_preference_check');
        Schema::table('secretaries', function (Blueprint $table): void {
            $table->dropColumn(['nickname', 'portrait_preference']);
        });
        DB::table('migrations')->whereIn('migration', [
            '2026_09_08_010000_add_secretary_nickname_and_image_slots',
            '2026_09_08_100000_add_underground_party_lending_persistence',
            '2026_09_08_110000_add_underground_skip_consumption',
            '2026_09_08_120000_add_secretary_lending_build_cache',
        ])->delete();
    }
}
