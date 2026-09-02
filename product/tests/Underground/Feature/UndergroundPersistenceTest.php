<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundEquipmentLoadoutResolver;
use App\Application\Underground\UndergroundProfileService;
use App\Application\Underground\UndergroundStarterEquipmentService;
use App\Models\Secretary;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\UndergroundSkillAllocation;
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
        $this->assertSame([0, 1, 0, 0, 0, null, null, null, null, null, null, null, 0, 0, 0, 0, 0, 0, 0, 0, null, 0, null], [
            $first->unlocked_area_layers,
            $first->combat_level,
            $first->combat_xp,
            $first->shard_balance,
            $first->banked_shard_balance,
            $first->current_hp,
            $first->next_battle_at,
            $first->underground_contract_completed_at,
            $first->growth_path_key,
            $first->growth_path_identity,
            $first->growth_path_selected_at,
            $first->last_respec_at,
            $first->unspent_stp,
            $first->allocated_vitality_stp,
            $first->allocated_might_stp,
            $first->allocated_finesse_stp,
            $first->allocated_spirit_stp,
            $first->allocated_agility_stp,
            $first->skill_points_total,
            $first->skill_points_unspent,
            $first->skill_tree_identity,
            $first->awakening_gauge,
            $first->awakening_message,
        ]);
        $this->assertSame($first->id, $secretary->undergroundProfile()->sole()->id);
        $this->assertSame(1, UndergroundProfile::query()->where('secretary_id', $secretary->id)->count());

        $columns = Schema::getColumnListing('underground_profiles');
        sort($columns);
        $this->assertSame([
            'allocated_agility_stp',
            'allocated_finesse_stp',
            'allocated_might_stp',
            'allocated_spirit_stp',
            'allocated_vitality_stp',
            'awakening_gauge',
            'awakening_message',
            'banked_shard_balance',
            'combat_level',
            'combat_xp',
            'created_at',
            'current_hp',
            'growth_path_identity',
            'growth_path_key',
            'growth_path_selected_at',
            'id',
            'last_respec_at',
            'next_battle_at',
            'secretary_id',
            'shard_balance',
            'skill_points_total',
            'skill_points_unspent',
            'skill_tree_identity',
            'underground_contract_completed_at',
            'unlocked_area_layers',
            'unspent_stp',
            'updated_at',
        ], $columns);
        $this->assertNotContains('current_mp', $columns);
        $this->assertTrue(Schema::hasTable('underground_trial_progress'));
        $this->assertTrue(Schema::hasTable('underground_trial_runs'));
        $this->assertTrue(Schema::hasTable('underground_battles'));
        $this->assertTrue(Schema::hasTable('underground_battle_logs'));
        $this->assertTrue(Schema::hasTable('underground_intro_progress'));
        $this->assertTrue(Schema::hasTable('underground_intro_requests'));
        $this->assertTrue(Schema::hasTable('underground_skill_allocations'));
        $this->assertTrue(Schema::hasTable('underground_owned_equipment'));
        foreach ([
            'underground_trial_progress', 'underground_trial_runs', 'underground_battles',
            'underground_intro_progress', 'underground_intro_requests', 'underground_owned_equipment',
        ] as $table) {
            $this->assertNotContains('user_id', Schema::getColumnListing($table));
            $this->assertNotContains('world_id', Schema::getColumnListing($table));
            $this->assertNotContains('nation_id', Schema::getColumnListing($table));
            $this->assertNotContains('turn_run_id', Schema::getColumnListing($table));
        }
    }

    public function test_starter_equipment_reconciles_exactly_once_and_owned_instances_remain_extensible(): void
    {
        $secretary = $this->secretary();
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update([
            'underground_contract_completed_at' => now(),
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
            'current_hp' => 492,
        ]);
        $service = app(UndergroundStarterEquipmentService::class);

        $first = DB::transaction(fn (): UndergroundOwnedEquipment => $service->reconcile($profile));
        $second = DB::transaction(fn (): UndergroundOwnedEquipment => $service->reconcile($profile));

        $this->assertSame($first->id, $second->id);
        $this->assertSame([
            'starter_knife',
            'secretary-underground-shop-equipment-alpha-v2',
            'weapon',
            UndergroundStarterEquipmentService::GRANT_KEY,
        ], [
            $first->definition_key,
            $first->catalog_identity,
            $first->equipped_slot,
            $first->grant_key,
        ]);
        $this->assertSame(1, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', UndergroundEquipmentCatalog::STARTER_KEY)
            ->count());
        $this->assertSame(1, app(UndergroundEquipmentLoadoutResolver::class)->summary($profile)['used']);

        foreach ([1, 2] as $offset) {
            UndergroundOwnedEquipment::query()->create([
                'underground_profile_id' => $profile->id,
                'definition_key' => 'iron_dagger',
                'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v1',
                'equipped_slot' => null,
                'grant_key' => null,
                'acquired_at' => now()->addSecond($offset),
            ]);
        }
        $this->assertSame(2, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'iron_dagger')
            ->count());

        $secretary->delete();
        $this->assertSame(0, UndergroundOwnedEquipment::query()->count());
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
            static fn () => $profile->update(['banked_shard_balance' => -1]),
            static fn () => $profile->update(['current_hp' => 0]),
            static fn () => $profile->update(['awakening_gauge' => 1_001]),
            static fn () => $profile->update(['awakening_message' => "invalid\nmessage"]),
            static fn () => $profile->update(['unspent_stp' => 1]),
            static fn () => $profile->update(['skill_points_total' => 1, 'skill_points_unspent' => 1]),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Expected the Underground profile database constraint to reject the mutation.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $persisted = $profile->fresh();
        $this->assertSame([0, 1, 0, 0, 0, null], [
            $persisted?->unlocked_area_layers,
            $persisted?->combat_level,
            $persisted?->combat_xp,
            $persisted?->shard_balance,
            $persisted?->banked_shard_balance,
            $persisted?->current_hp,
        ]);
        $profile->refresh();
        $profile->update([
            'underground_contract_completed_at' => now(),
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 15,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
            'current_hp' => 492,
        ]);
        UndergroundSkillAllocation::query()->create([
            'underground_profile_id' => $profile->id,
            'node_key' => 'miracle_holy_bolt',
            'rank' => 1,
            'active_slot' => 1,
        ]);
        foreach ([
            static fn () => UndergroundSkillAllocation::query()->create([
                'underground_profile_id' => $profile->id,
                'node_key' => 'miracle_mending_prayer',
                'rank' => 1,
                'active_slot' => 1,
            ]),
            static fn () => UndergroundSkillAllocation::query()->create([
                'underground_profile_id' => $profile->id,
                'node_key' => 'invalid_rank',
                'rank' => 0,
                'active_slot' => null,
            ]),
            static fn () => UndergroundSkillAllocation::query()->create([
                'underground_profile_id' => $profile->id,
                'node_key' => 'invalid_slot',
                'rank' => 1,
                'active_slot' => 6,
            ]),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Expected the Underground skill allocation constraint to reject the mutation.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $secretary->delete();
        $this->assertDatabaseMissing('underground_profiles', ['id' => $profile->id]);
        $this->assertSame(0, UndergroundSkillAllocation::query()->count());
    }

    private function secretary(): Secretary
    {
        return Secretary::query()->create([
            'user_id' => User::factory()->create()->id,
        ]);
    }
}
