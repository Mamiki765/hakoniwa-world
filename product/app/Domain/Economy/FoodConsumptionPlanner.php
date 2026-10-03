<?php

namespace App\Domain\Economy;

use DomainException;

final class FoodConsumptionPlanner
{
    /**
     * @param  list<array{resource_key: string, amount: int, nutrition: int}>  $foods  In consumption priority order.
     * @return array{required_nutrition: int, resources: list<array{resource_key: string, before: int, consumed_units: int, nutrition_per_unit: int, supplied_nutrition: int, after: int}>, supplied_nutrition: int, shortage: int, famine: bool}
     */
    public function plan(array $foods, int $requiredNutrition): array
    {
        if ($requiredNutrition < 0) {
            throw new DomainException('Required nutrition must be non-negative.');
        }
        $remaining = $requiredNutrition;
        $total = 0;
        $rows = [];
        foreach ($foods as $food) {
            $nutrition = $food['nutrition'];
            $before = $food['amount'];
            if ($nutrition < 1 || $before < 0) {
                throw new DomainException('Food consumption inputs are invalid.');
            }
            $units = min($before, $remaining === 0 ? 0 : intdiv($remaining + $nutrition - 1, $nutrition));
            $supplied = $units * $nutrition;
            $remaining = max(0, $remaining - $supplied);
            $total += $supplied;
            $rows[] = [
                'resource_key' => $food['resource_key'], 'before' => $before, 'consumed_units' => $units,
                'nutrition_per_unit' => $nutrition, 'supplied_nutrition' => $supplied, 'after' => $before - $units,
            ];
        }

        return [
            'required_nutrition' => $requiredNutrition, 'resources' => $rows,
            'supplied_nutrition' => $total, 'shortage' => $remaining, 'famine' => $remaining > 0,
        ];
    }
}
