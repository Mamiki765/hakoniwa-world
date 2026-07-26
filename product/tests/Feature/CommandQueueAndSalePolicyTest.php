<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommandQueueAndSalePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_add_list_reorder_and_cancel_without_executing_commands(): void
    {
        [$user, $nation, $mapSpace] = $this->nation('予約国');
        $target = MapCell::query()->where('owner_nation_id', $nation->id)->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        $queuePath = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";
        $initialCell = $target->only([
            'terrain_definition_id', 'facility_definition_id', 'population', 'terrain_quantity',
            'facility_scale', 'facility_experience', 'facility_operational_state', 'version',
        ]);
        $initialMoney = $nation->money;
        $initialResources = $nation->resourceBalances()->orderBy('resource_definition_id')->pluck('amount')->all();
        $initialTrees = MapCell::query()->where('owner_nation_id', $nation->id)->sum('terrain_quantity');
        $requestKey = (string) Str::uuid();

        $definitions = $this->actingAs($user)->getJson(
            "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-definitions?target_q={$target->q}&target_r={$target->r}",
        )->assertOk()->json('data');
        $farmDefinition = collect($definitions)->firstWhere('key', 'build_farm');
        $this->assertSame(10000, $farmDefinition['initial_facility_capacity']['capacity_people']);
        $this->assertSame('10,000人規模', $farmDefinition['initial_facility_capacity']['formatted']);

        $this->actingAs($user)->getJson($queuePath)->assertOk()->assertJsonPath('data.version', 1);
        $first = $this->postJson($queuePath, [
            'command_key' => 'build_farm', 'target_q' => $target->q, 'target_r' => $target->r,
            'request_key' => $requestKey, 'expected_version' => 1, 'parameters' => [],
        ])->assertCreated()->assertJsonPath('data.queue.version', 2)->json('data');
        $this->assertStringContainsString('まだ実行されていません', $first['message']);

        // Retrying with the same idempotency key returns the original item even with the old version.
        $this->postJson($queuePath, [
            'command_key' => 'build_farm', 'target_q' => $target->q, 'target_r' => $target->r,
            'request_key' => $requestKey, 'expected_version' => 1, 'parameters' => [],
        ])->assertCreated()->assertJsonCount(1, 'data.queue.items');

        $second = $this->postJson($queuePath, [
            'command_key' => 'land_clear', 'target_q' => $target->q, 'target_r' => $target->r,
            'request_key' => (string) Str::uuid(), 'expected_version' => 2, 'parameters' => ['future_quantity' => 1],
        ])->assertCreated()->assertJsonPath('data.queue.version', 3)->json('data');
        $firstId = $first['item_id'];
        $secondId = $second['item_id'];

        $this->putJson($queuePath.'/reorder', [
            'ordered_ids' => [$secondId, $firstId], 'expected_version' => 3,
        ])->assertOk()->assertJsonPath('data.items.0.id', $secondId)->assertJsonPath('data.version', 4);
        $this->deleteJson($queuePath."/{$secondId}", ['expected_version' => 4])
            ->assertOk()->assertJsonPath('data.items.0.id', $firstId)->assertJsonPath('data.items.0.queue_position', 1);

        $this->assertSame(2, NationCommandQueueItem::query()->count());
        $this->assertSame(1, NationCommandQueueItem::query()->where('status', 'queued')->count());
        $this->assertSame(1, NationCommandQueueItem::query()->where('status', 'cancelled')->count());
        $this->assertSame($initialCell, $target->fresh()->only(array_keys($initialCell)));
        $this->assertSame($initialMoney, $nation->fresh()->money);
        $this->assertSame($initialResources, $nation->resourceBalances()->orderBy('resource_definition_id')->pluck('amount')->all());
        $this->assertSame($initialTrees, MapCell::query()->where('owner_nation_id', $nation->id)->sum('terrain_quantity'));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'command.queued')->where('subject_id', $firstId)->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'command.reordered')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'command.cancelled')->count());
    }

    public function test_queue_rejects_unauthorized_invalid_stale_and_cross_world_requests(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('検証国');
        $target = MapCell::query()->where('owner_nation_id', $nation->id)->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";

        $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
        $this->actingAs($owner)->postJson($path, [
            'command_key' => 'not_a_command', 'target_q' => $target->q, 'target_r' => $target->r,
            'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertUnprocessable();
        $this->postJson($path, [
            'command_key' => 'build_mine', 'target_q' => $target->q, 'target_r' => $target->r,
            'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertUnprocessable();
        $this->postJson($path, [
            'command_key' => 'land_clear', 'target_q' => $mapSpace->max_q + 1, 'target_r' => $target->r,
            'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertUnprocessable();

        $this->postJson($path, [
            'command_key' => 'land_clear', 'target_q' => $target->q, 'target_r' => $target->r,
            'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertCreated();
        $this->postJson($path, [
            'command_key' => 'land_clear', 'target_q' => $target->q, 'target_r' => $target->r,
            'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertConflict();

        $otherWorld = World::query()->create([
            'key' => 'other-world', 'name' => '別世界',
            'ruleset_version_id' => $nation->world()->value('ruleset_version_id'), 'current_turn' => 0,
        ]);
        $otherSpace = MapSpace::query()->create([
            'world_id' => $otherWorld->id, 'key' => 'surface', 'name' => '別地上',
            'coordinate_system' => 'pointy_top_axial', 'min_q' => -1, 'max_q' => 1, 'min_r' => -1, 'max_r' => 1,
        ]);
        $this->getJson("/api/v1/nations/{$nation->id}/map-spaces/{$otherSpace->id}/command-queue")->assertUnprocessable();
    }

    public function test_queue_limit_is_enforced_from_the_versioned_ruleset_boundary(): void
    {
        config(['hakoniwa.ruleset.command_queue_limit' => 1]);
        [$owner, $nation, $mapSpace] = $this->nation('上限国');
        $target = MapCell::query()->where('owner_nation_id', $nation->id)->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";
        $payload = [
            'command_key' => 'land_clear', 'target_q' => $target->q, 'target_r' => $target->r,
            'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ];

        $this->actingAs($owner)->postJson($path, $payload)->assertCreated();
        $this->postJson($path, [
            ...$payload, 'request_key' => (string) Str::uuid(), 'expected_version' => 2,
        ])->assertUnprocessable();
    }

    public function test_sale_policy_validation_authorization_audit_and_concurrency(): void
    {
        [$owner, $nation] = $this->nation('売却国');
        $wheat = ResourceDefinition::query()->where('key', 'wheat')->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/resources/{$wheat->id}/sale-policy";

        $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/sale-policies")
            ->assertOk()->assertJsonCount(5, 'data');
        $this->putJson($path, ['policy' => 'sell_all', 'keep_amount' => null, 'expected_version' => 1])
            ->assertOk()->assertJsonPath('data.policy', 'sell_all')->assertJsonPath('data.version', 2);
        $this->putJson($path, ['policy' => 'keep_amount', 'keep_amount' => 25, 'expected_version' => 2])
            ->assertOk()->assertJsonPath('data.keep_amount', 25)->assertJsonPath('data.version', 3);
        $this->putJson($path, ['policy' => 'stockpile', 'keep_amount' => null, 'expected_version' => 2])->assertConflict();
        $this->putJson($path, ['policy' => 'keep_amount', 'keep_amount' => -1, 'expected_version' => 3])->assertUnprocessable();
        $this->putJson($path, ['policy' => 'sell_all', 'keep_amount' => 1, 'expected_version' => 3])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->putJson($path, [
            'policy' => 'stockpile', 'keep_amount' => null, 'expected_version' => 3,
        ])->assertForbidden();

        $nonTradable = ResourceDefinition::query()->create([
            'key' => 'test_non_tradable', 'name' => '非売品', 'category' => 'test', 'unit' => 'unit',
            'nutrition_per_unit' => null, 'storable' => true, 'tradable' => false,
            'sale_price_key' => null, 'sort_order' => 999, 'metadata' => [],
        ]);
        $this->actingAs($owner)->putJson("/api/v1/nations/{$nation->id}/resources/{$nonTradable->id}/sale-policy", [
            'policy' => 'stockpile', 'keep_amount' => null, 'expected_version' => 1,
        ])->assertUnprocessable();

        $this->assertSame('keep_amount', NationResourceSalePolicy::query()
            ->where('nation_id', $nation->id)->where('resource_definition_id', $wheat->id)->value('policy'));
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'resource.sale_policy.updated')->count());
    }

    /** @return array{User, Nation, MapSpace} */
    private function nation(string $name): array
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, $name);

        return [$user, $nation, MapSpace::query()->where('world_id', $world->id)->firstOrFail()];
    }
}
