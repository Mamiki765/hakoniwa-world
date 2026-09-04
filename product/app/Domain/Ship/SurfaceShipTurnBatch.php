<?php

namespace App\Domain\Ship;

use App\Models\Ship;
use InvalidArgumentException;

final class SurfaceShipTurnBatch
{
    /** @var array<int, Ship> */
    private array $shipsByCellId = [];

    /** @var array<int, true> */
    private array $portNationIds;

    /** @var array<string, int> */
    private array $metrics = [
        'ships_loaded' => 0,
        'ship_events' => 0,
        'ship_moves' => 0,
        'ship_no_port' => 0,
        'ship_blocked' => 0,
        'ship_fuel_shortages' => 0,
        'ship_fuel_damage' => 0,
        'ship_fuel_sunk' => 0,
        'ship_oil_consumed' => 0,
        'ship_fish_applied' => 0,
        'ship_money_applied' => 0,
        'ship_secretary_experience' => 0,
    ];

    /**
     * @param  iterable<Ship>  $ships
     * @param  list<int>  $portNationIds
     */
    public function __construct(iterable $ships, array $portNationIds)
    {
        $this->portNationIds = array_fill_keys($portNationIds, true);
        foreach ($ships as $ship) {
            if ($ship->map_cell_id === null || isset($this->shipsByCellId[$ship->map_cell_id])) {
                throw new InvalidArgumentException('Ship turn batch contains invalid or duplicate occupancy.');
            }
            $this->shipsByCellId[$ship->map_cell_id] = $ship;
            $this->metrics['ships_loaded']++;
        }
    }

    public function shipAt(int $cellId): ?Ship
    {
        return $this->shipsByCellId[$cellId] ?? null;
    }

    public function hasPort(int $nationId): bool
    {
        return isset($this->portNationIds[$nationId]);
    }

    public function move(Ship $ship, int $fromCellId, int $toCellId): void
    {
        if (($this->shipsByCellId[$fromCellId] ?? null)?->id !== $ship->id
            || isset($this->shipsByCellId[$toCellId])) {
            throw new InvalidArgumentException('Ship movement would desynchronize turn-local occupancy.');
        }
        unset($this->shipsByCellId[$fromCellId]);
        $this->shipsByCellId[$toCellId] = $ship;
    }

    public function forget(Ship $ship, int $cellId): void
    {
        if (($this->shipsByCellId[$cellId] ?? null)?->id === $ship->id) {
            unset($this->shipsByCellId[$cellId]);
        }
    }

    public function count(string $metric, int $amount = 1): void
    {
        if (! array_key_exists($metric, $this->metrics) || $amount < 0) {
            throw new InvalidArgumentException('Unknown Ship metric or invalid increment.');
        }
        $this->metrics[$metric] += $amount;
    }

    /** @return array<string, int> */
    public function metrics(): array
    {
        return $this->metrics;
    }
}
