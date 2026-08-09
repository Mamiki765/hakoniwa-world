<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CompleteTurnEngine;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\TurnRunner;
use App\Domain\Map\MapCellStateService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Turn\GameplayTurnPhase;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnPhase;
use App\Domain\Turn\TurnPhaseResult;
use App\Domain\Turn\TurnPipeline;
use App\Domain\Turn\TurnSeedGenerator;
use App\Domain\Turn\WorldTurnLock;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\NationResource;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class CompleteTurnIntegrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_realistic_multi_nation_3600_cell_world_commits_the_complete_non_combat_turn_atomically(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '統合国', '試験島主');
        $secondUser = User::factory()->create();
        $secondNation = app(NationCreationService::class)->create($secondUser, $world, '統合国二', '試験島主');
        $farmCell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $farmCell,
            FacilityDefinition::query()->where('key', 'farm')->firstOrFail(),
        );
        $farmCell->facility_scale = 1;
        $farmCell->save();
        $factoryTarget = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->whereKeyNot($farmCell->id)
            ->first();
        if ($factoryTarget === null) {
            $factoryTarget = MapCell::query()->where('owner_nation_id', $nation->id)
                ->whereNull('facility_definition_id')->whereKeyNot($farmCell->id)->firstOrFail();
            $factoryTarget = $factoryTarget->fresh(['terrain', 'facility']);
            app(MapCellStateService::class)->transitionTerrain(
                $factoryTarget,
                TerrainDefinition::query()->where('key', 'plain')->firstOrFail(),
            );
            $factoryTarget->save();
        }
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        $queuedFactory = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'build_factory',
            targetX: $factoryTarget->x,
            targetY: $factoryTarget->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $secondTarget = MapCell::query()->where('owner_nation_id', $secondNation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))->firstOrFail();
        $secondCommand = app(CommandQueueService::class)->add(
            user: $secondUser,
            nation: $secondNation,
            mapSpace: $space,
            commandKey: 'land_clear',
            targetX: $secondTarget->x,
            targetY: $secondTarget->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $industrialGoods = ResourceDefinition::query()->where('key', 'industrial_goods')->firstOrFail();
        NationResource::query()->where('nation_id', $nation->id)
            ->where('resource_definition_id', $industrialGoods->id)->update(['amount' => 1_500]);
        NationResourceSalePolicy::query()->where('nation_id', $nation->id)
            ->where('resource_definition_id', $industrialGoods->id)
            ->update(['policy' => 'sell_all', 'keep_amount' => null]);
        $populationBefore = (int) MapCell::query()->where('owner_nation_id', $nation->id)->sum('population');
        $wheatBefore = (int) NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('key', 'wheat'))->value('amount');
        $summaryStart = collect([$nation, $secondNation])->mapWithKeys(fn (Nation $summaryNation): array => [
            $summaryNation->id => [
                'money' => (int) $summaryNation->money,
                'population' => (int) MapCell::query()->where('owner_nation_id', $summaryNation->id)->sum('population'),
                'food' => (int) NationResource::query()->where('nation_id', $summaryNation->id)
                    ->whereHas('definition', fn ($query) => $query->where('category', 'food'))->sum('amount'),
            ],
        ]);
        $chunkVersionsBefore = DB::table('map_chunks')->orderBy('id')->pluck('version', 'id');

        $run = (new TurnRunner(
            app(TurnPipeline::class),
            new WorldTurnLock,
            new Pr11FixedTurnSeedGenerator(str_repeat('7', 64)),
            app(CurrentRulesetGuard::class),
        ))->run($world);

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $world->fresh()->current_turn);
        $this->assertCount(12, $run->phase_results);
        $this->assertSame(3600, collect($run->phase_results)->firstWhere('phase', 'process_cells')['metrics']['processed']);
        $this->assertGreaterThan(0, $populationBefore);
        $production = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'resource.food_produced')->where('subject_id', $nation->id)
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $consumption = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'resource.food_consumed')->where('subject_id', $nation->id)
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        /** @var list<array{resource_key: string, consumed_units: int}> $consumedResources */
        $consumedResources = $consumption['resources'];
        $consumedWheat = 0;
        foreach ($consumedResources as $consumedResource) {
            if ($consumedResource['resource_key'] === 'wheat') {
                $consumedWheat = $consumedResource['consumed_units'];
                break;
            }
        }
        $wheatAfter = (int) NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('key', 'wheat'))->value('amount');
        $this->assertGreaterThan(0, $production['applied_tons']);
        $this->assertSame($wheatBefore + $production['applied_tons'] - $consumedWheat, $wheatAfter);
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'resource.food_produced')->count());
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'resource.food_consumed')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'turn.completed')->count());
        $summaryRows = DB::table('audit_events')->where('event_type', 'turn.summary')
            ->where('turn', 2)->orderBy('nation_id')->get();
        $this->assertCount(2, $summaryRows);
        foreach ($summaryRows as $summaryRow) {
            $this->assertSame('nation', $summaryRow->visibility);
            $metadata = json_decode((string) $summaryRow->metadata, true, 512, JSON_THROW_ON_ERROR);
            $summaryNationId = (int) $summaryRow->nation_id;
            $summaryEnd = [
                'money' => (int) $world->nations()->whereKey($summaryNationId)->value('money'),
                'population' => (int) MapCell::query()->where('owner_nation_id', $summaryNationId)->sum('population'),
                'food' => (int) NationResource::query()->where('nation_id', $summaryNationId)
                    ->whereHas('definition', fn ($query) => $query->where('category', 'food'))->sum('amount'),
            ];
            foreach (['money', 'population', 'food'] as $key) {
                $this->assertSame($summaryStart[$summaryNationId][$key], $metadata['summary'][$key]['start']);
                $this->assertSame($summaryEnd[$key], $metadata['summary'][$key]['end']);
                $this->assertSame(
                    $summaryEnd[$key] - $summaryStart[$summaryNationId][$key],
                    $metadata['summary'][$key]['delta'],
                );
            }
        }
        $this->assertSame('completed', $queuedFactory->fresh()->status);
        $this->assertSame('completed', $secondCommand->fresh()->status);
        $this->assertSame('factory', $factoryTarget->fresh()->facility()->value('key'));
        $this->assertSame('plain', $secondTarget->fresh()->terrain()->value('key'));
        $this->assertSame(0, $this->eventMetadata('resource.industrial_produced', $nation->id)['produced_units']);
        $this->assertSame(500, (int) NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('key', 'industrial_goods'))->value('amount'));
        $this->assertGreaterThan(0, DB::table('audit_events')->where('event_type', 'population.increased')->count());
        $this->assertGreaterThan(0, DB::table('audit_events')->where('event_type', 'forest.grown')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'resource.automatic_sale')
            ->whereRaw("metadata->>'resource_key' = ?", ['industrial_goods'])
            ->whereRaw("(metadata->>'sold')::integer = ?", [1_000])->count());
        $mutatedCellIds = DB::table('audit_events')
            ->where('subject_type', (new MapCell)->getMorphClass())
            ->whereNotNull('subject_id')->distinct()->pluck('subject_id');
        $changedChunkIds = MapCell::query()->whereIn('id', $mutatedCellIds)
            ->distinct()->orderBy('map_chunk_id')->pluck('map_chunk_id');
        $this->assertNotEmpty($changedChunkIds);
        $maximumMutationsInOneChunk = (int) MapCell::query()->whereIn('id', $mutatedCellIds)
            ->selectRaw('count(*) as mutation_count')->groupBy('map_chunk_id')
            ->orderByDesc('mutation_count')->value('mutation_count');
        $this->assertGreaterThan(1, $maximumMutationsInOneChunk);
        $chunkVersionsAfter = DB::table('map_chunks')->orderBy('id')->pluck('version', 'id');
        foreach ($chunkVersionsBefore as $chunkId => $beforeVersion) {
            $expected = (int) $beforeVersion + ($changedChunkIds->contains($chunkId) ? 1 : 0);
            $this->assertSame($expected, (int) $chunkVersionsAfter[$chunkId]);
        }
        $aggregateMetrics = collect($run->phase_results)->firstWhere('phase', 'aggregate_nations')['metrics'];
        $this->assertSame($changedChunkIds->count(), $aggregateMetrics['map_chunks_updated']);
        $this->assertSame([], $run->failure_context);
    }

    public function test_real_gameplay_mutations_roll_back_and_retry_the_same_run_seed(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '原子性国', '試験島主');
        NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('key', 'industrial_goods'))
            ->update(['amount' => 10_000_000]);
        NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('key', 'minerals'))
            ->update(['amount' => 9_999_500]);
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))->firstOrFail();
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'land_clear',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $snapshot = $this->gameplaySnapshot($world, $nation->id, $item->id);
        $capturedResult = null;
        $capture = function () use (&$capturedResult, $world, $nation, $item): void {
            $capturedResult = $this->deterministicGameplayResult($world, $nation->id, $item->id);
        };
        $engine = app(CompleteTurnEngine::class);
        $pipeline = new TurnPipeline(array_map(
            static fn (string $key): TurnPhase => $key === 'finalize_turn'
                ? new CapturingFailingCompleteTurnPhase($key, $engine, $capture)
                : new GameplayTurnPhase($key, $engine),
            TurnPipeline::CANONICAL_PHASE_KEYS,
        ));
        $seed = str_repeat('7', 64);
        $runner = new TurnRunner(
            $pipeline,
            new WorldTurnLock,
            new Pr11FixedTurnSeedGenerator($seed),
            app(CurrentRulesetGuard::class),
        );

        try {
            $runner->run($world);
            $this->fail('Expected injected failure after cell processing.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected failure after gameplay mutations', $exception->getMessage());
        }

        $failed = TurnRun::query()->where('world_id', $world->id)->where('is_dry_run', false)->firstOrFail();
        $this->assertSame(TurnRun::STATUS_FAILED, $failed->status);
        $this->assertSame('finalize_turn', $failed->failure_context['phase']);
        $this->assertSame($seed, $failed->random_seed);
        $this->assertIsArray($capturedResult);
        $this->assertSame($snapshot, $this->gameplaySnapshot($world, $nation->id, $item->id));
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'turn.summary')->count());

        $completed = app(TurnRunner::class)->run($world->fresh());
        $this->assertSame(TurnRun::STATUS_COMPLETED, $completed->status);
        $this->assertSame($failed->id, $completed->id);
        $this->assertSame($seed, $completed->random_seed);
        $this->assertSame(2, $completed->attempt_count);
        $this->assertSame(2, $world->fresh()->current_turn);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame('plain', $target->fresh()->terrain()->value('key'));
        $this->assertSame(9_999_000, (int) NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('key', 'industrial_goods'))
            ->value('amount'));
        $this->assertSame(9_999_000, (int) NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('key', 'minerals'))
            ->value('amount'));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'resource.automatic_sale')
            ->whereRaw("metadata->>'resource_key' = ?", ['industrial_goods'])
            ->whereRaw("metadata->>'sold' = ?", ['1000'])
            ->whereRaw("metadata->>'sale_reason' = ?", ['capacity_overflow'])->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'capacity.overflow')
            ->whereRaw("metadata->>'asset' = ?", ['resource'])
            ->whereRaw("metadata->>'resource_key' = ?", ['minerals'])
            ->whereRaw("metadata->>'overflow' = ?", ['500'])->count());
        $this->assertGreaterThan($snapshot['audit_count'], DB::table('audit_events')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'turn.completed')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'turn.summary')->count());
        $this->assertSame($capturedResult, $this->deterministicGameplayResult($world, $nation->id, $item->id));
    }

    /** @return array<string, mixed> */
    private function gameplaySnapshot(World $world, int $nationId, int $itemId): array
    {
        return [
            'current_turn' => $world->fresh()->current_turn,
            'money' => $world->nations()->whereKey($nationId)->value('money'),
            'resources' => NationResource::query()->where('nation_id', $nationId)
                ->orderBy('resource_definition_id')->pluck('amount', 'resource_definition_id')->all(),
            'cells' => MapCell::query()->where('owner_nation_id', $nationId)->orderBy('id')->get([
                'id', 'terrain_definition_id', 'facility_definition_id', 'population', 'terrain_quantity',
                'facility_scale', 'facility_experience', 'facility_operational_state', 'version',
            ])->map->attributesToArray()->all(),
            'command' => NationCommandQueueItem::query()->findOrFail($itemId)->only([
                'status', 'queue_position', 'quantity', 'execution_started_at', 'execution_completed_at',
                'execution_failed_at', 'failure_code', 'failure_metadata',
            ]),
            'chunk_versions' => DB::table('map_chunks')
                ->whereIn('map_space_id', DB::table('map_spaces')->where('world_id', $world->id)->select('id'))
                ->orderBy('id')->pluck('version', 'id')->all(),
            'audit_count' => DB::table('audit_events')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function deterministicGameplayResult(World $world, int $nationId, int $itemId): array
    {
        return [
            'money' => $world->nations()->whereKey($nationId)->value('money'),
            'resources' => NationResource::query()->where('nation_id', $nationId)
                ->orderBy('resource_definition_id')->pluck('amount', 'resource_definition_id')->all(),
            'cells' => MapCell::query()->where('owner_nation_id', $nationId)->orderBy('id')->get([
                'id', 'terrain_definition_id', 'facility_definition_id', 'population', 'terrain_quantity',
                'facility_scale', 'facility_experience', 'facility_operational_state', 'version',
            ])->map->attributesToArray()->all(),
            'command' => NationCommandQueueItem::query()->findOrFail($itemId)->only([
                'status', 'queue_position', 'quantity', 'failure_code', 'failure_metadata',
            ]),
            'chunk_versions' => DB::table('map_chunks')
                ->whereIn('map_space_id', DB::table('map_spaces')->where('world_id', $world->id)->select('id'))
                ->orderBy('id')->pluck('version', 'id')->all(),
            'events' => DB::table('audit_events')->orderBy('id')->get([
                'event_type', 'subject_type', 'subject_id', 'metadata',
            ])->map(static fn (object $event): array => [
                'event_type' => $event->event_type,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'metadata' => json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function eventMetadata(string $eventType, ?int $subjectId = null): array
    {
        $query = DB::table('audit_events')->where('event_type', $eventType);
        if ($subjectId !== null) {
            $query->where('subject_id', $subjectId);
        }

        return json_decode(
            (string) $query->value('metadata'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}

final readonly class CapturingFailingCompleteTurnPhase implements TurnPhase
{
    public function __construct(
        private string $phaseKey,
        private CompleteTurnEngine $engine,
        private \Closure $capture,
    ) {}

    public function key(): string
    {
        return $this->phaseKey;
    }

    public function required(): bool
    {
        return true;
    }

    public function implemented(): bool
    {
        return true;
    }

    public function execute(TurnContext $context): TurnPhaseResult
    {
        $this->engine->execute($this->phaseKey, $context);
        ($this->capture)();

        throw new RuntimeException('injected failure after gameplay mutations');
    }
}

final readonly class Pr11FixedTurnSeedGenerator implements TurnSeedGenerator
{
    public function __construct(private string $seed) {}

    public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string
    {
        return $this->seed;
    }
}
