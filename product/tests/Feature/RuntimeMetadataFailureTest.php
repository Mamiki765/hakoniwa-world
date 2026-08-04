<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\TurnRunner;
use App\Domain\Map\GridCoordinate;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class RuntimeMetadataFailureTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_missing_current_reclaim_metadata_rolls_back_the_turn_without_game_state_changes(): void
    {
        [$world, $user, $nation] = $this->nation('埋立設定欠落国');
        $space = $this->surfaceMapSpace($world);
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $target->update([
            'terrain_definition_id' => TerrainDefinition::query()->where('key', 'shallow')->valueOrFail('id'),
            'owner_nation_id' => null,
            'population' => 0,
        ]);
        $nation->update(['money' => 10_000]);
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'reclaim',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $this->removeCommandMetadata($world, 'reclaim', 'adjacent_water_spread_maximum');
        $before = $this->gameState($world, $nation, $item, $target);

        try {
            app(TurnRunner::class)->run($world);
            $this->fail('Missing reclaim metadata did not stop the turn.');
        } catch (DomainException $exception) {
            $this->assertSame('Reclaim adjacent water spread settings are invalid.', $exception->getMessage());
        }

        $this->assertSame($before, $this->gameState($world, $nation, $item, $target));
        $this->assertSame(TurnRun::STATUS_FAILED, TurnRun::query()->where('world_id', $world->id)->value('status'));
    }

    public function test_missing_current_oil_metadata_rolls_back_the_turn_without_game_state_changes(): void
    {
        [$world, $user, $nation] = $this->nation('油田設定欠落国');
        $space = $this->surfaceMapSpace($world);
        $capital = $nation->capital()->firstOrFail();
        $origin = new GridCoordinate($capital->x, $capital->y);
        $coordinate = collect($origin->radius(3))->first(
            static fn (GridCoordinate $candidate): bool => $origin->distanceTo($candidate) === 3,
        );
        $this->assertInstanceOf(GridCoordinate::class, $coordinate);
        $target = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)->firstOrFail();
        $target->update([
            'terrain_definition_id' => TerrainDefinition::query()->where('key', 'sea')->valueOrFail('id'),
            'facility_definition_id' => null,
            'owner_nation_id' => null,
            'population' => 0,
        ]);
        $nation->update(['money' => 10_000]);
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'excavate',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
            quantity: 5,
        )['item'];
        $this->removeCommandMetadata($world, 'excavate', 'oil_search_effect_key');
        $before = $this->gameState($world, $nation, $item, $target);

        try {
            app(TurnRunner::class)->run($world);
            $this->fail('Missing oil metadata did not stop the turn.');
        } catch (DomainException $exception) {
            $this->assertSame('Seabed oil search metadata is missing from the active ruleset.', $exception->getMessage());
        }

        $this->assertSame($before, $this->gameState($world, $nation, $item, $target));
        $this->assertSame(TurnRun::STATUS_FAILED, TurnRun::query()->where('world_id', $world->id)->value('status'));
    }

    public function test_missing_current_disaster_settings_roll_back_the_turn(): void
    {
        $world = $this->lightweightWorld();
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $settings = $ruleset->settings;
        unset($settings['turn_processing']['disasters']);
        $this->writeRulesetSettings($ruleset, $settings);
        $before = [
            'turn' => $world->current_turn,
            'cell_versions' => MapCell::query()->whereIn('map_space_id', $world->mapSpaces()->select('id'))->sum('version'),
            'events' => DB::table('audit_events')->count(),
        ];

        try {
            app(TurnRunner::class)->run($world);
            $this->fail('Missing disaster settings did not stop the turn.');
        } catch (DomainException $exception) {
            $this->assertSame('The active ruleset is missing disaster settings.', $exception->getMessage());
        }

        $this->assertSame($before, [
            'turn' => $world->fresh()->current_turn,
            'cell_versions' => MapCell::query()->whereIn('map_space_id', $world->mapSpaces()->select('id'))->sum('version'),
            'events' => DB::table('audit_events')->count(),
        ]);
        $this->assertSame(TurnRun::STATUS_FAILED, TurnRun::query()->where('world_id', $world->id)->value('status'));
    }

    public function test_missing_current_sale_policy_settings_fail_closed_without_policy_changes(): void
    {
        [$world, $user, $nation] = $this->nation('売却設定欠落国');
        $resource = ResourceDefinition::query()->where('key', 'industrial_goods')->firstOrFail();
        $policy = NationResourceSalePolicy::query()
            ->where('nation_id', $nation->id)
            ->where('resource_definition_id', $resource->id)
            ->firstOrFail();
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $settings = $ruleset->settings;
        unset($settings['turn_processing']['sale_policy']['sell_all_forbidden_resource_keys']);
        $this->writeRulesetSettings($ruleset, $settings);
        $before = $policy->only(['policy', 'keep_amount', 'version']);
        $auditCount = DB::table('audit_events')->count();

        $this->actingAs($user)->getJson("/api/v1/nations/{$nation->id}/sale-policies")
            ->assertUnprocessable();
        $this->putJson("/api/v1/nations/{$nation->id}/resources/{$resource->id}/sale-policy", [
            'policy' => 'keep_amount',
            'keep_amount' => 20,
            'expected_version' => 1,
        ])->assertUnprocessable();

        $this->assertSame($before, $policy->fresh()->only(array_keys($before)));
        $this->assertSame($auditCount, DB::table('audit_events')->count());
    }

    /** @return array{World, User, Nation} */
    private function nation(string $name): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, $name, '試験島主');

        return [$world, $user, $nation];
    }

    private function removeCommandMetadata(World $world, string $commandKey, string $metadataKey): void
    {
        $definition = CommandDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', $commandKey)
            ->firstOrFail();
        $metadata = $definition->metadata;
        unset($metadata[$metadataKey]);
        $definition->update(['metadata' => $metadata]);
    }

    /** @param array<string, mixed> $settings */
    private function writeRulesetSettings(RulesetVersion $ruleset, array $settings): void
    {
        DB::table('ruleset_versions')->where('id', $ruleset->id)->update([
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array<string, mixed> */
    private function gameState(World $world, Nation $nation, NationCommandQueueItem $item, MapCell $target): array
    {
        return [
            'turn' => $world->fresh()->current_turn,
            'money' => $nation->fresh()->money,
            'item' => $item->fresh()->only([
                'status', 'queue_position', 'quantity', 'execution_started_at',
                'execution_completed_at', 'execution_failed_at', 'failure_code',
            ]),
            'target' => $target->fresh()->only([
                'terrain_definition_id', 'facility_definition_id', 'owner_nation_id',
                'population', 'version',
            ]),
            'events' => DB::table('audit_events')->count(),
        ];
    }
}
