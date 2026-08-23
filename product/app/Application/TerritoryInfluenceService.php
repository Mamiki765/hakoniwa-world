<?php

namespace App\Application;

use App\Domain\Command\CapitalCorePolicy;
use App\Domain\Command\TerritoryInfluencePolicy;
use App\Domain\Map\GridCoordinate;
use App\Domain\Nation\NationProtectionPolicy;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCapital;
use DomainException;
use Illuminate\Support\Facades\DB;

final class TerritoryInfluenceService
{
    private const PERSISTENCE_BATCH_SIZE = 1_000;

    public function __construct(
        private readonly TerritoryInfluencePolicy $policy,
        private readonly CapitalCorePolicy $capitalCores,
        private readonly TurnEventRecorder $events,
        private readonly NationProtectionPolicy $nationProtection,
    ) {}

    /**
     * @return array{processed: int, eligible_targets: int, direction_draws: int, mutations: int, extension_boundary: bool}
     */
    public function execute(TurnContext $context): array
    {
        $settings = $context->ruleset->settings['turn_processing']['territory_influence'] ?? [];
        if (! is_array($settings) || ! $this->policy->enabled($settings)) {
            return [
                'processed' => 0,
                'eligible_targets' => 0,
                'direction_draws' => 0,
                'mutations' => 0,
                'extension_boundary' => true,
            ];
        }

        $surfaceCellIds = $context->state->surfaceCellIds();
        $space = MapSpace::query()
            ->where('world_id', $context->world->id)
            ->where('key', 'surface')
            ->firstOrFail();
        $cells = MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereIn('id', $surfaceCellIds)
            ->with(['terrain', 'facility'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($cells->count() !== count($surfaceCellIds)) {
            throw new DomainException('Territory influence surface order references missing cells.');
        }
        $cellsById = $cells->keyBy('id');
        $cellsByCoordinate = $cells->mapWithKeys(static fn (MapCell $cell): array => [
            $cell->x.':'.$cell->y => $cell,
        ])->all();

        $lifecycle = $context->ruleset->settings['nation_lifecycle'] ?? [];
        $targetStates = is_array($lifecycle)
            ? ($lifecycle['territory_influence_target_states'] ?? $settings['owner_states'] ?? [])
            : ($settings['owner_states'] ?? []);
        $sourceStates = is_array($lifecycle)
            ? ($lifecycle['territory_influence_source_states'] ?? $settings['owner_states'] ?? [])
            : ($settings['owner_states'] ?? []);
        if (! is_array($targetStates) || ! is_array($sourceStates)) {
            throw new DomainException('Territory influence Nation state settings are invalid.');
        }
        $targetNations = Nation::query()
            ->where('world_id', $context->world->id)
            ->whereIn('state', $targetStates)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'name', 'state']);
        $targetNationIds = $targetNations->mapWithKeys(
            static fn (Nation $nation): array => [(int) $nation->id => true],
        )->all();
        $sourceNationIds = $targetNations
            ->filter(static fn (Nation $nation): bool => in_array($nation->state, $sourceStates, true))
            ->mapWithKeys(static fn (Nation $nation): array => [(int) $nation->id => true])
            ->all();
        $targetNationNames = $targetNations->mapWithKeys(
            static fn (Nation $nation): array => [(int) $nation->id => $nation->name],
        )->all();
        $capitals = NationCapital::query()
            ->whereIn('nation_id', array_keys($targetNationIds))
            ->orderBy('nation_id')
            ->lockForUpdate()
            ->get(['nation_id', 'map_cell_id', 'x', 'y']);
        if ($capitals->count() !== count($targetNationIds)) {
            throw new DomainException('Every territory-influence Nation must have exactly one Capital.');
        }
        $capitalFacts = $capitals->map(function (NationCapital $capital) use ($cellsById): array {
            $cell = $cellsById->get($capital->map_cell_id);
            if (! $cell instanceof MapCell || $cell->x !== $capital->x || $cell->y !== $capital->y) {
                throw new DomainException('An active Nation Capital does not match its surface cell.');
            }

            return [
                'nation_id' => (int) $capital->nation_id,
                'x' => (int) $capital->x,
                'y' => (int) $capital->y,
            ];
        })->all();
        $occupiedCellIds = MonsterOccupancy::query()
            ->whereIn('map_cell_id', $surfaceCellIds)
            ->pluck('map_cell_id')
            ->mapWithKeys(static fn ($cellId): array => [(int) $cellId => true])
            ->all();

        $coreRadius = (int) ($context->ruleset->settings['territory_transfer']['capital_core']['radius'] ?? 0);
        $directionStream = $settings['resolution']['direction_stream'] ?? null;
        if ($directionStream !== TurnRandomStreamFactory::TERRITORY_INFLUENCE_DIRECTION) {
            throw new DomainException('Territory influence direction stream does not match the runtime contract.');
        }
        $random = $context->random->stream($directionStream);
        $metrics = [
            'processed' => 0,
            'eligible_targets' => 0,
            'direction_draws' => 0,
            'mutations' => 0,
            'extension_boundary' => false,
        ];
        /** @var list<array{id: int, owner_nation_id: int, version: int}> $mutations */
        $mutations = [];
        /**
         * @var list<array{
         *     event_type: string,
         *     subject: MapCell,
         *     metadata: array<string, mixed>,
         *     visibility: string,
         *     severity: null,
         *     message: null
         * }> $pendingEvents
         */
        $pendingEvents = [];

        foreach ($surfaceCellIds as $cellId) {
            $target = $cellsById->get($cellId);
            if (! $target instanceof MapCell) {
                throw new DomainException("Surface cell order references missing cell {$cellId}.");
            }
            $metrics['processed']++;
            $targetOwnerNationId = $target->owner_nation_id;
            $targetCoreProtected = $targetOwnerNationId !== null
                && $this->capitalCores->protectsCurrentOwnerTerritory(
                    new GridCoordinate($target->x, $target->y),
                    $targetOwnerNationId,
                    $capitalFacts,
                    $coreRadius,
                );
            if (! $this->policy->targetEligible(
                $settings,
                $targetOwnerNationId,
                $target->terrain->key,
                $target->facility?->key,
                isset($occupiedCellIds[$target->id]),
                $targetCoreProtected,
                $targetNationIds,
            )) {
                continue;
            }

            $metrics['eligible_targets']++;
            $direction = $random->integer(0, 5);
            $metrics['direction_draws']++;
            $coordinate = (new GridCoordinate($target->x, $target->y))->neighbor($direction);
            $source = $cellsByCoordinate[$coordinate->x.':'.$coordinate->y] ?? null;
            if (! $source instanceof MapCell || $targetOwnerNationId === null
                || ! $this->policy->sourceEligible(
                    $settings,
                    $source->owner_nation_id,
                    $targetOwnerNationId,
                    $source->terrain->key,
                    $source->facility?->key,
                    isset($occupiedCellIds[$source->id]),
                    $sourceNationIds,
                )) {
                continue;
            }
            $newOwnerNationId = (int) $source->owner_nation_id;
            if ($this->capitalCores->protectsTransfer(
                new GridCoordinate($target->x, $target->y),
                $newOwnerNationId,
                $capitalFacts,
                $coreRadius,
            )) {
                continue;
            }
            if ($this->nationProtection->protects($context, $target->x, $target->y)) {
                continue;
            }

            $oldOwnerNationId = $targetOwnerNationId;
            $target->owner_nation_id = $newOwnerNationId;
            $target->version++;
            $mutations[] = [
                'id' => (int) $target->id,
                'owner_nation_id' => $newOwnerNationId,
                'version' => (int) $target->version,
            ];
            $context->state->markMapChunkChanged($target->map_chunk_id);
            $pendingEvents[] = [
                'event_type' => 'territory.influenced',
                'subject' => $target,
                'metadata' => [
                    'nation_id' => $newOwnerNationId,
                    'x' => $target->x,
                    'y' => $target->y,
                    'old_owner_nation_id' => $oldOwnerNationId,
                    'old_owner_nation_name' => $targetNationNames[$oldOwnerNationId],
                    'new_owner_nation_id' => $newOwnerNationId,
                    'new_owner_nation_name' => $targetNationNames[$newOwnerNationId],
                    'ownership_changed' => true,
                ],
                'visibility' => 'public',
                'severity' => null,
                'message' => null,
            ];
            $metrics['mutations']++;
        }

        $this->persistMutations($mutations);
        $this->events->recordMany($context, $pendingEvents, self::PERSISTENCE_BATCH_SIZE);

        return $metrics;
    }

    /** @param list<array{id: int, owner_nation_id: int, version: int}> $mutations */
    private function persistMutations(array $mutations): void
    {
        foreach (array_chunk($mutations, self::PERSISTENCE_BATCH_SIZE) as $batch) {
            $bindings = [];
            $values = [];
            foreach ($batch as $mutation) {
                $values[] = '(CAST(? AS BIGINT), CAST(? AS BIGINT), CAST(? AS BIGINT))';
                $bindings[] = $mutation['id'];
                $bindings[] = $mutation['owner_nation_id'];
                $bindings[] = $mutation['version'];
            }
            $updated = DB::update(
                'UPDATE map_cells AS cell '
                .'SET owner_nation_id = mutation.owner_nation_id, '
                .'version = mutation.version, updated_at = CURRENT_TIMESTAMP '
                .'FROM (VALUES '.implode(', ', $values).') AS mutation(id, owner_nation_id, version) '
                .'WHERE cell.id = mutation.id',
                $bindings,
            );
            if ($updated !== count($batch)) {
                throw new DomainException(
                    'Expected to persist '.count($batch)." territory influence mutations, but updated {$updated}.",
                );
            }
        }
    }
}
