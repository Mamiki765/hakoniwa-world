<?php

namespace App\Application;

use App\Domain\Command\CapitalCorePolicy;
use App\Domain\Command\CommandFailureReason;
use App\Domain\Command\MissileTargetPolicy;
use App\Domain\Command\SettlementOverbuildPolicy;
use App\Domain\Command\TerritoryExpansionFacts;
use App\Domain\Command\TerritoryExpansionPolicy;
use App\Domain\Economy\CappedAddition;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MonsterOccupancy;
use App\Models\MonumentDefinition;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\TerrainDefinition;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final class DomesticCommandExecutor
{
    /** @var list<string> */
    private const QUANTITY_COMMANDS = ['build_farm', 'build_factory', 'build_mine'];

    /** @var list<string> */
    private const CAPITAL_DESTRUCTIVE_COMMANDS = ['land_clear', 'land_level', 'excavate'];

    public function __construct(
        private readonly MapCellStateService $cells,
        private readonly NationCapacityResolver $capacities,
        private readonly CappedAddition $addition,
        private readonly TurnEventRecorder $events,
        private readonly DisasterTurnService $disasters,
        private readonly MonsterSpawnService $monsterSpawn,
        private readonly NationIdleCounterFinalizer $idleCounters,
        private readonly LegacyCommandQueueOrder $legacyOrder,
        private readonly TerritoryExpansionPolicy $territoryExpansion,
        private readonly CapitalCorePolicy $capitalCores,
    ) {}

    /**
     * @return array{
     *     successes: int,
     *     failures: int,
     *     removed: int,
     *     quantity_decrements: int,
     *     automatic_finance: int,
     *     finance_commands: int,
     *     idle_counter_increments: int,
     *     idle_counter_resets: int
     * }
     */
    public function execute(TurnContext $context): array
    {
        $metrics = [
            'successes' => 0,
            'failures' => 0,
            'removed' => 0,
            'quantity_decrements' => 0,
            'automatic_finance' => 0,
            'finance_commands' => 0,
            'idle_counter_increments' => 0,
            'idle_counter_resets' => 0,
        ];
        foreach ($context->state->developmentNationIds() as $nationId) {
            $nation = Nation::query()->whereKey($nationId)->lockForUpdate()->firstOrFail();
            if ($nation->state !== 'active') {
                continue;
            }
            $queue = NationCommandQueue::query()
                ->where('nation_id', $nation->id)
                ->lockForUpdate()
                ->first();
            if ($queue !== null) {
                $this->recoverLegacyStagedQueue($queue);
            }
            $consumedTurn = false;
            $queueMutated = false;

            while (! $consumedTurn) {
                $item = $queue === null ? null : NationCommandQueueItem::query()
                    ->where('nation_command_queue_id', $queue->id)
                    ->where('status', 'queued')
                    ->orderBy('queue_position')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->with('definition')
                    ->first();
                if ($item === null) {
                    break;
                }

                $item->update(['execution_started_at' => now()]);
                $failure = $this->validationFailure($context, $nation, $queue, $item);
                if ($failure !== null) {
                    $this->failAndRemove($context, $nation, $queue, $item, $failure['reason'], $failure['observed']);
                    $metrics['failures']++;
                    $metrics['removed']++;
                    $queueMutated = true;

                    continue;
                }

                $cell = MapCell::query()
                    ->where('map_space_id', $queue->map_space_id)
                    ->where('x', $item->target_x)
                    ->where('y', $item->target_y)
                    ->lockForUpdate()
                    ->with(['terrain', 'facility'])
                    ->firstOrFail();
                $definition = $item->definition;
                $before = $this->cellSnapshot($cell);
                $executionCost = $this->executionCost($nation, $item, $definition, $cell);
                $this->deductCostAndResources($nation, $definition, $executionCost);
                $meaningfulActivity = $this->apply(
                    $context,
                    $nation,
                    $item,
                    $definition,
                    $cell,
                    $executionCost,
                );
                $after = $this->cellSnapshot($cell->fresh(['terrain', 'facility']));

                $consumedTurn = (bool) ($definition->metadata['consumes_turn'] ?? true);
                $remainingQuantity = $item->quantity;
                if (in_array($definition->key, self::QUANTITY_COMMANDS, true) && $item->quantity > 1) {
                    $remainingQuantity = $item->quantity - 1;
                    $item->update([
                        'quantity' => $remainingQuantity,
                        'execution_completed_at' => now(),
                        'failure_code' => null,
                        'failure_metadata' => [],
                    ]);
                    $metrics['quantity_decrements']++;
                    $this->events->record($context, 'command.quantity_decremented', $item, [
                        'nation_id' => $nation->id,
                        'command_key' => $definition->key,
                        'before' => $remainingQuantity + 1,
                        'after' => $remainingQuantity,
                        'retained_at_head' => true,
                    ]);
                } else {
                    $item->update([
                        'status' => 'completed',
                        'queue_position' => null,
                        'execution_completed_at' => now(),
                        'failure_code' => null,
                        'failure_metadata' => [],
                    ]);
                    $this->compact($queue);
                    $metrics['removed']++;
                    $this->events->record($context, 'command.queue_removed', $item, [
                        'nation_id' => $nation->id,
                        'command_key' => $definition->key,
                        'reason' => 'completed',
                    ]);
                }
                $queueMutated = true;
                $metrics['successes']++;
                if ($definition->key === 'finance') {
                    $context->state->recordFinanceSucceeded($nation->id);
                    $metrics['finance_commands']++;
                } elseif ($meaningfulActivity) {
                    $context->state->recordImmediateNormalCommandSucceeded($nation->id);
                }
                $this->events->record($context, 'command.success', $item, [
                    'nation_id' => $nation->id,
                    'command_key' => $definition->key,
                    'cost_money' => $executionCost,
                    'consumes_turn' => $consumedTurn,
                    'meaningful_activity' => $meaningfulActivity,
                    'remaining_quantity' => $remainingQuantity,
                    'before' => $before,
                    'after' => $after,
                ]);
            }

            if (! $consumedTurn) {
                $this->automaticFinance($context, $nation);
                $context->state->recordFinanceSucceeded($nation->id);
                $metrics['automatic_finance']++;
            }
            if (! $context->state->nationActivity($nation->id)['missile_intent_pending']) {
                $idleCounterChange = $this->idleCounters->finalize($context, $nation);
                $metrics['idle_counter_increments'] += $idleCounterChange === 'incremented' ? 1 : 0;
                $metrics['idle_counter_resets'] += $idleCounterChange === 'reset' ? 1 : 0;
            }
            if ($queue !== null && $queueMutated) {
                $queue->increment('version');
            }
        }

        return $metrics;
    }

    /**
     * @return array{
     *     reason: CommandFailureReason,
     *     observed: array{terrain: string|null, facility: string|null, owner_nation_id: int|null, owner_nation_name: string|null, monster_id: int|null}
     * }|null
     */
    private function validationFailure(
        TurnContext $context,
        Nation $nation,
        NationCommandQueue $queue,
        NationCommandQueueItem $item,
    ): ?array {
        $definition = $item->definition;
        if ($definition->ruleset_version_id !== $nation->world()->value('ruleset_version_id')) {
            return [
                'reason' => CommandFailureReason::RulesetMismatch,
                'observed' => $this->emptyObservedState(),
            ];
        }
        if ($definition->key === 'finance') {
            return null;
        }
        if ($definition->target_type === 'nation') {
            return $this->nationCommandValidationFailure($context, $nation, $item, $definition);
        }
        $cell = MapCell::query()
            ->where('map_space_id', $queue->map_space_id)
            ->where('x', $item->target_x)
            ->where('y', $item->target_y)
            ->lockForUpdate()
            ->with(['terrain', 'facility', 'ownerNation'])
            ->first();
        if ($cell === null) {
            return [
                'reason' => CommandFailureReason::NoTarget,
                'observed' => $this->emptyObservedState(),
            ];
        }
        $occupancy = MonsterOccupancy::query()
            ->where('map_cell_id', $cell->id)
            ->lockForUpdate()
            ->first(['id', 'monster_instance_id']);
        $observed = $this->observedState($cell, $occupancy?->monster_instance_id);
        if (in_array($definition->key, MissileImpactResolver::MISSILE_KEYS, true)) {
            return $this->missileValidationFailure($context, $nation, $definition, $cell, $observed);
        }
        if ($definition->key === 'territory_expand') {
            $reason = $this->territoryExpansionFailure($context, $nation, $definition, $cell, $occupancy !== null);
            if ($reason !== null) {
                return ['reason' => $reason, 'observed' => $observed];
            }
        } elseif ($occupancy !== null) {
            return ['reason' => CommandFailureReason::OccupiedByMonster, 'observed' => $observed];
        } elseif ($definition->key === 'build_seabed_base') {
            if ($cell->owner_nation_id !== null && $cell->owner_nation_id !== $nation->id) {
                return ['reason' => CommandFailureReason::ForeignOwned, 'observed' => $observed];
            }
            if ($cell->terrain->key !== 'sea') {
                return ['reason' => CommandFailureReason::InvalidTerrain, 'observed' => $observed];
            }
            if (! $this->hasOwnedCellWithin($nation, $cell, 3, true)) {
                return ['reason' => CommandFailureReason::MissingAdjacentTerritory, 'observed' => $observed];
            }
        }
        if ($cell->facility?->key === 'capital'
            && in_array($definition->key, self::CAPITAL_DESTRUCTIVE_COMMANDS, true)) {
            return ['reason' => CommandFailureReason::CapitalProtected, 'observed' => $observed];
        }
        if (SettlementOverbuildPolicy::protectsCapital($definition->key, $cell->facility?->key)) {
            return ['reason' => CommandFailureReason::CapitalProtected, 'observed' => $observed];
        }
        if ($definition->key === 'reclaim') {
            if ($cell->owner_nation_id !== null && $cell->owner_nation_id !== $nation->id) {
                return ['reason' => CommandFailureReason::ForeignOwned, 'observed' => $observed];
            }
            if (! in_array($cell->terrain->key, $definition->target_terrain_keys, true)) {
                return ['reason' => CommandFailureReason::InvalidTerrain, 'observed' => $observed];
            }
            if ($this->hasForeignAdjacentCell($nation, $cell)) {
                return ['reason' => CommandFailureReason::ForeignAdjacentWater, 'observed' => $observed];
            }
            if (! $this->hasOwnedCellWithin($nation, $cell, 1, false)) {
                return ['reason' => CommandFailureReason::NoAdjacentOwnedLand, 'observed' => $observed];
            }
        } elseif (! in_array($definition->key, ['territory_expand', 'build_seabed_base'], true)) {
            if ($cell->owner_nation_id !== $nation->id
                && ! ($definition->key === 'excavate' && in_array($cell->terrain->key, ['sea', 'shallow'], true))) {
                return [
                    'reason' => $cell->owner_nation_id === null
                        ? CommandFailureReason::NotOwned
                        : CommandFailureReason::ForeignOwned,
                    'observed' => $observed,
                ];
            }
            if (! in_array($cell->terrain->key, $definition->target_terrain_keys, true)) {
                return ['reason' => CommandFailureReason::InvalidTerrain, 'observed' => $observed];
            }
        }
        if ($definition->requires_empty_facility && $cell->facility_definition_id !== null) {
            $matchingQuantityFacility = $this->isMatchingQuantityFacility($definition, $cell);
            if (! $matchingQuantityFacility
                && ! SettlementOverbuildPolicy::allows($definition->key, $cell->facility?->key)) {
                return ['reason' => CommandFailureReason::FacilityExists, 'observed' => $observed];
            }
            if ($matchingQuantityFacility
                && ($cell->facility_scale === null || $cell->facility?->scale_increment === null
                || $cell->facility->maximum_scale === null)) {
                return ['reason' => CommandFailureReason::InvalidFacilityScale, 'observed' => $observed];
            }
        }
        if ($definition->target_facility_keys !== []
            && ! in_array($cell->facility?->key, $definition->target_facility_keys, true)) {
            return ['reason' => CommandFailureReason::InvalidFacility, 'observed' => $observed];
        }
        if ($definition->key === 'build_monument'
            && ! MonumentDefinition::query()->whereKey($item->quantity)->exists()) {
            return ['reason' => CommandFailureReason::InvalidParameter, 'observed' => $observed];
        }
        if ($definition->key === 'excavate' && in_array($cell->terrain->key, ['sea', 'shallow'], true)) {
            if ($cell->owner_nation_id !== null && $cell->owner_nation_id !== $nation->id) {
                return ['reason' => CommandFailureReason::ForeignOwned, 'observed' => $observed];
            }
            if (! $this->hasOwnedCellWithin($nation, $cell, 3, true)) {
                return ['reason' => CommandFailureReason::MissingAdjacentTerritory, 'observed' => $observed];
            }
            if ($cell->terrain->key === 'sea') {
                $this->isSeabedOilSearch($definition, $cell);
            }
            if ($cell->terrain->key === 'sea' && $cell->facility_definition_id !== null) {
                return ['reason' => CommandFailureReason::FacilityExists, 'observed' => $observed];
            }
        }
        if ((int) $nation->money < $definition->cost_money) {
            return ['reason' => CommandFailureReason::InsufficientFunds, 'observed' => $observed];
        }
        foreach ($definition->required_resources as $resourceKey => $required) {
            $amount = NationResource::query()
                ->where('nation_id', $nation->id)
                ->whereHas('definition', fn ($query) => $query->where('key', $resourceKey))
                ->value('amount');
            if ((int) $amount < $required) {
                return ['reason' => CommandFailureReason::InsufficientResource, 'observed' => $observed];
            }
        }

        return null;
    }

    /**
     * @return array{
     *     reason: CommandFailureReason,
     *     observed: array{terrain: null, facility: null, owner_nation_id: null, owner_nation_name: null, monster_id: null}
     * }|null
     */
    private function nationCommandValidationFailure(
        TurnContext $context,
        Nation $nation,
        NationCommandQueueItem $item,
        CommandDefinition $definition,
    ): ?array {
        $observed = $this->emptyObservedState();
        if ((int) $nation->money < $definition->cost_money) {
            return ['reason' => CommandFailureReason::InsufficientFunds, 'observed' => $observed];
        }
        if ($definition->key === 'attraction') {
            return null;
        }
        if (! in_array($definition->key, ['money_aid', 'food_aid', 'monster_dispatch'], true)) {
            return ['reason' => CommandFailureReason::InvalidParameter, 'observed' => $observed];
        }
        $targetNationId = $item->parameters['target_nation_id'] ?? null;
        if (! is_int($targetNationId)) {
            return ['reason' => CommandFailureReason::InvalidParameter, 'observed' => $observed];
        }
        if ($targetNationId === $nation->id) {
            return ['reason' => CommandFailureReason::SameNationTarget, 'observed' => $observed];
        }
        $target = Nation::query()->whereKey($targetNationId)->lockForUpdate()->first();
        if ($target === null || $target->world_id !== $context->world->id || $target->state !== 'active') {
            return ['reason' => CommandFailureReason::InvalidTargetNation, 'observed' => $observed];
        }
        if ($definition->key === 'money_aid') {
            $requested = $this->moneyAidAmount($item, $definition);
            if ((int) $nation->money < $requested) {
                return ['reason' => CommandFailureReason::InsufficientFunds, 'observed' => $observed];
            }
        }
        if ($definition->key === 'food_aid') {
            $requested = $this->foodAidAmount($item, $definition);
            $available = (int) NationResource::query()
                ->where('nation_id', $nation->id)
                ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
                ->lockForUpdate()
                ->get()
                ->sum('amount');
            if ($available < $requested) {
                return ['reason' => CommandFailureReason::InsufficientResource, 'observed' => $observed];
            }
        }
        if ($definition->key === 'monster_dispatch' && ! $this->monsterSpawn->hasDispatchCandidate($context, $target)) {
            return ['reason' => CommandFailureReason::NoTarget, 'observed' => $observed];
        }

        return null;
    }

    /**
     * @param  array{terrain: string|null, facility: string|null, owner_nation_id: int|null, owner_nation_name: string|null, monster_id: int|null}  $observed
     * @return array{reason: CommandFailureReason, observed: array<string, int|string|null>}|null
     */
    private function missileValidationFailure(
        TurnContext $context,
        Nation $nation,
        CommandDefinition $definition,
        MapCell $target,
        array $observed,
    ): ?array {
        $targetPolicy = MissileTargetPolicy::explicitTargetState($context->ruleset->settings);
        if ($targetPolicy === MissileTargetPolicy::ACTIVE_NATION) {
            $targetNation = $target->owner_nation_id === null
                ? null
                : Nation::query()->whereKey($target->owner_nation_id)->lockForUpdate()->first();
            if ($targetNation === null || $targetNation->world_id !== $context->world->id
                || $targetNation->state !== 'active') {
                return ['reason' => CommandFailureReason::InvalidTargetNation, 'observed' => $observed];
            }
        }
        $baseKeys = $context->ruleset->settings['military']['launch_base_facility_keys'] ?? null;
        if (! is_array($baseKeys) || $baseKeys === []) {
            throw new DomainException('The active ruleset has invalid missile launch-base settings.');
        }
        $hasBase = MapCell::query()->where('owner_nation_id', $nation->id)
            ->where('facility_operational_state', 'operational')
            ->whereHas('facility', fn ($query) => $query->whereIn('key', $baseKeys))
            ->exists();
        if (! $hasBase) {
            return ['reason' => CommandFailureReason::NoLaunchBase, 'observed' => $observed];
        }
        if ((int) $nation->money < $definition->cost_money) {
            return ['reason' => CommandFailureReason::InsufficientFunds, 'observed' => $observed];
        }

        return null;
    }

    private function isMatchingQuantityFacility(CommandDefinition $definition, MapCell $cell): bool
    {
        return in_array($definition->key, self::QUANTITY_COMMANDS, true)
            && $definition->result_facility_key !== null
            && $cell->facility?->key === $definition->result_facility_key;
    }

    private function executionCost(
        Nation $nation,
        NationCommandQueueItem $item,
        CommandDefinition $definition,
        MapCell $cell,
    ): int {
        if (in_array($definition->key, MissileImpactResolver::MISSILE_KEYS, true)) {
            return 0;
        }
        if (! $this->isSeabedOilSearch($definition, $cell)) {
            return $definition->cost_money;
        }

        if ($definition->cost_money < 1) {
            throw new DomainException('Seabed oil search requires a positive base cost.');
        }

        $availableUnits = intdiv((int) $nation->money, $definition->cost_money);
        $investedUnits = min($item->quantity, $availableUnits);

        return $investedUnits * $definition->cost_money;
    }

    private function deductCostAndResources(
        Nation $nation,
        CommandDefinition $definition,
        int $executionCost,
    ): void {
        if ((int) $nation->money < $executionCost) {
            throw new DomainException('Command money validation changed while the World transaction was locked.');
        }
        if ($executionCost > 0) {
            $nation->decrement('money', $executionCost);
            $nation->refresh();
        }
        foreach ($definition->required_resources as $resourceKey => $required) {
            $balance = NationResource::query()
                ->where('nation_id', $nation->id)
                ->whereHas('definition', fn ($query) => $query->where('key', $resourceKey))
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $balance->amount < $required) {
                throw new DomainException('Command resource validation changed while the World transaction was locked.');
            }
            $balance->decrement('amount', $required);
        }
    }

    private function apply(
        TurnContext $context,
        Nation $nation,
        NationCommandQueueItem $item,
        CommandDefinition $definition,
        MapCell $cell,
        int $executionCost,
    ): bool {
        if (in_array($definition->key, MissileImpactResolver::MISSILE_KEYS, true)) {
            $context->state->registerLaunchIntent(
                $nation->id,
                $definition->key,
                $item->target_x,
                $item->target_y,
                $item->quantity,
                $item->id,
            );
            $this->events->record($context, 'missile.intent_registered', $item, [
                'nation_id' => $nation->id,
                'command_key' => $definition->key,
                'queue_item_id' => $item->id,
                'target_x' => $item->target_x,
                'target_y' => $item->target_y,
                'requested_shots' => $item->quantity,
            ], 'admin');

            return false;
        }
        if ($definition->key === 'finance') {
            $this->finance($context, $nation, 'command.finance');

            return false;
        }
        if ($definition->target_type === 'nation') {
            return $this->applyNationCommand($context, $nation, $item, $definition);
        }
        if ($definition->key === 'logging') {
            $this->applyLogging($context, $nation, $definition, $cell);

            return true;
        }
        if ($definition->key === 'territory_expand') {
            $this->applyTerritoryExpand($context, $nation, $cell);

            return true;
        }
        if ($definition->key === 'relocate_capital') {
            $this->applyCapitalRelocation($context, $nation, $cell);

            return true;
        }
        if ($definition->key === 'reclaim') {
            $this->applyReclaim($context, $nation, $definition, $cell);
            $this->recordPublicCommandCompanion($context, $nation, $definition, $cell);

            return true;
        }
        if ($this->isSeabedOilSearch($definition, $cell)) {
            $this->applySeabedOilSearch($context, $nation, $item, $definition, $cell, $executionCost);
            $this->recordPublicCommandCompanion($context, $nation, $definition, $cell);

            return true;
        }

        $terrainKey = match ($definition->key) {
            'land_clear', 'land_level' => 'plain',
            'excavate' => match ($cell->terrain->key) {
                'shallow' => 'sea',
                'mountain' => 'wasteland',
                default => 'shallow',
            },
            'build_farm', 'build_factory', 'build_mine' => null,
            default => $definition->result_facility_key !== null
                ? null
                : ($definition->result_terrain_key
                    ?? throw new DomainException("Unsupported domestic command {$definition->key}.")),
        };

        if ($terrainKey !== null) {
            $oldTerrain = $cell->terrain->key;
            $oldFacility = $cell->facility?->key;
            $this->cells->setFacility($cell, null);
            $terrain = TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail();
            $this->cells->transitionTerrain($cell, $terrain);
            $cell->population = 0;
            $cell->version++;
            $cell->save();
            $context->state->markMapChunkChanged($cell->map_chunk_id);
            $terrainEventVisibility = $definition->key === 'plant_forest' ? 'private' : 'nation';
            $this->events->record($context, 'terrain.changed', $cell, [
                'nation_id' => $nation->id,
                'command_key' => $definition->key,
                'x' => $cell->x,
                'y' => $cell->y,
                'from_terrain_key' => $oldTerrain,
                'to_terrain_key' => $terrainKey,
                'removed_facility_key' => $oldFacility,
            ], $terrainEventVisibility);
            $this->recordPublicCommandCompanion($context, $nation, $definition, $cell);
            if ($definition->key === 'plant_forest') {
                $metadata = [
                    'nation_id' => $nation->id, 'nation_name' => $nation->name,
                    'x' => $cell->x, 'y' => $cell->y,
                ];
                $this->events->record($context, 'command.forest_planted_public', $nation, [
                    'nation_id' => $nation->id, 'nation_name' => $nation->name,
                ], 'public');
                $this->events->record($context, 'command.forest_planted_private', $cell, $metadata, 'private');
            }
            if ($definition->key === 'land_clear') {
                $this->buriedTreasure($context, $nation, $item);
            } elseif ($definition->key === 'land_level') {
                $this->disasters->landLevelEarthquake($context, $item, $cell->x, $cell->y);
            }

            return true;
        }

        $facilityKey = $definition->result_facility_key;
        if ($facilityKey === null) {
            throw new DomainException("Command {$definition->key} has no facility result.");
        }
        $facility = FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail();
        $expanded = $this->isMatchingQuantityFacility($definition, $cell);
        $beforeScale = $cell->facility_scale;
        $scale = null;
        if ($expanded) {
            $scale = min(
                (int) $facility->maximum_scale,
                (int) $cell->facility_scale + (int) $facility->scale_increment,
            );
        }
        $initialExperience = $context->ruleset->settings['facility_definitions'][$facilityKey]['initial_experience'] ?? null;
        $this->cells->setFacility(
            $cell,
            $facility,
            $scale,
            is_int($initialExperience) ? $initialExperience : null,
        );
        $monument = null;
        if ($definition->key === 'build_seabed_base') {
            $cell->owner_nation_id = $nation->id;
        }
        if ($definition->key === 'build_monument') {
            $monument = MonumentDefinition::query()->findOrFail($item->quantity);
            $cell->monument_definition_id = $monument->id;
        }
        $cell->population = 0;
        $cell->version++;
        $cell->save();
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $constructionVisibility = in_array(
            $definition->key,
            ['build_missile_base', 'build_seabed_base', 'build_decoy'],
            true,
        ) ? 'private' : 'nation';
        $this->events->record($context, $expanded ? 'facility.expanded' : 'facility.constructed', $cell, [
            'nation_id' => $nation->id,
            'command_key' => $definition->key,
            'facility_key' => $facilityKey,
            'before_scale' => $beforeScale,
            'facility_scale' => $cell->facility_scale,
            'scale_increment' => $expanded ? $facility->scale_increment : null,
            'x' => $cell->x,
            'y' => $cell->y,
            'monument_definition_key' => $monument?->key,
        ], $constructionVisibility);
        $this->recordConstructionProjection($context, $nation, $definition, $cell, $expanded, $beforeScale);

        return true;
    }

    private function recordConstructionProjection(
        TurnContext $context,
        Nation $nation,
        CommandDefinition $definition,
        MapCell $cell,
        bool $expanded,
        ?int $beforeScale,
    ): void {
        $metadata = [
            'nation_id' => $nation->id, 'nation_name' => $nation->name,
            'command_key' => $definition->key,
            'facility_key' => $definition->result_facility_key,
            'expanded' => $expanded,
            'before_scale' => $beforeScale,
            'facility_scale' => $cell->facility_scale,
            'x' => $cell->x, 'y' => $cell->y,
        ];
        if ($definition->key === 'build_missile_base') {
            if ($expanded) {
                return;
            }
            $this->events->record($context, 'command.forest_planted_public', $nation, [
                'nation_id' => $nation->id, 'nation_name' => $nation->name,
            ], 'public');
            $this->events->record($context, 'command.missile_base_built_private', $cell, $metadata, 'private');

            return;
        }
        if ($definition->key === 'build_seabed_base') {
            if ($expanded) {
                return;
            }
            $this->events->record($context, 'command.seabed_base_built_public', $nation, [
                'nation_id' => $nation->id, 'nation_name' => $nation->name,
            ], 'public');
            $this->events->record($context, 'command.seabed_base_built_private', $cell, $metadata, 'private');

            return;
        }
        if ($definition->key === 'build_decoy') {
            if ($expanded) {
                return;
            }
            $this->events->record($context, 'command.facility_built_public', $cell, [
                ...$metadata,
                'command_key' => 'build_defense_facility',
                'facility_key' => 'defense',
            ], 'public');
            $this->events->record($context, 'command.decoy_built_private', $cell, $metadata, 'private');

            return;
        }
        if (in_array($definition->key, [
            'build_farm',
            'build_factory',
            'build_mine',
            'build_defense_facility',
            'build_monument',
        ], true)) {
            $this->events->record($context, 'command.facility_built_public', $cell, $metadata, 'public');
        }
    }

    private function recordPublicCommandCompanion(
        TurnContext $context,
        Nation $nation,
        CommandDefinition $definition,
        MapCell $cell,
    ): void {
        if (! in_array($definition->key, ['land_clear', 'land_level', 'reclaim', 'excavate'], true)) {
            return;
        }

        $this->events->record($context, 'command.terrain_changed_public', $cell, [
            'nation_id' => $nation->id,
            'nation_name' => $nation->name,
            'command_key' => $definition->key,
            'x' => $cell->x,
            'y' => $cell->y,
            'result_terrain_key' => $cell->fresh()->terrain()->value('key'),
        ], 'public');
    }

    private function applyLogging(
        TurnContext $context,
        Nation $nation,
        CommandDefinition $definition,
        MapCell $cell,
    ): void {
        $treeUnits = (int) ($cell->terrain_quantity ?? 0);
        $moneyPerUnit = $definition->metadata['money_per_legacy_tree_unit'] ?? null;
        if (! is_int($moneyPerUnit) || $moneyPerUnit < 0) {
            throw new DomainException('Logging income settings are invalid.');
        }
        $requested = intdiv($treeUnits, 100) * $moneyPerUnit;
        $capacity = $this->capacities->resolve($nation, $context->ruleset)->money;
        $income = $this->addition->calculate((int) $nation->money, $requested, $capacity);
        if ($income->applied > 0) {
            $nation->update(['money' => $income->after]);
        }
        $this->cells->setFacility($cell, null);
        $this->cells->transitionTerrain($cell, TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail());
        $cell->population = 0;
        $cell->version++;
        $cell->save();
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $metadata = [
            'nation_id' => $nation->id, 'nation_name' => $nation->name,
            'x' => $cell->x, 'y' => $cell->y,
            'tree_units' => $treeUnits, 'requested_money' => $requested,
            'applied_money' => $income->applied, 'overflow_money' => $income->overflow,
        ];
        $this->events->record($context, 'command.logging_public', $nation, [
            'nation_id' => $nation->id, 'nation_name' => $nation->name,
        ], 'public');
        $this->events->record($context, 'command.logging_private', $cell, $metadata, 'private');
    }

    private function applyTerritoryExpand(TurnContext $context, Nation $nation, MapCell $cell): void
    {
        $cell->loadMissing('ownerNation');
        $oldOwnerNationId = $cell->owner_nation_id;
        $oldOwnerNationName = $oldOwnerNationId === null ? '中立' : $cell->ownerNation->name;
        $cell->owner_nation_id = $nation->id;
        $cell->version++;
        $cell->save();
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $this->events->record($context, 'command.territory_expanded', $cell, [
            'nation_id' => $nation->id,
            'x' => $cell->x,
            'y' => $cell->y,
            'old_owner_nation_id' => $oldOwnerNationId,
            'old_owner_nation_name' => $oldOwnerNationName,
            'new_owner_nation_id' => $nation->id,
            'new_owner_nation_name' => $nation->name,
            'ownership_changed' => true,
        ], 'public');
    }

    private function territoryExpansionFailure(
        TurnContext $context,
        Nation $nation,
        CommandDefinition $definition,
        MapCell $cell,
        bool $monsterOccupied,
    ): ?CommandFailureReason {
        $transfer = $context->ruleset->settings['territory_transfer']['capital_core'] ?? null;
        $capitalProtected = false;
        if (is_array($transfer) && ($transfer['ownership_transfer_protected'] ?? false) === true) {
            $ownerStates = is_array($transfer['owner_states'] ?? null) ? $transfer['owner_states'] : [];
            $capitals = NationCapital::query()
                ->whereHas('nation', fn ($query) => $query
                    ->where('world_id', $context->world->id)
                    ->whereIn('state', $ownerStates))
                ->orderBy('nation_id')
                ->lockForUpdate()
                ->get(['nation_id', 'x', 'y'])
                ->map(static fn (NationCapital $capital): array => [
                    'nation_id' => (int) $capital->nation_id,
                    'x' => (int) $capital->x,
                    'y' => (int) $capital->y,
                ])->all();
            $capitalProtected = $this->capitalCores->protectsTransfer(
                new GridCoordinate($cell->x, $cell->y),
                $nation->id,
                $capitals,
                (int) ($transfer['radius'] ?? 0),
            );
        }

        $facts = new TerritoryExpansionFacts(
            actorNationId: $nation->id,
            actorNationState: $nation->state,
            targetOwnerNationId: $cell->owner_nation_id,
            targetOwnerNationState: $cell->ownerNation?->state,
            targetOwnerInActorWorld: $cell->owner_nation_id === null
                || $cell->ownerNation?->world_id === $context->world->id,
            terrainKey: $cell->terrain->key,
            facilityKey: $cell->facility?->key,
            monsterOccupied: $monsterOccupied,
            capitalCoreProtected: $capitalProtected,
            adjacentActorTerritory: $this->hasOwnedCellWithin($nation, $cell, 1, false),
            definitionTargetTerrainKeys: $definition->target_terrain_keys,
            definitionRequiresEmptyFacility: $definition->requires_empty_facility,
        );

        return $this->territoryExpansion->failureReason($definition->metadata, $facts);
    }

    private function applyCapitalRelocation(TurnContext $context, Nation $nation, MapCell $target): void
    {
        $capital = NationCapital::query()->where('nation_id', $nation->id)->lockForUpdate()->firstOrFail();
        $old = MapCell::query()->whereKey($capital->map_cell_id)->with(['terrain', 'facility'])
            ->lockForUpdate()->firstOrFail();
        $city = FacilityDefinition::query()->where('key', 'city')->firstOrFail();
        $capitalFacility = FacilityDefinition::query()->where('key', 'capital')->firstOrFail();
        $this->cells->setFacility($old, $city);
        $this->cells->setFacility($target, $capitalFacility);
        $old->version++;
        $target->version++;
        $old->save();
        $target->save();
        $capital->update(['map_cell_id' => $target->id, 'x' => $target->x, 'y' => $target->y]);
        $context->state->markMapChunkChanged($old->map_chunk_id);
        $context->state->markMapChunkChanged($target->map_chunk_id);
        $this->events->record($context, 'command.capital_relocated', $target, [
            'nation_id' => $nation->id,
            'from_x' => $old->x, 'from_y' => $old->y,
            'x' => $target->x, 'y' => $target->y,
            'old_population_preserved' => $old->population,
            'new_population_preserved' => $target->population,
        ]);
        $this->events->record($context, 'command.capital_relocated_public', $target, [
            'nation_id' => $nation->id,
            'nation_name' => $nation->name,
            'from_x' => $old->x,
            'from_y' => $old->y,
            'x' => $target->x,
            'y' => $target->y,
        ], 'public');
    }

    private function applyNationCommand(
        TurnContext $context,
        Nation $nation,
        NationCommandQueueItem $item,
        CommandDefinition $definition,
    ): bool {
        if ($definition->key === 'attraction') {
            $context->state->markAttraction($nation->id);
            $this->events->record($context, 'command.attraction_started', $nation, [
                'nation_id' => $nation->id,
            ]);
            $this->events->record($context, 'command.attraction_started_public', $nation, [
                'nation_id' => $nation->id,
                'nation_name' => $nation->name,
            ], 'public');

            return true;
        }
        $target = $this->targetNation($context, $nation, $item);
        if ($definition->key === 'money_aid') {
            $requested = $this->moneyAidAmount($item, $definition);
            $capacity = $this->capacities->resolve($target, $context->ruleset)->money;
            $addition = $this->addition->calculate((int) $target->money, $requested, $capacity);
            if ($addition->applied > 0) {
                $nation->decrement('money', $addition->applied);
                $target->update(['money' => $addition->after]);
                $nation->refresh();
            }
            $metadata = [
                'sender_nation_id' => $nation->id,
                'sender_nation_name' => $nation->name,
                'receiver_nation_id' => $target->id,
                'receiver_nation_name' => $target->name,
                'requested_money' => $requested, 'transferred_money' => $addition->applied,
                'receiver_capacity_money' => $capacity,
                'receiver_capacity_overflow' => $addition->overflow,
            ];
            $this->events->record($context, 'command.money_aid_transferred', $nation, [
                ...$metadata, 'nation_id' => $nation->id,
            ]);
            $this->events->record($context, 'command.money_aid_received', $target, [
                ...$metadata, 'nation_id' => $target->id,
            ]);
            if ($addition->applied > 0) {
                $this->events->record($context, 'command.money_aid_public', $nation, [
                    'nation_id' => $nation->id,
                    'sender_nation_id' => $nation->id,
                    'sender_nation_name' => $nation->name,
                    'receiver_nation_id' => $target->id,
                    'receiver_nation_name' => $target->name,
                    'transferred_money' => $addition->applied,
                ], 'public');
            }

            return $addition->applied > 0;
        }
        if ($definition->key === 'food_aid') {
            $requested = $this->foodAidAmount($item, $definition);
            $foodBalances = NationResource::query()->where('nation_id', $target->id)
                ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
                ->lockForUpdate()->get();
            $before = (int) $foodBalances->sum('amount');
            $capacity = $this->capacities->resolve($target, $context->ruleset)->foodTons;
            $addition = $this->addition->calculate($before, $requested, $capacity);
            if ($addition->applied > 0) {
                $this->debitFood($nation, $addition->applied);
                $wheat = ResourceDefinition::query()->where('key', 'wheat')->firstOrFail();
                $balance = NationResource::query()->firstOrCreate([
                    'nation_id' => $target->id, 'resource_definition_id' => $wheat->id,
                ], ['amount' => 0]);
                $balance->increment('amount', $addition->applied);
            }
            $metadata = [
                'sender_nation_id' => $nation->id,
                'sender_nation_name' => $nation->name,
                'receiver_nation_id' => $target->id,
                'receiver_nation_name' => $target->name,
                'requested_food_tons' => $requested, 'transferred_food_tons' => $addition->applied,
                'receiver_capacity_food_tons' => $capacity,
                'receiver_capacity_overflow_tons' => $addition->overflow,
            ];
            $this->events->record($context, 'command.food_aid_transferred', $nation, [
                ...$metadata, 'nation_id' => $nation->id,
            ]);
            $this->events->record($context, 'command.food_aid_received', $target, [
                ...$metadata, 'nation_id' => $target->id,
            ]);
            if ($addition->applied > 0) {
                $this->events->record($context, 'command.food_aid_public', $nation, [
                    'nation_id' => $nation->id,
                    'sender_nation_id' => $nation->id,
                    'sender_nation_name' => $nation->name,
                    'receiver_nation_id' => $target->id,
                    'receiver_nation_name' => $target->name,
                    'transferred_food_tons' => $addition->applied,
                ], 'public');
            }

            return $addition->applied > 0;
        }
        if ($definition->key === 'monster_dispatch') {
            $monster = $this->monsterSpawn->dispatch($context, $target, $item->id);
            $this->events->record($context, 'command.monster_dispatched', $monster, [
                'nation_id' => $nation->id, 'target_nation_id' => $target->id,
                'monster_key' => 'mecha_inora',
            ], 'private');

            return true;
        }

        throw new DomainException("Unsupported Nation command {$definition->key}.");
    }

    private function targetNation(TurnContext $context, Nation $sender, NationCommandQueueItem $item): Nation
    {
        $targetNationId = $item->parameters['target_nation_id'] ?? null;
        if (! is_int($targetNationId) || $targetNationId === $sender->id) {
            throw new DomainException('Nation command target changed after validation.');
        }

        return Nation::query()->whereKey($targetNationId)
            ->where('world_id', $context->world->id)->where('state', 'active')
            ->lockForUpdate()->firstOrFail();
    }

    private function moneyAidAmount(NationCommandQueueItem $item, CommandDefinition $definition): int
    {
        $perQuantity = $definition->metadata['transfer_money_per_quantity'] ?? null;
        if (! is_int($perQuantity) || $perQuantity < 1) {
            throw new DomainException('Money aid amount settings are invalid.');
        }

        return $item->quantity * $perQuantity;
    }

    private function foodAidAmount(NationCommandQueueItem $item, CommandDefinition $definition): int
    {
        $perQuantity = $definition->metadata['transfer_food_tons_per_quantity'] ?? null;
        if (! is_int($perQuantity) || $perQuantity < 1) {
            throw new DomainException('Food aid amount settings are invalid.');
        }

        return $item->quantity * $perQuantity;
    }

    private function debitFood(Nation $nation, int $amount): void
    {
        $remaining = $amount;
        $balances = NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
            ->with('definition')->lockForUpdate()->get()
            ->sortBy(fn (NationResource $balance): array => [$balance->definition->sort_order, $balance->id]);
        foreach ($balances as $balance) {
            $debit = min($remaining, (int) $balance->amount);
            if ($debit > 0) {
                $balance->decrement('amount', $debit);
                $remaining -= $debit;
            }
            if ($remaining === 0) {
                return;
            }
        }
        throw new DomainException('Food aid balance changed after validation.');
    }

    private function applyReclaim(
        TurnContext $context,
        Nation $nation,
        CommandDefinition $definition,
        MapCell $cell,
    ): void {
        $wasShallow = $cell->terrain->key === 'shallow';
        $this->changeReclaimCell(
            $context,
            $nation,
            $cell,
            $wasShallow ? 'wasteland' : 'shallow',
            $wasShallow ? $nation->id : null,
            false,
        );
        if (! $wasShallow) {
            return;
        }
        $neighbors = $this->adjacentCells($cell);
        $water = array_values(array_filter(
            $neighbors,
            static fn (MapCell $neighbor): bool => in_array($neighbor->terrain->key, ['sea', 'shallow'], true),
        ));
        $spreadMaximum = $definition->metadata['adjacent_water_spread_maximum'] ?? null;
        if (! is_int($spreadMaximum) || $spreadMaximum < 0) {
            throw new DomainException('Reclaim adjacent water spread settings are invalid.');
        }
        if (count($water) > $spreadMaximum) {
            return;
        }

        $spreadCandidates = array_filter(
            $water,
            static fn (MapCell $neighbor): bool => $neighbor->owner_nation_id === null
                && $neighbor->facility_definition_id === null,
        );
        foreach ($spreadCandidates as $neighbor) {
            $this->changeReclaimCell($context, $nation, $neighbor, 'shallow', null, true);
        }
    }

    private function changeReclaimCell(
        TurnContext $context,
        Nation $nation,
        MapCell $cell,
        string $terrainKey,
        ?int $ownerNationId,
        bool $adjacentEffect,
    ): void {
        $oldTerrain = $cell->terrain->key;
        $oldFacility = $cell->facility?->key;
        $oldOwner = $cell->owner_nation_id;
        if ($oldTerrain === $terrainKey
            && $oldFacility === null
            && $oldOwner === $ownerNationId
            && $cell->population === 0) {
            return;
        }

        $this->cells->setFacility($cell, null);
        $terrain = TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail();
        $this->cells->transitionTerrain($cell, $terrain);
        $cell->owner_nation_id = $ownerNationId;
        $cell->population = 0;
        $cell->version++;
        $cell->save();
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $this->events->record($context, 'terrain.changed', $cell, [
            'nation_id' => $nation->id,
            'command_key' => 'reclaim',
            'x' => $cell->x,
            'y' => $cell->y,
            'from_terrain_key' => $oldTerrain,
            'to_terrain_key' => $terrainKey,
            'from_owner_nation_id' => $oldOwner,
            'to_owner_nation_id' => $ownerNationId,
            'removed_facility_key' => $oldFacility,
            'adjacent_effect' => $adjacentEffect,
        ]);
    }

    /** @return list<MapCell> */
    private function adjacentCells(MapCell $cell): array
    {
        $origin = new GridCoordinate($cell->x, $cell->y);
        $neighbors = [];
        foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $direction) {
            $coordinate = $origin->neighbor($direction);
            $neighbor = MapCell::query()
                ->where('map_space_id', $cell->map_space_id)
                ->where('x', $coordinate->x)
                ->where('y', $coordinate->y)
                ->lockForUpdate()
                ->with(['terrain', 'facility'])
                ->first();
            if ($neighbor !== null) {
                $neighbors[] = $neighbor;
            }
        }

        return $neighbors;
    }

    private function isSeabedOilSearch(CommandDefinition $definition, MapCell $cell): bool
    {
        if ($definition->key !== 'excavate' || $cell->terrain->key !== 'sea') {
            return false;
        }

        $effectKey = $definition->metadata['oil_search_effect_key'] ?? null;
        if (! is_string($effectKey) || $effectKey === '') {
            throw new DomainException('Seabed oil search metadata is missing from the active ruleset.');
        }

        return true;
    }

    private function applySeabedOilSearch(
        TurnContext $context,
        Nation $nation,
        NationCommandQueueItem $item,
        CommandDefinition $definition,
        MapCell $cell,
        int $executionCost,
    ): void {
        if ($definition->cost_money < 1) {
            throw new DomainException('Seabed oil search requires a positive base cost.');
        }
        $effectKey = $definition->metadata['oil_search_effect_key'] ?? null;
        $effects = $context->ruleset->settings['turn_processing']['command_random_effects'] ?? null;
        $settings = is_string($effectKey) && is_array($effects) ? ($effects[$effectKey] ?? null) : null;
        if (! is_array($settings)) {
            throw new DomainException('Seabed oil search rules are missing from the active ruleset.');
        }
        $denominator = $settings['draw_denominator'] ?? null;
        $thresholdPerCostUnit = $settings['success_threshold_per_cost_unit'] ?? null;
        $facilityKey = $settings['facility_key'] ?? null;
        if (! is_int($denominator) || $denominator < 1
            || ! is_int($thresholdPerCostUnit) || $thresholdPerCostUnit < 1
            || ! is_string($facilityKey) || $facilityKey === '') {
            throw new DomainException('Seabed oil search rules are invalid.');
        }

        $costUnits = intdiv($executionCost, $definition->cost_money);
        $threshold = min($denominator, $costUnits * $thresholdPerCostUnit);
        $draw = $context->random->stream(TurnRandomStreamFactory::SEABED_OIL_SEARCH)
            ->integer(0, $denominator - 1);
        $found = $draw < $threshold;
        if ($found) {
            $facility = FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail();
            if (! in_array('sea', $facility->buildable_terrain_keys, true)) {
                throw new DomainException('Seabed oil field facility is not buildable on sea terrain.');
            }
            $this->cells->setFacility($cell, $facility);
            $cell->owner_nation_id = $nation->id;
            $cell->population = 0;
            $cell->version++;
            $cell->save();
            $context->state->markMapChunkChanged($cell->map_chunk_id);
        }

        $this->events->record($context, 'command.seabed_oil_search', $cell, [
            'nation_id' => $nation->id,
            'queue_item_id' => $item->id,
            'command_key' => $definition->key,
            'x' => $cell->x,
            'y' => $cell->y,
            'spent_money' => $executionCost,
            'cost_units' => $costUnits,
            'draw' => $draw,
            'success_threshold' => $threshold,
            'denominator' => $denominator,
            'found' => $found,
            'facility_key' => $found ? $facilityKey : null,
        ]);
    }

    private function buriedTreasure(
        TurnContext $context,
        Nation $nation,
        NationCommandQueueItem $item,
    ): void {
        $settings = $context->ruleset->settings['turn_processing']['command_random_effects']['land_clear_buried_treasure'];
        $probability = $settings['probability'];
        $draw = $context->random
            ->stream(TurnRandomStreamFactory::LAND_CLEAR_BURIED_TREASURE)
            ->integer(0, $probability['denominator'] - 1);
        $found = $draw < $probability['numerator'];
        $reward = 0;
        $applied = 0;
        $overflow = 0;
        if ($found) {
            $reward = $context->random
                ->stream(TurnRandomStreamFactory::LAND_CLEAR_BURIED_TREASURE)
                ->integer($settings['reward_minimum_money'], $settings['reward_maximum_money']);
            $capacity = $this->capacities->resolve($nation, $context->ruleset)->money;
            $addition = $this->addition->calculate((int) $nation->money, $reward, $capacity);
            $nation->update(['money' => $addition->after]);
            $applied = $addition->applied;
            $overflow = $addition->overflow;
        }
        $this->events->record($context, 'command.buried_treasure', $item, [
            'nation_id' => $nation->id,
            'draw' => $draw,
            'numerator' => $probability['numerator'],
            'denominator' => $probability['denominator'],
            'found' => $found,
            'reward_money' => $reward,
            'applied_money' => $applied,
            'overflow_money' => $overflow,
        ]);
    }

    private function automaticFinance(TurnContext $context, Nation $nation): void
    {
        $this->finance($context, $nation, 'command.automatic_finance');
    }

    private function finance(TurnContext $context, Nation $nation, string $eventType): void
    {
        $requested = $context->ruleset->settings['turn_processing']['automatic_finance_money'];
        $capacity = $this->capacities->resolve($nation, $context->ruleset)->money;
        $addition = $this->addition->calculate((int) $nation->money, $requested, $capacity);
        $nation->update(['money' => $addition->after]);
        $this->events->record($context, $eventType, $nation, [
            'before' => $addition->before,
            'requested' => $addition->requested,
            'applied' => $addition->applied,
            'overflow' => $addition->overflow,
            'after' => $addition->after,
            'capacity' => $addition->capacity,
        ]);
    }

    /**
     * @param  array<string, int|string|null>  $observed
     */
    private function failAndRemove(
        TurnContext $context,
        Nation $nation,
        NationCommandQueue $queue,
        NationCommandQueueItem $item,
        CommandFailureReason $reason,
        array $observed,
    ): void {
        $metadata = [
            'nation_id' => $nation->id,
            'nation_name' => $nation->name,
            'command_key' => $item->definition->key,
            'command_name' => $item->definition->name,
            'x' => $item->target_x,
            'y' => $item->target_y,
            'failure_reason' => $reason->value,
            'observed' => $observed,
            'original_parameters' => $item->parameters === [] ? (object) [] : $item->parameters,
            'quantity' => $item->quantity,
            'target_turn' => $context->targetTurn,
        ];
        $item->update([
            'status' => 'failed',
            'queue_position' => null,
            'execution_failed_at' => now(),
            'failure_code' => $reason->value,
            'failure_metadata' => $metadata,
        ]);
        $this->compact($queue);
        $this->events->record($context, 'command.failed', $item, $metadata);
        $this->events->record($context, 'command.queue_removed', $item, [
            'nation_id' => $queue->nation_id,
            'command_key' => $item->definition->key,
            'reason' => $reason->value,
        ]);
    }

    /**
     * @return array{terrain: string|null, facility: string|null, owner_nation_id: int|null, owner_nation_name: string|null, monster_id: int|null}
     */
    private function observedState(MapCell $cell, ?int $monsterId): array
    {
        return [
            'terrain' => $cell->terrain->key,
            'facility' => $cell->facility?->key,
            'owner_nation_id' => $cell->owner_nation_id,
            'owner_nation_name' => $cell->ownerNation?->name,
            'monster_id' => $monsterId,
        ];
    }

    /**
     * @return array{terrain: null, facility: null, owner_nation_id: null, owner_nation_name: null, monster_id: null}
     */
    private function emptyObservedState(): array
    {
        return [
            'terrain' => null,
            'facility' => null,
            'owner_nation_id' => null,
            'owner_nation_name' => null,
            'monster_id' => null,
        ];
    }

    private function hasForeignAdjacentCell(Nation $nation, MapCell $cell): bool
    {
        $coordinates = array_values(array_filter(
            (new GridCoordinate($cell->x, $cell->y))->radius(1),
            static fn (GridCoordinate $coordinate): bool => $coordinate->x !== $cell->x || $coordinate->y !== $cell->y,
        ));

        return MapCell::query()
            ->where('map_space_id', $cell->map_space_id)
            ->whereNotNull('owner_nation_id')
            ->where('owner_nation_id', '!=', $nation->id)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair->where('x', $coordinate->x)->where('y', $coordinate->y));
                }
            })
            ->exists();
    }

    private function compact(NationCommandQueue $queue): void
    {
        $items = $this->lockedQueuedItems($queue);
        if ($items->isEmpty()) {
            return;
        }
        $this->writeCompactedPositions($this->legacyOrder->recover($items));
    }

    private function recoverLegacyStagedQueue(NationCommandQueue $queue): void
    {
        $items = $this->lockedQueuedItems($queue);
        if ($items->isEmpty()) {
            return;
        }
        $recovered = $this->legacyOrder->recover($items);
        if ($recovered === $items) {
            return;
        }
        $this->writeCompactedPositions($recovered);
    }

    /** @return Collection<int, NationCommandQueueItem> */
    private function lockedQueuedItems(NationCommandQueue $queue): Collection
    {
        return NationCommandQueueItem::query()
            ->where('nation_command_queue_id', $queue->id)
            ->where('status', 'queued')
            ->orderBy('queue_position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param Collection<int, NationCommandQueueItem> $items */
    private function writeCompactedPositions(Collection $items): void
    {
        NationCommandQueueItem::query()->whereIn('id', $items->modelKeys())
            ->update(['queue_position' => null]);
        foreach ($items as $index => $queuedItem) {
            NationCommandQueueItem::query()->whereKey($queuedItem->id)
                ->update(['queue_position' => $index + 1]);
        }
    }

    private function hasOwnedCellWithin(
        Nation $nation,
        MapCell $cell,
        int $radius,
        bool $includeCenter,
    ): bool {
        $coordinates = (new GridCoordinate($cell->x, $cell->y))->radius($radius);
        if (! $includeCenter) {
            $coordinates = array_values(array_filter(
                $coordinates,
                static fn (GridCoordinate $coordinate): bool => $coordinate->x !== $cell->x || $coordinate->y !== $cell->y,
            ));
        }

        return MapCell::query()
            ->where('map_space_id', $cell->map_space_id)
            ->where('owner_nation_id', $nation->id)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair->where('x', $coordinate->x)->where('y', $coordinate->y));
                }
            })
            ->exists();
    }

    /** @return array<string, int|string|null> */
    private function cellSnapshot(MapCell $cell): array
    {
        return [
            'cell_id' => $cell->id,
            'x' => $cell->x,
            'y' => $cell->y,
            'terrain_key' => $cell->terrain->key,
            'facility_key' => $cell->facility?->key,
            'population' => $cell->population,
            'facility_scale' => $cell->facility_scale,
            'terrain_quantity' => $cell->terrain_quantity,
            'owner_nation_id' => $cell->owner_nation_id,
        ];
    }
}
