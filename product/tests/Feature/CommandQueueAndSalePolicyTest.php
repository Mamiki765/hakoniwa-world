<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\RulesetPublisher;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class CommandQueueAndSalePolicyTest extends TestCase
{
    use CreatesTestWorlds;
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
        $this->assertStringContainsString('実行時に資金・資源・地形・施設・所有権・怪獣占有を再確認', $first['message']);

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

    public function test_queue_accepts_future_state_but_rejects_unauthorized_structural_stale_and_cross_world_requests(): void
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
        ])->assertCreated();
        $this->postJson($path, [
            'command_key' => 'land_clear', 'target_x' => $mapSpace->max_x + 1, 'target_y' => $target->y,
            'request_key' => (string) Str::uuid(), 'expected_version' => 2,
        ])->assertUnprocessable();

        $this->postJson($path, [
            'command_key' => 'land_clear', 'target_x' => $target->x, 'target_y' => $target->y,
            'request_key' => (string) Str::uuid(), 'expected_version' => 2,
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

    public function test_seabed_oil_search_availability_and_queue_validation_match_terrain_ownership_and_facility_state(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('油田予約国');
        $nation->update(['money' => 1_000]);
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->whereIn('key', ['plain', 'wasteland', 'forest']))
            ->orderBy('id')->firstOrFail();
        $seaId = DB::table('terrain_definitions')->where('key', 'sea')->value('id');
        $this->assertNotNull($seaId);
        $target->update([
            'terrain_definition_id' => $seaId,
            'facility_definition_id' => null,
            'owner_nation_id' => null,
            'population' => 0,
            'terrain_quantity' => null,
        ]);
        $anchorCoordinate = (new GridCoordinate($target->x, $target->y))->neighbor(GridCoordinate::EAST);
        $anchor = MapCell::query()->where('map_space_id', $mapSpace->id)
            ->where('x', $anchorCoordinate->x)->where('y', $anchorCoordinate->y)->firstOrFail();
        $anchor->update(['owner_nation_id' => $nation->id]);
        $definitionsPath = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-definitions"
            ."?target_x={$target->x}&target_y={$target->y}";
        $queuePath = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";

        $definitions = $this->actingAs($owner)->getJson($definitionsPath)->assertOk()->json('data.commands');
        $excavate = collect($definitions)->firstWhere('key', 'excavate');
        $this->assertTrue($excavate['applicable']);
        $this->assertTrue($excavate['available']);
        $this->assertStringContainsString('海底油田', $excavate['description']);
        $this->postJson($queuePath, [
            'command_key' => 'excavate',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 1,
            'quantity' => 3,
        ])->assertCreated()->assertJsonPath('data.queue.items.0.quantity', 3);

        $oil = FacilityDefinition::query()->where('key', 'seabed_oil_field')->firstOrFail();
        $target->update(['facility_definition_id' => $oil->id, 'owner_nation_id' => $nation->id]);
        $occupied = collect($this->getJson($definitionsPath)->assertOk()->json('data.commands'))
            ->firstWhere('key', 'excavate');
        $this->assertTrue($occupied['applicable']);
        $this->assertSame('currently_unavailable', $occupied['execution_preview_status']);
        $this->assertContains('施設のある海では油田探索できません。', $occupied['execution_warnings']);
        $this->assertNull($occupied['unavailable_reason']);
        $this->postJson($queuePath, [
            'command_key' => 'excavate',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 2,
        ])->assertCreated();

        [, $rival] = $this->nation('油田競合国');
        $target->update(['facility_definition_id' => null, 'owner_nation_id' => $rival->id]);
        $rivalOwned = collect($this->getJson($definitionsPath)->assertOk()->json('data.commands'))
            ->firstWhere('key', 'excavate');
        $this->assertTrue($rivalOwned['applicable']);
        $this->assertSame('currently_unavailable', $rivalOwned['execution_preview_status']);
        $this->assertContains('他国所有の水域は掘削できません。', $rivalOwned['execution_warnings']);
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
            ->assertJsonCount(25, 'data.commands');
        foreach ($definitions->json('data.commands') as $definition) {
            $this->assertArrayNotHasKey('parameter_schema', $definition);
            $this->assertArrayHasKey('target_type', $definition);
            $this->assertArrayHasKey('parameters', $definition);
            $this->assertArrayHasKey('quantity_semantics', $definition);
            $this->assertArrayHasKey('quantity_default', $definition);
            $this->assertArrayHasKey('quantity_options', $definition);
        }
        $commands = collect($definitions->json('data.commands'));
        $this->assertSame('unused', $commands->firstWhere('key', 'land_clear')['quantity_semantics']);
        $this->assertSame('ordinary', $commands->firstWhere('key', 'excavate')['quantity_semantics']);
        $this->assertSame(99, $commands->firstWhere('key', 'missile')['quantity_default']);
        $this->assertSame('selector', $commands->firstWhere('key', 'build_monument')['quantity_semantics']);
        $this->assertNull($commands->firstWhere('key', 'build_monument')['quantity_default']);
        $this->assertNotEmpty($commands->firstWhere('key', 'build_monument')['quantity_options']);

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
        $landClearId = NationCommandQueueItem::query()->whereHas(
            'definition', fn ($query) => $query->where('key', 'land_clear'),
        )->value('id');
        $this->patchJson("{$path}/{$landClearId}", [
            'quantity' => 2,
            'expected_version' => 4,
        ])->assertUnprocessable();
        $this->postJson($path, [
            'command_key' => 'land_clear',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => 2,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 4,
        ])->assertUnprocessable();

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

    public function test_command_specific_defaults_and_selector_are_validated_at_registration(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('選択数量国');
        $nation->update(['money' => 20_000]);
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";

        $this->actingAs($owner)->postJson($path, [
            'command_key' => 'build_monument',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 1,
        ])->assertUnprocessable();

        $monument = $this->postJson($path, [
            'command_key' => 'build_monument',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => 1,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.queue.items.0.quantity_semantics', 'selector')
            ->assertJsonPath('data.queue.items.0.quantity_label', '平和記念碑');

        $this->patchJson("{$path}/{$monument->json('data.item_id')}", [
            'quantity' => 2,
            'expected_version' => 2,
        ])->assertUnprocessable();

        $this->postJson($path, [
            'command_key' => 'missile',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.queue.items.1.quantity', 1)
            ->assertJsonPath('data.queue.items.1.quantity_semantics', 'ordinary');
    }

    public function test_selector_requires_explicit_presence_when_queue_service_is_called_directly(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('直接選択国');
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();

        try {
            app(CommandQueueService::class)->add(
                user: $owner,
                nation: $nation,
                mapSpace: $mapSpace,
                commandKey: 'build_monument',
                targetX: $target->x,
                targetY: $target->y,
                requestKey: (string) Str::uuid(),
                expectedVersion: 1,
            );
            $this->fail('selector quantity omission must be rejected by the application service');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('選択肢を明示', $exception->getMessage());
        }

        $this->assertDatabaseMissing('nation_command_queues', ['nation_id' => $nation->id]);
    }

    public function test_quantity_patch_does_not_reorder_or_repair_legacy_queue_positions(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('数量位置国');
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";

        foreach ([1, 2] as $expectedVersion) {
            $this->actingAs($owner)->postJson($path, [
                'command_key' => 'excavate',
                'target_x' => $target->x,
                'target_y' => $target->y,
                'quantity' => 1,
                'request_key' => (string) Str::uuid(),
                'expected_version' => $expectedVersion,
            ])->assertCreated();
        }
        $items = NationCommandQueueItem::query()->where('status', 'queued')->orderBy('queue_position')->get();
        $items[1]->update(['queue_position' => 1001]);

        $response = $this->patchJson("{$path}/{$items[0]->id}", [
            'quantity' => 5,
            'expected_version' => 3,
        ])->assertOk()
            ->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.items.0.id', $items[1]->id)
            ->assertJsonPath('data.items.0.queue_position', 1)
            ->assertJsonPath('data.items.1.id', $items[0]->id)
            ->assertJsonPath('data.items.1.queue_position', 2);

        $this->assertSame([1, 2], collect($response->json('data.items'))->pluck('queue_position')->all());

        $this->assertSame(
            [1, 1001],
            NationCommandQueueItem::query()->where('status', 'queued')->orderBy('id')->pluck('queue_position')->all(),
        );
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
        config([
            'hakoniwa.ruleset.key' => $settings['key'],
            'hakoniwa.ruleset.version' => $settings['version'],
        ]);
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

    public function test_future_preview_follows_prior_same_cell_results_without_requiring_full_simulation(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('未来投影国');
        $nation->update(['money' => 2_000]);
        $anchor = MapCell::query()->where('owner_nation_id', $nation->id)->firstOrFail();
        $coordinate = (new GridCoordinate($anchor->x, $anchor->y))->neighborsWithin(
            $mapSpace->min_x,
            $mapSpace->max_x,
            $mapSpace->min_y,
            $mapSpace->max_y,
        )[0];
        $target = MapCell::query()->where('map_space_id', $mapSpace->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)->firstOrFail();
        $target->update([
            'terrain_definition_id' => DB::table('terrain_definitions')->where('key', 'shallow')->value('id'),
            'facility_definition_id' => null,
            'owner_nation_id' => null,
            'population' => 0,
        ]);
        $base = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}";
        $queue = "{$base}/command-queue";
        $this->actingAs($owner)->postJson($queue, [
            'command_key' => 'reclaim', 'target_x' => $target->x, 'target_y' => $target->y,
            'position' => 1, 'request_key' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertCreated();

        $landClear = collect($this->getJson(
            "{$base}/command-definitions?target_x={$target->x}&target_y={$target->y}&position=2",
        )->assertOk()->json('data.commands'))->firstWhere('key', 'land_clear');
        $this->assertSame('executable_after_queue', $landClear['execution_preview_status']);
        $this->assertContains('予約済みcommand後は実行可能です。', $landClear['execution_warnings']);
        $this->postJson($queue, [
            'command_key' => 'land_clear', 'target_x' => $target->x, 'target_y' => $target->y,
            'position' => 2, 'request_key' => (string) Str::uuid(), 'expected_version' => 2,
        ])->assertCreated();

        $farm = collect($this->getJson(
            "{$base}/command-definitions?target_x={$target->x}&target_y={$target->y}&position=3",
        )->assertOk()->json('data.commands'))->firstWhere('key', 'build_farm');
        $this->assertSame('executable_after_queue', $farm['execution_preview_status']);
        $this->assertContains('予約済みcommand後は実行可能です。', $farm['execution_warnings']);
    }

    public function test_queue_preview_and_registration_allow_settlements_but_reject_capital_overbuild(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('集落予約国');
        $capital = $nation->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($capital->id)->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->orderBy('id')->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $target,
            FacilityDefinition::query()->where('key', 'village')->firstOrFail(),
        );
        $target->update(['population' => 1_234]);
        $base = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}";

        $farm = collect($this->actingAs($owner)->getJson(
            "{$base}/command-definitions?target_x={$target->x}&target_y={$target->y}",
        )->assertOk()->json('data.commands'))->firstWhere('key', 'build_farm');
        $this->assertSame('currently_executable', $farm['execution_preview_status']);

        $this->postJson("{$base}/command-queue", [
            'command_key' => 'build_farm',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 1,
        ])->assertCreated();

        $capitalFarm = collect($this->getJson(
            "{$base}/command-definitions?target_x={$capital->x}&target_y={$capital->y}",
        )->assertOk()->json('data.commands'))->firstWhere('key', 'build_farm');
        $this->assertSame('currently_unavailable', $capitalFarm['execution_preview_status']);
        $this->assertContains('首都を通常建設commandで上書きすることはできません。', $capitalFarm['execution_warnings']);
        $this->postJson("{$base}/command-queue", [
            'command_key' => 'build_farm',
            'target_x' => $capital->x,
            'target_y' => $capital->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 2,
        ])->assertUnprocessable();
    }

    public function test_nation_target_commands_use_capital_coordinates_and_validate_parameters_without_cell_selection(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('Nation対象国');
        $target = app(NationCreationService::class)->create(
            User::factory()->create(),
            $nation->world()->firstOrFail(),
            '援助対象国',
            '試験島主',
        );
        $base = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}";
        $catalog = $this->actingAs($owner)->getJson("{$base}/command-definitions")->assertOk();
        $finance = collect($catalog->json('data.commands'))->firstWhere('key', 'finance');
        $aid = collect($catalog->json('data.commands'))->firstWhere('key', 'money_aid');
        $this->assertTrue($finance['applicable']);
        $this->assertSame('nation', $finance['target_type']);
        $this->assertSame('integer', $aid['parameters']['target_nation_id']['type']);

        $created = $this->postJson("{$base}/command-queue", [
            'command_key' => 'finance',
            'request_key' => (string) Str::uuid(),
            'expected_version' => 1,
        ])->assertCreated();
        $this->assertSame($nation->capital()->value('x'), $created->json('data.queue.items.0.target_x'));
        $this->assertSame($nation->capital()->value('y'), $created->json('data.queue.items.0.target_y'));

        $this->postJson("{$base}/command-queue", [
            'command_key' => 'money_aid',
            'request_key' => (string) Str::uuid(),
            'expected_version' => 2,
            'parameters' => ['target_nation_id' => $target->id],
        ])->assertCreated()->assertJsonPath('data.queue.items.1.parameters.target_nation_id', $target->id);
        $this->postJson("{$base}/command-queue", [
            'command_key' => 'money_aid',
            'request_key' => (string) Str::uuid(),
            'expected_version' => 3,
            'parameters' => [],
        ])->assertUnprocessable();
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
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, $name, '試験島主');

        return [$user, $nation, MapSpace::query()->where('world_id', $world->id)->firstOrFail()];
    }
}
