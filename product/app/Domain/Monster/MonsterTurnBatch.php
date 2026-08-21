<?php

namespace App\Domain\Monster;

use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use InvalidArgumentException;

final class MonsterTurnBatch
{
    /** @var array<int, MonsterOccupancy> */
    private array $occupancyByCellId = [];

    /** @var array<int, MonsterOccupancy> */
    private array $occupancyByMonsterId = [];

    /** @var array<int, int> */
    private array $movesTakenByMonster = [];

    /** @var array<int, true> */
    private array $actionDeferredMonsterIds = [];

    /** @var array<string, int> */
    private array $metrics = [
        'monsters_loaded' => 0,
        'monster_actions' => 0,
        'monster_moves' => 0,
        'cells_trampled' => 0,
        'defense_self_destructs' => 0,
        'maximum_moves_by_single_monster' => 0,
    ];

    /**
     * @param  iterable<MonsterOccupancy>  $occupancies
     * @param  list<int>  $actionDeferredMonsterIds
     */
    public function __construct(iterable $occupancies, array $actionDeferredMonsterIds = [])
    {
        $this->actionDeferredMonsterIds = array_fill_keys($actionDeferredMonsterIds, true);
        foreach ($occupancies as $occupancy) {
            if (isset($this->occupancyByCellId[$occupancy->map_cell_id])) {
                throw new InvalidArgumentException('Monster batch contains duplicate occupied cells.');
            }
            if (isset($this->occupancyByMonsterId[$occupancy->monster_instance_id])) {
                throw new InvalidArgumentException('Monster batch contains duplicate monster occupancies.');
            }
            $this->occupancyByCellId[$occupancy->map_cell_id] = $occupancy;
            $this->occupancyByMonsterId[$occupancy->monster_instance_id] = $occupancy;
            $this->movesTakenByMonster[$occupancy->monster_instance_id] = 0;
            if (! $this->isActionDeferred($occupancy->monster_instance_id)) {
                $this->metrics['monsters_loaded']++;
            }
        }
    }

    public function occupancyAt(int $cellId): ?MonsterOccupancy
    {
        return $this->occupancyByCellId[$cellId] ?? null;
    }

    public function isActionDeferred(int $monsterId): bool
    {
        return isset($this->actionDeferredMonsterIds[$monsterId]);
    }

    public function move(
        MonsterOccupancy $occupancy,
        int $fromCellId,
        int $toCellId,
        bool $trampled = true,
    ): void {
        if (($this->occupancyByCellId[$fromCellId] ?? null)?->id !== $occupancy->id
            || isset($this->occupancyByCellId[$toCellId])) {
            throw new InvalidArgumentException('Monster movement would desynchronize the turn-local occupancy index.');
        }
        unset($this->occupancyByCellId[$fromCellId]);
        $this->occupancyByCellId[$toCellId] = $occupancy;
        $monsterId = $occupancy->monster_instance_id;
        $moves = ($this->movesTakenByMonster[$monsterId] ?? 0) + 1;
        $this->movesTakenByMonster[$monsterId] = $moves;
        $this->metrics['monster_moves']++;
        if ($trampled) {
            $this->metrics['cells_trampled']++;
        }
        $this->metrics['maximum_moves_by_single_monster'] = max(
            $this->metrics['maximum_moves_by_single_monster'],
            $moves,
        );
    }

    public function forget(MonsterOccupancy $occupancy): void
    {
        if (($this->occupancyByCellId[$occupancy->map_cell_id] ?? null)?->id === $occupancy->id) {
            unset($this->occupancyByCellId[$occupancy->map_cell_id]);
        }
        if (($this->occupancyByMonsterId[$occupancy->monster_instance_id] ?? null)?->id === $occupancy->id) {
            unset($this->occupancyByMonsterId[$occupancy->monster_instance_id]);
        }
        unset($this->actionDeferredMonsterIds[$occupancy->monster_instance_id]);
    }

    public function synchronizeMonsterSnapshot(MonsterInstance $monster): void
    {
        $occupancy = $this->occupancyByMonsterId[$monster->id] ?? null;
        if ($occupancy === null) {
            return;
        }
        if ($monster->state !== 'alive' || ! $monster->relationLoaded('definition')) {
            throw new InvalidArgumentException('Monster batch synchronization requires an alive definition-loaded instance.');
        }
        $occupancy->setRelation('monster', $monster);
    }

    public function movesTaken(int $monsterId): int
    {
        return $this->movesTakenByMonster[$monsterId] ?? 0;
    }

    public function countAction(): void
    {
        $this->metrics['monster_actions']++;
    }

    public function countDefenseSelfDestruct(): void
    {
        $this->metrics['defense_self_destructs']++;
    }

    public function countWaterMove(bool $destructive): void
    {
        $this->metrics['aoi_water_moves'] = ($this->metrics['aoi_water_moves'] ?? 0) + 1;
        if ($destructive) {
            $this->metrics['aoi_destructive_moves'] = ($this->metrics['aoi_destructive_moves'] ?? 0) + 1;
        }
    }

    public function countNuclearSelfDestruct(): void
    {
        $this->metrics['nuclear_self_destructs'] = ($this->metrics['nuclear_self_destructs'] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public function metrics(): array
    {
        return $this->metrics;
    }
}
