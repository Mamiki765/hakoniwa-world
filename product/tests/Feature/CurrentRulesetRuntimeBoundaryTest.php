<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\TurnRunner;
use App\Domain\Ruleset\ResetRequiredException;
use App\Domain\World\WorldGenerationProfile;
use App\Models\MapCell;
use App\Models\MapChunk;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class CurrentRulesetRuntimeBoundaryTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_historical_ruleset_world_read_only_apis_and_audit_snapshots_remain_available(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '閲覧国');
        $space = $this->surfaceMapSpace($world);
        $chunk = MapChunk::query()->where('map_space_id', $space->id)->orderBy('id')->firstOrFail();
        $historical = RulesetVersion::query()->where('key', 'roadmap-pr14-v1')->firstOrFail();
        $world->update(['ruleset_version_id' => $historical->id]);
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => 2,
            'ruleset_version_id' => $historical->id,
            'random_seed' => str_repeat('a', 64),
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);

        $this->getJson("/api/v1/public/worlds/{$world->id}/summary")->assertOk();
        $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")->assertOk();
        $this->getJson("/api/v1/public/worlds/{$world->id}/events")->assertOk();
        $this->getJson("/api/v1/public/worlds/{$world->id}/map-spaces")->assertOk();
        $this->getJson("/api/v1/public/nations/{$nation->id}")->assertOk();
        $this->getJson(
            "/api/v1/public/nations/{$nation->id}/map-spaces/{$space->id}/chunks/{$chunk->chunk_x}/{$chunk->chunk_y}",
        )->assertOk();

        $queueCount = NationCommandQueue::query()->count();
        $this->actingAs($user)->getJson("/api/v1/worlds/{$world->id}/map-spaces")->assertOk();
        $this->getJson("/api/v1/nations/{$nation->id}")->assertOk();
        $this->getJson("/api/v1/nations/{$nation->id}/events")->assertOk();
        $this->getJson("/api/v1/map-spaces/{$space->id}/chunks/{$chunk->chunk_x}/{$chunk->chunk_y}")->assertOk();
        $this->getJson("/api/v1/nations/{$nation->id}/map-spaces/{$space->id}/command-definitions")->assertOk();
        $this->getJson("/api/v1/nations/{$nation->id}/map-spaces/{$space->id}/command-queue")
            ->assertOk()->assertJsonPath('data.version', 1);
        $this->getJson("/api/v1/nations/{$nation->id}/sale-policies")->assertOk();

        $this->assertSame($queueCount, NationCommandQueue::query()->count());
        $this->assertSame($historical->settings, $run->rulesetVersion()->firstOrFail()->settings);
        $this->artisan('hakoniwa:turn:status', ['--world' => $world->key])
            ->expectsOutputToContain("ruleset={$historical->key}")
            ->assertSuccessful();
    }

    public function test_historical_ruleset_world_mutations_return_reset_required_without_game_state_changes(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '停止国');
        $space = $this->surfaceMapSpace($world);
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $item = app(CommandQueueService::class)->add(
            user: $owner,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'land_clear',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $resource = ResourceDefinition::query()->where('key', 'industrial_goods')->firstOrFail();
        $policy = NationResourceSalePolicy::query()
            ->where('nation_id', $nation->id)
            ->where('resource_definition_id', $resource->id)
            ->firstOrFail();
        $historical = RulesetVersion::query()->where('key', 'roadmap-pr14-v1')->firstOrFail();
        $world->update(['ruleset_version_id' => $historical->id]);
        $before = $this->gameState($world, $nation, $item, $policy);

        try {
            app(TurnRunner::class)->run($world);
            $this->fail('Historical World turn mutation was not rejected.');
        } catch (ResetRequiredException $exception) {
            $this->assertStringStartsWith('reset_required:', $exception->getMessage());
        }

        $queuePath = "/api/v1/nations/{$nation->id}/map-spaces/{$space->id}/command-queue";
        $this->actingAs($owner)->postJson($queuePath, [
            'command_key' => 'land_clear',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 2,
        ])->assertConflict()->assertJsonPath('code', 'reset_required');
        $this->patchJson("{$queuePath}/{$item->id}", [
            'quantity' => 2,
            'expected_version' => 2,
        ])->assertConflict()->assertJsonPath('code', 'reset_required');
        $this->putJson("{$queuePath}/reorder", [
            'ordered_ids' => [$item->id],
            'expected_version' => 2,
        ])->assertConflict()->assertJsonPath('code', 'reset_required');
        $this->deleteJson("{$queuePath}/{$item->id}", [
            'expected_version' => 2,
        ])->assertConflict()->assertJsonPath('code', 'reset_required');

        $this->actingAs(User::factory()->create())->postJson('/api/v1/nations', [
            'world_id' => $world->id,
            'name' => '拒否国',
        ])->assertConflict()->assertJsonPath('code', 'reset_required');
        $this->actingAs($owner)->putJson(
            "/api/v1/nations/{$nation->id}/resources/{$resource->id}/sale-policy",
            ['policy' => 'keep_amount', 'keep_amount' => 20, 'expected_version' => 1],
        )->assertConflict()->assertJsonPath('code', 'reset_required');

        try {
            app(OceanWorldGenerator::class)->initialize(WorldGenerationProfile::Debug32x32);
            $this->fail('Historical World initialization mutation was not rejected.');
        } catch (ResetRequiredException $exception) {
            $this->assertStringStartsWith('reset_required:', $exception->getMessage());
        }

        $this->assertSame($before, $this->gameState($world, $nation, $item, $policy));
    }

    /** @return array<string, mixed> */
    private function gameState(
        World $world,
        Nation $nation,
        NationCommandQueueItem $item,
        NationResourceSalePolicy $policy,
    ): array {
        $queue = $item->queue()->firstOrFail();
        $cellVersion = MapCell::query()
            ->where('map_space_id', $queue->map_space_id)
            ->where('x', $item->target_x)
            ->where('y', $item->target_y)
            ->value('version');

        return [
            'world' => $world->fresh()->only(['ruleset_version_id', 'current_turn']),
            'nation_count' => Nation::query()->where('world_id', $world->id)->count(),
            'nation' => $nation->fresh()->only(['money', 'state']),
            'creation_requests' => DB::table('nation_creation_requests')->where('world_id', $world->id)->count(),
            'queue' => $queue->only(['version']),
            'item' => $item->fresh()->only([
                'status', 'queue_position', 'quantity', 'execution_started_at',
                'execution_completed_at', 'execution_failed_at', 'failure_code',
            ]),
            'policy' => $policy->fresh()->only(['policy', 'keep_amount', 'version']),
            'turn_runs' => TurnRun::query()->where('world_id', $world->id)->count(),
            'audit_events' => DB::table('audit_events')->count(),
            'ruleset_versions' => DB::table('ruleset_versions')->count(),
            'command_definitions' => DB::table('command_definitions')->count(),
            'production_definitions' => DB::table('production_definitions')->count(),
            'world_generation_runs' => DB::table('world_generation_runs')->count(),
            'cell_version' => $cellVersion,
        ];
    }
}
