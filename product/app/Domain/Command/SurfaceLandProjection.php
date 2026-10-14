<?php

namespace App\Domain\Command;

use App\Models\MapCell;
use Illuminate\Database\Eloquent\Collection;

final class SurfaceLandProjection
{
    /** @var array<string, MapCell> */
    private array $cells = [];

    /** @var array<string, array<int, array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}>> */
    private array $changes = [];

    private int $builtBeforePosition = 1;

    private bool $complete = false;

    /**
     * @param  Collection<int, MapCell>  $cells
     * @param  array<string, true>  $coordinates
     */
    public function __construct(Collection $cells, private readonly array $coordinates)
    {
        foreach ($cells as $cell) {
            $key = $cell->x.':'.$cell->y;
            $this->cells[$key] = $cell;
            $this->changes[$key][0] = [
                'terrain_key' => $cell->terrain->key,
                'facility_key' => $cell->facility?->key,
                'owner_nation_id' => $cell->owner_nation_id,
                'facility_operational_state' => $cell->facility_operational_state,
            ];
        }
    }

    public function cell(int $x, int $y): ?MapCell
    {
        return $this->cells[$x.':'.$y] ?? null;
    }

    public function covers(int $x, int $y): bool
    {
        return isset($this->coordinates[$x.':'.$y]);
    }

    public function canResolve(int $beforePosition): bool
    {
        return $this->complete || $beforePosition <= $this->builtBeforePosition;
    }

    public function advanceTo(int $beforePosition): void
    {
        $this->builtBeforePosition = $beforePosition;
    }

    public function finish(): void
    {
        $this->complete = true;
    }

    /** @return array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}|null */
    public function stateBefore(int $x, int $y, int $beforePosition): ?array
    {
        $state = null;
        foreach ($this->changes[$x.':'.$y] ?? [] as $position => $change) {
            if ($position >= $beforePosition) {
                break;
            }
            $state = $change;
        }

        return $state;
    }

    /** @param array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null} $state */
    public function record(MapCell $cell, int $position, array $state): void
    {
        $this->changes[$cell->x.':'.$cell->y][$position] = $state;
    }
}
