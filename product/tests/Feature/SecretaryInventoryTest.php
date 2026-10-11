<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\SecretaryItemGrantService;
use App\Application\SecretaryItemSaleService;
use App\Domain\Economy\NationCapacityResolver;
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

        try {
            app(SecretaryItemGrantService::class)->grant(
                $secretary,
                SecretaryItemCatalog::OLD_BOW,
                1,
                2,
                null,
            );
            $this->fail('Expected granting into an occupied slot to be rejected.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(['slot_fixture'], $secretary->itemInstances()->pluck('item_key')->all());
    }

    public function test_item_catalog_keeps_effects_outside_presentation_and_bow_equipment_limit_is_one(): void
    {
        $catalog = app(SecretaryItemCatalog::class);
        $definition = $catalog->definition(SecretaryItemCatalog::OLD_BOW);

        $this->assertSame('bow', $definition['category']);
        $this->assertSame(1, $catalog->maximumEquipped('bow'));
        $this->assertArrayNotHasKey('effect', $definition);
    }

    public function test_ring_catalog_contract_values_and_no_registration_path_grants_one(): void
    {
        $catalog = app(SecretaryItemCatalog::class);
        $definition = $catalog->definition(SecretaryItemCatalog::RING);

        $this->assertSame(100, $definition['fixed_sale_price_money']);
        $this->assertSame(10, $definition['max_level']);
        $this->assertSame(1, $catalog->sameItemMaximum(SecretaryItemCatalog::RING));
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
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(2, $secretary->itemInstances()->where('item_key', SecretaryItemCatalog::RING)->count());
    }

    public function test_fixed_item_sale_credits_full_rarity_price_deletes_only_the_item_and_records_private_audit(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '固定売却島', '固定売却島主');
        $nation->update(['money' => 1_000]);
        $secretary = $user->secretary()->sole();
        $items = [
            [SecretaryItemCatalog::RING, 3, 100, null],
            [SecretaryItemCatalog::ELF_BOW, 4, 500, null],
            [SecretaryItemCatalog::COLLAR, 5, 1, null],
            [SecretaryItemCatalog::RING, 1, 777, SecretaryItemCatalog::RARITY_HIGH_QUALITY],
        ];
        $expectedMoney = 1_000;
        foreach ($items as $index => [$itemKey, $level, $price, $resolvedRarity]) {
            $item = SecretaryItemInstance::query()->create([
                'secretary_id' => $secretary->id,
                'item_key' => $itemKey,
                'level' => $level,
                'equipped_slot' => null,
                'is_escrowed' => false,
                'grant_key' => "test:fixed-sale:{$index}:{$itemKey}",
                'resolved_rarity' => $resolvedRarity,
                'resolved_fixed_sale_price_money' => $resolvedRarity === null ? null : $price,
                'obtained_at' => now(),
            ]);
            $expectedMoney += $price;
            $this->actingAs($user)->postJson("/api/v1/me/secretary/items/{$item->id}/sell", [
                'world_id' => $world->id,
            ])->assertOk()->assertJsonPath('data.nation.money', $expectedMoney);
            $this->assertDatabaseMissing('secretary_item_instances', ['id' => $item->id]);
        }
        $this->assertSame($expectedMoney, $nation->fresh()->money);
        $this->assertSame(4, DB::table('audit_events')->where('event_type', 'secretary.item_sold')
            ->where('visibility', 'private')->where('nation_id', $nation->id)->count());
    }

    public function test_fixed_item_sale_rejects_equipment_escrow_and_capacity_without_partial_credit(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '固定拒否島', '固定拒否島主');
        $secretary = $user->secretary()->sole();
        $equipped = SecretaryItemInstance::query()->create([
            'secretary_id' => $secretary->id, 'item_key' => SecretaryItemCatalog::RING, 'level' => 1,
            'equipped_slot' => 2, 'is_escrowed' => false, 'grant_key' => 'test:fixed-sale:equipped',
            'obtained_at' => now(),
        ]);
        $escrowed = SecretaryItemInstance::query()->create([
            'secretary_id' => $secretary->id, 'item_key' => SecretaryItemCatalog::ELF_BOW, 'level' => 1,
            'equipped_slot' => null, 'is_escrowed' => true, 'grant_key' => 'test:fixed-sale:escrowed',
            'obtained_at' => now(),
        ]);
        $capacity = SecretaryItemInstance::query()->create([
            'secretary_id' => $secretary->id, 'item_key' => SecretaryItemCatalog::COLLAR, 'level' => 1,
            'equipped_slot' => null, 'is_escrowed' => false, 'grant_key' => 'test:fixed-sale:capacity',
            'obtained_at' => now(),
        ]);
        $beforeVersion = $secretary->surfaceState->equipment_version;
        $beforeMoney = (int) $nation->money;

        // Equipment and escrow are rejected while there is ample money headroom.
        foreach ([$equipped, $escrowed] as $item) {
            $this->actingAs($user)->postJson("/api/v1/me/secretary/items/{$item->id}/sell", ['world_id' => $world->id])
                ->assertUnprocessable();
        }
        $this->assertSame($beforeMoney, (int) $nation->fresh()->money);

        $moneyCapacity = app(NationCapacityResolver::class)->resolve($nation)->money;
        $nation->update(['money' => $moneyCapacity]);
        $this->actingAs($user)->postJson("/api/v1/me/secretary/items/{$capacity->id}/sell", ['world_id' => $world->id])
            ->assertUnprocessable();

        $this->assertSame($moneyCapacity, $nation->fresh()->money);
        $this->assertSame($beforeVersion, $secretary->fresh()->surfaceState->equipment_version);
        $this->assertSame(3, SecretaryItemInstance::query()->whereIn('id', [$equipped->id, $escrowed->id, $capacity->id])->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'secretary.item_sold')->count());
    }

    public function test_fixed_item_sale_uses_the_posted_instance_when_an_identical_item_is_equipped(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '同名個体島', '同名個体島主');
        $secretary = $user->secretary()->sole();
        $equipped = $secretary->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::INORA_BRACELET,
            'level' => 1,
            'equipped_slot' => 2,
            'is_escrowed' => false,
            'grant_key' => 'test:fixed-sale:same-equipped',
            'obtained_at' => now(),
        ]);
        $sellable = $secretary->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::INORA_BRACELET,
            'level' => 1,
            'equipped_slot' => null,
            'is_escrowed' => false,
            'grant_key' => 'test:fixed-sale:same-sellable',
            'obtained_at' => now(),
        ]);
        $beforeMoney = (int) $nation->money;

        $this->actingAs($user)->postJson("/api/v1/me/secretary/items/{$sellable->id}/sell", [
            'world_id' => $world->id,
        ])->assertOk();

        $this->assertDatabaseHas('secretary_item_instances', [
            'id' => $equipped->id,
            'equipped_slot' => 2,
        ]);
        $this->assertDatabaseMissing('secretary_item_instances', ['id' => $sellable->id]);
        $this->assertSame($beforeMoney + 100, (int) $nation->fresh()->money);
    }

    public function test_fixed_item_sale_rolls_back_money_item_and_audit_when_audit_insert_fails(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '売却原子性島', '売却原子性島主');
        $secretary = $user->secretary()->sole();
        $item = $secretary->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::RING,
            'level' => 1,
            'equipped_slot' => null,
            'is_escrowed' => false,
            'grant_key' => 'test:fixed-sale:atomicity',
            'obtained_at' => now(),
        ]);
        $beforeMoney = (int) $nation->money;
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_secretary_item_sale_audit() RETURNS trigger AS $$
BEGIN
    IF NEW.event_type = 'secretary.item_sold' THEN
        RAISE EXCEPTION 'injected item sale audit failure';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER reject_secretary_item_sale_audit
BEFORE INSERT ON audit_events
FOR EACH ROW EXECUTE FUNCTION reject_secretary_item_sale_audit();
SQL);

        try {
            app(SecretaryItemSaleService::class)->sell($user, $world->id, $item->id);
            $this->fail('Expected injected item sale audit failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('injected item sale audit failure', $exception->getMessage());
        } finally {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS reject_secretary_item_sale_audit ON audit_events;
DROP FUNCTION IF EXISTS reject_secretary_item_sale_audit();
SQL);
        }

        $this->assertSame($beforeMoney, (int) $nation->fresh()->money);
        $this->assertDatabaseHas('secretary_item_instances', [
            'id' => $item->id,
            'secretary_id' => $secretary->id,
        ]);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'secretary.item_sold')->count());
    }

    public function test_old_bow_fixed_sale_is_refused_without_mutation(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '弓拒否島', '弓拒否島主');
        $secretary = $user->secretary()->sole();
        $oldBow = $secretary->itemInstances()->where('item_key', SecretaryItemCatalog::OLD_BOW)->sole();
        // Unequipped, so the refusal cannot come from the equipped-item rule.
        $oldBow->update(['equipped_slot' => null]);
        $beforeMoney = (int) $nation->money;
        $beforeVersion = $secretary->surfaceState->equipment_version;

        $this->actingAs($user)->postJson("/api/v1/me/secretary/items/{$oldBow->id}/sell", ['world_id' => $world->id])
            ->assertUnprocessable();
        $this->assertDatabaseHas('secretary_item_instances', ['id' => $oldBow->id, 'secretary_id' => $secretary->id]);
        $this->assertSame($beforeMoney, (int) $nation->fresh()->money);
        $this->assertSame($beforeVersion, $secretary->fresh()->surfaceState->equipment_version);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'secretary.item_sold')->count());
    }
}
