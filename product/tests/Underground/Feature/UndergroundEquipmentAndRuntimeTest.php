<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundEquipmentLoadoutResolver;
use App\Application\Underground\UndergroundRuntimeEquipmentGenerator;
use App\Models\UndergroundBattle;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\UndergroundSkillAllocation;
use App\Models\UndergroundTrialProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\UndergroundPlayerAccessTestCase;

final class UndergroundEquipmentAndRuntimeTest extends UndergroundPlayerAccessTestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_equipment_shop_vault_purchase_sell_and_equip_are_owner_scoped_atomic_and_idempotent(): void
    {
        [$user, $secretary] = $this->secretaryUser('Equipment secretary');
        [$otherUser, $otherSecretary] = $this->secretaryUser('Other equipment secretary');
        $profile = $this->openEquipmentProfile($secretary);
        $otherProfile = $this->openEquipmentProfile($otherSecretary, 0, 0);
        $profile->update(['skill_points_total' => 20, 'skill_points_unspent' => 2]);
        foreach ([
            'martial_precision_cut' => ['rank' => 1, 'active_slot' => null],
            'martial_dagger_flurry' => ['rank' => 1, 'active_slot' => 1],
        ] as $nodeKey => $allocation) {
            UndergroundSkillAllocation::query()->create([
                'underground_profile_id' => $profile->id,
                'node_key' => $nodeKey,
                ...$allocation,
            ]);
        }

        $main = $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.equipment_summary.used', 1)
            ->assertJsonPath('data.equipment_summary.capacity', 500)
            ->assertJsonPath('data.equipment_summary.equipped.weapon.key', 'starter_knife')
            ->assertJsonPath('data.default_hunting_ground_key', 'shallow_caves')
            ->assertJsonPath('data.hunting_grounds.0.key', 'shallow_caves')
            ->assertJsonPath('data.hunting_grounds.0.locked', false)
            ->assertJsonPath('data.hunting_grounds.1.key', 'black_crystal_cave')
            ->assertJsonPath('data.hunting_grounds.1.locked', true)
            ->assertJsonPath('data.hunting_grounds.1.unlock_condition', '試練1を初回clear')
            ->assertJsonMissingPath('data.equipment_summary.items');

        $this->actingAs($user)->postJson('/api/v1/me/underground/explore', [
            'request_id' => (string) Str::uuid(),
            'hunting_ground_key' => 'unknown_ground',
        ])->assertConflict()->assertJsonPath('code', 'underground_hunting_ground_not_supported');
        $this->actingAs($user)->postJson('/api/v1/me/underground/explore', [
            'request_id' => (string) Str::uuid(),
            'hunting_ground_key' => 'black_crystal_cave',
        ])->assertConflict()->assertJsonPath('code', 'underground_hunting_ground_locked');
        $this->assertFalse($main->json('data.equipment_summary.equipped.weapon.shop_sold'));
        $starterId = (int) $main->json('data.equipment_summary.equipped.weapon.id');
        $this->assertSame([], UndergroundBattle::query()
            ->where('underground_profile_id', $profile->id)
            ->where('activity_type', UndergroundBattle::ACTIVITY_TUTORIAL)
            ->sole()->snapshot);
        $shop = $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/shop')
            ->assertOk()
            ->assertJsonPath('data.catalog_identity', 'secretary-underground-shop-equipment-alpha-v2')
            ->assertJsonPath('data.currency_label', '輝石の欠片 G')
            ->assertJsonPath('data.shard_balance', 5_000)
            ->assertJsonPath('data.banked_shard_balance', 5_000)
            ->assertJsonPath('data.bank_auto_withdraw', false);
        $this->assertCount(40, $shop->json('data.items'));
        $this->assertCount(1, $shop->json('data.owned_items'));
        foreach ($shop->json('data.items') as $shopItem) {
            $this->assertSame(intdiv($shopItem['buy_price'], 2), $shopItem['sell_price']);
        }
        $this->assertSame([null, 0, false], [
            $shop->json('data.owned_items.0.buy_price'),
            $shop->json('data.owned_items.0.sell_price'),
            $shop->json('data.owned_items.0.sellable'),
        ]);
        $blackDagger = collect($shop->json('data.items'))->firstWhere('key', 'black_crystal_dagger');
        $this->assertSame([true, '試練1を初回clear', 'ノービス'], [
            $blackDagger['locked'],
            $blackDagger['unlock_requirement'],
            $blackDagger['rarity_label'],
        ]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'black_crystal_dagger',
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_locked');

        $forgedRequest = (string) Str::uuid();
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => $forgedRequest,
            'definition_key' => 'iron_dagger',
            'buy_price' => 1,
            'stats' => ['might' => 50],
        ])->assertUnprocessable();
        $this->assertSame(5_000, $profile->fresh()->shard_balance);

        $purchaseRequest = (string) Str::uuid();
        $purchasePayload = ['request_id' => $purchaseRequest, 'definition_key' => 'iron_dagger'];
        $purchase = $this->actingAs($user)
            ->postJson('/api/v1/me/underground/equipment/shop/purchase', $purchasePayload)
            ->assertOk()
            ->assertJsonPath('data.shard_balance', 4_880)
            ->assertJsonPath('data.banked_shard_balance', 5_000)
            ->assertJsonPath('data.vault.used', 2);
        $this->actingAs($user)
            ->postJson('/api/v1/me/underground/equipment/shop/purchase', $purchasePayload)
            ->assertOk()->assertExactJson($purchase->json());
        $this->assertSame(1, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'iron_dagger')->count());
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'iron_dagger',
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_already_owned');
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => $purchaseRequest,
            'definition_key' => 'bronze_rapier',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'iron_longsword',
        ])->assertOk()
            ->assertJsonPath('data.shard_balance', 4_760)
            ->assertJsonPath('data.vault.used', 3);

        $profile->update(['shard_balance' => 0]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'steel_dagger',
        ])->assertConflict()
            ->assertJsonPath('code', 'underground_equipment_insufficient_carried_shards');
        $this->assertSame([0, 5_000], [
            $profile->fresh()->shard_balance,
            $profile->banked_shard_balance,
        ]);
        $profile->update(['shard_balance' => 5_000]);

        foreach (['leather_armor', 'vitality_accessory_rank_1'] as $definitionKey) {
            $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
                'request_id' => (string) Str::uuid(),
                'definition_key' => $definitionKey,
            ])->assertOk();
        }
        $ironDagger = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'iron_dagger')->sole();
        $ironLongsword = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'iron_longsword')->sole();
        $armor = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'leather_armor')->sole();
        $accessory = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'vitality_accessory_rank_1')->sole();

        $equipWeaponRequest = (string) Str::uuid();
        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => $equipWeaponRequest,
            'item_id' => $ironDagger->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.weapon.key', 'iron_dagger');
        $this->assertSame(484, $profile->fresh()->current_hp);
        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => $equipWeaponRequest,
            'item_id' => $ironDagger->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.weapon.key', 'iron_dagger');
        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $ironLongsword->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.weapon.key', 'iron_longsword');
        $longswordMain = $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.equipment_summary.equipped.weapon.key', 'iron_longsword')
            ->assertJsonPath('data.active_slots.0.key', 'dagger_flurry');
        $this->assertContains(
            'skill:dagger_flurry',
            array_column($longswordMain->json('data.ai.default_rules'), 'action'),
        );
        $this->assertSame(
            $longswordMain->json('data.ai.default_rules'),
            $longswordMain->json('data.ai.rules'),
        );
        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $ironDagger->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.weapon.key', 'iron_dagger');
        $daggerMain = $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.equipment_summary.equipped.weapon.key', 'iron_dagger')
            ->assertJsonPath('data.active_slots.0.key', 'dagger_flurry');
        $this->assertContains(
            'skill:dagger_flurry',
            array_column($daggerMain->json('data.ai.default_rules'), 'action'),
        );
        $this->actingAs($user)->deleteJson('/api/v1/me/underground/equipment/equipped/weapon', [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_slot_invalid');

        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $armor->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.armor.key', 'leather_armor');
        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $accessory->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.accessory_1.key', 'vitality_accessory_rank_1');
        $this->assertSame(484, $profile->fresh()->current_hp);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.growth_path.max_hp', 520)
            ->assertJsonPath('data.current_hp', 484);
        $this->assertSame([1, 0, 0, 0, 0, 0, 0, 0], [
            $profile->fresh()->combat_level,
            $profile->combat_xp,
            $profile->unspent_stp,
            $profile->allocated_vitality_stp,
            $profile->allocated_might_stp,
            $profile->allocated_finesse_stp,
            $profile->allocated_spirit_stp,
            $profile->allocated_agility_stp,
        ]);
        config([
            'underground-alpha-v1.exploration.drop.profiles.standard.presence_bps' => 0,
            'underground-alpha-v1.exploration.drop.profiles.elite.presence_bps' => 0,
            'underground-alpha-v1.exploration.drop.profiles.rare.presence_bps' => 0,
        ]);
        $explorationRequest = (string) Str::uuid();
        $this->actingAs($user)->postJson('/api/v1/me/underground/explore', [
            'request_id' => $explorationRequest,
            'weapon_power' => 50,
            'equipment' => ['key' => 'forged_equipment'],
        ])->assertOk();
        $equipmentBattle = UndergroundBattle::query()
            ->where('request_id', $explorationRequest)->sole();
        $combatLoadout = app(UndergroundEquipmentLoadoutResolver::class)
            ->combatLoadout($profile->fresh());
        $this->assertSame('iron_dagger', $combatLoadout['key']);
        $this->assertSame(30, $combatLoadout['weapon_power']);
        $this->assertSame([12, 9, 20], [
            $combatLoadout['physical_defense'],
            $combatLoadout['magical_defense'],
            $combatLoadout['max_hp'],
        ]);
        $this->assertSame(
            ['iron_dagger', 'leather_armor', 'vitality_accessory_rank_1'],
            array_column($combatLoadout['items'], 'key'),
        );
        $this->assertArrayNotHasKey('equipment', $equipmentBattle->snapshot);
        $this->assertArrayNotHasKey('combat_stats', $equipmentBattle->snapshot);
        $this->assertArrayNotHasKey('equipped_active_skills', $equipmentBattle->snapshot);
        $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$armor->id}/sell", [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_equipped');
        $this->actingAs($otherUser)->getJson('/api/v1/me/underground/main')
            ->assertOk()->assertJsonPath('data.equipment_summary.equipped.weapon.key', 'starter_knife');
        $this->actingAs($otherUser)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $accessory->id,
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_not_owned');
        $this->actingAs($otherUser)->postJson("/api/v1/me/underground/equipment/items/{$armor->id}/sell", [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_not_owned');
        $this->assertSame(1, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $otherProfile->id)
            ->where('definition_key', 'starter_knife')->count());

        $profile->update(['current_hp' => 515]);
        $unequipRequest = (string) Str::uuid();
        $this->actingAs($user)->deleteJson('/api/v1/me/underground/equipment/equipped/armor', [
            'request_id' => $unequipRequest,
        ])->assertOk()->assertJsonPath('data.vault.equipped.armor', null);
        $this->assertSame(492, $profile->fresh()->current_hp);
        $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$armor->id}/sell", [
            'request_id' => (string) Str::uuid(),
            'sell_price' => 9_999,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('underground_owned_equipment', ['id' => $armor->id]);
        $balanceBeforeSale = $profile->fresh()->shard_balance;
        $saleRequest = (string) Str::uuid();
        $sale = $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$armor->id}/sell", [
            'request_id' => $saleRequest,
        ])->assertOk()
            ->assertJsonPath('data.shard_balance', $balanceBeforeSale + 50)
            ->assertJsonPath('data.banked_shard_balance', 5_000)
            ->assertJsonPath('data.vault.used', 4);
        $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$armor->id}/sell", [
            'request_id' => $saleRequest,
        ])->assertOk()->assertExactJson($sale->json());
        $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$accessory->id}/sell", [
            'request_id' => $saleRequest,
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->assertDatabaseMissing('underground_owned_equipment', ['id' => $armor->id]);
        $this->actingAs($user)->postJson("/api/v1/me/underground/equipment/items/{$starterId}/sell", [
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_not_sellable');
        $this->actingAs($user)->deleteJson('/api/v1/me/underground/equipment/equipped/accessory', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.vault.equipped.accessory_1', null);
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'leather_armor',
        ])->assertOk();

    }

    public function test_vault_sort_keeps_equipped_slot_order_and_sorts_across_pages(): void
    {
        [$user, $secretary] = $this->secretaryUser('Vault sorting');
        $profile = $this->openEquipmentProfile($secretary);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')->assertOk();
        foreach (['accessory_3' => 'finesse_accessory_rank_1', 'armor' => 'leather_armor',
            'accessory_1' => 'vitality_accessory_rank_1', 'accessory_2' => 'might_accessory_rank_1'] as $slot => $key) {
            UndergroundOwnedEquipment::query()->create([
                'underground_profile_id' => $profile->id, 'definition_key' => $key,
                'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2', 'equipped_slot' => $slot,
                'instance_kind' => 'fixed', 'acquired_at' => Carbon::now(),
            ]);
        }
        $count = config('underground-equipment.page_size') + 1;
        $rarityIds = ['common' => [], 'uncommon' => [], 'rare' => [], 'epic' => []];
        for ($index = 1; $index <= $count; $index++) {
            $rarity = array_keys($rarityIds)[($index - 1) % 4];
            $generated = app(UndergroundRuntimeEquipmentGenerator::class)->generate(
                $index, 'shallow_caves', $rarity, 'armor', null, null, $index, 'vault-sort-'.$index,
            );
            $owned = UndergroundOwnedEquipment::query()->create([
                'underground_profile_id' => $profile->id, 'definition_key' => $generated['key'],
                'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2', 'equipped_slot' => null,
                'instance_kind' => 'generated', 'instance_identity' => $generated['instance_identity'],
                'generator_identity' => $generated['generator_identity'], 'generated_payload' => $generated,
                'grant_key' => 'vault-sort-'.$index,
                'source_battle_id' => $this->tutorialBattle($profile)->id,
                'acquired_at' => Carbon::now()->subMinutes($index),
            ]);
            $rarityIds[$rarity][] = $owned->id;
        }
        $first = $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/vault?sort=item_level')->assertOk()->json('data');
        $this->assertSame(['weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3'], array_column(array_slice($first['items'], 0, 5), 'equipped_slot'));
        $second = $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/vault?sort=item_level&page=2')->assertOk()->json('data');
        $items = [...array_slice($first['items'], 5), ...$second['items']];
        $this->assertSame(range($count, 1), array_column($items, 'item_level'));
        $this->assertCount($count, array_unique(array_column($items, 'id')));
        $newest = $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/vault')->assertOk()->json('data.items');
        $this->assertSame(1, $newest[5]['item_level']);
        $fixedIds = [];
        foreach (['leather_armor', 'demon_sword_gram'] as $key) {
            $fixedIds[$key] = UndergroundOwnedEquipment::query()->create([
                'underground_profile_id' => $profile->id, 'definition_key' => $key,
                'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
                'instance_kind' => 'fixed', 'acquired_at' => Carbon::now(),
            ])->id;
        }
        $rareFirst = $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/vault?sort=rarity')->assertOk()->json('data.items');
        $rareSecond = $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/vault?sort=rarity&page=2')->assertOk()->json('data.items');
        $this->assertSame(['weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3'], array_column(array_slice($rareFirst, 0, 5), 'equipped_slot'));
        $this->assertSame([
            $fixedIds['demon_sword_gram'],
            ...array_reverse($rarityIds['epic']), ...array_reverse($rarityIds['rare']),
            ...array_reverse($rarityIds['uncommon']), ...array_reverse($rarityIds['common']),
            $fixedIds['leather_armor'],
        ], array_column([...array_slice($rareFirst, 5), ...$rareSecond], 'id'));
        $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/vault?sort[]=newest')->assertUnprocessable();
    }

    public function test_bulk_sale_previews_canonical_filters_and_atomically_sells_only_the_quoted_items(): void
    {
        [$user, $secretary] = $this->secretaryUser('Bulk sale secretary');
        [$otherUser, $otherSecretary] = $this->secretaryUser('Other bulk sale secretary');
        $profile = $this->openEquipmentProfile($secretary, 100, 0);
        $otherProfile = $this->openEquipmentProfile($otherSecretary, 0, 0);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')->assertOk();
        $this->actingAs($otherUser)->getJson('/api/v1/me/underground/main')->assertOk();

        $fixed = [];
        foreach (['iron_dagger', 'bronze_rapier'] as $definitionKey) {
            $fixed[$definitionKey] = UndergroundOwnedEquipment::query()->create([
                'underground_profile_id' => $profile->id,
                'definition_key' => $definitionKey,
                'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
                'equipped_slot' => null,
                'grant_key' => null,
                'instance_kind' => 'fixed',
                'acquired_at' => Carbon::now(),
            ]);
        }
        $generated = app(UndergroundRuntimeEquipmentGenerator::class)->generate(
            30,
            'shallow_caves',
            'epic',
            'armor',
            null,
            null,
            31,
            'bulk-sale-generated-equipment',
        );
        $generatedItem = UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => $generated['key'],
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => null,
            'grant_key' => 'drop:bulk-sale-generated-equipment',
            'instance_kind' => 'generated',
            'instance_identity' => $generated['instance_identity'],
            'generator_identity' => $generated['generator_identity'],
            'generated_payload' => $generated,
            'source_battle_id' => UndergroundBattle::query()
                ->where('underground_profile_id', $profile->id)
                ->where('activity_type', UndergroundBattle::ACTIVITY_TUTORIAL)
                ->sole()->id,
            'acquired_at' => Carbon::now()->addSecond(),
        ]);
        $equippedArmor = UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => 'leather_armor',
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => 'armor',
            'grant_key' => null,
            'instance_kind' => 'fixed',
            'acquired_at' => Carbon::now(),
        ]);
        $otherItem = UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $otherProfile->id,
            'definition_key' => 'iron_dagger',
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => null,
            'grant_key' => null,
            'instance_kind' => 'fixed',
            'acquired_at' => Carbon::now(),
        ]);

        $vault = $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/vault')
            ->assertOk()
            ->assertJsonPath('data.bulk_sell_options.rarities.0', ['key' => 'novice', 'label' => 'ノービス'])
            ->assertJsonPath('data.bulk_sell_options.rarities.5', ['key' => 'unique', 'label' => 'ユニーク'])
            ->assertJsonPath('data.bulk_sell_options.categories.0', ['key' => 'weapon', 'label' => '武器'])
            ->assertJsonPath('data.bulk_sell_options.weapon_styles.0', ['key' => 'dagger', 'label' => '短剣'])
            ->assertJsonPath('data.bulk_sell_options.weapon_styles.3', ['key' => 'crystal_staff', 'label' => '輝石杖']);
        $this->assertCount(6, $vault->json('data.bulk_sell_options.rarities'));
        $this->assertCount(4, $vault->json('data.bulk_sell_options.weapon_styles'));

        $filters = [
            'item_level_max' => 30,
            'rarities' => ['novice', 'relic'],
            'categories' => ['weapon', 'armor'],
            'weapon_styles' => ['dagger'],
        ];
        $this->actingAs($user)
            ->postJson('/api/v1/me/underground/equipment/vault/bulk-sell/preview', [
                ...$filters,
                'item_level_max' => 1_254,
            ])
            ->assertOk()
            ->assertJsonPath('data.count', 2);
        $preview = $this->actingAs($user)
            ->postJson('/api/v1/me/underground/equipment/vault/bulk-sell/preview', $filters)
            ->assertOk()
            ->assertJsonPath('data.catalog_identity', 'secretary-underground-shop-equipment-alpha-v2')
            ->assertJsonPath('data.count', 2);
        $previewItems = collect($preview->json('data.items'));
        $this->assertEqualsCanonicalizing(
            [$fixed['iron_dagger']->id, $generatedItem->id],
            $previewItems->pluck('id')->all(),
        );
        $this->assertNotContains($fixed['bronze_rapier']->id, $previewItems->pluck('id')->all());
        $this->assertNotContains($equippedArmor->id, $previewItems->pluck('id')->all());
        $this->assertSame(60, $previewItems->firstWhere('id', $fixed['iron_dagger']->id)['sell_price']);
        $this->assertSame($generated['sell_price'], $previewItems->firstWhere('id', $generatedItem->id)['sell_price']);
        $this->assertSame(60 + $generated['sell_price'], $preview->json('data.total_sell_price'));
        $quotes = $previewItems->map(static fn (array $item): array => [
            'id' => $item['id'],
            'sell_price' => $item['sell_price'],
        ])->values()->all();

        $duplicate = [$quotes[0], $quotes[0]];
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            'request_id' => (string) Str::uuid(),
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => $duplicate,
        ])->assertUnprocessable()->assertJsonValidationErrors('items.1.id');

        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            'request_id' => (string) Str::uuid(),
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => [['id' => $otherItem->id, 'sell_price' => 60]],
        ])->assertConflict()->assertJsonPath('code', 'underground_bulk_sell_preview_changed');
        $starter = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'starter_knife')->sole();
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            'request_id' => (string) Str::uuid(),
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => [['id' => $starter->id, 'sell_price' => 1]],
        ])->assertConflict()->assertJsonPath('code', 'underground_bulk_sell_preview_changed');

        $saleRequest = (string) Str::uuid();
        $wrongQuote = $quotes;
        $wrongQuote[0]['sell_price']++;
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            'request_id' => $saleRequest,
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => $wrongQuote,
        ])->assertConflict()->assertJsonPath('code', 'underground_bulk_sell_preview_changed');
        $this->assertSame(100, $profile->fresh()->shard_balance);
        foreach ($quotes as $quote) {
            $this->assertDatabaseHas('underground_owned_equipment', ['id' => $quote['id']]);
        }

        $equippedArmor->update(['equipped_slot' => null]);
        $generatedItem->update(['equipped_slot' => 'armor']);
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            'request_id' => $saleRequest,
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => $quotes,
        ])->assertConflict()->assertJsonPath('code', 'underground_bulk_sell_preview_changed');
        foreach ($quotes as $quote) {
            $this->assertDatabaseHas('underground_owned_equipment', ['id' => $quote['id']]);
        }
        $generatedItem->update(['equipped_slot' => null]);

        $pickedUpAfterPreview = UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => 'steel_dagger',
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => null,
            'grant_key' => null,
            'instance_kind' => 'fixed',
            'acquired_at' => Carbon::now()->addSeconds(2),
        ]);
        $salePayload = [
            'request_id' => $saleRequest,
            'catalog_identity' => $preview->json('data.catalog_identity'),
            'items' => $quotes,
        ];
        $sale = $this->actingAs($user)
            ->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', $salePayload)
            ->assertOk()
            ->assertJsonPath('data.operation', 'equipment_bulk_sell')
            ->assertJsonPath('data.shard_balance', 160 + $generated['sell_price']);
        $this->actingAs($user)
            ->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', $salePayload)
            ->assertOk()->assertExactJson($sale->json());
        foreach ($quotes as $quote) {
            $this->assertDatabaseMissing('underground_owned_equipment', ['id' => $quote['id']]);
        }
        $this->assertDatabaseHas('underground_owned_equipment', ['id' => $fixed['bronze_rapier']->id]);
        $this->assertDatabaseHas('underground_owned_equipment', ['id' => $pickedUpAfterPreview->id]);
        $this->assertDatabaseHas('underground_owned_equipment', ['id' => $otherItem->id]);

        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/vault/bulk-sell', [
            ...$salePayload,
            'request_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'underground_bulk_sell_preview_changed');
        $this->assertSame(160 + $generated['sell_price'], $profile->fresh()->shard_balance);
    }

    public function test_three_accessory_slots_and_trial_one_shop_unlock_keep_fixed_catalog_compatibility(): void
    {
        [$user, $secretary] = $this->secretaryUser('Three slot equipment secretary');
        $profile = $this->openEquipmentProfile($secretary, 20_000, 0);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')->assertOk();
        $starter = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'starter_knife')
            ->sole();
        $starter->catalog_identity = 'secretary-underground-shop-equipment-alpha-v1';
        $starter->save();

        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.equipment_summary.equipped.weapon.catalog_identity', 'secretary-underground-shop-equipment-alpha-v1')
            ->assertJsonPath('data.equipment_summary.equipped.accessory_1', null)
            ->assertJsonPath('data.equipment_summary.equipped.accessory_2', null)
            ->assertJsonPath('data.equipment_summary.equipped.accessory_3', null);

        $legacyRequestId = (string) Str::uuid();
        UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => 'iron_dagger',
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v1',
            'equipped_slot' => null,
            'grant_key' => null,
            'instance_kind' => 'fixed',
            'acquired_at' => Carbon::now(),
        ]);
        $legacyFingerprint = hash('sha256', json_encode([
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v1',
            'operation' => 'equipment_purchase',
            'payload' => ['definition_key' => 'iron_dagger'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        UndergroundIntroRequest::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => $legacyRequestId,
            'request_fingerprint' => $legacyFingerprint,
            'operation' => 'equipment_purchase',
            'resulting_stage' => 'underground_open',
            'underground_battle_id' => null,
        ]);
        $balanceBeforeLegacyReplay = $profile->shard_balance;
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => $legacyRequestId,
            'definition_key' => 'iron_dagger',
        ])->assertOk()->assertJsonPath('data.shard_balance', $balanceBeforeLegacyReplay);
        $this->assertSame(1, UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('definition_key', 'iron_dagger')
            ->count());

        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => Carbon::now(),
            'first_cleared_at' => Carbon::now(),
        ]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/equipment/shop')
            ->assertOk()
            ->assertJsonPath('data.items.30.locked', false);
        $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(),
            'definition_key' => 'black_crystal_dagger',
        ])->assertOk();

        $items = [];
        foreach (['vitality', 'might', 'finesse'] as $stat) {
            $key = $stat.'_accessory_rank_1';
            $this->actingAs($user)->postJson('/api/v1/me/underground/equipment/shop/purchase', [
                'request_id' => (string) Str::uuid(),
                'definition_key' => $key,
            ])->assertOk();
            $items[] = UndergroundOwnedEquipment::query()
                ->where('underground_profile_id', $profile->id)
                ->where('definition_key', $key)
                ->sole();
        }

        foreach ($items as $index => $item) {
            $slot = 'accessory_'.($index + 1);
            $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
                'request_id' => (string) Str::uuid(),
                'item_id' => $item->id,
                'target_slot' => $slot,
            ])->assertOk()->assertJsonPath("data.vault.equipped.{$slot}.id", $item->id);
        }
        $this->assertSame(492, $profile->fresh()->current_hp);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.equipment_summary.equipped.accessory_1.key', 'vitality_accessory_rank_1')
            ->assertJsonPath('data.equipment_summary.equipped.accessory_2.key', 'might_accessory_rank_1')
            ->assertJsonPath('data.equipment_summary.equipped.accessory_3.key', 'finesse_accessory_rank_1');

        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $items[0]->id,
            'target_slot' => 'weapon',
        ])->assertConflict()->assertJsonPath('code', 'underground_equipment_slot_invalid');
        $this->actingAs($user)->deleteJson('/api/v1/me/underground/equipment/equipped/accessory_2', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.vault.equipped.accessory_2', null);
    }

    public function test_generated_payload_is_persisted_projected_and_used_by_canonical_combat_without_regeneration(): void
    {
        [$user, $secretary] = $this->secretaryUser('Generated equipment secretary');
        $profile = $this->openEquipmentProfile($secretary, 5_000, 0);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')->assertOk();
        $sourceBattle = UndergroundBattle::query()
            ->where('underground_profile_id', $profile->id)
            ->where('activity_type', UndergroundBattle::ACTIVITY_TUTORIAL)
            ->sole();
        $generated = app(UndergroundRuntimeEquipmentGenerator::class)->generate(
            30,
            'shallow_caves',
            'epic',
            'armor',
            null,
            null,
            23,
            'generated-equipment-feature-test',
        );
        $item = UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => $generated['key'],
            'catalog_identity' => 'secretary-underground-shop-equipment-alpha-v2',
            'equipped_slot' => null,
            'grant_key' => 'drop:generated-equipment-test',
            'instance_kind' => 'generated',
            'instance_identity' => $generated['instance_identity'],
            'generator_identity' => $generated['generator_identity'],
            'generated_payload' => $generated,
            'source_battle_id' => $sourceBattle->id,
            'acquired_at' => Carbon::now(),
        ]);

        $vault = $this->actingAs($user)
            ->getJson('/api/v1/me/underground/equipment/vault')
            ->assertOk();
        $projected = collect($vault->json('data.items'))->firstWhere('id', $item->id);
        $this->assertSame([
            'generated',
            $generated['instance_identity'],
            'secretary-underground-drop-equipment-alpha-v1',
            'epic',
            'レリック',
        ], [
            $projected['instance_kind'],
            $projected['instance_identity'],
            $projected['generator_identity'],
            $projected['rarity'],
            $projected['rarity_label'],
        ]);
        $this->assertEquals(array_map(
            static fn (array $affix): array => Arr::only(
                $affix,
                ['key', 'label', 'kind', 'target', 'value', 'quality_bps'],
            ),
            $generated['affixes'],
        ), $projected['affixes']);
        $this->assertArrayNotHasKey('source', $projected);
        $this->assertArrayNotHasKey('base', $projected);
        $this->assertEquals($generated['stats'], $projected['stats']);
        $this->assertEquals($generated['modifiers'], $projected['modifiers']);

        $this->actingAs($user)->putJson('/api/v1/me/underground/equipment/equipped', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $item->id,
        ])->assertOk()->assertJsonPath('data.vault.equipped.armor.instance_identity', $generated['instance_identity']);
        $combatLoadout = app(UndergroundEquipmentLoadoutResolver::class)
            ->combatLoadout($profile->fresh());
        $equippedGenerated = collect($combatLoadout['items'])->firstWhere('equipped_slot', 'armor');
        $this->assertSame($generated['instance_identity'], $equippedGenerated['instance_identity']);
        $this->assertEquals($generated['modifiers'], $combatLoadout['modifiers']);
        $this->assertCount(count($generated['affixes']), $combatLoadout['affixes']);

        $exploreRequest = (string) Str::uuid();
        $this->actingAs($user)->postJson('/api/v1/me/underground/explore', [
            'request_id' => $exploreRequest,
        ])->assertOk();
        $battle = UndergroundBattle::query()->where('request_id', $exploreRequest)->sole();
        $this->assertArrayNotHasKey('equipment', $battle->snapshot);
        $this->assertEquals($generated, $item->fresh()->generated_payload);
    }

    public function test_player_runtime_keeps_acquired_skills_available_with_a_different_weapon_type(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $equipment = config('underground-alpha-v1.exploration.starter_weapon');
        $this->assertIsArray($equipment);
        $allocations = [
            'martial_precision_cut' => ['rank' => 1, 'active_slot' => null],
            'martial_dagger_flurry' => ['rank' => 1, 'active_slot' => 1],
            'miracle_holy_bolt' => ['rank' => 1, 'active_slot' => 2],
            'miracle_mending_prayer' => ['rank' => 1, 'active_slot' => 3],
        ];

        $equipment['weapon_style'] = 'longsword';
        $definition = $catalog->explorationCombatDefinition(
            'free_black',
            1,
            ['vitality' => 0, 'might' => 0, 'finesse' => 0, 'spirit' => 0, 'agility' => 0],
            $equipment,
            '装備条件試験の秘書',
            skillAllocations: $allocations,
        );
        $this->assertSame(
            ['dagger_flurry', 'holy_bolt', 'mending_prayer'],
            $definition['active_skills'],
        );
        $actions = array_column($definition['player_snapshot']['ai_rules'], 'action');
        $this->assertContains('skill:dagger_flurry', $actions);
        $this->assertContains('skill:holy_bolt', $actions);
        $this->assertContains('skill:mending_prayer', $actions);
        $this->assertSame('normal_attack', $actions[array_key_last($actions)]);
        $this->assertSame(1, $allocations['martial_dagger_flurry']['active_slot']);
    }

    public function test_mending_prayer_ally_targeting_is_available_for_a_non_blessing_growth_path(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $equipment = config('underground-alpha-v1.exploration.starter_weapon');
        $this->assertIsArray($equipment);
        $allocations = [
            'miracle_holy_bolt' => ['rank' => 1, 'active_slot' => null],
            'miracle_mending_prayer' => ['rank' => 1, 'active_slot' => 1],
        ];
        $customAi = [[
            'conditions' => [['type' => 'ally_hp_lte', 'percent' => 50]],
            'action' => 'skill:mending_prayer',
            'target' => 'lowest_hp_ally',
        ], [
            'conditions' => [['type' => 'always']],
            'action' => 'normal_attack',
        ]];

        $definition = $catalog->explorationCombatDefinition(
            'guardianship_blue',
            1,
            ['vitality' => 0, 'might' => 0, 'finesse' => 0, 'spirit' => 0, 'agility' => 0],
            $equipment,
            '回復対象試験の秘書',
            skillAllocations: $allocations,
            customAiRules: $customAi,
        );

        $this->assertContains('mending_prayer', $definition['active_skills']);
        $this->assertSame('lowest_hp_ally', $definition['player_snapshot']['ai_rules'][0]['target']);
        $this->assertArrayNotHasKey('party_healing_target_scope', $definition['player_snapshot']);
    }

    public function test_normal_exploration_uses_owned_growth_snapshot_rewards_history_and_cross_operation_idempotency(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser('Exploration secretary');
        $profile = UndergroundProfile::query()->create([
            'secretary_id' => $secretary->id,
            'combat_level' => 2,
            'combat_xp' => 100,
            'shard_balance' => 101,
            'underground_contract_completed_at' => Carbon::now()->subMinute(),
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v2',
            'unspent_stp' => 5,
            'current_hp' => 400,
        ]);
        UndergroundIntroProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'shopkeeper_name' => '案内係',
            'special_loss_required' => false,
            'branch_identity' => 'normal',
            'tutorial_battle_id' => $this->tutorialBattle($profile)->id,
        ]);
        UndergroundSkillAllocation::query()->create([
            'underground_profile_id' => $profile->id,
            'node_key' => 'miracle_holy_bolt',
            'rank' => 1,
            'active_slot' => 1,
        ]);
        UndergroundSkillAllocation::query()->create([
            'underground_profile_id' => $profile->id,
            'node_key' => 'martial_precision_cut',
            'rank' => 1,
            'active_slot' => null,
        ]);
        $profile->update(['skill_points_unspent' => 8]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.next_level_requirement', 150)
            ->assertJsonPath('data.xp_to_next_level', 150)
            ->assertJsonPath('data.unspent_stp', 5)
            ->assertJsonPath('data.current_hp', 400)
            ->assertJsonPath('data.current_stats.vitality', 19)
            ->assertJsonPath('data.current_stats.might', 36)
            ->assertJsonPath('data.combat_stats.vitality', 20)
            ->assertJsonPath('data.combat_stats.might', 37)
            ->assertJsonPath('data.equipment.key', 'starter_knife')
            ->assertJsonPath('data.equipment.label', '護身用ナイフ')
            ->assertJsonPath('data.equipment_summary.used', 1)
            ->assertJsonPath('data.equipment_summary.capacity', 500)
            ->assertJsonPath('data.equipment_summary.equipped.weapon.key', 'starter_knife')
            ->assertJsonMissingPath('data.equipment_summary.items');

        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'enemy_key' => 'client_selected_enemy',
            'private_seed' => 1,
            'combat_level' => 100,
        ];
        $first = $this->actingAs($user)->postJson('/api/v1/me/underground/explore', $payload)
            ->assertOk()
            ->assertJsonPath('data.context', 'exploration')
            ->assertJsonPath('data.player_display_name', 'Explo…')
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.hunting_ground.key', 'shallow_caves')
            ->assertJsonPath('data.hunting_ground.name', '浅い洞窟')
            ->assertJsonMissingPath('data.private_seed')
            ->assertJsonMissingPath('data.snapshot');
        $this->actingAs($user)->postJson('/api/v1/me/underground/explore', $payload)
            ->assertOk()->assertExactJson($first->json());
        $battle = UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_EXPLORATION)
            ->sole();
        $encounter = app(UndergroundAlphaV1PlayerCatalog::class)
            ->explorationEncounter($battle->encounter_key);
        $expectedXp = match ($battle->result) {
            UndergroundBattle::RESULT_VICTORY => $encounter['xp'],
            UndergroundBattle::RESULT_WITHDRAWAL => intdiv($encounter['xp'], 4),
            default => 0,
        };
        $expectedShardDelta = match ($battle->result) {
            UndergroundBattle::RESULT_VICTORY => $encounter['shards'],
            UndergroundBattle::RESULT_DEFEAT => -51,
            default => 0,
        };
        $this->assertSame([$expectedXp, $expectedShardDelta], [
            $battle->xp_awarded,
            $battle->shard_delta,
        ]);
        $this->assertSame(2, $battle->combat_level_before);
        foreach ([
            'progression_stats', 'combat_stats', 'equipment', 'skill_tree_identity',
            'targeting_contract_identity', 'acquired_skill_nodes', 'equipped_active_skills',
            'effective_passive_modifiers',
        ] as $heavySnapshotKey) {
            $this->assertArrayNotHasKey($heavySnapshotKey, $battle->snapshot);
        }
        $this->assertSame(400, $battle->snapshot['current_hp_before']);
        $this->assertSame(10_000, $battle->snapshot['battle_start_mp']);
        $this->assertSame($profile->fresh()->current_hp, $battle->snapshot['current_hp_after']);
        $this->assertArrayNotHasKey('current_mp', $profile->getAttributes());
        $this->assertSame(0, UndergroundTrialProgress::query()->count());
        $this->assertTrue($battle->log?->expires_at->equalTo($battle->finished_at->addHour()) ?? false);

        $secretary->update(['name' => 'Renamed exploration secretary']);
        $this->actingAs($user)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.context', 'exploration')
            ->assertJsonPath('data.0.player_display_name', 'Explo…')
            ->assertJsonPath('data.0.rounds', null);
        $this->actingAs($user)->getJson("/api/v1/me/underground/battles/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.context', 'exploration')
            ->assertJsonPath('data.player_display_name', 'Explo…');
        $this->actingAs($user)->postJson('/api/v1/me/underground/playtest', [
            'request_id' => $requestId,
            'build_key' => 'pure_attacker',
            'enemy_key' => 'depth_stalker',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
    }

    public function test_trial_api_exposes_named_first_challenge_and_replays_the_same_result(): void
    {
        Carbon::setTestNow('2026-08-30 13:00:00+09:00');
        [$user, $secretary] = $this->secretaryUser('封印探索者');
        $this->openEquipmentProfile($secretary);

        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.trial.label', '地下に眠る古代遺跡')
            ->assertJsonPath('data.trial.first_cleared', false)
            ->assertJsonPath('data.trial.active_run', null);
        $run = $this->actingAs($user)->postJson('/api/v1/me/underground/trial/start')
            ->assertOk()
            ->assertJsonPath('data.label', '地下に眠る古代遺跡')
            ->assertJsonPath('data.next_battle_index', 1)
            ->json('data');
        $requestId = (string) Str::uuid();
        $payload = ['run_key' => $run['run_key'], 'request_id' => $requestId];
        $first = $this->actingAs($user)->postJson('/api/v1/me/underground/trial/fight', $payload)
            ->assertOk()
            ->assertJsonPath('data.context', 'trial')
            ->assertJsonPath('data.player_display_name', '封印探索者')
            ->assertJsonPath('data.trial_battle_index', 1)
            ->assertJsonPath('data.first_clear_story', null)
            ->assertJsonPath(
                'data.challenge_intro',
                "　崩れかけた石壁の向こうに広がっていた不思議な空間。\n"
                ."　土と岩に埋もれたそこは、明らかに人の手で造られた古い石造りの遺跡であった。\n"
                .'　入り口からは生暖かい風が吹いている……そこが魔物の巣窟であることは、明らかであった。',
            );
        $this->actingAs($user)->postJson('/api/v1/me/underground/trial/fight', $payload)
            ->assertOk()
            ->assertExactJson($first->json());
        $this->actingAs($user)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.context', 'trial')
            ->assertJsonPath('data.0.challenge_intro', $first->json('data.challenge_intro'));
        $this->assertSame(1, UndergroundBattle::query()
            ->where('activity_type', UndergroundBattle::ACTIVITY_TRIAL)
            ->where('request_id', $requestId)
            ->count());
    }
}
