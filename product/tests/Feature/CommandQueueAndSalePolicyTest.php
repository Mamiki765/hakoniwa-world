<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\RulesetPublisher;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommandQueueAndSalePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_read_validates_ruleset_without_locking_the_shared_world(): void
    {
        [$user, $nation, $mapSpace] = $this->nation('読取国');
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($user)->getJson(
            "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue",
        )->assertOk();

        $worldQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from "worlds"'),
        ));
        $this->assertNotEmpty($worldQueries);
        $this->assertFalse(collect($worldQueries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'for update'),
        ));
    }

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
            "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-definitions?target_x={$target->x}&target_y={$target->y}",
        )->assertOk()->json('data.commands');
        $farmDefinition = collect($definitions)->firstWhere('key', 'build_farm');
        $this->assertSame(10000, $farmDefinition['initial_facility_capacity']['capacity_people']);
        $this->assertSame('10,000人規模', $farmDefinition['initial_facility_capacity']['formatted']);

        $this->actingAs($user)->getJson($queuePath)->assertOk()->assertJsonPath('data.version', 1);
        $first = $this->postJson($queuePath, [
            'command_key' => 'build_farm', 'target_x' => $target->x, 'target_y' => $target->y,
            'request_key' => $requestKey, 'expected_version' => 1, 'parameters' => [],
        ])->assertCreated()->assertJsonPath('data.queue.version', 2)->json('data');
        $this->assertStringContainsString('まだ実行されていません', $first['message']);

        // Retrying with the same idempotency key returns the original item even with the old version.
        $this->postJson($queuePath, [
            'command_key' => 'build_farm', 'target_x' => $target->x, 'target_y' => $target->y,
            'request_key' => $requestKey, 'expected_version' => 1, 'parameters' => [],
        ])->assertCreated()->assertJsonCount(1, 'data.queue.items');

        $second = $this->postJson($queuePath, [
            'command_key' => 'land_clear', 'target_x' => $target->x, 'target_y' => $target->y,
            'request_key' => (string) Str::uuid(), 'expected_version' => 2, 'parameters' => [],
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
            'command_key' => 'not_a_command', 'target_x' => $target->x, 'target_y' => $target->y,
            'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertUnprocessable();
        $this->postJson($path, [
            'command_key' => 'build_mine', 'target_x' => $target->x, 'target_y' => $target->y,
            'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertUnprocessable();
        $this->postJson($path, [
            'command_key' => 'land_clear', 'target_x' => $mapSpace->max_x + 1, 'target_y' => $target->y,
            'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertUnprocessable();

        $this->postJson($path, [
            'command_key' => 'land_clear', 'target_x' => $target->x, 'target_y' => $target->y,
            'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertCreated();
        $this->postJson($path, [
            'command_key' => 'land_clear', 'target_x' => $target->x, 'target_y' => $target->y,
            'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertConflict();

        $otherWorld = World::query()->create([
            'key' => 'other-world', 'name' => '別世界',
            'ruleset_version_id' => $nation->world()->value('ruleset_version_id'), 'current_turn' => 1,
        ]);
        $otherSpace = MapSpace::query()->create([
            'world_id' => $otherWorld->id, 'key' => 'surface', 'name' => '別地上',
            'coordinate_system' => 'staggered_square_offset', 'min_x' => 0, 'max_x' => 1, 'min_y' => 0, 'max_y' => 1,
        ]);
        $this->getJson("/api/v1/nations/{$nation->id}/map-spaces/{$otherSpace->id}/command-queue")->assertUnprocessable();
    }

    public function test_queue_limit_is_enforced_from_the_versioned_ruleset_boundary(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('上限国');
        $target = MapCell::query()->where('owner_nation_id', $nation->id)->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";
        $this->actingAs($owner);

        foreach (range(1, 30) as $expectedVersion) {
            $this->postJson($path, [
                'command_key' => 'land_clear', 'target_x' => $target->x, 'target_y' => $target->y,
                'request_key' => (string) Str::uuid(), 'expected_version' => $expectedVersion,
            ])->assertCreated();
        }

        $this->postJson($path, [
            'command_key' => 'land_clear', 'target_x' => $target->x, 'target_y' => $target->y,
            'request_key' => (string) Str::uuid(), 'expected_version' => 31,
        ])->assertUnprocessable();
    }

    public function test_effective_plan_has_thirty_slots_and_supports_selected_insertion(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('計画国');
        $target = MapCell::query()->where('owner_nation_id', $nation->id)->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";

        $empty = $this->actingAs($owner)->getJson($path)->assertOk()
            ->assertJsonCount(30, 'data.plan')
            ->assertJsonPath('data.explicit_count', 0);
        foreach ($empty->json('data.plan') as $slot) {
            $this->assertSame('automatic_finance', $slot['kind']);
            $this->assertFalse($slot['editable']);
        }

        $this->postJson($path, [
            'command_key' => 'land_clear',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'position' => 30,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.queue.plan.29.kind', 'explicit')
            ->assertJsonPath('data.queue.plan.29.command_name', '整地')
            ->assertJsonCount(30, 'data.queue.plan');

        $inserted = $this->postJson($path, [
            'command_key' => 'land_clear',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'position' => 29,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.queue.plan.28.kind', 'explicit')
            ->assertJsonPath('data.queue.plan.29.kind', 'explicit')
            ->assertJsonCount(30, 'data.queue.plan');

        $firstId = $inserted->json('data.queue.plan.29.id');
        $this->deleteJson($path."/{$firstId}", ['expected_version' => 3])
            ->assertOk()
            ->assertJsonPath('data.plan.0.kind', 'explicit')
            ->assertJsonPath('data.plan.1.kind', 'automatic_finance')
            ->assertJsonCount(30, 'data.plan');

        foreach ([4, 5] as $expectedVersion) {
            $inserted = $this->postJson($path, [
                'command_key' => 'land_clear',
                'target_x' => $target->x,
                'target_y' => $target->y,
                'position' => 5,
                'request_key' => (string) Str::uuid(),
                'expected_version' => $expectedVersion,
            ])->assertCreated();
        }
        $inserted->assertJsonPath('data.queue.plan.4.kind', 'explicit')
            ->assertJsonPath('data.queue.plan.5.kind', 'explicit')
            ->assertJsonCount(30, 'data.queue.plan');
    }

    public function test_universal_quantity_contract_validation_storage_editing_response_and_audit(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('数量国');
        $nation->update(['money' => 10_000]);
        $target = MapCell::query()->where('owner_nation_id', $nation->id)->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        $base = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}";
        $path = "{$base}/command-queue";

        $definitions = $this->actingAs($owner)->getJson(
            "{$base}/command-definitions?target_x={$target->x}&target_y={$target->y}",
        )->assertOk();
        $definitions->assertJsonPath('data.quantity_contract.type', 'integer')
            ->assertJsonPath('data.quantity_contract.minimum', 1)
            ->assertJsonPath('data.quantity_contract.maximum', 99)
            ->assertJsonPath('data.quantity_contract.default', 1)
            ->assertJsonPath('data.quantity_contract.quick_presets', [1, 5, 10, 25, 50, 99])
            ->assertJsonCount(7, 'data.commands');
        foreach ($definitions->json('data.commands') as $definition) {
            $this->assertArrayNotHasKey('parameter_schema', $definition);
        }

        foreach ([null, 0, 100, -1, 1.5, '10', true, [], ['nested' => true]] as $invalid) {
            $this->postJson($path, [
                'command_key' => 'excavate',
                'target_x' => $target->x,
                'target_y' => $target->y,
                'quantity' => $invalid,
                'request_key' => (string) Str::uuid(),
                'expected_version' => 1,
            ])->assertUnprocessable();
        }

        $created = $this->postJson($path, [
            'command_key' => 'excavate',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.queue.items.0.quantity', 1)
            ->assertJsonPath('data.queue.items.0.parameters', [])
            ->assertJsonPath('data.queue.plan.0.quantity', 1)
            ->assertJsonPath('data.queue.plan.1.quantity', null);
        $this->assertStringContainsString('"parameters":{}', $created->getContent());
        $itemId = $created->json('data.item_id');

        $this->patchJson("{$path}/{$itemId}", [
            'quantity' => 99,
            'expected_version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.items.0.quantity', 99)
            ->assertJsonPath('data.items.0.parameters', []);

        $this->patchJson("{$path}/{$itemId}", [
            'quantity' => 100,
            'expected_version' => 3,
        ])->assertUnprocessable();
        $this->patchJson("{$path}/{$itemId}", [
            'quantity' => null,
            'expected_version' => 3,
        ])->assertUnprocessable();
        $this->postJson($path, [
            'command_key' => 'excavate',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => 10,
            'parameters' => ['quantity' => 20],
            'request_key' => (string) Str::uuid(),
            'expected_version' => 3,
        ])->assertUnprocessable();

        $this->postJson($path, [
            'command_key' => 'land_clear',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => 1,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.queue.items.1.quantity', 1);

        $this->postJson($path, [
            'command_key' => 'build_farm',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => 99,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 4,
        ])->assertCreated()
            ->assertJsonPath('data.queue.items.2.quantity', 99)
            ->assertJsonCount(3, 'data.queue.items');

        $this->assertSame(3, NationCommandQueueItem::query()->where('status', 'queued')->count());
        $queuedAudit = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'command.queued')
            ->where('subject_id', $itemId)
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $queuedAudit['quantity']);
        $updatedAudit = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'command.quantity_updated')
            ->where('subject_id', $itemId)
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $updatedAudit);
        $this->assertSame(1, $updatedAudit['old_quantity']);
        $this->assertSame(99, $updatedAudit['new_quantity']);
    }

    public function test_future_special_parameter_api_distinguishes_omitted_defaults_from_explicit_null(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('特殊parameter国');
        $settings = config('hakoniwa.published_rulesets.roadmap-pr6-v1');
        $settings['key'] = 'test-special-parameters-v1';
        foreach ($settings['command_definitions'] as &$definition) {
            if ($definition['key'] !== 'land_clear') {
                continue;
            }
            $definition['metadata']['parameters'] = [
                'design_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 9,
                    'default' => 2,
                    'required' => true,
                ],
                'optional_variant' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 9,
                    'required' => false,
                    'nullable' => true,
                ],
            ];
        }
        unset($definition);
        $ruleset = app(RulesetPublisher::class)->publish($settings);
        $nation->world()->update(['ruleset_version_id' => $ruleset->id]);
        $target = MapCell::query()->where('owner_nation_id', $nation->id)->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";

        $this->actingAs($owner)->postJson($path, [
            'command_key' => 'land_clear',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 1,
            'parameters' => [],
        ])->assertCreated()
            ->assertJsonPath('data.queue.items.0.parameters.design_id', 2);

        $this->postJson($path, [
            'command_key' => 'land_clear',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 2,
            'parameters' => ['design_id' => null],
        ])->assertUnprocessable();

        $this->postJson($path, [
            'command_key' => 'land_clear',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 2,
            'parameters' => ['optional_variant' => null],
        ])->assertCreated()
            ->assertJsonPath('data.queue.items.1.parameters.design_id', 2)
            ->assertJsonPath('data.queue.items.1.parameters.optional_variant', null);
    }

    public function test_full_effective_plan_rejects_without_discarding_an_explicit_command(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('満員国');
        $target = MapCell::query()->where('owner_nation_id', $nation->id)->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";

        $this->actingAs($owner);
        for ($index = 0; $index < 30; $index++) {
            $this->postJson($path, [
                'command_key' => 'land_clear',
                'target_x' => $target->x,
                'target_y' => $target->y,
                'request_key' => (string) Str::uuid(),
                'expected_version' => $index + 1,
            ])->assertCreated();
        }

        $before = NationCommandQueueItem::query()->where('status', 'queued')->orderBy('queue_position')->pluck('id')->all();
        $this->postJson($path, [
            'command_key' => 'land_clear',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'position' => 1,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 31,
        ])->assertUnprocessable();

        $this->assertSame(
            $before,
            NationCommandQueueItem::query()->where('status', 'queued')->orderBy('queue_position')->pluck('id')->all(),
        );
        $reversed = array_reverse($before);
        $this->putJson($path.'/reorder', [
            'ordered_ids' => $reversed,
            'expected_version' => 31,
        ])->assertOk()
            ->assertJsonPath('data.items.0.id', $reversed[0])
            ->assertJsonCount(30, 'data.items');
        $this->deleteJson($path.'/'.$reversed[0], ['expected_version' => 32])
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $reversed[1])
            ->assertJsonPath('data.items.0.queue_position', 1)
            ->assertJsonPath('data.explicit_count', 29)
            ->assertJsonCount(30, 'data.plan');
        $this->actingAs($owner)->getJson($path)
            ->assertOk()
            ->assertJsonCount(30, 'data.plan')
            ->assertJsonPath('data.explicit_count', 29);
    }

    public function test_sale_policy_validation_authorization_audit_and_concurrency(): void
    {
        [$owner, $nation] = $this->nation('売却国');
        $wheat = ResourceDefinition::query()->where('key', 'wheat')->firstOrFail();
        $industrialGoods = ResourceDefinition::query()->where('key', 'industrial_goods')->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/resources/{$industrialGoods->id}/sale-policy";

        $policies = $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/sale-policies")
            ->assertOk()->assertJsonCount(5, 'data');
        $wheatPolicy = collect($policies->json('data'))->firstWhere('resource_key', 'wheat');
        $this->assertSame('stockpile', $wheatPolicy['policy']);
        $this->assertNotContains('sell_all', $wheatPolicy['allowed_policies']);
        $this->putJson("/api/v1/nations/{$nation->id}/resources/{$wheat->id}/sale-policy", [
            'policy' => 'sell_all', 'keep_amount' => null, 'expected_version' => 1,
        ])->assertUnprocessable();
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
            'unit_label' => null,
            'nutrition_per_unit' => null, 'storable' => true, 'tradable' => false,
            'sale_price_key' => null, 'sort_order' => 999, 'metadata' => [],
        ]);
        $this->actingAs($owner)->putJson("/api/v1/nations/{$nation->id}/resources/{$nonTradable->id}/sale-policy", [
            'policy' => 'stockpile', 'keep_amount' => null, 'expected_version' => 1,
        ])->assertUnprocessable();

        $this->assertSame('keep_amount', NationResourceSalePolicy::query()
            ->where('nation_id', $nation->id)->where('resource_definition_id', $industrialGoods->id)->value('policy'));
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
