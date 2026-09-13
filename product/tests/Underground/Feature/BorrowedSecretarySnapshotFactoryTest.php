<?php

namespace Tests\Underground\Feature;

use App\Application\SecretaryProfilePresenter;
use App\Application\Underground\BorrowedSecretarySnapshotFactory;
use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundEquipmentLoadoutResolver;
use App\Application\Underground\UndergroundProfileService;
use App\Application\Underground\UndergroundRuntimeEquipmentGenerator;
use App\Application\Underground\UndergroundStarterEquipmentService;
use App\Domain\Underground\Combat\PriorityCombatAiConfiguration;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Models\Secretary;
use App\Models\SecretaryLendingBuildSnapshot;
use App\Models\UndergroundBattle;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BorrowedSecretarySnapshotFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_lower_level_snapshot_keeps_level_allocation_and_source_unchanged(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['visitor_code' => 'BORROW01'])->save();
        $secretary = Secretary::query()->create(['user_id' => $user->id, 'name' => 'Borrowed', 'named_at' => Carbon::now()]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $contractAt = Carbon::now()->subMinute();
        $profile->update([
            'underground_contract_completed_at' => $contractAt,
            'growth_path_key' => 'martial_red', 'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'combat_level' => 6, 'allocated_might_stp' => 20, 'allocated_vitality_stp' => 5,
            'current_hp' => 999999,
            'unspent_stp' => 0, 'skill_points_total' => 20, 'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
        ]);
        app(UndergroundStarterEquipmentService::class)->reconcile($profile->fresh());
        UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('equipped_slot', 'weapon')
            ->update(['definition_key' => 'iron_dagger', 'grant_key' => null]);
        UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => 'spirit_accessory_rank_3',
            'catalog_identity' => app(UndergroundEquipmentCatalog::class)->identity(),
            'equipped_slot' => 'accessory_1',
            'instance_kind' => 'fixed',
            'acquired_at' => Carbon::now(),
        ]);
        $before = $profile->fresh()->toArray();

        $snapshot = app(BorrowedSecretarySnapshotFactory::class)->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            3,
            ['weapon' => 3, 'accessory_1' => 10],
            $user,
            false,
        );

        $this->assertSame(6, $snapshot['original_combat_level']);
        $this->assertSame(3, $snapshot['effective_combat_level']);
        $this->assertSame(['vitality' => 5, 'might' => 20, 'finesse' => 0, 'spirit' => 0, 'agility' => 0], $snapshot['original_allocated_stp']);
        $this->assertSame(10, array_sum($snapshot['effective_allocated_stp']));
        $this->assertSame(999999, $snapshot['resources']['original_current_hp']);
        $this->assertSame($snapshot['player_snapshot']['current_hp'], $snapshot['resources']['effective_current_hp']);
        $this->assertLessThan(999999, $snapshot['resources']['effective_current_hp']);
        $this->assertArrayNotHasKey('party_healing_target_scope', $snapshot['player_snapshot']);
        $this->assertArrayNotHasKey('email', $snapshot['source']);
        $this->assertSame($before, $profile->fresh()->toArray());
        $effectiveItems = collect($snapshot['effective_equipment']['items'])->keyBy('equipped_slot');
        $this->assertSame('iron_dagger', $effectiveItems['weapon']['key']);
        $this->assertSame('spirit_accessory_rank_2', $effectiveItems['accessory_1']['key']);
        $this->assertSame(2, $snapshot['effective_equipment']['stats']['spirit']);
        $this->assertSame(0, $snapshot['effective_equipment']['stats']['vitality']);

        $uncapped = app(BorrowedSecretarySnapshotFactory::class)->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            99,
            ['weapon' => 99, 'accessory_1' => 99],
            $user,
            false,
        );
        $this->assertSame(6, $uncapped['effective_combat_level']);
        $uncappedItems = collect($uncapped['effective_equipment']['items'])->keyBy('equipped_slot');
        $this->assertSame('iron_dagger', $uncappedItems['weapon']['key']);
        $this->assertSame('spirit_accessory_rank_3', $uncappedItems['accessory_1']['key']);
        $cache = SecretaryLendingBuildSnapshot::query()->where('secretary_id', $secretary->id)->sole();
        $this->assertSame(2, $cache->projection_cache['schema_version']);
        $staleProjectionCache = $cache->projection_cache;
        $staleProjectionCache['schema_version'] = 1;
        $cache->update(['projection_cache' => $staleProjectionCache]);
        app(BorrowedSecretarySnapshotFactory::class)->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            99,
            ['weapon' => 99, 'accessory_1' => 99],
            $user,
            false,
        );
        $this->assertSame(2, $cache->refresh()->projection_cache['schema_version']);
        $cachedFingerprint = $cache->source_fingerprint;
        app(BorrowedSecretarySnapshotFactory::class)->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            99,
            ['weapon' => 99, 'accessory_1' => 99],
            $user,
            false,
        );
        $this->assertSame($cachedFingerprint, $cache->refresh()->source_fingerprint);
        $profile->update(['combat_level' => 7, 'unspent_stp' => 5]);
        app(BorrowedSecretarySnapshotFactory::class)->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            99,
            ['weapon' => 99, 'accessory_1' => 99],
            $user,
            false,
        );
        $this->assertNotSame($cachedFingerprint, $cache->refresh()->source_fingerprint);
    }

    public function test_generated_high_level_equipment_is_rerendered_at_leader_level_without_changing_original_identity(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['visitor_code' => 'BORROW02'])->save();
        $secretary = Secretary::query()->create(['user_id' => $user->id, 'name' => 'Generated lender', 'named_at' => Carbon::now()]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $contractAt = Carbon::now()->subMinute();
        $profile->update([
            'underground_contract_completed_at' => $contractAt,
            'growth_path_key' => 'martial_red', 'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'combat_level' => 6, 'unspent_stp' => 25,
            'skill_points_total' => 20, 'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
        ]);
        app(UndergroundStarterEquipmentService::class)->reconcile($profile->fresh());
        UndergroundOwnedEquipment::query()->where('underground_profile_id', $profile->id)
            ->where('equipped_slot', 'weapon')->update(['equipped_slot' => null]);
        $sourceBattle = $this->sourceBattle($profile);
        $generated = app(UndergroundRuntimeEquipmentGenerator::class)->generate(
            6,
            'shallow_caves',
            'epic',
            'weapon',
            'dagger',
            null,
            380,
            'borrowed-sync-test',
        );
        $item = UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => $generated['key'],
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => 'weapon',
            'grant_key' => 'drop:borrowed-sync-test',
            'instance_kind' => 'generated',
            'instance_identity' => $generated['instance_identity'],
            'generator_identity' => $generated['generator_identity'],
            'generated_payload' => $generated,
            'source_battle_id' => $sourceBattle->id,
            'acquired_at' => Carbon::now(),
        ]);
        $generatedArmor = app(UndergroundRuntimeEquipmentGenerator::class)->generate(
            6,
            'shallow_caves',
            'rare',
            'armor',
            null,
            null,
            382,
            'borrowed-sync-armor-test',
        );
        UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => $generatedArmor['key'],
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => 'armor',
            'grant_key' => 'drop:borrowed-sync-armor-test',
            'instance_kind' => 'generated',
            'instance_identity' => $generatedArmor['instance_identity'],
            'generator_identity' => $generatedArmor['generator_identity'],
            'generated_payload' => $generatedArmor,
            'source_battle_id' => $this->sourceBattle($profile)->id,
            'acquired_at' => Carbon::now(),
        ]);
        $before = $item->fresh()->toArray();

        $projectionCalls = [];
        $factory = $this->instrumentedFactory($projectionCalls);
        $snapshot = $factory->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            3,
            ['weapon' => 3],
            $user,
            false,
        );
        $firstProjectionCalls = $projectionCalls;
        $this->assertSame(1, $firstProjectionCalls['equipment_generator'] ?? 0);
        $this->assertSame(1, $firstProjectionCalls['combat_loadout'] ?? 0);
        $this->assertSame(1, $firstProjectionCalls['combat_definition'] ?? 0);

        $factory->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            3,
            ['weapon' => 3],
            $user,
            false,
        );
        $this->assertSame($firstProjectionCalls, $projectionCalls);

        $this->assertCount(2, $snapshot['original_equipment']);
        $this->assertSame(6, $snapshot['original_equipment'][0]['payload']['item_level']);
        $this->assertSame($generated['instance_identity'], $snapshot['original_equipment'][0]['instance_identity']);
        $this->assertSame(3, $snapshot['effective_equipment']['item_level']);
        $this->assertSame($generated['instance_identity'], $snapshot['effective_equipment']['items'][0]['instance_identity']);
        $this->assertCount(1, $snapshot['effective_equipment']['items']);
        $this->assertSame(
            array_column($generated['affixes'], 'key'),
            array_column($snapshot['effective_equipment']['affixes'], 'key'),
        );
        $this->assertLessThan($generated['weapon_power'], $snapshot['effective_equipment']['weapon_power']);
        $this->assertSame($before, $item->fresh()->toArray());

        $slotSynced = $factory->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            3,
            ['weapon' => 3, 'armor' => 2],
            $user,
            false,
        );
        $effectiveArmor = collect($slotSynced['effective_equipment']['items'])
            ->firstWhere('equipped_slot', 'armor');
        $this->assertIsArray($effectiveArmor);
        $this->assertSame(2, $effectiveArmor['item_level']);
        $this->assertSame(2, $projectionCalls['equipment_generator']);
        $this->assertSame(2, $projectionCalls['combat_definition']);

        $cache = SecretaryLendingBuildSnapshot::query()->where('secretary_id', $secretary->id)->sole();
        $this->assertCount(2, $cache->refresh()->projection_cache['slots']);
        $cachedFingerprint = $cache->source_fingerprint;
        $replacement = app(UndergroundRuntimeEquipmentGenerator::class)->generate(
            5,
            'shallow_caves',
            'epic',
            'weapon',
            'dagger',
            null,
            381,
            'borrowed-sync-test-updated',
        );
        $item->update([
            'definition_key' => $replacement['key'],
            'instance_identity' => $replacement['instance_identity'],
            'generated_payload' => $replacement,
        ]);
        $updated = app(BorrowedSecretarySnapshotFactory::class)->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            3,
            ['weapon' => 3],
            $user,
            false,
        );
        $this->assertSame(5, $updated['original_equipment'][0]['payload']['item_level']);
        $this->assertNotSame($cachedFingerprint, $cache->refresh()->source_fingerprint);
    }

    public function test_display_and_resource_changes_reuse_the_numeric_projection(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['visitor_code' => 'BORROW03'])->save();
        $secretary = Secretary::query()->create([
            'user_id' => $user->id,
            'name' => 'Display changes lender',
            'named_at' => Carbon::now(),
        ]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update([
            'underground_contract_completed_at' => Carbon::now()->subMinute(),
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'combat_level' => 6,
            'allocated_might_stp' => 20,
            'current_hp' => 999999,
            'unspent_stp' => 5,
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
        ]);
        app(UndergroundStarterEquipmentService::class)->reconcile($profile->fresh());

        $projectionCalls = [];
        $factory = $this->instrumentedFactory($projectionCalls);
        $first = $factory->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            3,
            ['weapon' => 3],
            $user,
            false,
        );
        $cache = SecretaryLendingBuildSnapshot::query()->where('secretary_id', $secretary->id)->sole();
        $projectionCache = $cache->projection_cache;
        $sourceFingerprint = $cache->source_fingerprint;
        $firstProjectionCalls = $projectionCalls;
        $this->assertIsArray($projectionCache);

        $secretary->update(['nickname' => '新表示']);
        $profile->update([
            'current_hp' => 1,
            'awakening_message' => '別の覚醒演出',
        ]);
        $second = $factory->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            3,
            ['weapon' => 3],
            $user,
            false,
        );

        $this->assertSame('新表示', $second['display_name']);
        $this->assertSame(1, $second['resources']['effective_current_hp']);
        $this->assertSame($first['effective_equipment'], $second['effective_equipment']);
        $this->assertSame($projectionCache, $cache->refresh()->projection_cache);
        $this->assertSame($sourceFingerprint, $cache->refresh()->source_fingerprint);
        $this->assertSame('別の覚醒演出', $second['awakening']['message']);
        $this->assertSame($firstProjectionCalls, $projectionCalls);

        $customAiRules = [[
            'conditions' => [['type' => 'always']],
            'action' => 'normal_attack',
            'target' => 'untaunted_enemy',
        ]];
        $profile->update(['custom_ai_rules' => $customAiRules]);
        $third = $factory->create(
            $secretary->fresh(['user', 'images']),
            $profile->fresh(),
            3,
            ['weapon' => 3],
            $user,
            false,
        );
        $this->assertSame($customAiRules, $third['ai']['rules']);
        $this->assertSame('custom', $third['player_snapshot']['ai_mode']);
        $this->assertSame($second['effective_equipment'], $third['effective_equipment']);
        $this->assertSame($projectionCache['slots'], $cache->refresh()->projection_cache['slots']);
        $this->assertSame($firstProjectionCalls, $projectionCalls);
    }

    private function sourceBattle(UndergroundProfile $profile): UndergroundBattle
    {
        return UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'runtime_identity' => 'borrowed-sync-test',
            'activity_type' => UndergroundBattle::ACTIVITY_EXPLORATION,
            'activity_key' => 'shallow_caves',
            'encounter_key' => 'cave_crawler',
            'result' => UndergroundBattle::RESULT_VICTORY,
            'rounds' => 1,
            'damage_dealt' => 1,
            'damage_received' => 0,
            'healing_done' => 0,
            'combat_level_before' => 6,
            'combat_level_after' => 6,
            'combat_xp_before' => 0,
            'combat_xp_after' => 0,
            'shard_balance_before' => 0,
            'shard_balance_after' => 0,
            'private_seed' => 380,
            'snapshot' => [],
            'started_at' => Carbon::now(),
            'finished_at' => Carbon::now(),
        ]);
    }

    /** @param array<string, int> $calls */
    private function instrumentedFactory(array &$calls): BorrowedSecretarySnapshotFactory
    {
        return new BorrowedSecretarySnapshotFactory(
            app(UndergroundAlphaV1PlayerCatalog::class),
            app(UndergroundEquipmentCatalog::class),
            app(UndergroundEquipmentLoadoutResolver::class),
            app(UndergroundRuntimeEquipmentGenerator::class),
            app(SecretaryProfilePresenter::class),
            app(UndergroundAwakening::class),
            app(PriorityCombatAiConfiguration::class),
            static function (string $operation) use (&$calls): void {
                $calls[$operation] = ($calls[$operation] ?? 0) + 1;
            },
        );
    }
}
