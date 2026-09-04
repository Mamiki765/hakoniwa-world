<?php

namespace App\Application;

use App\Domain\Ship\SurfaceShipCatalog;
use App\Domain\Ship\SurfaceShipDefinition;
use App\Domain\Turn\TurnContext;
use App\Models\MapCell;
use App\Models\Ship;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final class SurfaceShipRemovalService
{
    public function __construct(
        private readonly SurfaceShipCatalog $catalog,
        private readonly TurnEventRecorder $events,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function sinkAtCell(
        TurnContext $context,
        MapCell $cell,
        string $reason,
        array $metadata = [],
    ): ?Ship {
        $ship = Ship::query()
            ->where('world_id', $context->world->id)
            ->where('map_cell_id', $cell->id)
            ->where('state', Ship::STATE_ACTIVE)
            ->lockForUpdate()
            ->first();

        return $this->sinkLockedAtCell($context, $cell, $ship, $reason, $metadata);
    }

    /** @return Collection<int, Ship> */
    public function lockActiveWorldIndex(TurnContext $context): Collection
    {
        return Ship::query()
            ->where('world_id', $context->world->id)
            ->where('state', Ship::STATE_ACTIVE)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('map_cell_id');
    }

    /** @param array<string, mixed> $metadata */
    public function sinkLockedAtCell(
        TurnContext $context,
        MapCell $cell,
        ?Ship $ship,
        string $reason,
        array $metadata = [],
    ): ?Ship {
        if (! $ship instanceof Ship
            || $ship->world_id !== $context->world->id
            || $ship->map_cell_id !== $cell->id
            || $ship->state !== Ship::STATE_ACTIVE) {
            return null;
        }

        $definition = collect($this->catalog->definitions($context->ruleset->settings))
            ->first(static fn (SurfaceShipDefinition $candidate): bool => $candidate->key === $ship->ship_type_key);
        if ($definition === null) {
            throw new DomainException('The active Ship type is unavailable from its Ruleset snapshot.');
        }

        $ship->current_hp = 0;
        $ship->map_cell_id = null;
        $ship->state = Ship::STATE_REMOVED;
        $ship->removal_reason = $reason;
        $ship->removed_at = now();
        $ship->version++;
        $ship->save();
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $this->events->record($context, 'ship.sunk', $ship, [
            'nation_id' => (int) $ship->nation_id,
            'ship_id' => (int) $ship->id,
            'ship_type_key' => $ship->ship_type_key,
            'ship_name' => $definition->name,
            'removal_reason' => $reason,
            'x' => (int) $cell->x,
            'y' => (int) $cell->y,
            ...$metadata,
        ], 'nation', 'warning');

        return $ship;
    }
}
