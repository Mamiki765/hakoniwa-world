<?php

namespace App\Application;

use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Economy\InventorySalePlanner;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Economy\SalePolicy;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Map\NationLandAreaCalculator;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Secretary\SecretaryProductionBonus;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnOrderService;
use App\Domain\Turn\TurnPhaseResult;
use App\Domain\Turn\TurnPipeline;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapChunk;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\TerrainDefinition;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final class CompleteTurnEngine
{
    /** @var list<string> */
    private const SETTLEMENT_FACILITY_KEYS = ['village', 'town', 'city', 'capital'];

    private ?int $definitionCatalogTurnRunId = null;

    /** @var Collection<int, ResourceDefinition>|null */
    private ?Collection $resourceCatalog = null;

    /** @var array<string, FacilityDefinition> */
    private array $facilityDefinitions = [];

    /** @var array<string, TerrainDefinition> */
    private array $terrainDefinitions = [];

    public function __construct(
        private readonly TurnOrderService $orders,
        private readonly DomesticCommandExecutor $commands,
        private readonly CapacityBoundedAssetService $boundedAssets,
        private readonly FoodOverflowResolver $foodOverflow,
        private readonly InventorySalePlanner $salePlanner,
        private readonly NationCapacityResolver $capacities,
        private readonly MapCellStateService $cells,
        private readonly NationLandAreaCalculator $landArea,
        private readonly TurnEventRecorder $events,
        private readonly DisasterTurnService $disasters,
        private readonly MonsterTurnService $monsters,
        private readonly MissileImpactResolver $missiles,
        private readonly AwardTurnFinalizer $awards,
        private readonly TerritoryInfluenceService $territoryInfluence,
        private readonly SecretaryTurnService $secretaries,
        private readonly SecretaryProductionBonus $secretaryProduction,
        private readonly SecretaryOldBowService $secretaryOldBow,
        private readonly NationLifecycleService $nationLifecycle,
        private readonly KarmaTurnService $karma,
    ) {}

    public function execute(string $phase, TurnContext $context): TurnPhaseResult
    {
        $metrics = match ($phase) {
            'prepare_turn' => $this->prepareTurn($context),
            'calculate_terrain_context' => $this->calculateTerrainContext($context),
            'resolve_territory_influence' => $this->territoryInfluence->execute($context),
            'nation_economy' => $this->nationEconomy($context),
            'resource_sales' => $this->sellResources($context),
            'development_commands' => $this->developmentCommands($context),
            'process_cells' => $this->processCells($context),
            'settle_deferred_effects' => $this->settleDeferredEffects($context),
            'global_disasters' => $this->disasters->executeGlobal($context),
            'aggregate_nations' => $this->aggregateNations($context),
            'enforce_capacities' => $this->enforceCapacities($context),
            'finalize_turn' => $this->finalizeTurn($context),
            default => throw new DomainException("Unknown canonical turn phase {$phase}."),
        };

        return new TurnPhaseResult($phase, $metrics);
    }

    /** @return array<string, int|bool> */
    private function prepareTurn(TurnContext $context): array
    {
        if (! is_array($context->ruleset->settings['turn_processing'] ?? null)) {
            throw new DomainException('The active ruleset does not implement the complete non-combat turn contract.');
        }
        $lifecycleMetrics = $this->nationLifecycle->prepare($context);
        $karmaMetrics = $this->karma->prepare($context);
        $nationIds = $this->orders->stableNationIds($context->world);
        $context->state->setStableNationIds($nationIds);
        foreach ($this->summaryRecords($context->state->lifecycleNationIds()) as $nationId => $record) {
            $context->state->setNationStartSummary($nationId, $record['summary']);
        }

        $secretarySnapshots = $this->secretaries->loadAttemptSnapshots(
            $context,
            $context->state->lifecycleNationIds(),
        );

        $metrics = [
            'nations' => count($nationIds),
            'ruleset_validated' => true,
            'secretary_snapshots' => $secretarySnapshots,
            ...$lifecycleMetrics,
            ...$karmaMetrics,
        ];
        if ($this->secretaries->itemEffectsEnabled($context)) {
            $metrics['secretary_item_effect_snapshots'] = $context->state->secretaryItemEffectSnapshotCount();
            $metrics['secretary_item_effect_items'] = $context->state->secretaryItemEffectItemCount();
        }

        return $metrics;
    }

    /** @return array<string, int> */
    private function developmentCommands(TurnContext $context): array
    {
        $commandMetrics = $this->commands->execute($context);
        $heartbeatMetrics = $this->nationLifecycle->heartbeat($context);

        foreach ($heartbeatMetrics as $key => $value) {
            $commandMetrics['dormancy_'.$key] = $value;
        }

        return $commandMetrics;
    }

    /** @return array<string, int> */
    private function calculateTerrainContext(TurnContext $context): array
    {
        $context->state->setDevelopmentNationIds(
            $this->orders->shuffledNationIds($context->world, $context->random),
        );
        $surfaceCellIds = $this->orders->shuffledSurfaceCellIds($context->world, $context->random);
        $context->state->setSurfaceCellIds($surfaceCellIds);

        $metrics = [
            'development_nations' => count($context->state->developmentNationIds()),
            'surface_cells' => count($surfaceCellIds),
        ];

        return $metrics;
    }

    /** @return array<string, int> */
    private function nationEconomy(TurnContext $context): array
    {
        $metrics = [
            'nations' => 0, 'wheat_produced' => 0, 'industrial_goods_produced' => 0,
            'minerals_produced' => 0, 'nutrition_required' => 0,
            'nutrition_shortage' => 0, 'famine_nations' => 0,
            'food_overflow_sold' => 0, 'food_overflow_revenue' => 0,
            'food_overflow_discarded' => 0,
        ];
        $workforceRules = $context->ruleset->settings['turn_processing']['workforce'];
        $foodRules = $context->ruleset->settings['turn_processing']['food'];
        $productionOverflowStage = $foodRules['production_overflow_resolution_stage'] ?? null;
        if ($productionOverflowStage !== null
            && $productionOverflowStage !== 'after_population_nutrition_consumption') {
            throw new DomainException('The food production overflow resolution stage is invalid.');
        }
        $resources = $this->resourceDefinitions($context);
        $wheat = $this->resourceDefinition($resources, 'wheat');

        foreach ($context->state->stableNationIds() as $nationId) {
            $nation = Nation::query()->whereKey($nationId)->lockForUpdate()->firstOrFail();
            if (! in_array($nation->state, ['active', 'recovery'], true)) {
                continue;
            }
            $aggregate = $this->nationEconomyAggregate($nation);
            $population = $aggregate['population'];
            $agriculturalWorkers = min($population, $aggregate['farm_capacity']);
            $wheatProduction = $agriculturalWorkers * $workforceRules['farm_output_per_worker'];
            $wheatProduction = $this->secretaryProduction(
                $context,
                $nation->id,
                SecretarySkillCatalog::AGRICULTURAL_POLICY,
                $wheatProduction,
            );
            $foodCredit = $productionOverflowStage === 'after_population_nutrition_consumption'
                ? $this->boundedAssets->creditFoodProductionForTurn(
                    $nation,
                    $wheat,
                    $wheatProduction,
                    $context->ruleset,
                )
                : $this->boundedAssets->creditFood($nation, $wheat, $wheatProduction, $context->ruleset);
            $productionMetadata = [
                'resource_key' => 'wheat', 'population' => $population,
                'farm_capacity_people' => $aggregate['farm_capacity'], 'workers' => $agriculturalWorkers,
                'requested_tons' => $wheatProduction, 'applied_tons' => $foodCredit->applied,
                'overflow_tons' => $productionOverflowStage === null ? $foodCredit->overflow : 0,
            ];
            if ($productionOverflowStage !== null) {
                $productionMetadata['pre_nutrition_over_capacity_tons'] = $foodCredit->overflow;
                $productionMetadata['overflow_resolution_stage'] = $productionOverflowStage;
            }
            $this->events->record($context, 'resource.food_produced', $nation, $productionMetadata);
            if ($productionOverflowStage === null && $foodCredit->overflow > 0) {
                $overflow = $this->foodOverflow->resolve($context, $nation, $wheat, $foodCredit);
                $metrics['food_overflow_sold'] += $overflow['sold_tons'];
                $metrics['food_overflow_revenue'] += $overflow['revenue'];
                $metrics['food_overflow_discarded'] += $overflow['discarded_tons'];
            }

            $remainingWorkers = max(0, $population - $agriculturalWorkers);
            $allocation = $this->allocateFactoryAndMineWorkers(
                $aggregate['industrial_facilities'],
                $remainingWorkers,
            );
            $industrial = $allocation['factory'] * $workforceRules['factory_output_per_worker'];
            $minerals = $allocation['mine'] * $workforceRules['mine_output_per_worker'];
            $industrial = $this->secretaryProduction(
                $context,
                $nation->id,
                SecretarySkillCatalog::SPECIALTY_DEVELOPMENT,
                $industrial,
            );
            $minerals = $this->secretaryProduction(
                $context,
                $nation->id,
                SecretarySkillCatalog::GOLD_VEIN_SURVEY,
                $minerals,
            );
            $this->creditInventory(
                $nation,
                $this->resourceDefinition($resources, 'industrial_goods'),
                $industrial,
            );
            $this->creditInventory($nation, $this->resourceDefinition($resources, 'minerals'), $minerals);
            $this->events->record($context, 'resource.industrial_produced', $nation, [
                'workers' => $allocation['factory'], 'produced_units' => $industrial,
            ]);
            $this->events->record($context, 'resource.mineral_produced', $nation, [
                'workers' => $allocation['mine'], 'produced_units' => $minerals,
            ]);

            $requiredNutrition = intdiv($population, $foodRules['population_per_nutrition']);
            $consumption = $this->consumeFood(
                $nation,
                $foodRules['consumption_priority'],
                $requiredNutrition,
                $resources,
            );
            $this->events->record($context, 'resource.food_consumed', $nation, $consumption);
            if ($productionOverflowStage === 'after_population_nutrition_consumption') {
                if ($foodCredit->requested > 0) {
                    $overflow = $this->foodOverflow->resolveAfterNutrition(
                        $context,
                        $nation,
                        $wheat,
                        $foodCredit,
                    );
                    $metrics['food_overflow_sold'] += $overflow['sold_tons'];
                    $metrics['food_overflow_revenue'] += $overflow['revenue'];
                    $metrics['food_overflow_discarded'] += $overflow['discarded_tons'];
                }
            }
            if ($consumption['shortage'] > 0) {
                $context->state->markFamine($nation->id);
                $metrics['famine_nations']++;
                $this->events->record($context, 'resource.food_shortage', $nation, [
                    'nation_name' => $nation->name,
                    'required_nutrition' => $requiredNutrition,
                    'shortage' => $consumption['shortage'], 'famine' => true,
                ]);
            }

            $metrics['nations']++;
            $metrics['wheat_produced'] += $foodCredit->applied;
            $metrics['industrial_goods_produced'] += $industrial;
            $metrics['minerals_produced'] += $minerals;
            $metrics['nutrition_required'] += $requiredNutrition;
            $metrics['nutrition_shortage'] += $consumption['shortage'];
        }

        return $metrics;
    }

    /** @return array<string, int> */
    private function processCells(TurnContext $context): array
    {
        $metrics = [
            'processed' => 0, 'forest_growth' => 0, 'settlements_appeared' => 0,
            'population_increased' => 0, 'population_decreased' => 0,
            'stage_transitions' => 0, 'riots' => 0, 'fires' => 0,
            'oil_income' => 0, 'oil_depleted' => 0,
            // Combat integrations call MonsterDamageService explicitly. Keep the
            // canonical phase schema stable even when this turn has no such hit.
            'damage_blocked' => 0, 'monsters_killed' => 0,
            'rewards_distributed' => 0, 'kill_stats_incremented' => 0,
            'missile_launches' => 0, 'missile_shots_fired' => 0,
            'missile_money_spent' => 0, 'missile_meaningful_impacts' => 0,
            'missile_ineffective_impacts' => 0,
            'missile_idle_counter_resets' => 0,
        ];
        $activeNations = Nation::query()->where('world_id', $context->world->id)
            ->whereIn('state', ['active', 'recovery'])
            ->pluck('id')->map(static fn ($id): int => (int) $id)->flip();
        $disasterMutableNationIds = Nation::query()->where('world_id', $context->world->id)
            ->whereIn('state', ['active', 'dormant', 'recovery'])
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $space = MapSpace::query()
            ->where('world_id', $context->world->id)
            ->where('key', 'surface')
            ->firstOrFail();
        $cells = MapCell::query()
            ->whereIn('id', $context->state->surfaceCellIds())
            ->with(['terrain', 'facility', 'ownerNation'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $cellsById = $cells->keyBy('id');
        $cellsByCoordinate = $cells->mapWithKeys(static fn (MapCell $cell): array => [
            $cell->x.':'.$cell->y => $cell,
        ])->all();
        $disasterCells = DisasterMutableCellIndex::fromCells(
            $cells,
            activeNationIds: $disasterMutableNationIds,
            terrainDefinitions: [
                'sea' => $this->terrainDefinition($context, 'sea'),
                'shallow' => $this->terrainDefinition($context, 'shallow'),
                'wasteland' => $this->terrainDefinition($context, 'wasteland'),
            ],
        );
        $monsterBatch = $this->monsters->load($context);
        $metrics['missile_boundary_monsters'] = $this->karma->snapshotMissileBoundary($context);
        $this->missiles->begin($cellsByCoordinate);
        $launchBaseKeys = $context->ruleset->settings['military']['launch_base_facility_keys'] ?? [];
        $separateNormalMonsterPass = ($context->ruleset->settings['turn_resolution']['normal_monster_stage'] ?? null)
            === SecretaryItemGameplayContract::REQUIRED_NORMAL_MONSTER_STAGE;

        foreach ($context->state->surfaceCellIds() as $cellId) {
            $cell = $cellsById->get($cellId);
            if (! $cell instanceof MapCell) {
                throw new DomainException("Surface cell order references missing cell {$cellId}.");
            }
            $metrics['processed']++;
            if (! $separateNormalMonsterPass
                && $this->monsters->processCell(
                    $context,
                    $space,
                    $cell,
                    $cellsByCoordinate,
                    $monsterBatch,
                    $disasterCells,
                )) {
                continue;
            }
            if (in_array($cell->facility?->key, $launchBaseKeys, true)) {
                $launch = $this->missiles->processBase($context, $space, $cell);
                $metrics['missile_shots_fired'] += $launch['shots_fired'];
                $metrics['missile_money_spent'] += $launch['money_spent'];
                $metrics['missile_meaningful_impacts'] += $launch['meaningful_impacts'];
                $metrics['missile_ineffective_impacts'] += $launch['ineffective_impacts'];
                foreach ($launch['changed_cell_ids'] as $changedCellId) {
                    $changed = $cellsById->get($changedCellId);
                    if ($changed instanceof MapCell) {
                        $changed->refresh()->load(['terrain', 'facility']);
                    }
                }
                $cell->refresh()->load(['terrain', 'facility']);
            }
            if ($cell->owner_nation_id === null || ! $activeNations->has($cell->owner_nation_id)) {
                continue;
            }
            $famine = $context->state->isFamine($cell->owner_nation_id);
            if ($cell->facility?->key === ($context->ruleset->settings['turn_processing']['oil_field']['facility_key'] ?? null)) {
                $oil = $this->processOilField($context, $cell);
                $metrics['oil_income'] += $oil['income'];
                $metrics['oil_depleted'] += $oil['depleted'];

                continue;
            }
            $facilityKey = $cell->facility?->key;
            if ($this->isSettlement($cell)) {
                if ($this->disasters->processFire($context, $cell, $disasterCells)) {
                    $metrics['fires']++;

                    continue;
                }
                if ($famine) {
                    $loss = $this->applyFamine($context, $cell);
                    $metrics['population_decreased'] += $loss['decrease'];
                    $metrics['stage_transitions'] += $loss['stage_transition'];
                } else {
                    $growth = $this->growPopulation($context, $cell);
                    $metrics['population_increased'] += $growth['increase'];
                    $metrics['stage_transitions'] += $growth['stage_transition'];
                }

                continue;
            }
            if ($facilityKey === 'factory') {
                if ($this->disasters->processFire($context, $cell, $disasterCells)) {
                    $metrics['fires']++;

                    continue;
                }
                if ($this->processRiot($context, $cell, $famine)) {
                    $metrics['riots']++;
                }

                continue;
            }
            if ($facilityKey === 'decoy') {
                if ($this->processRiot($context, $cell, $famine)) {
                    $metrics['riots']++;

                    continue;
                }
                if ($this->disasters->processFire($context, $cell, $disasterCells)) {
                    $metrics['fires']++;
                }

                continue;
            }
            if ($this->processRiot($context, $cell, $famine)) {
                $metrics['riots']++;

                continue;
            }
            if ($cell->terrain->key === 'forest') {
                if ($this->growForest($context, $cell)) {
                    $metrics['forest_growth']++;
                }

                continue;
            }
            if (! $famine && $this->appearSettlement($context, $space, $cell, $cellsByCoordinate)) {
                $metrics['settlements_appeared']++;
            }
        }

        $launches = $this->missiles->finalize($context);
        $metrics['missile_launches'] = $launches['launches'];
        $metrics['missile_idle_counter_resets'] = $launches['idle_counter_resets'];

        $secretaryItemMetrics = $this->secretaryOldBow->execute(
            $context,
            $space,
            $separateNormalMonsterPass,
        );
        if ($secretaryItemMetrics !== []) {
            $metrics = [...$metrics, ...$secretaryItemMetrics];
        }

        if ($separateNormalMonsterPass) {
            // Reuse the ordinary pass order without another shuffle or random
            // draw. Cell-based processing preserves later-destination repeat
            // movement while the shared batch observes missile/terrain removals.
            foreach ($context->state->surfaceCellIds() as $cellId) {
                $cell = $cellsById->get($cellId);
                if (! $cell instanceof MapCell) {
                    throw new DomainException("Surface cell order references missing cell {$cellId}.");
                }
                $this->monsters->processCell(
                    $context,
                    $space,
                    $cell,
                    $cellsByCoordinate,
                    $monsterBatch,
                    $disasterCells,
                );
            }
        }

        return [...$metrics, ...$monsterBatch->metrics()];
    }

    /** @return array<string, int> */
    private function settleDeferredEffects(TurnContext $context): array
    {
        $alliance = $this->karma->settleAllianceMoney($context);
        $sanctions = $this->missiles->resolveSanctions($context);

        return [
            'alliance_nations' => $alliance['nations'],
            'alliance_money_requested' => $alliance['requested'],
            'alliance_money_applied' => $alliance['applied'],
            'alliance_money_overflow' => $alliance['overflow'],
            ...$sanctions,
        ];
    }

    /** @return array<string, int> */
    private function aggregateNations(TurnContext $context): array
    {
        $changedChunks = $this->updateChangedMapChunkVersions($context);
        $population = 0;
        $ownedLandCells = 0;
        $nationIds = $context->state->stableNationIds();
        $populationByNation = $this->populationByNation($nationIds);
        $landByNation = $this->landArea->forNationIds($context->world, $nationIds);
        $aggregates = [];
        foreach ($nationIds as $nationId) {
            $aggregates[$nationId] = [
                'population' => $populationByNation[$nationId] ?? 0,
                'farm_capacity' => 0,
                'factory_capacity' => 0,
                'mine_capacity' => 0,
                'owned_land_cells' => $landByNation[$nationId] ?? 0,
            ];
        }
        $facilities = $nationIds === []
            ? new Collection
            : MapCell::query()->whereIn('owner_nation_id', $nationIds)
                ->whereNotNull('facility_definition_id')->with('facility')->orderBy('id')->get();
        foreach ($facilities as $cell) {
            $nationId = $cell->owner_nation_id;
            $key = $cell->facility?->key;
            if ($nationId === null || ! isset($aggregates[$nationId])
                || ! in_array($key, ['farm', 'factory', 'mine'], true)) {
                continue;
            }
            if ($cell->facility_scale === null || $cell->facility->scale_unit_people === null) {
                throw new DomainException("Facility {$key} has incomplete workforce capacity state.");
            }
            $aggregates[$nationId]["{$key}_capacity"] +=
                $cell->facility_scale * $cell->facility->scale_unit_people;
        }
        foreach ($aggregates as $nationId => $aggregate) {
            $context->state->setNationAggregate($nationId, $aggregate);
            $population += $aggregate['population'];
            $ownedLandCells += $aggregate['owned_land_cells'];
        }

        return [
            'nations' => count($context->state->nationAggregates()),
            'population' => $population,
            'owned_land_cells' => $ownedLandCells,
            'map_chunks_updated' => $changedChunks,
        ];
    }

    private function updateChangedMapChunkVersions(TurnContext $context): int
    {
        $ids = $context->state->changedMapChunkIds();
        if ($ids === []) {
            return 0;
        }
        $chunks = MapChunk::query()->whereIn('id', $ids)
            ->orderBy('map_space_id')->orderBy('chunk_y')->orderBy('chunk_x')
            ->lockForUpdate()->get();
        if ($chunks->count() !== count($ids)) {
            throw new DomainException('A changed MapCell references a missing MapChunk.');
        }
        foreach ($chunks as $chunk) {
            $chunk->increment('version');
        }

        return $chunks->count();
    }

    /** @return array<string, int> */
    private function sellResources(TurnContext $context): array
    {
        $metrics = ['sales' => 0, 'revenue' => 0];
        $settings = $context->ruleset->settings;
        $forbiddenSellAll = $settings['turn_processing']['sale_policy']['sell_all_forbidden_resource_keys'];
        $rates = $settings['inventory_sale_rates'];
        $resources = $this->resourceDefinitions($context);
        $tradableResources = $resources->where('tradable', true);
        $tradableResourceIds = $tradableResources->pluck('id')->all();
        $pendingEvents = [];

        foreach ($context->state->stableNationIds() as $nationId) {
            $nation = Nation::query()->whereKey($nationId)->lockForUpdate()->firstOrFail();
            if (! in_array($nation->state, ['active', 'recovery'], true)) {
                continue;
            }
            $capacity = $this->capacities->resolve($nation, $context->ruleset);
            $balances = NationResource::query()
                ->where('nation_id', $nation->id)
                ->whereIn('resource_definition_id', $tradableResourceIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('resource_definition_id');
            $policies = NationResourceSalePolicy::query()
                ->where('nation_id', $nation->id)
                ->whereIn('resource_definition_id', $tradableResourceIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('resource_definition_id');
            foreach ($tradableResources as $resource) {
                $balance = $this->lockedOrCreatedBalance($balances, $nation, $resource);
                $policyRecord = $policies->get($resource->id);
                $policy = $policyRecord->policy ?? $settings['default_sale_policy'];
                $keepAmount = $policyRecord?->keep_amount;
                if (! SalePolicy::isSupported($policy)) {
                    throw new DomainException("Stored sale policy for {$resource->key} is invalid.");
                }
                if ($policy === SalePolicy::SellAll->value && in_array($resource->key, $forbiddenSellAll, true)) {
                    throw new DomainException("Stored sell_all policy is forbidden for {$resource->key}.");
                }
                $before = (int) $balance->amount;
                $resourceCapacity = $capacity->resources[$resource->key] ?? null;
                $requested = match ($policy) {
                    SalePolicy::SellAll->value => $before,
                    SalePolicy::KeepAmount->value => max(0, $before - (int) $keepAmount),
                    SalePolicy::Stockpile->value => is_int($resourceCapacity)
                        ? max(0, $before - $resourceCapacity)
                        : 0,
                    default => 0,
                };
                $rate = $rates[$resource->key] ?? null;
                if (! is_array($rate)
                    || ! is_int($rate['inventory_units'] ?? null)
                    || $rate['inventory_units'] < 1
                    || ! is_int($rate['money_units'] ?? null)
                    || $rate['money_units'] < 1) {
                    throw new DomainException("Inventory sale rate is missing or invalid for {$resource->key}.");
                }
                $quote = $this->salePlanner->plan(
                    $requested,
                    (int) $nation->money,
                    $capacity->money,
                    $rate['inventory_units'],
                    $rate['money_units'],
                );
                $sold = $quote->inventorySold;
                $revenue = $quote->appliedMoney;
                if ($sold > 0) {
                    $balance->decrement('amount', $sold);
                    $nation->increment('money', $revenue);
                    $metrics['sales']++;
                    $metrics['revenue'] += $revenue;
                }
                $pendingEvents[] = [
                    'event_type' => 'resource.automatic_sale',
                    'subject' => $nation,
                    'metadata' => [
                        'resource_key' => $resource->key, 'policy' => $policy, 'keep_amount' => $keepAmount,
                        'before' => $before, 'requested' => $requested, 'sold' => $sold,
                        'revenue' => $revenue, 'after' => $before - $sold,
                        'sale_reason' => $policy === SalePolicy::Stockpile->value && is_int($resourceCapacity)
                            ? 'capacity_overflow'
                            : 'sale_policy',
                        'resource_capacity' => $resourceCapacity,
                        'money_capacity' => $capacity->money,
                    ],
                    'visibility' => null,
                    'severity' => null,
                    'message' => null,
                ];
            }
        }
        $this->events->recordMany($context, $pendingEvents);

        return $metrics;
    }

    /** @return array<string, int> */
    private function enforceCapacities(TurnContext $context): array
    {
        $metrics = ['overflow_reports' => 0];
        $settings = $context->ruleset->settings;
        $resourceOverflowEvent = $this->resourceOverflowEvent($settings);
        $resources = $this->resourceDefinitions($context);
        $resourcesByKey = $resources->keyBy('key');
        $resourceIds = $resources->pluck('id')->all();

        foreach ($context->state->stableNationIds() as $nationId) {
            $nation = Nation::query()->whereKey($nationId)->lockForUpdate()->firstOrFail();
            if (! in_array($nation->state, ['active', 'recovery'], true)) {
                continue;
            }
            $capacity = $this->capacities->resolve($nation, $context->ruleset);
            $balances = NationResource::query()
                ->where('nation_id', $nation->id)
                ->whereIn('resource_definition_id', $resourceIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('resource_definition_id');

            $resourceAmounts = [];
            foreach ($capacity->resources as $resourceKey => $resourceCapacity) {
                $resource = $resourcesByKey->get($resourceKey);
                if (! $resource instanceof ResourceDefinition) {
                    throw new DomainException("Configured resource capacity references missing catalog key {$resourceKey}.");
                }
                $balance = $this->lockedOrCreatedBalance($balances, $nation, $resource);
                $before = (int) $balance->amount;
                $after = min($before, $resourceCapacity);
                $overflow = $before - $after;
                if ($overflow > 0) {
                    $balance->update(['amount' => $after]);
                    $metrics['overflow_reports']++;
                    $this->events->record($context, $resourceOverflowEvent, $nation, [
                        'asset' => 'resource',
                        'resource_key' => $resourceKey,
                        'before' => $before,
                        'requested' => $before,
                        'applied' => $after,
                        'overflow' => $overflow,
                        'capacity' => $resourceCapacity,
                        'after' => $after,
                        'discarded' => true,
                        'source' => 'post_sale_inventory_capacity',
                    ]);
                }
                $resourceAmounts[$resourceKey] = $after;
            }

            $foodTotal = 0;
            foreach ($balances as $balance) {
                $definition = $resources->firstWhere('id', $balance->resource_definition_id);
                if ($definition?->category === 'food') {
                    $foodTotal += (int) $balance->amount;
                }
            }
            foreach ([
                ['asset' => 'money', 'overflow' => max(0, (int) $nation->money - $capacity->money), 'capacity' => $capacity->money],
                ['asset' => 'aggregate_food', 'overflow' => max(0, $foodTotal - $capacity->foodTons), 'capacity' => $capacity->foodTons],
            ] as $report) {
                if ($report['overflow'] > 0) {
                    $metrics['overflow_reports']++;
                    $this->events->record($context, 'capacity.overflow', $nation, [...$report, 'preserved' => true]);
                }
            }
            $this->events->record($context, 'capacity.applied', $nation, [
                'money' => (int) $nation->money, 'money_capacity' => $capacity->money,
                'food_tons' => $foodTotal, 'food_capacity_tons' => $capacity->foodTons,
                'resource_amounts' => $resourceAmounts,
                'resource_capacities' => $capacity->resources,
                'bounded_credits' => true,
            ]);
        }

        return $metrics;
    }

    /** @param array<string, mixed> $settings */
    private function resourceOverflowEvent(array $settings): string
    {
        $contract = $settings['resource_capacity_overflow'] ?? null;
        if (! is_array($contract)
            || ($contract['behavior'] ?? null) !== 'sell_stockpile_overflow_then_discard_unsold'
            || ($contract['applies_after_sale_policy'] ?? null) !== true
            || ($contract['converts_unsold_to_money'] ?? null) !== false
            || ($contract['event_type'] ?? null) !== 'capacity.overflow') {
            throw new DomainException('Published resource capacity overflow contract is invalid.');
        }

        return $contract['event_type'];
    }

    /** @return array<string, int|bool> */
    private function finalizeTurn(TurnContext $context): array
    {
        $secretaryMetrics = $this->secretaries->flushExperience($context);
        $awardMetrics = $this->awards->finalize($context);
        $lifecycleMetrics = $this->nationLifecycle->finalize($context);
        $karmaMetrics = $this->karma->finalize($context);
        foreach ($this->summaryRecords($context->state->lifecycleNationIds()) as $nationId => $record) {
            $nation = $record['nation'];
            $start = $context->state->nationStartSummary($nationId);
            $end = $record['summary'];
            $summary = [];
            foreach (['money', 'population', 'food'] as $key) {
                $summary[$key] = [
                    'start' => $start[$key],
                    'end' => $end[$key],
                    'delta' => $end[$key] - $start[$key],
                ];
            }
            $this->events->record($context, 'turn.summary', $nation, [
                'nation_id' => $nation->id,
                'nation_name' => $nation->name,
                'summary' => $summary,
            ], 'nation');
        }
        $this->events->record($context, 'turn.completed', $context->world, [
            'ruleset_key' => $context->ruleset->key,
            'phase_count' => count(TurnPipeline::CANONICAL_PHASE_KEYS),
        ], 'admin');
        $karmaResultMetrics = [];
        foreach ($karmaMetrics as $key => $value) {
            $karmaResultMetrics['karma_'.$key] = $value;
        }

        return [
            'completed' => true,
            'target_turn' => $context->targetTurn,
            'secretary_experience_awarded' => $secretaryMetrics['experience_awarded'],
            'secretary_skills_changed' => $secretaryMetrics['skills_changed'],
            'secretary_levels_gained' => $secretaryMetrics['levels_gained'],
            'secretary_monster_experience_awarded' => $secretaryMetrics['monster_experience_awarded'],
            'secretary_monster_experience_changed' => $secretaryMetrics['monster_experience_secretaries_changed'],
            ...$lifecycleMetrics,
            ...$karmaResultMetrics,
            ...$awardMetrics,
        ];
    }

    private function secretaryProduction(
        TurnContext $context,
        int $nationId,
        string $skillKey,
        int $baseProduction,
    ): int {
        if (! $context->state->hasSecretarySnapshot($nationId)) {
            return $baseProduction;
        }

        return $this->secretaryProduction->apply(
            $context->ruleset->settings,
            $skillKey,
            $context->state->secretarySkillLevel($nationId, $skillKey),
            $baseProduction,
        );
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, array{nation: Nation, summary: array{money: int, population: int, food: int}}>
     */
    private function summaryRecords(array $nationIds): array
    {
        if ($nationIds === []) {
            return [];
        }
        $nations = Nation::query()->whereIn('id', $nationIds)->orderBy('id')->get()->keyBy('id');
        if ($nations->count() !== count($nationIds)) {
            throw new DomainException('Stable Nation order references a missing Nation.');
        }
        $populationByNation = $this->populationByNation($nationIds);
        $foodRows = NationResource::query()
            ->whereIn('nation_id', $nationIds)
            ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
            ->selectRaw('nation_id, SUM(amount) AS aggregate')
            ->groupBy('nation_id')
            ->pluck('aggregate', 'nation_id');
        $foodByNation = [];
        foreach ($foodRows as $nationId => $amount) {
            $foodByNation[(int) $nationId] = (int) $amount;
        }

        $records = [];
        foreach ($nationIds as $nationId) {
            $nation = $nations->get($nationId);
            if (! $nation instanceof Nation) {
                throw new DomainException("Stable Nation order references missing Nation {$nationId}.");
            }
            $records[$nationId] = [
                'nation' => $nation,
                'summary' => [
                    'money' => (int) $nation->money,
                    'population' => $populationByNation[$nationId] ?? 0,
                    'food' => $foodByNation[$nationId] ?? 0,
                ],
            ];
        }

        return $records;
    }

    /** @param list<int> $nationIds
     * @return array<int, int>
     */
    private function populationByNation(array $nationIds): array
    {
        if ($nationIds === []) {
            return [];
        }
        $rows = MapCell::query()
            ->whereIn('owner_nation_id', $nationIds)
            ->selectRaw('owner_nation_id, SUM(population) AS aggregate')
            ->groupBy('owner_nation_id')
            ->pluck('aggregate', 'owner_nation_id');
        $populationByNation = [];
        foreach ($rows as $nationId => $population) {
            $populationByNation[(int) $nationId] = (int) $population;
        }

        return $populationByNation;
    }

    /** @return Collection<int, ResourceDefinition> */
    private function resourceDefinitions(TurnContext $context): Collection
    {
        $this->ensureDefinitionCatalogContext($context);
        $this->resourceCatalog ??= ResourceDefinition::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->resourceCatalog;
    }

    /** @param Collection<int, ResourceDefinition> $resources */
    private function resourceDefinition(Collection $resources, string $key): ResourceDefinition
    {
        $resource = $resources->firstWhere('key', $key);
        if (! $resource instanceof ResourceDefinition) {
            throw new DomainException("Resource catalog is missing key {$key}.");
        }

        return $resource;
    }

    private function facilityDefinition(TurnContext $context, string $key): FacilityDefinition
    {
        $this->ensureDefinitionCatalogContext($context);

        return $this->facilityDefinitions[$key] ??=
            FacilityDefinition::query()->where('key', $key)->firstOrFail();
    }

    private function terrainDefinition(TurnContext $context, string $key): TerrainDefinition
    {
        $this->ensureDefinitionCatalogContext($context);

        return $this->terrainDefinitions[$key] ??=
            TerrainDefinition::query()->where('key', $key)->firstOrFail();
    }

    private function ensureDefinitionCatalogContext(TurnContext $context): void
    {
        if ($this->definitionCatalogTurnRunId === $context->run->id) {
            return;
        }
        $this->definitionCatalogTurnRunId = $context->run->id;
        $this->resourceCatalog = null;
        $this->facilityDefinitions = [];
        $this->terrainDefinitions = [];
    }

    /**
     * @param  Collection<int, NationResource>  $balances
     */
    private function lockedOrCreatedBalance(
        Collection $balances,
        Nation $nation,
        ResourceDefinition $resource,
    ): NationResource {
        $balance = $balances->get($resource->id);
        if ($balance instanceof NationResource) {
            return $balance;
        }
        $balance = NationResource::query()->firstOrCreate([
            'nation_id' => $nation->id,
            'resource_definition_id' => $resource->id,
        ], ['amount' => 0]);
        if (! $balance->wasRecentlyCreated) {
            $balance = NationResource::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
        }
        $balances->put($resource->id, $balance);

        return $balance;
    }

    /**
     * @return array{
     *     population: int,
     *     farm_capacity: int,
     *     factory_capacity: int,
     *     mine_capacity: int,
     *     industrial_facilities: list<array{cell_id: int, key: string, capacity: int, workers: int, remainder: int}>
     * }
     */
    private function nationEconomyAggregate(Nation $nation): array
    {
        $population = (int) MapCell::query()->where('owner_nation_id', $nation->id)->sum('population');
        $capacities = ['farm' => 0, 'factory' => 0, 'mine' => 0];
        $industrialFacilities = [];
        $facilities = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotNull('facility_definition_id')->with('facility')->orderBy('id')->get();
        foreach ($facilities as $cell) {
            $key = $cell->facility?->key;
            if (! is_string($key) || ! array_key_exists($key, $capacities)) {
                continue;
            }
            if ($cell->facility_scale === null || $cell->facility->scale_unit_people === null) {
                throw new DomainException("Facility {$key} has incomplete workforce capacity state.");
            }
            $capacity = $cell->facility_scale * $cell->facility->scale_unit_people;
            $capacities[$key] += $capacity;
            if (in_array($key, ['factory', 'mine'], true)) {
                $industrialFacilities[] = [
                    'cell_id' => $cell->id,
                    'key' => $key,
                    'capacity' => $capacity,
                    'workers' => 0,
                    'remainder' => 0,
                ];
            }
        }

        return [
            'population' => $population, 'farm_capacity' => $capacities['farm'],
            'factory_capacity' => $capacities['factory'], 'mine_capacity' => $capacities['mine'],
            'industrial_facilities' => $industrialFacilities,
        ];
    }

    /**
     * @param  list<array{cell_id: int, key: string, capacity: int, workers: int, remainder: int}>  $facilities
     * @return array{factory: int, mine: int}
     */
    private function allocateFactoryAndMineWorkers(array $facilities, int $availableWorkers): array
    {
        $totalCapacity = array_sum(array_column($facilities, 'capacity'));
        $workers = min($availableWorkers, $totalCapacity);
        if ($workers === 0 || $totalCapacity === 0) {
            return ['factory' => 0, 'mine' => 0];
        }
        $allocated = 0;
        foreach ($facilities as &$facility) {
            $weighted = $workers * $facility['capacity'];
            $facility['workers'] = intdiv($weighted, $totalCapacity);
            $facility['remainder'] = $weighted % $totalCapacity;
            $allocated += $facility['workers'];
        }
        unset($facility);
        usort($facilities, static function (array $left, array $right): int {
            return $right['remainder'] <=> $left['remainder']
                ?: $left['key'] <=> $right['key']
                ?: $left['cell_id'] <=> $right['cell_id'];
        });
        for ($index = 0; $index < $workers - $allocated; $index++) {
            $facilities[$index]['workers']++;
        }
        $result = ['factory' => 0, 'mine' => 0];
        foreach ($facilities as $facility) {
            $result[$facility['key']] += $facility['workers'];
        }

        return $result;
    }

    private function creditInventory(Nation $nation, ResourceDefinition $resource, int $amount): void
    {
        if ($amount < 1) {
            return;
        }
        $balance = NationResource::query()->firstOrCreate([
            'nation_id' => $nation->id, 'resource_definition_id' => $resource->id,
        ], ['amount' => 0]);
        $balance->increment('amount', $amount);
    }

    /** @param list<string> $priority
     * @param  Collection<int, ResourceDefinition>  $catalog
     * @return array<string, mixed>
     */
    private function consumeFood(
        Nation $nation,
        array $priority,
        int $requiredNutrition,
        Collection $catalog,
    ): array {
        $remaining = $requiredNutrition;
        $totalSupplied = 0;
        $resources = [];
        foreach ($priority as $resourceKey) {
            $resource = $this->resourceDefinition($catalog, $resourceKey);
            $nutrition = $this->integerNutrition($resource);
            if ($resource->category !== 'food' || $nutrition < 1) {
                throw new DomainException("Food resource {$resourceKey} has invalid nutrition.");
            }
            $balance = NationResource::query()->firstOrCreate([
                'nation_id' => $nation->id, 'resource_definition_id' => $resource->id,
            ], ['amount' => 0]);
            $balance = NationResource::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
            $before = (int) $balance->amount;
            $neededUnits = $remaining === 0 ? 0 : intdiv($remaining + $nutrition - 1, $nutrition);
            $consumed = min($before, $neededUnits);
            $supplied = $consumed * $nutrition;
            $totalSupplied += $supplied;
            if ($consumed > 0) {
                $balance->decrement('amount', $consumed);
            }
            $remaining = max(0, $remaining - $supplied);
            $resources[] = [
                'resource_key' => $resourceKey, 'before' => $before, 'consumed_units' => $consumed,
                'nutrition_per_unit' => $nutrition, 'supplied_nutrition' => $supplied,
                'after' => $before - $consumed,
            ];
        }

        return [
            'required_nutrition' => $requiredNutrition, 'resources' => $resources,
            'supplied_nutrition' => $totalSupplied,
            'shortage' => $remaining, 'famine' => $remaining > 0,
        ];
    }

    private function integerNutrition(ResourceDefinition $resource): int
    {
        $raw = $resource->getRawOriginal('nutrition_per_unit');
        if (! is_string($raw) && ! is_int($raw)) {
            throw new DomainException("Food resource {$resource->key} must use integer nutrition.");
        }
        $authored = (string) $raw;
        if (preg_match('/\A([0-9]+)(?:\.0+)?\z/D', $authored, $matches) !== 1) {
            throw new DomainException("Food resource {$resource->key} must use integer nutrition.");
        }

        return (int) $matches[1];
    }

    private function processRiot(TurnContext $context, MapCell $cell, bool $famine): bool
    {
        if (! $famine || $cell->facility === null) {
            return false;
        }
        $rules = $context->ruleset->settings['turn_processing']['riot'];
        if (! in_array($cell->facility->key, $rules['facility_keys'], true)) {
            return false;
        }
        $draw = $context->random->stream(TurnRandomStreamFactory::FACILITY_RIOT)
            ->integer(0, $rules['probability']['denominator'] - 1);
        if ($draw >= $rules['probability']['numerator']) {
            return false;
        }
        $facilityKey = $cell->facility->key;
        $wasteland = $this->terrainDefinition($context, 'wasteland');
        $this->cells->setFacility($cell, null);
        $this->cells->transitionTerrain($cell, $wasteland);
        $cell->population = 0;
        $cell->version++;
        $this->saveChangedCell($context, $cell);
        $this->events->record($context, 'facility.riot', $cell, [
            'nation_id' => $cell->owner_nation_id, 'facility_key' => $facilityKey,
            'draw' => $draw, 'numerator' => $rules['probability']['numerator'],
            'denominator' => $rules['probability']['denominator'], 'result_terrain_key' => 'wasteland',
        ]);

        return true;
    }

    /** @return array{income: int, depleted: int} */
    private function processOilField(TurnContext $context, MapCell $cell): array
    {
        $rules = $context->ruleset->settings['turn_processing']['oil_field'];
        $nation = Nation::query()->whereKey($cell->owner_nation_id)->lockForUpdate()->firstOrFail();
        $resourceKey = $rules['output_resource_key'] ?? null;
        $productionUnits = $rules['production_units'] ?? null;
        if (! is_string($resourceKey) || $resourceKey === ''
            || ! is_int($productionUnits) || $productionUnits < 1) {
            throw new DomainException('Seabed oil field production rules are invalid.');
        }
        $resource = $this->resourceDefinition($this->resourceDefinitions($context), $resourceKey);
        $before = (int) NationResource::query()
            ->where('nation_id', $nation->id)
            ->where('resource_definition_id', $resource->id)
            ->lockForUpdate()
            ->value('amount');
        $this->creditInventory($nation, $resource, $productionUnits);
        $this->events->record($context, 'oil.income', $cell, [
            'nation_id' => $nation->id,
            'x' => $cell->x,
            'y' => $cell->y,
            'resource_key' => $resource->key,
            'requested_units' => $productionUnits,
            'applied_units' => $productionUnits,
            'before_units' => $before,
            'after_units' => $before + $productionUnits,
        ]);

        $probability = $rules['depletion_probability'];
        $draw = $context->random->stream(TurnRandomStreamFactory::OIL_DEPLETION)
            ->integer(0, $probability['denominator'] - 1);
        if ($draw >= $probability['numerator']) {
            return ['income' => $productionUnits, 'depleted' => 0];
        }

        $beforeFacility = $cell->facility?->key;
        $this->cells->setFacility($cell, null);
        $terrain = $this->terrainDefinition($context, $rules['depleted_terrain_key']);
        $this->cells->transitionTerrain($cell, $terrain);
        $cell->owner_nation_id = null;
        $cell->population = 0;
        $cell->version++;
        $this->saveChangedCell($context, $cell);
        $this->events->record($context, 'oil.depleted', $cell, [
            'nation_id' => $nation->id,
            'x' => $cell->x,
            'y' => $cell->y,
            'facility_key' => $beforeFacility,
            'result_terrain_key' => $terrain->key,
            'owner_nation_id_after' => null,
            'draw' => $draw,
            'numerator' => $probability['numerator'],
            'denominator' => $probability['denominator'],
            'production_applied_first' => true,
        ]);

        return ['income' => $productionUnits, 'depleted' => 1];
    }

    private function growForest(TurnContext $context, MapCell $cell): bool
    {
        $forest = $context->ruleset->settings['terrain_quantities']['forest'];
        if ($cell->terrain_quantity === null || $cell->terrain_quantity < $forest['minimum_quantity']
            || $cell->terrain_quantity > $forest['maximum_quantity']) {
            throw new DomainException("Forest cell {$cell->id} has an invalid tree quantity.");
        }
        $growthIncrement = $forest['growth_increment'];
        if ($cell->owner_nation_id !== null && $context->state->hasSecretarySnapshot($cell->owner_nation_id)) {
            $growthIncrement = $this->secretaryProduction->applyForestManagement(
                $context->ruleset->settings,
                $context->state->secretarySkillLevel(
                    $cell->owner_nation_id,
                    SecretarySkillCatalog::FOREST_MANAGEMENT,
                ),
                $growthIncrement,
            );
        }
        $after = min($forest['maximum_quantity'], $cell->terrain_quantity + $growthIncrement);
        if ($after === $cell->terrain_quantity) {
            return false;
        }
        $before = $cell->terrain_quantity;
        $cell->terrain_quantity = $after;
        $cell->version++;
        $this->saveChangedCell($context, $cell);
        $this->events->record($context, 'forest.grown', $cell, [
            'before' => $before, 'base_increment' => $forest['growth_increment'],
            'increment' => $after - $before, 'after' => $after,
            'maximum' => $forest['maximum_quantity'],
        ]);

        return true;
    }

    private function isSettlement(MapCell $cell): bool
    {
        return $cell->facility !== null
            && in_array($cell->facility->key, self::SETTLEMENT_FACILITY_KEYS, true)
            && ($cell->population > 0 || $cell->facility->key === 'capital');
    }

    /** @param array<string, MapCell> $cellsByCoordinate */
    private function appearSettlement(
        TurnContext $context,
        MapSpace $space,
        MapCell $cell,
        array $cellsByCoordinate,
    ): bool {
        $rules = $context->ruleset->settings['turn_processing']['settlement'];
        if ($cell->terrain->key !== $rules['eligible_terrain_key']
            || $cell->facility_definition_id !== null || $cell->population !== 0) {
            return false;
        }
        $probability = $rules['appearance_probability'];
        $draw = $context->random->stream(TurnRandomStreamFactory::SETTLEMENT_APPEARANCE)
            ->integer(0, $probability['denominator'] - 1);
        if ($draw >= $probability['numerator'] || ! $this->hasSettlementNeighbor(
            $space,
            $cell,
            $rules['adjacent_facility_key'],
            $cellsByCoordinate,
        )) {
            return false;
        }
        $villageKey = $rules['stages']['village']['facility_key'];
        $village = $this->facilityDefinition($context, $villageKey);
        $this->cells->setFacility($cell, $village);
        $cell->population = $rules['initial_population'];
        $cell->version++;
        $this->saveChangedCell($context, $cell);
        $this->events->record($context, 'settlement.appeared', $cell, [
            'nation_id' => $cell->owner_nation_id, 'population' => $cell->population,
            'facility_key' => $villageKey, 'draw' => $draw,
            'numerator' => $probability['numerator'], 'denominator' => $probability['denominator'],
        ]);

        return true;
    }

    /** @param array<string, MapCell> $cellsByCoordinate */
    private function hasSettlementNeighbor(
        MapSpace $space,
        MapCell $cell,
        string $farmKey,
        array $cellsByCoordinate,
    ): bool {
        $neighbors = (new GridCoordinate($cell->x, $cell->y))->neighborsWithin(
            $space->min_x, $space->max_x, $space->min_y, $space->max_y,
        );
        foreach ($neighbors as $neighbor) {
            $coordinateKey = $neighbor->x.':'.$neighbor->y;
            $neighborCell = $cellsByCoordinate[$coordinateKey] ?? null;
            if (! array_key_exists($coordinateKey, $cellsByCoordinate)) {
                $neighborCell = MapCell::query()->where('map_space_id', $cell->map_space_id)
                    ->where('x', $neighbor->x)->where('y', $neighbor->y)->with('facility')->first();
            }
            if ($neighborCell !== null
                && ($neighborCell->population > 0 || $neighborCell->facility?->key === $farmKey)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{decrease: int, stage_transition: int} */
    private function applyFamine(TurnContext $context, MapCell $cell): array
    {
        $rules = $context->ruleset->settings['turn_processing']['famine'];
        $loss = $context->random->stream(TurnRandomStreamFactory::FAMINE_POPULATION_LOSS)
            ->integer($rules['loss_minimum'], $rules['loss_maximum']);
        $before = $cell->population;
        $facilityKey = $cell->facility?->key;
        $minimumPopulation = $facilityKey === 'capital'
            ? $context->ruleset->settings['capital_minimum_population']
            : 0;
        $cell->population = max($minimumPopulation, $before - $loss);
        if ($cell->population === 0 && $facilityKey !== 'capital') {
            $plain = $this->terrainDefinition($context, 'plain');
            $this->cells->setFacility($cell, null);
            $this->cells->transitionTerrain($cell, $plain);
        }
        $cell->version++;
        $this->saveChangedCell($context, $cell);
        $actualLoss = max(0, $before - $cell->population);
        $minimumPopulationAdjustment = max(0, $cell->population - $before);
        $this->events->record($context, 'famine.applied', $cell, [
            'nation_id' => $cell->owner_nation_id, 'before' => $before,
            'drawn_loss' => $loss, 'actual_loss' => $actualLoss, 'after' => $cell->population,
            'facility_key_before' => $facilityKey,
            'minimum_population_applied' => $minimumPopulationAdjustment > 0,
            'minimum_population_adjustment' => $minimumPopulationAdjustment,
        ]);
        $this->events->record($context, 'population.decreased', $cell, [
            'nation_id' => $cell->owner_nation_id, 'reason' => 'famine', 'before' => $before,
            'drawn_loss' => $loss, 'actual_loss' => $actualLoss, 'after' => $cell->population,
            'facility_key_before' => $facilityKey, 'capital_identity_preserved' => $facilityKey === 'capital',
            'minimum_population_applied' => $minimumPopulationAdjustment > 0,
            'minimum_population_adjustment' => $minimumPopulationAdjustment,
        ]);
        $stageTransition = $cell->population > 0
            ? $this->syncSettlementStage($context, $cell)
            : 0;

        return ['decrease' => $actualLoss, 'stage_transition' => $stageTransition];
    }

    /** @return array{increase: int, stage_transition: int} */
    private function growPopulation(TurnContext $context, MapCell $cell): array
    {
        $rules = $context->ruleset->settings['turn_processing']['settlement'];
        $ordinaryMaximum = $rules['ordinary_maximum_population'] ?? null;
        if (! is_int($ordinaryMaximum)) {
            throw new DomainException('Settlement ordinary maximum population is missing.');
        }
        $before = $cell->population;
        $attraction = $cell->owner_nation_id !== null
            && $context->state->hasAttraction($cell->owner_nation_id);
        $ordinaryMaximum = $cell->facility?->key === 'capital'
            ? $context->ruleset->settings['capital_growth_maximum_population']
            : $ordinaryMaximum;
        $maximumPopulation = $attraction && $cell->facility?->key !== 'capital'
            ? $rules['attraction_maximum_population']
            : $ordinaryMaximum;
        if ($before < $maximumPopulation) {
            $growthRules = match (true) {
                ! $attraction => $rules['ordinary_growth'],
                $before < $ordinaryMaximum => $rules['attraction_growth'],
                default => $rules['post_ordinary_attraction_growth'],
            };
            $growth = $context->random->stream(TurnRandomStreamFactory::POPULATION_GROWTH)->integer(
                $growthRules['minimum'], $growthRules['maximum'],
            );
            $cell->population = min($maximumPopulation, $before + $growth);
        }
        $increase = $cell->population - $before;
        $transition = $this->syncSettlementStage($context, $cell);
        if ($increase > 0) {
            $cell->version++;
            $this->saveChangedCell($context, $cell);
            $metadata = [
                'nation_id' => $cell->owner_nation_id, 'before' => $before,
                'increase' => $increase, 'after' => $cell->population,
                'ordinary_maximum' => $ordinaryMaximum,
                'effective_maximum' => $maximumPopulation,
                'attraction' => $attraction,
            ];
            $this->events->record($context, 'population.increased', $cell, $metadata);
        }

        return ['increase' => $increase, 'stage_transition' => $transition];
    }

    private function syncSettlementStage(TurnContext $context, MapCell $cell): int
    {
        if ($cell->facility?->key === 'capital') {
            return 0;
        }
        $stages = $context->ruleset->settings['turn_processing']['settlement']['stages'];
        $targetKey = 'city';
        foreach (['village', 'town', 'city'] as $stageKey) {
            $stage = $stages[$stageKey];
            if ($cell->population >= $stage['minimum_population'] && $cell->population <= $stage['maximum_population']) {
                $targetKey = $stage['facility_key'];
                break;
            }
        }
        $before = $cell->facility?->key;
        if ($before === $targetKey) {
            return 0;
        }
        $facility = $this->facilityDefinition($context, $targetKey);
        $this->cells->setFacility($cell, $facility);
        $cell->version++;
        $this->saveChangedCell($context, $cell);
        $this->events->record($context, 'settlement.stage_transitioned', $cell, [
            'nation_id' => $cell->owner_nation_id, 'population' => $cell->population,
            'from_facility_key' => $before, 'to_facility_key' => $targetKey,
        ]);

        return 1;
    }

    private function saveChangedCell(TurnContext $context, MapCell $cell): void
    {
        $cell->save();
        $context->state->markMapChunkChanged($cell->map_chunk_id);
    }
}
