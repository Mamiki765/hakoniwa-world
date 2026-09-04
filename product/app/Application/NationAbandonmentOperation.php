<?php

namespace App\Application;

use App\Domain\Map\MapCellStateService;
use App\Models\MapCell;
use App\Models\MapChunk;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\NationResource;
use App\Models\NationResourceSalePolicy;
use App\Models\RulesetVersion;
use App\Models\Ship;
use App\Models\TerrainDefinition;
use App\Models\World;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class NationAbandonmentOperation
{
    public function __construct(
        private readonly MapCellStateService $cellStates,
        private readonly MonsterRemovalService $monsterRemoval,
    ) {}

    /**
     * The caller owns the World/Nation transaction and authorization boundary.
     *
     * @return array{nation_id: int, state: string, owned_cell_count: int, neutral_cleanup_cell_count: int, monster_removed_count: int, ship_removed_count: int, changed_chunk_count: int}
     */
    public function execute(
        World $world,
        RulesetVersion $ruleset,
        Nation $nation,
        ?int $actorUserId,
        string $source,
        int $eventTurn,
    ): array {
        if (! in_array($source, ['manual', 'automatic_idle'], true)) {
            throw new DomainException('Nation abandonment source is invalid.');
        }

        $capital = NationCapital::query()->where('nation_id', $nation->id)->lockForUpdate()->first();
        if ($capital === null) {
            throw new DomainException('A current Nation is missing its Capital.');
        }
        $membership = NationMembership::query()
            ->where('world_id', $world->id)
            ->where('nation_id', $nation->id)
            ->where('role', 'owner')
            ->lockForUpdate()
            ->sole();
        if ($actorUserId !== null && (int) $membership->user_id !== $actorUserId) {
            throw new DomainException('Manual abandonment actor changed after authorization.');
        }
        $oldCapital = [
            'map_cell_id' => (int) $capital->map_cell_id,
            'x' => (int) $capital->x,
            'y' => (int) $capital->y,
        ];

        $surface = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')
            ->lockForUpdate()->firstOrFail();
        $radius = $ruleset->settings['initial_island_reservation_radius'] ?? null;
        if (! is_int($radius) || $radius < 0) {
            throw new DomainException('The current ruleset has an invalid initial island reservation radius.');
        }
        $sea = TerrainDefinition::query()->where('key', 'sea')->firstOrFail();
        $capitalCubeX = $oldCapital['x'] - (int) floor(($oldCapital['y'] + 1) / 2);
        $capitalCubeSum = $capitalCubeX + $oldCapital['y'];
        $cells = MapCell::query()
            ->where('map_space_id', $surface->id)
            ->where(function (Builder $scope) use ($nation, $radius, $capitalCubeX, $oldCapital, $capitalCubeSum): void {
                $scope->where('owner_nation_id', $nation->id)
                    ->orWhere(function (Builder $neutral) use ($radius, $capitalCubeX, $oldCapital, $capitalCubeSum): void {
                        $neutral->whereNull('owner_nation_id')->whereRaw(<<<'SQL'
GREATEST(
    ABS((x - FLOOR((y + 1) / 2.0)) - ?),
    ABS(y - ?),
    ABS(((x - FLOOR((y + 1) / 2.0)) + y) - ?)
) <= ?
SQL, [$capitalCubeX, $oldCapital['y'], $capitalCubeSum, $radius]);
                    });
            })
            ->orderBy('id')->lockForUpdate()->get();

        $ownedCellCount = $cells->where('owner_nation_id', $nation->id)->count();
        $neutralCleanupCellCount = $cells->whereNull('owner_nation_id')->count();
        $chunkIds = $cells->pluck('map_chunk_id')->unique()->sort()->values()->all();
        $chunks = $chunkIds === []
            ? collect()
            : MapChunk::query()->whereIn('id', $chunkIds)->orderBy('id')->lockForUpdate()->get();
        $occupancies = $cells->isEmpty()
            ? collect()
            : MonsterOccupancy::query()->whereIn('map_cell_id', $cells->modelKeys())
                ->orderBy('id')->lockForUpdate()->get();
        $monsterRemovedCount = 0;
        foreach ($occupancies as $occupancy) {
            if ($this->monsterRemoval->removeForWorldMutation($occupancy, 'nation_abandoned')) {
                $monsterRemovedCount++;
            }
        }
        $ships = Ship::query()->where('nation_id', $nation->id)->where('state', Ship::STATE_ACTIVE)
            ->orderBy('id')->lockForUpdate()->get();
        $removedAt = now();
        foreach ($ships as $ship) {
            $ship->map_cell_id = null;
            $ship->state = Ship::STATE_REMOVED;
            $ship->removal_reason = 'nation_abandoned';
            $ship->removed_at = $removedAt;
            $ship->version++;
            $ship->save();
        }
        $shipRemovedCount = $ships->count();

        foreach ($cells as $cell) {
            $this->cellStates->setFacility($cell, null);
            $this->cellStates->transitionTerrain($cell, $sea);
            $cell->owner_nation_id = null;
            $cell->population = 0;
            $cell->version++;
            $cell->save();
        }
        foreach ($chunks as $chunk) {
            $chunk->version++;
            $chunk->save();
        }

        $queue = NationCommandQueue::query()->where('nation_id', $nation->id)->lockForUpdate()->first();
        if ($queue !== null) {
            NationCommandQueueItem::query()->where('nation_command_queue_id', $queue->id)
                ->orderBy('id')->lockForUpdate()->get();
            $queue->delete();
        }
        foreach (NationResource::query()->where('nation_id', $nation->id)
            ->orderBy('id')->lockForUpdate()->get() as $resource) {
            $resource->amount = 0;
            $resource->save();
        }
        foreach (NationResourceSalePolicy::query()->where('nation_id', $nation->id)
            ->orderBy('id')->lockForUpdate()->get() as $salePolicy) {
            $salePolicy->delete();
        }

        $capital->delete();
        $membership->delete();
        $nation->money = 0;
        $nation->idle_counter = 0;
        $nation->state = 'abandoned';
        $nation->state_reason = null;
        $nation->state_started_turn = null;
        $nation->resume_at_turn = null;
        $nation->save();

        $automatic = $source === 'automatic_idle';
        $occurredAt = now();
        DB::table('audit_events')->insert([
            'actor_user_id' => $actorUserId,
            'world_id' => $world->id,
            'turn' => $eventTurn,
            'nation_id' => $nation->id,
            'x' => $oldCapital['x'],
            'y' => $oldCapital['y'],
            'message' => $automatic
                ? "{$nation->name}は放置され、忘れ去られる。"
                : "{$nation->name}は破棄され、忘れ去られた。",
            'visibility' => 'public',
            'event_type' => 'nation.abandoned',
            'severity' => 'critical',
            'subject_type' => Nation::class,
            'subject_id' => $nation->id,
            'metadata' => json_encode([
                'nation_id' => $nation->id,
                'nation_number' => $nation->nation_number,
                'nation_name' => $nation->name,
                'actor_user_id' => $actorUserId,
                'actor' => $automatic ? 'system' : 'owner',
                'reason' => $automatic ? 'idle_threshold' : 'manual_abandonment',
                'world_id' => $world->id,
                'target_turn' => $eventTurn,
                'current_turn' => $eventTurn,
                'old_capital_map_cell_id' => $oldCapital['map_cell_id'],
                'old_capital_x' => $oldCapital['x'],
                'old_capital_y' => $oldCapital['y'],
                'affected_owned_cell_count' => $ownedCellCount,
                'affected_neutral_cleanup_cell_count' => $neutralCleanupCellCount,
                'removed_monster_count' => $monsterRemovedCount,
                'removed_ship_count' => $shipRemovedCount,
                'changed_chunk_count' => count($chunkIds),
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);

        return [
            'nation_id' => $nation->id,
            'state' => $nation->state,
            'owned_cell_count' => $ownedCellCount,
            'neutral_cleanup_cell_count' => $neutralCleanupCellCount,
            'monster_removed_count' => $monsterRemovedCount,
            'ship_removed_count' => $shipRemovedCount,
            'changed_chunk_count' => count($chunkIds),
        ];
    }
}
