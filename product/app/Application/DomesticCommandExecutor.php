<?php

namespace App\Application;

use App\Domain\Economy\CappedAddition;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationResource;
use App\Models\TerrainDefinition;
use DomainException;

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
    ) {}

    /** @return array{successes: int, failures: int, removed: int, quantity_decrements: int, automatic_finance: int} */
    public function execute(TurnContext $context): array
    {
        $metrics = [
            'successes' => 0,
            'failures' => 0,
            'removed' => 0,
            'quantity_decrements' => 0,
            'automatic_finance' => 0,
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
                $failure = $this->validationFailure($nation, $queue, $item);
                if ($failure !== null) {
                    $this->failAndRemove($context, $queue, $item, $failure['code'], $failure['message']);
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
                $this->deductCostAndResources($nation, $definition);
                $this->apply($context, $nation, $item, $definition, $cell);
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
                $this->events->record($context, 'command.success', $item, [
                    'nation_id' => $nation->id,
                    'command_key' => $definition->key,
                    'cost_money' => $definition->cost_money,
                    'consumes_turn' => $consumedTurn,
                    'remaining_quantity' => $remainingQuantity,
                    'before' => $before,
                    'after' => $after,
                ]);
            }

            if (! $consumedTurn) {
                $this->automaticFinance($context, $nation);
                $metrics['automatic_finance']++;
            }
            if ($queue !== null && $queueMutated) {
                $queue->increment('version');
            }
        }

        return $metrics;
    }

    /** @return array{code: string, message: string}|null */
    private function validationFailure(
        Nation $nation,
        NationCommandQueue $queue,
        NationCommandQueueItem $item,
    ): ?array {
        $definition = $item->definition;
        if ($definition->ruleset_version_id !== $nation->world()->value('ruleset_version_id')) {
            return ['code' => 'ruleset_mismatch', 'message' => 'Command definition does not match the active World ruleset.'];
        }
        $cell = MapCell::query()
            ->where('map_space_id', $queue->map_space_id)
            ->where('x', $item->target_x)
            ->where('y', $item->target_y)
            ->lockForUpdate()
            ->with(['terrain', 'facility'])
            ->first();
        if ($cell === null) {
            return ['code' => 'target_missing', 'message' => 'Target cell no longer exists.'];
        }
        if (! in_array($cell->terrain->key, $definition->target_terrain_keys, true)) {
            return ['code' => 'invalid_terrain', 'message' => 'Target terrain is no longer valid.'];
        }
        if ($cell->facility?->key === 'capital'
            && in_array($definition->key, self::CAPITAL_DESTRUCTIVE_COMMANDS, true)) {
            return ['code' => 'capital_protected', 'message' => 'Terrain commands cannot remove the Nation Capital.'];
        }
        if ($definition->requires_empty_facility && $cell->facility_definition_id !== null) {
            if (! $this->isMatchingQuantityFacility($definition, $cell)) {
                return ['code' => 'facility_not_empty', 'message' => 'Target cell now contains a facility.'];
            }
            if ($cell->facility_scale === null || $cell->facility?->scale_increment === null
                || $cell->facility->maximum_scale === null) {
                return ['code' => 'invalid_facility_scale', 'message' => 'Target facility has invalid scale state.'];
            }
        }
        if ($definition->target_facility_keys !== []
            && ! in_array($cell->facility?->key, $definition->target_facility_keys, true)) {
            return ['code' => 'invalid_facility', 'message' => 'Target facility is no longer valid.'];
        }
        if ($definition->key === 'reclaim') {
            if (! $this->hasOwnedCellWithin($nation, $cell, 1, false)) {
                return ['code' => 'ownership_mismatch', 'message' => 'Reclaim target has no adjacent owned cell.'];
            }
        } elseif ($definition->key === 'excavate' && in_array($cell->terrain->key, ['sea', 'shallow'], true)) {
            if (! $this->hasOwnedCellWithin($nation, $cell, 3, true)) {
                return ['code' => 'ownership_mismatch', 'message' => 'Excavation target has no owned cell within radius three.'];
            }
            if ($cell->terrain->key === 'sea') {
                return ['code' => 'oil_search_deferred', 'message' => 'Deep-sea oil search remains deferred.'];
            }
        } elseif ($cell->owner_nation_id !== $nation->id) {
            return ['code' => 'ownership_mismatch', 'message' => 'Target cell is no longer owned by the Nation.'];
        }
        if ((int) $nation->money < $definition->cost_money) {
            return ['code' => 'insufficient_money', 'message' => 'Nation no longer has enough money.'];
        }
        foreach ($definition->required_resources as $resourceKey => $required) {
            $amount = NationResource::query()
                ->where('nation_id', $nation->id)
                ->whereHas('definition', fn ($query) => $query->where('key', $resourceKey))
                ->value('amount');
            if ((int) $amount < $required) {
                return ['code' => 'insufficient_resources', 'message' => "Nation lacks required resource {$resourceKey}."];
            }
        }

        return null;
    }

    private function isMatchingQuantityFacility(CommandDefinition $definition, MapCell $cell): bool
    {
        return in_array($definition->key, self::QUANTITY_COMMANDS, true)
            && $definition->result_facility_key !== null
            && $cell->facility?->key === $definition->result_facility_key;
    }

    private function deductCostAndResources(Nation $nation, CommandDefinition $definition): void
    {
        if ((int) $nation->money < $definition->cost_money) {
            throw new DomainException('Command money validation changed while the World transaction was locked.');
        }
        if ($definition->cost_money > 0) {
            $nation->decrement('money', $definition->cost_money);
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
    ): void {
        $terrainKey = match ($definition->key) {
            'land_clear', 'land_level' => 'plain',
            'reclaim' => $cell->terrain->key === 'sea' ? 'shallow' : 'wasteland',
            'excavate' => match ($cell->terrain->key) {
                'shallow' => 'sea',
                'mountain' => 'wasteland',
                default => 'shallow',
            },
            'build_farm', 'build_factory', 'build_mine' => null,
            default => throw new DomainException("Unsupported domestic command {$definition->key}."),
        };

        if ($terrainKey !== null) {
            $oldTerrain = $cell->terrain->key;
            $oldFacility = $cell->facility?->key;
            $this->cells->setFacility($cell, null);
            $terrain = TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail();
            $this->cells->transitionTerrain($cell, $terrain);
            if ($definition->key === 'reclaim') {
                $cell->owner_nation_id = $nation->id;
            }
            $cell->population = 0;
            $cell->version++;
            $cell->save();
            $context->state->markMapChunkChanged($cell->map_chunk_id);
            $this->events->record($context, 'terrain.changed', $cell, [
                'nation_id' => $nation->id,
                'command_key' => $definition->key,
                'x' => $cell->x,
                'y' => $cell->y,
                'from_terrain_key' => $oldTerrain,
                'to_terrain_key' => $terrainKey,
                'removed_facility_key' => $oldFacility,
            ]);
            if ($definition->key === 'land_clear') {
                $this->buriedTreasure($context, $nation, $item);
            }

            return;
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
        $this->cells->setFacility($cell, $facility, $scale);
        $cell->population = 0;
        $cell->version++;
        $cell->save();
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $this->events->record($context, $expanded ? 'facility.expanded' : 'facility.constructed', $cell, [
            'nation_id' => $nation->id,
            'command_key' => $definition->key,
            'facility_key' => $facilityKey,
            'before_scale' => $beforeScale,
            'facility_scale' => $cell->facility_scale,
            'scale_increment' => $expanded ? $facility->scale_increment : null,
            'x' => $cell->x,
            'y' => $cell->y,
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
        $requested = $context->ruleset->settings['turn_processing']['automatic_finance_money'];
        $capacity = $this->capacities->resolve($nation, $context->ruleset)->money;
        $addition = $this->addition->calculate((int) $nation->money, $requested, $capacity);
        $nation->update(['money' => $addition->after]);
        $this->events->record($context, 'command.automatic_finance', $nation, [
            'before' => $addition->before,
            'requested' => $addition->requested,
            'applied' => $addition->applied,
            'overflow' => $addition->overflow,
            'after' => $addition->after,
            'capacity' => $addition->capacity,
        ]);
    }

    private function failAndRemove(
        TurnContext $context,
        NationCommandQueue $queue,
        NationCommandQueueItem $item,
        string $code,
        string $message,
    ): void {
        $item->update([
            'status' => 'failed',
            'queue_position' => null,
            'execution_failed_at' => now(),
            'failure_code' => $code,
            'failure_metadata' => ['message' => $message],
        ]);
        $this->compact($queue);
        $eventType = in_array($code, ['insufficient_money', 'insufficient_resources'], true)
            ? 'command.insufficient_assets'
            : 'command.invalid';
        $this->events->record($context, $eventType, $item, [
            'nation_id' => $queue->nation_id,
            'command_key' => $item->definition->key,
            'failure_code' => $code,
            'message' => $message,
        ]);
        $this->events->record($context, 'command.queue_removed', $item, [
            'nation_id' => $queue->nation_id,
            'command_key' => $item->definition->key,
            'reason' => $code,
        ]);
    }

    private function compact(NationCommandQueue $queue): void
    {
        $items = NationCommandQueueItem::query()
            ->where('nation_command_queue_id', $queue->id)
            ->where('status', 'queued')
            ->orderBy('queue_position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($items->isEmpty()) {
            return;
        }
        NationCommandQueueItem::query()->whereIn('id', $items->modelKeys())->increment('queue_position', 1000);
        foreach ($items as $index => $queuedItem) {
            $queuedItem->update(['queue_position' => $index + 1]);
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
        ];
    }
}
