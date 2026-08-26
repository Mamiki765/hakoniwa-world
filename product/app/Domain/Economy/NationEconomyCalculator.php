<?php

namespace App\Domain\Economy;

use App\Domain\Secretary\SecretaryProductionBonus;
use App\Domain\Secretary\SecretarySkillCatalog;
use DomainException;

final class NationEconomyCalculator
{
    public function __construct(
        private readonly SecretaryProductionBonus $secretaryProduction,
    ) {}

    /**
     * This calculator is intentionally side-effect-free. Callers own all current-state
     * reads and pass only the scalar projections needed by the canonical economy formula.
     *
     * @param  array<string, mixed>  $ruleset
     * @param  list<array{cell_id: int, key: string, capacity: int}>  $industrialFacilities
     * @param  array<string, int>  $secretarySkillLevels
     * @return array{
     *     population: int,
     *     farm_capacity: int,
     *     factory_capacity: int,
     *     mine_capacity: int,
     *     total_workforce_demand: int,
     *     farm_workers: int,
     *     factory_workers: int,
     *     mine_workers: int,
     *     wheat_production: int,
     *     industrial_goods_production: int,
     *     minerals_production: int,
     *     oil_production: int,
     *     food_consumption: int
     * }
     */
    public function calculate(
        array $ruleset,
        string $nationState,
        int $population,
        int $farmCapacity,
        array $industrialFacilities,
        int $oilFieldCount,
        array $secretarySkillLevels = [],
    ): array {
        $turn = $ruleset['turn_processing'] ?? null;
        $workforceRules = is_array($turn) ? ($turn['workforce'] ?? null) : null;
        $foodRules = is_array($turn) ? ($turn['food'] ?? null) : null;
        $oilRules = is_array($turn) ? ($turn['oil_field'] ?? null) : null;
        if (! is_array($workforceRules)
            || ($workforceRules['priority'] ?? null) !== ['farm', 'factory_mine']
            || ($workforceRules['allocation_rule'] ?? null) !== 'capacity_proportional_largest_remainder'
            || ! is_int($workforceRules['farm_output_per_worker'] ?? null)
            || ! is_int($workforceRules['factory_output_per_worker'] ?? null)
            || ! is_int($workforceRules['mine_output_per_worker'] ?? null)
            || ! is_array($foodRules)
            || ! is_int($foodRules['population_per_nutrition'] ?? null)
            || $foodRules['population_per_nutrition'] < 1
            || ! is_array($oilRules)
            || ! is_string($oilRules['facility_key'] ?? null)
            || ! is_int($oilRules['production_units'] ?? null)
            || $oilRules['production_units'] < 1) {
            throw new DomainException('Published ruleset economy settings are invalid.');
        }
        if ($population < 0 || $farmCapacity < 0 || $oilFieldCount < 0) {
            throw new DomainException('Economy projection inputs must be non-negative integers.');
        }

        $factoryCapacity = 0;
        $mineCapacity = 0;
        $seenCellIds = [];
        foreach ($industrialFacilities as $facility) {
            $cellId = $facility['cell_id'];
            $key = $facility['key'];
            $capacity = $facility['capacity'];
            if ($cellId < 1 || isset($seenCellIds[$cellId])
                || ! in_array($key, ['factory', 'mine'], true) || $capacity < 0) {
                throw new DomainException('Industrial workforce projection inputs are invalid.');
            }
            $seenCellIds[$cellId] = true;
            if ($key === 'factory') {
                $factoryCapacity += $capacity;
            } else {
                $mineCapacity += $capacity;
            }
        }

        $enabled = in_array($nationState, ['active', 'recovery'], true);
        $farmWorkers = $enabled ? min($population, $farmCapacity) : 0;
        $allocation = $enabled
            ? $this->allocateFactoryAndMineWorkers($industrialFacilities, max(0, $population - $farmWorkers))
            : ['factory' => 0, 'mine' => 0];
        $wheatProduction = $farmWorkers * $workforceRules['farm_output_per_worker'];
        $industrialProduction = $allocation['factory'] * $workforceRules['factory_output_per_worker'];
        $mineralProduction = $allocation['mine'] * $workforceRules['mine_output_per_worker'];
        if ($enabled && isset($ruleset['secretary'])) {
            $wheatProduction = $this->secretaryProduction->apply(
                $ruleset,
                SecretarySkillCatalog::AGRICULTURAL_POLICY,
                $secretarySkillLevels[SecretarySkillCatalog::AGRICULTURAL_POLICY] ?? 0,
                $wheatProduction,
            );
            $industrialProduction = $this->secretaryProduction->apply(
                $ruleset,
                SecretarySkillCatalog::SPECIALTY_DEVELOPMENT,
                $secretarySkillLevels[SecretarySkillCatalog::SPECIALTY_DEVELOPMENT] ?? 0,
                $industrialProduction,
            );
            $mineralProduction = $this->secretaryProduction->apply(
                $ruleset,
                SecretarySkillCatalog::GOLD_VEIN_SURVEY,
                $secretarySkillLevels[SecretarySkillCatalog::GOLD_VEIN_SURVEY] ?? 0,
                $mineralProduction,
            );
        }

        return [
            'population' => $population,
            'farm_capacity' => $farmCapacity,
            'factory_capacity' => $factoryCapacity,
            'mine_capacity' => $mineCapacity,
            'total_workforce_demand' => $farmCapacity + $factoryCapacity + $mineCapacity,
            'farm_workers' => $farmWorkers,
            'factory_workers' => $allocation['factory'],
            'mine_workers' => $allocation['mine'],
            'wheat_production' => $wheatProduction,
            'industrial_goods_production' => $industrialProduction,
            'minerals_production' => $mineralProduction,
            'oil_production' => $enabled ? $oilFieldCount * $oilRules['production_units'] : 0,
            'food_consumption' => $enabled ? intdiv($population, $foodRules['population_per_nutrition']) : 0,
        ];
    }

    /**
     * @param  list<array{cell_id: int, key: string, capacity: int}>  $facilities
     * @return array{factory: int, mine: int}
     */
    private function allocateFactoryAndMineWorkers(array $facilities, int $availableWorkers): array
    {
        $totalCapacity = array_sum(array_column($facilities, 'capacity'));
        $workers = min($availableWorkers, $totalCapacity);
        if ($workers === 0 || $totalCapacity === 0) {
            return ['factory' => 0, 'mine' => 0];
        }
        $allocated = 0;
        $allocations = [];
        foreach ($facilities as $facility) {
            $weighted = $workers * $facility['capacity'];
            $facility['workers'] = intdiv($weighted, $totalCapacity);
            $facility['remainder'] = $weighted % $totalCapacity;
            $allocated += $facility['workers'];
            $allocations[] = $facility;
        }
        usort($allocations, static function (array $left, array $right): int {
            return $right['remainder'] <=> $left['remainder']
                ?: $left['key'] <=> $right['key']
                ?: $left['cell_id'] <=> $right['cell_id'];
        });
        for ($index = 0; $index < $workers - $allocated; $index++) {
            $allocations[$index]['workers']++;
        }
        $result = ['factory' => 0, 'mine' => 0];
        foreach ($allocations as $facility) {
            $result[$facility['key']] += $facility['workers'];
        }

        return $result;
    }
}
