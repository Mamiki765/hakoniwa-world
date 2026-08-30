<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundProfileService;
use App\Models\Secretary;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UndergroundPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_secretary_has_one_lazy_profile_with_zero_default_and_no_surface_identity(): void
    {
        $secretary = $this->secretary();
        $service = app(UndergroundProfileService::class);

        $first = $service->ensureForSecretary($secretary)->refresh();
        $second = $service->ensureForSecretary($secretary);

        $this->assertSame($first->id, $second->id);
        $this->assertSame([0, 1, 0, 0, null, null, null, null, null], [
            $first->unlocked_area_layers,
            $first->combat_level,
            $first->combat_xp,
            $first->shard_balance,
            $first->next_battle_at,
            $first->underground_contract_completed_at,
            $first->growth_path_key,
            $first->growth_path_identity,
            $first->growth_path_selected_at,
        ]);
        $this->assertSame($first->id, $secretary->undergroundProfile()->sole()->id);
        $this->assertSame(1, UndergroundProfile::query()->where('secretary_id', $secretary->id)->count());

        $columns = Schema::getColumnListing('underground_profiles');
        sort($columns);
        $this->assertSame([
            'combat_level',
            'combat_xp',
            'created_at',
            'growth_path_identity',
            'growth_path_key',
            'growth_path_selected_at',
            'id',
            'next_battle_at',
            'secretary_id',
            'shard_balance',
            'underground_contract_completed_at',
            'unlocked_area_layers',
            'updated_at',
        ], $columns);
        $this->assertTrue(Schema::hasTable('underground_trial_progress'));
        $this->assertTrue(Schema::hasTable('underground_trial_runs'));
        $this->assertTrue(Schema::hasTable('underground_battles'));
        $this->assertTrue(Schema::hasTable('underground_battle_logs'));
        $this->assertTrue(Schema::hasTable('underground_intro_progress'));
        $this->assertTrue(Schema::hasTable('underground_intro_requests'));
        foreach ([
            'underground_trial_progress', 'underground_trial_runs', 'underground_battles',
            'underground_intro_progress', 'underground_intro_requests',
        ] as $table) {
            $this->assertNotContains('user_id', Schema::getColumnListing($table));
            $this->assertNotContains('world_id', Schema::getColumnListing($table));
            $this->assertNotContains('nation_id', Schema::getColumnListing($table));
            $this->assertNotContains('turn_run_id', Schema::getColumnListing($table));
        }
    }

    public function test_unlocked_layers_persist_and_derive_four_facility_slots_per_layer(): void
    {
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($this->secretary());

        $profile->update(['unlocked_area_layers' => 3]);
        $persisted = $profile->fresh();

        $this->assertNotNull($persisted);
        $this->assertSame(3, $persisted->unlocked_area_layers);
        $this->assertSame(12, $persisted->facilitySlotCapacity());
        $this->assertSame(0, DB::table('underground_profiles')->whereNotNull('secretary_id')
            ->where('unlocked_area_layers', '<', 0)->count());
    }

    public function test_database_enforces_one_nonnegative_profile_and_secretary_delete_cascades(): void
    {
        $secretary = $this->secretary();
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);

        foreach ([
            static fn () => UndergroundProfile::query()->create(['secretary_id' => $secretary->id]),
            static fn () => $profile->update(['unlocked_area_layers' => -1]),
            static fn () => $profile->update(['combat_level' => 0]),
            static fn () => $profile->update(['combat_xp' => -1]),
            static fn () => $profile->update(['shard_balance' => -1]),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Expected the Underground profile database constraint to reject the mutation.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $persisted = $profile->fresh();
        $this->assertSame([0, 1, 0, 0], [
            $persisted?->unlocked_area_layers,
            $persisted?->combat_level,
            $persisted?->combat_xp,
            $persisted?->shard_balance,
        ]);
        $secretary->delete();
        $this->assertDatabaseMissing('underground_profiles', ['id' => $profile->id]);
    }

    private function secretary(): Secretary
    {
        return Secretary::query()->create([
            'user_id' => User::factory()->create()->id,
        ]);
    }
}
