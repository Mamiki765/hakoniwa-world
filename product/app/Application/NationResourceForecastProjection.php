<?php

namespace App\Application;

use App\Domain\Economy\NationEconomyCalculator;
use App\Domain\Facility\FacilityCapacityService;
use App\Models\FacilityDefinition;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class NationResourceForecastProjection
{
    public function __construct(
        private readonly NationEconomyCalculator $economy,
        private readonly SecretaryTurnService $secretaries,
        private readonly FacilityCapacityService $facilityCapacities,
    ) {}

    /**
     * @param  Collection<int, NationResource>  $balances
     * @param  array{
     *     total_population: int,
     *     territory_cell_count: int,
     *     owned_land_cells: int,
     *     food_total_tons: int,
     *     farm_capacity_people: int,
     *     factory_capacity_people: int,
     *     mine_capacity_people: int
     * }  $basicStatus
     * @return array{
     *     rows: list<array{key: string, name: string, production: int, consumption: int, delta: int, holding: int}>,
     *     food_holding_note: string,
     *     workforce: array{status: string, label: string, percentage_tenths: int, population: int, demand: int}
     * }
     */
    public function forNation(Nation $nation, Collection $balances, array $basicStatus): array
    {
        $ruleset = $nation->world()->firstOrFail()->rulesetVersion()->firstOrFail();
        $skillLevels = $this->secretaries->currentSkillLevels($nation, $ruleset);
        $oilRules = $ruleset->settings['turn_processing']['oil_field'] ?? null;
        $oilFacilityKey = is_array($oilRules) ? ($oilRules['facility_key'] ?? null) : null;
        if (! is_string($oilFacilityKey)) {
            throw new DomainException('Published ruleset oil-field settings are invalid.');
        }
        $definitions = FacilityDefinition::query()
            ->whereIn('key', ['factory', 'mine', $oilFacilityKey])
            ->get();
        $definitionsByKey = $definitions->keyBy('key');
        $definitionsById = $definitions->keyBy('id');
        $factory = $definitionsByKey->get('factory');
        $mine = $definitionsByKey->get('mine');
        $oilField = $definitionsByKey->get($oilFacilityKey);
        if (! $factory instanceof FacilityDefinition
            || ! $mine instanceof FacilityDefinition
            || ! $oilField instanceof FacilityDefinition) {
            throw new DomainException('Facility catalog is missing an economy projection definition.');
        }
        $industrialIds = [
            (int) $factory->id,
            (int) $mine->id,
        ];
        $industrialFacilities = [];
        $facilityRows = DB::table('map_cells')
            ->where('owner_nation_id', $nation->id)
            ->whereIn('facility_definition_id', $industrialIds)
            ->orderBy('id')
            ->get(['id', 'facility_definition_id', 'facility_scale']);
        foreach ($facilityRows as $row) {
            $definition = $definitionsById->get((int) $row->facility_definition_id);
            if (! $definition instanceof FacilityDefinition || ! is_numeric($row->facility_scale)) {
                throw new DomainException('Facility has incomplete workforce capacity state.');
            }
            $industrialFacilities[] = [
                'cell_id' => (int) $row->id,
                'key' => $definition->key,
                'capacity' => $this->facilityCapacities->capacityPeople($definition, (int) $row->facility_scale),
            ];
        }
        $oilFieldCount = DB::table('map_cells')
            ->where('owner_nation_id', $nation->id)
            ->where('facility_definition_id', $oilField->id)
            ->count();
        $economy = $this->economy->calculate(
            $ruleset->settings,
            $nation->state,
            $basicStatus['total_population'],
            $basicStatus['farm_capacity_people'],
            $industrialFacilities,
            $oilFieldCount,
            $skillLevels,
        );
        $balancesByKey = $balances->keyBy(fn (NationResource $balance): string => $balance->definition->key);

        $wheat = $this->balance($balancesByKey, 'wheat');
        $foodHolding = 0;
        foreach ($balances as $balance) {
            if ($balance->definition->category !== 'food') {
                continue;
            }
            $foodHolding += (int) $balance->amount * $this->nutrition($balance->definition);
        }
        $wheatNutrition = $this->nutrition($wheat->definition);

        $rows = [
            $this->row(
                'food',
                '食料',
                $economy['wheat_production'] * $wheatNutrition,
                $economy['food_consumption'],
                $foodHolding,
            ),
            $this->resourceRow(
                $balancesByKey,
                'industrial_goods',
                $economy['industrial_goods_production'],
            ),
            $this->resourceRow($balancesByKey, 'minerals', $economy['minerals_production']),
            $this->resourceRow($balancesByKey, 'oil', $economy['oil_production']),
        ];
        $population = $economy['population'];
        $demand = $economy['total_workforce_demand'];
        if ($population > $demand) {
            $status = 'unemployment';
            $label = '失業率';
            $percentageTenths = $population === 0
                ? 0
                : (int) round((($population - $demand) / $population) * 1000, 0, PHP_ROUND_HALF_UP);
        } else {
            $status = 'saturation';
            $label = '労働力飽和';
            $percentageTenths = $demand === 0
                ? 0
                : (int) round((($demand - $population) / $demand) * 1000, 0, PHP_ROUND_HALF_UP);
        }

        return [
            'rows' => $rows,
            'food_holding_note' => '食料の所持は小麦換算です。',
            'workforce' => [
                'status' => $status,
                'label' => $label,
                'percentage_tenths' => $percentageTenths,
                'population' => $population,
                'demand' => $demand,
            ],
        ];
    }

    /**
     * @param  Collection<string, NationResource>  $balances
     * @return array{key: string, name: string, production: int, consumption: int, delta: int, holding: int}
     */
    private function resourceRow(Collection $balances, string $key, int $production): array
    {
        $balance = $this->balance($balances, $key);

        return $this->row($key, $balance->definition->name, $production, 0, (int) $balance->amount);
    }

    /** @return array{key: string, name: string, production: int, consumption: int, delta: int, holding: int} */
    private function row(string $key, string $name, int $production, int $consumption, int $holding): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'production' => $production,
            'consumption' => $consumption,
            'delta' => $production - $consumption,
            'holding' => $holding,
        ];
    }

    /** @param Collection<string, NationResource> $balances */
    private function balance(Collection $balances, string $key): NationResource
    {
        $balance = $balances->get($key);
        if (! $balance instanceof NationResource) {
            throw new DomainException("Nation resource {$key} is missing from the owner projection.");
        }

        return $balance;
    }

    private function nutrition(ResourceDefinition $definition): int
    {
        $raw = $definition->getRawOriginal('nutrition_per_unit');
        if (! is_numeric($raw) || (float) $raw < 1 || (float) $raw !== (float) (int) $raw) {
            throw new DomainException("Food resource {$definition->key} has invalid nutrition.");
        }

        return (int) $raw;
    }
}
