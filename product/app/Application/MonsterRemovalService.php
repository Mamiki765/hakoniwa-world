<?php

namespace App\Application;

use App\Domain\Monster\MonsterTurnBatch;
use App\Domain\Turn\TurnContext;
use App\Models\MapCell;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use DomainException;

final class MonsterRemovalService
{
    private ?MonsterTurnBatch $batch = null;

    private int $removedCount = 0;

    public function __construct(private readonly TurnEventRecorder $events) {}

    public function useBatch(MonsterTurnBatch $batch): void
    {
        $this->batch = $batch;
        $this->removedCount = 0;
    }

    public function beginWorld(TurnContext $context): int
    {
        $occupancies = MonsterOccupancy::query()
            ->whereHas('monster', fn ($query) => $query
                ->where('world_id', $context->world->id)
                ->where('state', 'alive'))
            ->with(['monster.definition'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $this->useBatch(new MonsterTurnBatch($occupancies));

        return $occupancies->count();
    }

    public function hasAtCell(int $cellId): bool
    {
        if ($this->batch !== null) {
            return $this->batch->occupancyAt($cellId) !== null;
        }

        return MonsterOccupancy::query()->where('map_cell_id', $cellId)->exists();
    }

    /** @param array<string, mixed> $metadata */
    public function removeAtCell(
        TurnContext $context,
        MapCell $cell,
        string $reason,
        string $eventType = 'monster.removed_by_terrain_event',
        array $metadata = [],
    ): bool {
        $occupancy = $this->batch?->occupancyAt($cell->id)
            ?? MonsterOccupancy::query()
                ->where('map_cell_id', $cell->id)
                ->with('monster.definition')
                ->lockForUpdate()
                ->first();
        if ($occupancy === null || $occupancy->monster->state !== 'alive') {
            return false;
        }

        return $this->removeOccupancy($context, $occupancy, $cell, $reason, $eventType, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public function removeInstance(
        TurnContext $context,
        MonsterInstance $monster,
        MapCell $cell,
        string $reason,
        string $eventType,
        array $metadata = [],
    ): bool {
        $occupancy = $this->batch?->occupancyAt($cell->id)
            ?? MonsterOccupancy::query()
                ->where('monster_instance_id', $monster->id)
                ->with('monster.definition')
                ->lockForUpdate()
                ->first();
        if ($occupancy === null || $occupancy->monster->state !== 'alive') {
            return false;
        }

        return $this->removeOccupancy($context, $occupancy, $cell, $reason, $eventType, $metadata);
    }

    public function removedCount(): int
    {
        return $this->removedCount;
    }

    public function removeForWorldMutation(MonsterOccupancy $occupancy, string $reason): bool
    {
        $monster = MonsterInstance::query()
            ->whereKey($occupancy->monster_instance_id)
            ->lockForUpdate()
            ->firstOrFail();
        if ($monster->state !== 'alive') {
            throw new DomainException('Only an alive occupied monster can be removed from the World.');
        }

        $occupancy->setRelation('monster', $monster);
        $this->markRemoved($occupancy, $monster, $reason);

        return true;
    }

    public function detachForKill(
        TurnContext $context,
        MonsterOccupancy $occupancy,
        MapCell $cell,
    ): void {
        $occupancy->delete();
        $this->batch?->forget($occupancy);
        $context->state->markMapChunkChanged($cell->map_chunk_id);
    }

    /** @param array<string, mixed> $metadata */
    private function removeOccupancy(
        TurnContext $context,
        MonsterOccupancy $occupancy,
        MapCell $cell,
        string $reason,
        string $eventType,
        array $metadata,
    ): bool {
        $monster = $occupancy->monster;
        $definition = $monster->definition;
        $this->markRemoved($occupancy, $monster, $reason);
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $this->events->record($context, $eventType, $monster, [
            'monster_key' => $definition->key,
            'x' => $cell->x,
            'y' => $cell->y,
            'nation_id' => $cell->owner_nation_id,
            'removal_reason' => $reason,
            'rewards_granted' => false,
            'kill_stat_incremented' => false,
            ...$metadata,
        ]);

        return true;
    }

    private function markRemoved(
        MonsterOccupancy $occupancy,
        MonsterInstance $monster,
        string $reason,
    ): void {
        $occupancy->delete();
        $this->batch?->forget($occupancy);
        $monster->state = 'removed';
        $monster->removal_reason = $reason;
        $monster->removed_at = now();
        $monster->version++;
        $monster->save();
        $this->removedCount++;
    }
}
