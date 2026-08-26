<?php

namespace App\Domain\Economy;

use DomainException;

final class UnderseaCityMaintenancePlanner
{
    /**
     * @param  array<string, mixed>  $ruleset
     * @param  list<int>  $cellIds
     * @return array{
     *     industrial_goods_consumed: int,
     *     minerals_consumed: int,
     *     industrial_goods_remaining: int,
     *     minerals_remaining: int,
     *     settlements: list<array{cell_id: int, paid: bool, industrial_goods_consumed: int, minerals_consumed: int}>
     * }
     */
    public function plan(
        array $ruleset,
        int $industrialGoods,
        int $minerals,
        array $cellIds,
    ): array {
        if ($industrialGoods < 0 || $minerals < 0) {
            throw new DomainException('Undersea-city maintenance balances must be non-negative.');
        }
        $settings = $ruleset['turn_processing']['undersea_city_maintenance'] ?? null;
        if ($settings === null) {
            return [
                'industrial_goods_consumed' => 0,
                'minerals_consumed' => 0,
                'industrial_goods_remaining' => $industrialGoods,
                'minerals_remaining' => $minerals,
                'settlements' => [],
            ];
        }
        if (! is_array($settings)
            || ($settings['facility_key'] ?? null) !== 'undersea_city'
            || ($settings['resource_keys'] ?? null) !== ['industrial_goods', 'minerals']
            || ($settings['base_units_per_resource'] ?? null) !== 1000
            || ($settings['substitution_units_per_shortage'] ?? null) !== 2
            || ($settings['payment_policy'] ?? null) !== 'all_or_nothing'
            || ($settings['settlement_order'] ?? null) !== 'map_cell_id_ascending') {
            throw new DomainException('Published undersea-city maintenance settings are invalid.');
        }

        if (array_filter($cellIds, static fn (int $id): bool => $id < 1) !== []
            || count(array_unique($cellIds)) !== count($cellIds)) {
            throw new DomainException('Undersea-city maintenance cell IDs must be unique positive integers.');
        }
        sort($cellIds, SORT_NUMERIC);

        $startingIndustrialGoods = $industrialGoods;
        $startingMinerals = $minerals;
        $settlements = [];
        foreach ($cellIds as $cellId) {
            [$industrialConsumption, $mineralConsumption] = $this->payment($industrialGoods, $minerals);
            $paid = $industrialConsumption !== null && $mineralConsumption !== null;
            if ($paid) {
                $industrialGoods -= $industrialConsumption;
                $minerals -= $mineralConsumption;
            }
            $settlements[] = [
                'cell_id' => $cellId,
                'paid' => $paid,
                'industrial_goods_consumed' => $industrialConsumption ?? 0,
                'minerals_consumed' => $mineralConsumption ?? 0,
            ];
        }

        return [
            'industrial_goods_consumed' => $startingIndustrialGoods - $industrialGoods,
            'minerals_consumed' => $startingMinerals - $minerals,
            'industrial_goods_remaining' => $industrialGoods,
            'minerals_remaining' => $minerals,
            'settlements' => $settlements,
        ];
    }

    /** @return array{int|null, int|null} */
    private function payment(int $industrialGoods, int $minerals): array
    {
        $base = 1000;
        if ($industrialGoods >= $base && $minerals >= $base) {
            return [$base, $base];
        }
        if ($industrialGoods < $base && $minerals >= $base) {
            $requiredMinerals = $base + (($base - $industrialGoods) * 2);

            return $minerals >= $requiredMinerals
                ? [$industrialGoods, $requiredMinerals]
                : [null, null];
        }
        if ($minerals < $base && $industrialGoods >= $base) {
            $requiredIndustrialGoods = $base + (($base - $minerals) * 2);

            return $industrialGoods >= $requiredIndustrialGoods
                ? [$requiredIndustrialGoods, $minerals]
                : [null, null];
        }

        return [null, null];
    }
}
