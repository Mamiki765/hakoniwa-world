<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\SecretaryItemGrantService;
use App\Application\SecretaryService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class SecretaryInventoryTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_new_secretary_gets_exactly_one_equipped_starter_old_bow_and_retries_are_idempotent(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();

        app(NationCreationService::class)->create($user, $world, '装備基盤島', '装備島主');
        app(SecretaryService::class)->ensureForUser($user);

        $secretary = $user->secretary()->firstOrFail();
        $this->assertDatabaseCount('secretary_item_instances', 1);
        $this->assertDatabaseHas('secretary_item_instances', [
            'secretary_id' => $secretary->id,
            'item_key' => SecretaryItemCatalog::OLD_BOW,
            'level' => 1,
            'equipped_slot' => 1,
            'grant_key' => SecretaryItemGrantService::STARTER_OLD_BOW_GRANT,
        ]);
        $this->assertNotNull($secretary->itemInstances()->firstOrFail()->getKey());
        $this->assertSame(0, $secretary->itemInstances()->where('item_key', SecretaryItemCatalog::RING)->count());
    }

    public function test_secretary_api_renders_five_slots_and_a_fifty_item_warehouse_without_get_repair(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        app(NationCreationService::class)->create($user, $world, '倉庫表示島', '倉庫島主');

        $this->actingAs($user)->getJson('/api/v1/me/secretary')
            ->assertOk()
            ->assertJsonPath('data.inventory.capacity', 50)
            ->assertJsonPath('data.inventory.used', 1)
            ->assertJsonPath('data.inventory.items.0.key', 'old_bow')
            ->assertJsonPath('data.inventory.items.0.name', '古びた弓')
            ->assertJsonPath('data.inventory.items.0.category', 'bow')
            ->assertJsonPath('data.inventory.items.0.level', 1)
            ->assertJsonPath('data.inventory.items.0.equipped_slot', 1)
            ->assertJsonCount(5, 'data.equipment.slots')
            ->assertJsonPath('data.equipment.slots.0.item.key', 'old_bow')
            ->assertJsonPath('data.equipment.slots.1.item', null)
            ->assertJsonPath('data.equipment.slots.4.item', null);

        $user->secretary->itemInstances()->delete();
        $this->actingAs($user)->getJson('/api/v1/me/secretary')
            ->assertOk()
            ->assertJsonPath('data.inventory.used', 0)
            ->assertJsonPath('data.equipment.slots.0.item', null);
        $this->assertDatabaseCount('secretary_item_instances', 0);
    }

    public function test_capacity_rejects_the_fifty_first_item_preserves_existing_rows_and_records_private_audit(): void
    {
        $secretary = Secretary::query()->create(['user_id' => User::factory()->create()->id]);
        $now = now();
        DB::table('secretary_item_instances')->insert(collect(range(1, 50))->map(fn (int $number): array => [
            'secretary_id' => $secretary->id,
            'item_key' => "capacity_fixture_{$number}",
            'level' => 1,
            'equipped_slot' => null,
            'grant_key' => null,
            'obtained_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        $result = app(SecretaryItemGrantService::class)->grant(
            $secretary,
            SecretaryItemCatalog::OLD_BOW,
            1,
            null,
            SecretaryItemGrantService::STARTER_OLD_BOW_GRANT,
        );

        $this->assertNull($result);
        $this->assertSame(50, $secretary->itemInstances()->count());
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'secretary.inventory_full',
            'visibility' => 'private',
            'subject_type' => Secretary::class,
            'subject_id' => $secretary->id,
        ]);
    }

    public function test_database_rejects_two_items_in_the_same_secretary_slot(): void
    {
        $secretary = Secretary::query()->create(['user_id' => User::factory()->create()->id]);
        SecretaryItemInstance::query()->create([
            'secretary_id' => $secretary->id,
            'item_key' => 'slot_fixture_a',
            'level' => 1,
            'equipped_slot' => 2,
            'obtained_at' => now(),
        ]);

        try {
            SecretaryItemInstance::query()->create([
                'secretary_id' => $secretary->id,
                'item_key' => 'slot_fixture_b',
                'level' => 1,
                'equipped_slot' => 2,
                'obtained_at' => now(),
            ]);
            $this->fail('Expected the database slot uniqueness constraint to reject the second item.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_application_rejects_granting_an_item_into_an_occupied_slot(): void
    {
        $secretary = Secretary::query()->create(['user_id' => User::factory()->create()->id]);
        SecretaryItemInstance::query()->create([
            'secretary_id' => $secretary->id,
            'item_key' => 'slot_fixture',
            'level' => 1,
            'equipped_slot' => 2,
            'obtained_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Secretary equipment slot 2 is already occupied.');
        app(SecretaryItemGrantService::class)->grant(
            $secretary,
            SecretaryItemCatalog::OLD_BOW,
            1,
            2,
            null,
        );
    }

    public function test_item_catalog_keeps_presentation_outside_rulesets_and_bow_equipment_limit_is_one(): void
    {
        $catalog = app(SecretaryItemCatalog::class);
        $definition = $catalog->definition(SecretaryItemCatalog::OLD_BOW);

        $this->assertSame('bow', $definition['category']);
        $this->assertSame(1, $definition['max_level']);
        $this->assertSame(1, $catalog->maximumEquipped('bow'));
        $this->assertSame('古びた弓', $definition['name']);
        $this->assertStringContainsString('施設の最奥', $definition['flavor_text']);
        $this->assertArrayNotHasKey('effect', $definition);
    }

    public function test_ring_catalog_values_are_exact_and_no_registration_path_grants_one(): void
    {
        $catalog = app(SecretaryItemCatalog::class);
        $definition = $catalog->definition(SecretaryItemCatalog::RING);

        $this->assertSame([
            'key' => SecretaryItemCatalog::RING,
            'category' => 'accessory',
            'category_label' => 'アクセサリー',
            'category_max_equipped' => 99,
            'rarity' => SecretaryItemCatalog::RARITY_NOVICE,
            'rarity_label' => 'ノービス',
            'tradable' => true,
            'npc_tradable' => true,
            'max_level' => 10,
            'name' => '指輪',
            'flavor_text' => '貴金属が使われた豪華な指輪。魔法の道具ではないが、贈り物にはぴったりだ。',
            'unique_per_secretary' => false,
        ], $definition);
        $this->assertSame(99, $catalog->maximumEquipped('accessory'));
        $this->assertSame(1, $catalog->sameItemMaximum(SecretaryItemCatalog::RING));
        $this->assertArrayNotHasKey('same_item_max_equipped', $definition);
        $this->assertArrayNotHasKey('effect', $definition);

        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        app(NationCreationService::class)->create($user, $world, '指輪未配布島', '指輪未配布島主');
        $this->assertSame(0, $user->secretary->itemInstances()
            ->where('item_key', SecretaryItemCatalog::RING)->count());
    }

    public function test_ring_grants_accept_levels_one_and_ten_but_reject_level_eleven(): void
    {
        $secretary = Secretary::query()->create(['user_id' => User::factory()->create()->id]);
        $grants = app(SecretaryItemGrantService::class);

        $this->assertSame(1, $grants->grant(
            $secretary,
            SecretaryItemCatalog::RING,
            1,
            null,
            'test:ring:level-1',
        )?->level);
        $this->assertSame(10, $grants->grant(
            $secretary,
            SecretaryItemCatalog::RING,
            10,
            null,
            'test:ring:level-10',
        )?->level);

        try {
            $grants->grant(
                $secretary,
                SecretaryItemCatalog::RING,
                11,
                null,
                'test:ring:level-11',
            );
            $this->fail('Expected Ring level 11 to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('Invalid level 11 for Secretary item ring.', $exception->getMessage());
        }
        $this->assertSame(2, $secretary->itemInstances()->where('item_key', SecretaryItemCatalog::RING)->count());
    }
}
