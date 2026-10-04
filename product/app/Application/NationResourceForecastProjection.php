<?php

namespace App\Application;

use App\Application\Underground\UndergroundFacilityBenefits;
use App\Domain\Economy\FoodConsumptionPlanner;
use App\Domain\Economy\NationCapacities;
use App\Domain\Economy\NationEconomyCalculator;
use App\Domain\Economy\PowerEconomyCalculator;
use App\Domain\Economy\UnderseaCityMaintenancePlanner;
use App\Domain\Facility\FacilityCapacityService;
use App\Domain\Facility\FacilityRankPolicy;
use App\Domain\Nation\NationLifecyclePrepareStateResolver;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Models\FacilityDefinition;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\Ship;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class NationResourceForecastProjection
{
    public function __construct(
        private readonly NationEconomyCalculator $economy,
        private readonly UnderseaCityMaintenancePlanner $underseaCityMaintenance,
        private readonly SecretaryTurnService $secretaries,
        private readonly FacilityCapacityService $facilityCapacities,
        private readonly FacilityRankPolicy $facilityRanks,
        private readonly NationLifecyclePrepareStateResolver $prepareState,
        private readonly UndergroundFacilityBenefits $undergroundBenefits,
        private readonly NationQueuedMeaningfulActivityQuery $meaningfulActivity,
        private readonly SurfaceShipCatalog $surfaceShips,
        private readonly PowerEconomyCalculator $power,
        private readonly FoodConsumptionPlanner $food,
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
     *     rows: list<array<string, mixed>>,
     *     food_holding_note: string,
     *     power_summary: array<string, mixed>|null,
     *     workforce: array{status: string, label: string, percentage_tenths: int, population: int, demand: int}
     * }
     */
    public function forNation(Nation $nation, Collection $balances, array $basicStatus, NationCapacities $capacities): array
    {
        $world = $nation->world()->firstOrFail();
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $skillLevels = $this->secretaries->currentSkillLevels($nation, $ruleset);
        $effectiveNationState = $this->effectiveNationState(
            $nation,
            $ruleset->settings,
            (int) $world->current_turn + 1,
        );
        $oilRules = $ruleset->settings['turn_processing']['oil_field'] ?? null;
        $oilFacilityKey = is_array($oilRules) ? ($oilRules['facility_key'] ?? null) : null;
        if (! is_string($oilFacilityKey)) {
            throw new DomainException('Published ruleset oil-field settings are invalid.');
        }
        $maintenanceRules = $ruleset->settings['turn_processing']['undersea_city_maintenance'] ?? null;
        $underseaCityFacilityKey = is_array($maintenanceRules)
            ? ($maintenanceRules['facility_key'] ?? null)
            : null;
        if ($maintenanceRules !== null && $underseaCityFacilityKey !== 'undersea_city') {
            throw new DomainException('Published undersea-city maintenance settings are invalid.');
        }
        $hasPower = isset($ruleset->settings['power_economy']);
        $facilityKeys = array_values(array_unique(array_filter([
            'factory', 'mine', $oilFacilityKey, $underseaCityFacilityKey,
            ...($hasPower ? ['wind_power', 'pizzeria', 'thermal_power'] : []),
        ], 'is_string')));
        $definitions = FacilityDefinition::query()
            ->whereIn('key', $facilityKeys)
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
        $underseaCity = $underseaCityFacilityKey === null
            ? null
            : $definitionsByKey->get($underseaCityFacilityKey);
        if ($underseaCityFacilityKey !== null && ! $underseaCity instanceof FacilityDefinition) {
            throw new DomainException('Facility catalog is missing the undersea-city projection definition.');
        }
        $industrialIds = [
            (int) $factory->id,
            (int) $mine->id,
        ];
        $industrialFacilities = [];
        $underseaCityCellIds = [];
        $oilFieldCount = 0;
        $windCount = 0;
        $pizzeriaScales = [];
        $thermalScales = [];
        $projectedFacilityIds = array_values(array_unique(array_filter([
            ...$industrialIds,
            (int) $oilField->id,
            $underseaCity instanceof FacilityDefinition ? (int) $underseaCity->id : null,
            ...($hasPower ? $definitions->whereIn('key', ['wind_power', 'pizzeria', 'thermal_power'])->modelKeys() : []),
        ], 'is_int')));
        $facilityRows = DB::table('map_cells')
            ->where('owner_nation_id', $nation->id)
            ->whereIn('facility_definition_id', $projectedFacilityIds)
            ->orderBy('id')
            ->get(['id', 'facility_definition_id', 'facility_scale', 'facility_operational_state']);
        foreach ($facilityRows as $row) {
            $definition = $definitionsById->get((int) $row->facility_definition_id);
            if (! $definition instanceof FacilityDefinition) {
                throw new DomainException('Facility catalog is missing an economy projection definition.');
            }
            if ($definition->id === $oilField->id) {
                $oilFieldCount++;

                continue;
            }
            if ($definition->key === 'wind_power') {
                $windCount += (int) ($row->facility_operational_state !== 'damaged');

                continue;
            }
            if ($definition->key === 'pizzeria') {
                if (! is_numeric($row->facility_scale)) {
                    throw new DomainException('Pizzeria scale is missing from the forecast.');
                }
                $pizzeriaScales[] = (int) $row->facility_scale;

                continue;
            }
            if ($definition->key === 'thermal_power') {
                if (! is_numeric($row->facility_scale)) {
                    throw new DomainException('Thermal scale is missing from the forecast.');
                }
                $thermalScales[] = (int) $row->facility_scale;

                continue;
            }
            if ($underseaCity instanceof FacilityDefinition && $definition->id === $underseaCity->id) {
                $underseaCityCellIds[] = (int) $row->id;

                continue;
            }
            if (! is_numeric($row->facility_scale)) {
                throw new DomainException('Facility has incomplete workforce capacity state.');
            }
            $industrialFacilities[] = [
                'cell_id' => (int) $row->id,
                'key' => $definition->key,
                'capacity' => $this->facilityCapacities->capacityPeople(
                    $definition,
                    (int) $row->facility_scale,
                    $this->facilityRanks->maximumScale($ruleset->settings, $definition),
                ),
            ];
        }
        foreach ($this->undergroundBenefits->factoryFacilities($nation->id) as $facility) {
            $industrialFacilities[] = [
                'cell_id' => $facility['id'],
                'source_key' => 'underground:'.$facility['id'],
                'key' => 'factory',
                'capacity' => $this->undergroundBenefits->effectValue(
                    $facility,
                    'factory_capacity_people',
                ),
            ];
        }
        $economy = $this->economy->calculate(
            $ruleset->settings,
            $effectiveNationState,
            $basicStatus['total_population'],
            $basicStatus['farm_capacity_people'],
            $industrialFacilities,
            $oilFieldCount,
            $skillLevels,
        );
        $balancesByKey = $balances->keyBy(fn (NationResource $balance): string => $balance->definition->key);
        $maintenance = $this->underseaCityMaintenance->plan(
            $ruleset->settings,
            (int) $this->balance($balancesByKey, 'industrial_goods')->amount
                + $economy['industrial_goods_production'],
            (int) $this->balance($balancesByKey, 'minerals')->amount
                + $economy['minerals_production'],
            in_array($effectiveNationState, ['active', 'recovery'], true)
                ? $underseaCityCellIds
                : [],
        );
        $shipOilConsumption = $effectiveNationState === 'active'
            ? $this->shipOilConsumption($nation, $ruleset->settings)
            : 0;

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
                $maintenance['industrial_goods_consumed'],
            ),
            $this->resourceRow(
                $balancesByKey,
                'minerals',
                $economy['minerals_production'],
                $maintenance['minerals_consumed'],
            ),
            $this->resourceRow($balancesByKey, 'oil', $economy['oil_production'], $shipOilConsumption),
        ];
        $powerSummary = null;
        if ($hasPower) {
            $foods = [];
            foreach ($ruleset->settings['turn_processing']['food']['consumption_priority'] as $key) {
                $balance = $this->balance($balancesByKey, $key);
                $foods[] = [
                    'resource_key' => $key,
                    'amount' => (int) $balance->amount + ($key === 'wheat' ? $economy['wheat_production'] : 0),
                    'nutrition' => $this->nutrition($balance->definition),
                ];
            }
            $populationFood = $this->food->plan($foods, $economy['food_consumption']);
            $remainingFoods = array_map(static fn (array $row): array => [
                'resource_key' => $row['resource_key'], 'amount' => $row['after'], 'nutrition' => $row['nutrition_per_unit'],
            ], $populationFood['resources']);
            $remainingNutrition = array_sum(array_map(static fn (array $row): int => $row['amount'] * $row['nutrition'], $remainingFoods));
            $enabled = in_array($effectiveNationState, ['active', 'recovery'], true);
            $storedMw = (int) $this->balance($balancesByKey, 'power')->amount;
            $capacityMw = $capacities->resources['power'];
            // Oil fields produce later in process_cells, after this settlement.
            $fuelOil = (int) $this->balance($balancesByKey, 'oil')->amount;
            $fuelMinerals = (int) $this->balance($balancesByKey, 'minerals')->amount + $economy['minerals_production'] - $maintenance['minerals_consumed'];
            $powerRules = $ruleset->settings['power_economy'];
            $thermalMinimum = $this->power->thermalGeneration($ruleset->settings, $enabled ? $thermalScales : [], $fuelOil, $fuelMinerals,
                $powerRules['thermal_oil_mw_per_unit'] * $powerRules['thermal_coal_tons_per_oil_unit'] - 1, intdiv($powerRules['thermal_oil_mw_per_unit'], 2) - 1);
            $thermalMaximum = $this->power->thermalGeneration($ruleset->settings, $enabled ? $thermalScales : [], $fuelOil, $fuelMinerals, 0, 0);
            $forecast = $this->power->forecast(
                $ruleset->settings, $storedMw, $capacityMw, $remainingNutrition,
                $enabled ? $pizzeriaScales : [], $enabled ? $windCount : 0,
                $thermalMinimum['generated_mw'], $skillLevels['energy_saving'] ?? 0,
            );
            $ranges = $forecast['ranges'];
            $foodMinimum = $this->food->plan($remainingFoods, $ranges['food_consumed_tons']['minimum'])['supplied_nutrition'];
            $foodMaximum = $this->food->plan($remainingFoods, $ranges['food_consumed_tons']['maximum'])['supplied_nutrition'];
            $rows[0]['consumption_range'] = [
                'minimum' => $economy['food_consumption'] + $foodMinimum,
                'maximum' => $economy['food_consumption'] + $foodMaximum,
            ];
            $rows[0]['delta_range'] = [
                'minimum' => $rows[0]['production'] - $rows[0]['consumption_range']['maximum'],
                'maximum' => $rows[0]['production'] - $rows[0]['consumption_range']['minimum'],
            ];
            $rows[] = [
                'key' => 'power', 'name' => '電力', 'unit_label' => 'MW',
                'production' => $ranges['generated_mw']['minimum'],
                'consumption' => $ranges['consumed_mw']['minimum'],
                'delta' => $ranges['stored_after_mw']['minimum'] - $storedMw,
                'holding' => $storedMw,
                'production_range' => $ranges['generated_mw'], 'consumption_range' => $ranges['consumed_mw'],
                'delta_range' => [
                    'minimum' => $ranges['stored_after_mw']['minimum'] - $storedMw,
                    'maximum' => $ranges['stored_after_mw']['maximum'] - $storedMw,
                ],
            ];
            $powerSummary = [
                'wind_expected_mw' => $forecast['wind_expected_mw'], 'capacity_mw' => $capacityMw,
                'stored_after_mw' => $ranges['stored_after_mw'], 'discarded_mw' => $ranges['discarded_mw'],
                'pizzeria_revenue' => $ranges['pizzeria_revenue'],
                'thermal_generated_mw' => $thermalMinimum['generated_mw'],
                'thermal_oil_display' => $thermalMaximum['oil_display'],
                'thermal_minerals_display' => $thermalMaximum['minerals_display'],
            ];
            foreach (['oil' => 3, 'minerals' => 2] as $key => $rowIndex) {
                $rows[$rowIndex]['consumption_range'] = [
                    'minimum' => $rows[$rowIndex]['consumption'] + $thermalMinimum[$key.'_consumed'],
                    'maximum' => $rows[$rowIndex]['consumption'] + $thermalMaximum[$key.'_consumed'],
                ];
                $rows[$rowIndex]['delta_range'] = [
                    'minimum' => $rows[$rowIndex]['production'] - $rows[$rowIndex]['consumption_range']['maximum'],
                    'maximum' => $rows[$rowIndex]['production'] - $rows[$rowIndex]['consumption_range']['minimum'],
                ];
            }
        }
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
            'power_summary' => $powerSummary,
            'workforce' => [
                'status' => $status,
                'label' => $label,
                'percentage_tenths' => $percentageTenths,
                'population' => $population,
                'demand' => $demand,
            ],
        ];
    }

    /** @param array<string, mixed> $settings */
    private function effectiveNationState(Nation $nation, array $settings, int $targetTurn): string
    {
        $lifecycle = $settings['nation_lifecycle'] ?? null;
        $financeKey = is_array($lifecycle) ? ($lifecycle['finance_command_key'] ?? null) : null;
        $dormantIdleThreshold = is_array($lifecycle) ? ($lifecycle['dormant_idle_threshold'] ?? null) : null;
        if (! is_string($financeKey) || ! is_int($dormantIdleThreshold)) {
            throw new DomainException('Published ruleset Nation lifecycle settings are invalid.');
        }

        $resumeDue = $nation->resume_at_turn !== null && $targetTurn >= $nation->resume_at_turn;
        $needsQueueProjection = ($nation->state === 'recovery' && $resumeDue)
            || ($nation->state === 'dormant' && $nation->state_reason !== 'manual');
        $hasQueuedNonFinanceCommand = $needsQueueProjection
            && $this->meaningfulActivity->exists($nation, $financeKey);

        return $this->prepareState->resolve(
            $nation->state,
            $nation->state_reason,
            $nation->resume_at_turn,
            (int) $nation->idle_counter,
            $targetTurn,
            $hasQueuedNonFinanceCommand,
            $dormantIdleThreshold,
        );
    }

    /**
     * @param  Collection<string, NationResource>  $balances
     * @return array{key: string, name: string, production: int, consumption: int, delta: int, holding: int}
     */
    private function resourceRow(
        Collection $balances,
        string $key,
        int $production,
        int $consumption = 0,
    ): array {
        $balance = $this->balance($balances, $key);

        return $this->row($key, $balance->definition->name, $production, $consumption, (int) $balance->amount);
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

    /** @param array<string, mixed> $settings */
    private function shipOilConsumption(Nation $nation, array $settings): int
    {
        $counts = Ship::query()
            ->where('world_id', $nation->world_id)
            ->where('nation_id', $nation->id)
            ->where('state', Ship::STATE_ACTIVE)
            ->selectRaw('ship_type_key, COUNT(*) AS ship_count')
            ->groupBy('ship_type_key')
            ->get();
        if ($counts->isEmpty()) {
            return 0;
        }

        $oilByShipType = [];
        foreach ($this->surfaceShips->definitions($settings) as $definition) {
            $oilByShipType[$definition->key] = $definition->movementOilUnits;
        }

        $consumption = 0;
        foreach ($counts as $count) {
            $shipTypeKey = $count->ship_type_key;
            if (! array_key_exists($shipTypeKey, $oilByShipType)) {
                throw new DomainException('Active Surface Ship has an unavailable definition.');
            }
            $consumption += (int) $count->getAttribute('ship_count') * $oilByShipType[$shipTypeKey];
        }

        return $consumption;
    }
}
