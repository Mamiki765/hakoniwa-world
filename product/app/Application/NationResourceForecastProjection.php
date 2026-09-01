<?php

namespace App\Application;

use App\Application\Underground\UndergroundFacilityBenefits;
use App\Domain\Economy\NationEconomyCalculator;
use App\Domain\Economy\UnderseaCityMaintenancePlanner;
use App\Domain\Facility\FacilityCapacityService;
use App\Domain\Nation\NationLifecyclePrepareStateResolver;
use App\Models\FacilityDefinition;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\NationUndergroundFacility;
use App\Models\ResourceDefinition;
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
        private readonly NationLifecyclePrepareStateResolver $prepareState,
        private readonly UndergroundFacilityBenefits $undergroundBenefits,
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
        $facilityKeys = array_values(array_unique(array_filter([
            'factory', 'mine', $oilFacilityKey, $underseaCityFacilityKey,
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
        $projectedFacilityIds = array_values(array_unique(array_filter([
            ...$industrialIds,
            (int) $oilField->id,
            $underseaCity instanceof FacilityDefinition ? (int) $underseaCity->id : null,
        ], 'is_int')));
        $facilityRows = DB::table('map_cells')
            ->where('owner_nation_id', $nation->id)
            ->whereIn('facility_definition_id', $projectedFacilityIds)
            ->orderBy('id')
            ->get(['id', 'facility_definition_id', 'facility_scale']);
        foreach ($facilityRows as $row) {
            $definition = $definitionsById->get((int) $row->facility_definition_id);
            if (! $definition instanceof FacilityDefinition) {
                throw new DomainException('Facility catalog is missing an economy projection definition.');
            }
            if ($definition->id === $oilField->id) {
                $oilFieldCount++;

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
                'capacity' => $this->facilityCapacities->capacityPeople($definition, (int) $row->facility_scale),
            ];
        }
        $undergroundFactoryCapacity = $this->undergroundBenefits->factoryCapacityPerFacility();
        foreach (NationUndergroundFacility::query()
            ->where('nation_id', $nation->id)
            ->where('facility_key', 'underground_factory')
            ->orderBy('id')->get(['id']) as $facility) {
            $industrialFacilities[] = [
                'cell_id' => (int) $facility->id,
                'source_key' => 'underground:'.(int) $facility->id,
                'key' => 'factory',
                'capacity' => $undergroundFactoryCapacity,
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
        $hasQueuedNonFinanceCommand = $needsQueueProjection && DB::table('nation_command_queue_items as item')
            ->join('nation_command_queues as queue', 'queue.id', '=', 'item.nation_command_queue_id')
            ->join('command_definitions as definition', 'definition.id', '=', 'item.command_definition_id')
            ->where('queue.nation_id', $nation->id)
            ->where('item.status', 'queued')
            ->where('definition.key', '<>', $financeKey)
            ->exists();

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
}
