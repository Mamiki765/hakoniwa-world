<?php

namespace Tests\Feature;

use App\Application\SecretaryItemGrantService;
use App\Application\SecretarySynthesisService;
use App\Application\SecretaryWarehouseService;
use App\Domain\Secretary\SecretaryInventoryException;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SecretarySynthesisTest extends TestCase
{
    use RefreshDatabase;

    public function test_stack_keeps_the_equipped_base_within_the_cap_and_changes_its_equipment_version_once(): void
    {
        $secretary = Secretary::query()->create(['user_id' => User::factory()->create()->id]);
        $base = $this->item($secretary, 'fire_charm', 9997, 2);
        $small = $this->item($secretary, 'fire_charm', 2);
        $this->item($secretary, 'fire_charm', 3);
        $this->item($secretary, 'fire_charm', 3);
        $service = app(SecretarySynthesisService::class);
        $candidate = collect($service->index($secretary->user)['available'])->firstWhere('recipe_key', 'stack:fire_charm');
        $this->assertSame([$base->id, $small->id], $candidate['material_ids']);
        $this->assertSame(2, $candidate['left_over_count']);
        $version = $secretary->surfaceState->equipment_version;
        $requestId = (string) Str::uuid();
        $result = $service->synthesize($secretary->user, $requestId, $candidate['recipe_key'], $candidate['material_ids'], $base->id);

        $this->assertSame(9999, $base->fresh()->level);
        $this->assertSame(2, $base->fresh()->equipped_slot);
        $this->assertSame($version + 1, $secretary->fresh()->surfaceState->equipment_version);
        $this->assertSame([$small->id], $result['consumed_ids']);
        $this->assertEquals($result, $service->synthesize($secretary->user, $requestId, $candidate['recipe_key'], $candidate['material_ids'], $base->id));
        $this->assertSame($version + 1, $secretary->fresh()->surfaceState->equipment_version);
        $this->assertNotContains('stack:fire_charm', array_column($service->index($secretary->user)['available'], 'recipe_key'));
    }

    public function test_changed_materials_are_rejected_without_substituting_another_eligible_item(): void
    {
        $secretary = Secretary::query()->create(['user_id' => User::factory()->create()->id]);
        $base = $this->item($secretary, 'fire_charm', 1);
        $selected = $this->item($secretary, 'fire_charm', 1);
        $service = app(SecretarySynthesisService::class);
        $candidate = collect($service->index($secretary->user)['available'])->firstWhere('recipe_key', 'stack:fire_charm');
        $selected->update(['is_escrowed' => true]);
        $replacement = $this->item($secretary, 'fire_charm', 1);

        try {
            $service->synthesize($secretary->user, (string) Str::uuid(), $candidate['recipe_key'], $candidate['material_ids'], $base->id);
            $this->fail('Expected the changed material set to be rejected.');
        } catch (SecretaryInventoryException $exception) {
            $this->assertSame('materials_invalid', $exception->errorCode);
        }
        $this->assertSame(1, $base->fresh()->level);
        $this->assertNotNull($selected->fresh());
        $this->assertNotNull($replacement->fresh());
        $this->assertDatabaseCount('secretary_synthesis_receipts', 0);
    }

    public function test_fixed_recipe_works_at_capacity_and_replays_its_snapshot_after_the_result_is_gone(): void
    {
        $secretary = Secretary::query()->create(['user_id' => User::factory()->create()->id]);
        foreach (['love_emblem', 'twin_star_emblem', 'crescent_emblem'] as $key) {
            $this->item($secretary, $key, 1);
        }
        $capacity = SecretaryItemGrantService::capacity($secretary);
        for ($index = 3; $index < $capacity; $index++) {
            $this->item($secretary, 'ring', 1);
        }
        $service = app(SecretarySynthesisService::class);
        $candidate = collect($service->index($secretary->user)['available'])->firstWhere('recipe_key', 'succubus_emblem');
        $this->assertSame(1, $candidate['preview']['result_level']);
        $requestId = (string) Str::uuid();
        $result = $service->synthesize($secretary->user, $requestId, $candidate['recipe_key'], $candidate['material_ids'], null);
        $this->assertSame('succubus_emblem', $result['result']['item_key']);
        $this->assertTrue($result['first_craft']);
        $this->assertSame($capacity - 2, $result['warehouse']['used']);
        SecretaryItemInstance::query()->findOrFail($result['result']['item_id'])->delete();
        $this->assertEquals($result, $service->synthesize($secretary->user, $requestId, $candidate['recipe_key'], $candidate['material_ids'], null));
        $this->assertSame($capacity - 3, $secretary->itemInstances()->count());

        $this->expectException(SecretaryInventoryException::class);
        $this->expectExceptionMessage('同じ受付IDが別の合成操作に使用されています。');
        $service->synthesize($secretary->user, $requestId, 'stack:fire_charm', $candidate['material_ids'], null);
    }

    public function test_expansion_debits_once_preserves_the_response_and_rejects_stale_requests_and_the_limit(): void
    {
        $secretary = Secretary::query()->create(['user_id' => User::factory()->create()->id]);
        $rules = SecretaryItemGrantService::warehouseRules();
        $cost = $rules['expansion_cost_paradox'];
        DB::table('user_paradox_balances')->insert([
            'user_id' => $secretary->user_id, 'balance' => $cost * $rules['max_expansions'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $warehouse = app(SecretaryWarehouseService::class);
        $requestId = (string) Str::uuid();
        $result = $warehouse->expand($secretary->user, $requestId, 0);
        $this->assertSame($rules['base_capacity'] + $rules['expansion_slots'], $result['capacity']);
        $this->assertEquals($result, $warehouse->expand($secretary->user, $requestId, 0));
        $this->assertDatabaseCount('user_paradox_ledger', 1);
        try {
            $warehouse->expand($secretary->user, (string) Str::uuid(), 0);
            $this->fail('Expected stale expansion state to be rejected.');
        } catch (SecretaryInventoryException $exception) {
            $this->assertSame('expansion_mismatch', $exception->errorCode);
        }
        for ($expansions = 1; $expansions < $rules['max_expansions']; $expansions++) {
            $result = $warehouse->expand($secretary->user, (string) Str::uuid(), $expansions);
        }
        $this->assertNull($result['next_cost_paradox']);
        $this->assertSame(0, $result['paradox_balance']);
        $this->assertSame($rules['max_expansions'], DB::table('user_paradox_ledger')->count());
        try {
            $warehouse->expand($secretary->user, (string) Str::uuid(), $rules['max_expansions']);
            $this->fail('Expected the maximum expansion count to be enforced.');
        } catch (SecretaryInventoryException $exception) {
            $this->assertSame('expansion_max', $exception->errorCode);
        }
        $this->assertSame(0, DB::table('user_paradox_balances')->where('user_id', $secretary->user_id)->value('balance'));
    }

    private function item(Secretary $secretary, string $key, int $level, ?int $slot = null): SecretaryItemInstance
    {
        return $secretary->itemInstances()->create([
            'item_key' => $key, 'level' => $level, 'equipped_slot' => $slot, 'obtained_at' => now(),
        ]);
    }
}
