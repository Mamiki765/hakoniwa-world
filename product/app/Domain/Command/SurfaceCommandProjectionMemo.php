<?php

namespace App\Domain\Command;

use App\Models\MapCell;
use Illuminate\Database\Eloquent\Collection;

final class SurfaceCommandProjectionMemo
{
    /** @var array<string, array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}> */
    private array $states = [];

    /** @var array<string, Collection<int, MapCell>> */
    private array $neighbors = [];

    /** @var array<string, bool>|null */
    private ?array $terrainWater = null;

    /** @var array<string, bool> */
    private array $currentLandDistances = [];

    /** @var array<string, SurfaceLandProjection> */
    private array $landProjections = [];

    public function landProjection(string $key): ?SurfaceLandProjection
    {
        return $this->landProjections[$key] ?? null;
    }

    public function putLandProjection(string $key, SurfaceLandProjection $projection): void
    {
        $this->landProjections[$key] = $projection;
    }

    public function currentLandDistance(string $key): ?bool
    {
        return $this->currentLandDistances[$key] ?? null;
    }

    public function putCurrentLandDistance(string $key, bool $inRange): void
    {
        $this->currentLandDistances[$key] = $inRange;
    }

    /** @return array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null}|null */
    public function get(string $key): ?array
    {
        return $this->states[$key] ?? null;
    }

    /** @param array{terrain_key: string, facility_key: string|null, owner_nation_id: int|null, facility_operational_state: string|null} $state */
    public function put(string $key, array $state): void
    {
        $this->states[$key] = $state;
    }

    /** @return Collection<int, MapCell>|null */
    public function neighbors(string $key): ?Collection
    {
        return $this->neighbors[$key] ?? null;
    }

    /** @param Collection<int, MapCell> $neighbors */
    public function putNeighbors(string $key, Collection $neighbors): void
    {
        $this->neighbors[$key] = $neighbors;
    }

    /** @return array<string, bool>|null */
    public function terrainWater(): ?array
    {
        return $this->terrainWater;
    }

    /** @param array<string, bool> $terrainWater */
    public function putTerrainWater(array $terrainWater): void
    {
        $this->terrainWater = $terrainWater;
    }
}
