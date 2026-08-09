<?php

namespace App\Application;

use App\Domain\Facility\FacilityCapacityService;
use App\Domain\Map\NationLandAreaCalculator;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\World;
use Illuminate\Database\Eloquent\Collection;

final class NationBasicStatusProjection
{
    /** @var array<string, string> */
    private const FACILITY_STATUS_FIELDS = [
        'farm' => 'farm_capacity_people',
        'factory' => 'factory_capacity_people',
        'mine' => 'mine_capacity_people',
    ];

    public function __construct(
        private readonly NationLandAreaCalculator $landArea,
        private readonly FacilityCapacityService $facilityCapacities,
    ) {}

    /**
     * @return array{
     *     total_population: int,
     *     territory_cell_count: int,
     *     owned_land_cells: int,
     *     food_total_tons: int,
     *     farm_capacity_people: int,
     *     factory_capacity_people: int,
     *     mine_capacity_people: int
     * }
     */
    public function forNation(Nation $nation): array
    {
        $cells = $nation->territoryCells()->with('facility')->get();
        $foodTotals = $this->foodTotals([$nation->id]);

        return $this->project(
            $cells,
            $this->landArea->forNation($nation),
            $foodTotals[$nation->id] ?? 0,
        );
    }

    /**
     * @param  Collection<int, Nation>  $nations
     * @return array<int, array{
     *     total_population: int,
     *     territory_cell_count: int,
     *     owned_land_cells: int,
     *     food_total_tons: int,
     *     farm_capacity_people: int,
     *     factory_capacity_people: int,
     *     mine_capacity_people: int
     * }>
     */
    public function forWorld(World $world, Collection $nations): array
    {
        $nationIds = $nations->modelKeys();
        if ($nationIds === []) {
            return [];
        }

        $cellsByNation = MapCell::query()
            ->whereIn('owner_nation_id', $nationIds)
            ->with('facility')
            ->orderBy('id')
            ->get()
            ->groupBy('owner_nation_id');
        $areas = $this->landArea->forWorld($world);
        $foodTotals = $this->foodTotals($nationIds);
        $projection = [];

        foreach ($nations as $nation) {
            $projection[$nation->id] = $this->project(
                $cellsByNation->get($nation->id, collect()),
                $areas[$nation->id] ?? 0,
                $foodTotals[$nation->id] ?? 0,
            );
        }

        return $projection;
    }

    /**
     * @param  iterable<int, MapCell>  $cells
     * @return array{
     *     total_population: int,
     *     territory_cell_count: int,
     *     owned_land_cells: int,
     *     food_total_tons: int,
     *     farm_capacity_people: int,
     *     factory_capacity_people: int,
     *     mine_capacity_people: int
     * }
     */
    private function project(iterable $cells, int $ownedLandCells, int $foodTotalTons): array
    {
        $status = [
            'total_population' => 0,
            'territory_cell_count' => 0,
            'owned_land_cells' => $ownedLandCells,
            'food_total_tons' => $foodTotalTons,
            'farm_capacity_people' => 0,
            'factory_capacity_people' => 0,
            'mine_capacity_people' => 0,
        ];

        foreach ($cells as $cell) {
            $status['total_population'] += (int) $cell->population;
            $status['territory_cell_count']++;
            $facility = $cell->facility;
            $field = $facility === null ? null : (self::FACILITY_STATUS_FIELDS[$facility->key] ?? null);
            if ($field !== null) {
                $status[$field] += $this->facilityCapacities->capacityPeople(
                    $facility,
                    (int) $cell->facility_scale,
                );
            }
        }

        return $status;
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, int>
     */
    private function foodTotals(array $nationIds): array
    {
        $totals = NationResource::query()
            ->selectRaw('nation_resources.nation_id, SUM(nation_resources.amount) AS food_total_tons')
            ->join(
                'resource_definitions',
                'resource_definitions.id',
                '=',
                'nation_resources.resource_definition_id',
            )
            ->whereIn('nation_resources.nation_id', $nationIds)
            ->where('resource_definitions.category', 'food')
            ->groupBy('nation_resources.nation_id')
            ->pluck('food_total_tons', 'nation_resources.nation_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        /** @var array<int, int> $totals */
        return $totals;
    }
}
