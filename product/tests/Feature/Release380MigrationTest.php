<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Models\AuctionListing;
use App\Models\UndergroundBattle;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\RestoresPre380Schema;
use Tests\TestCase;

final class Release380MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use RestoresPre380Schema;

    public function test_exact_3_7_3_schema_upgrades_with_listing_history_and_actual_content_counts(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '移行島', '島主');
        $secretary = $user->secretary()->firstOrFail();
        $secretary->update(['name' => '既存秘書', 'named_at' => now()]);
        $profile = UndergroundProfile::query()->firstOrCreate(['secretary_id' => $secretary->id]);
        $before = $profile->refresh()->getRawOriginal();
        $item = $secretary->itemInstances()->create([
            'item_key' => 'inora_bracelet', 'level' => 1, 'equipped_slot' => null,
            'grant_key' => 'upgrade:history', 'obtained_at' => now(),
        ]);

        $this->return390PersistenceToPre390Source();
        $this->returnPartyPersistenceToPre380Source();
        $this->returnAuctionItemHistoryToPre380Source();
        $this->assertFalse(Schema::hasColumn('auction_listings', 'original_secretary_item_instance_id'));
        $this->assertFalse(Schema::hasColumn('secretaries', 'nickname'));
        $this->assertFalse(Schema::hasTable('underground_parties'));
        $listing = AuctionListing::query()->create([
            'world_id' => $world->id, 'seller_type' => 'nation', 'seller_nation_id' => $nation->id,
            'product_type' => 'item', 'secretary_item_instance_id' => $item->id,
            'item_key' => $item->item_key, 'item_level' => 1, 'start_price' => 100,
            'duration_turns' => 3, 'started_turn' => 0, 'ends_turn' => 3,
            'auto_relist' => false, 'status' => 'cancelled', 'completed_turn' => 0,
        ]);
        foreach (['shallow_caves' => 50, 'black_crystal_cave' => 1, 'trial_01' => 5] as $key => $count) {
            for ($index = 0; $index < $count; $index++) {
                $trial = $key === 'trial_01';
                UndergroundBattle::query()->create([
                    'underground_profile_id' => $profile->id, 'request_id' => (string) Str::uuid(),
                    'request_fingerprint' => str_repeat('a', 64), 'runtime_identity' => 'historical-3.7.3',
                    'activity_type' => $trial ? 'trial' : 'exploration', 'activity_key' => $key,
                    'encounter_key' => 'giant_rat', 'result' => 'victory', 'rounds' => 1,
                    'trial_run_key' => $trial ? (string) Str::uuid() : null,
                    'trial_battle_index' => $trial ? 10 : null,
                    'damage_dealt' => 1, 'damage_received' => 0, 'healing_done' => 0,
                    'combat_level_before' => 1, 'combat_level_after' => 1,
                    'combat_xp_before' => 0, 'combat_xp_after' => 0,
                    'shard_balance_before' => 0, 'shard_balance_after' => 0,
                    'private_seed' => 1, 'snapshot' => $trial ? ['trial_status' => 'cleared'] : [],
                    'started_at' => now()->subDay(), 'finished_at' => now()->subDay(),
                ]);
            }
        }
        $historicalBattles = DB::table('underground_battles')->orderBy('id')->get()->toArray();

        // Execute all actual forward migrations, including review fixes, without a hand-written ledger.
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertSame('hakoniwa-2s-plus-v23', $world->fresh()->rulesetVersion()->value('key'));
        $this->assertTrue(Schema::hasTable('user_paradox_balances'));
        $this->assertTrue(Schema::hasTable('compensation_grants'));
        $this->assertTrue(Schema::hasTable('guide_conversation_topics'));

        $this->assertDatabaseHas('auction_listings', [
            'id' => $listing->id, 'original_secretary_item_instance_id' => $item->id, 'status' => 'cancelled',
        ]);
        $item->delete();
        $this->assertDatabaseHas('auction_listings', [
            'id' => $listing->id, 'original_secretary_item_instance_id' => $item->id,
            'secretary_item_instance_id' => null,
        ]);
        foreach (['shallow_caves' => 50, 'black_crystal_cave' => 1, 'trial_01' => 5] as $key => $count) {
            $this->assertDatabaseHas('underground_content_clear_progress', [
                'underground_profile_id' => $profile->id, 'content_key' => $key,
                'actual_clear_count' => $count, 'total_clear_count' => $count,
            ]);
        }
        foreach ($historicalBattles as $battle) {
            $after = DB::table('underground_battles')->where('id', $battle->id)->first();
            unset($after->underground_party_id);
            $this->assertEquals($battle, $after);
        }
        $this->assertSame($before, $profile->fresh()->getRawOriginal());
        $this->assertSame('既存秘書', $secretary->fresh()->name);
        $this->assertNull($secretary->fresh()->nickname);
        $this->assertSame('full_body', $secretary->fresh()->portrait_preference);
    }
}
