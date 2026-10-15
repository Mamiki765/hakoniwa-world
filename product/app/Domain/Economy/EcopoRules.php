<?php

namespace App\Domain\Economy;

use DomainException;

final readonly class EcopoRules
{
    /** @param array<string, int> $pointsPerResource */
    private function __construct(
        public int $pointsPerMoney,
        public int $pointsPerParadox,
        public string $name,
        public string $unit,
        private array $pointsPerResource,
    ) {}

    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): ?self
    {
        if (! array_key_exists('ecopo', $settings)) {
            return null;
        }
        $rules = $settings['ecopo'];
        if (! is_array($rules)
            || ($rules['behavior'] ?? null) !== 'credit_final_capacity_discard'
            || ($rules['exchange_stage'] ?? null) !== 'finalize_turn_before_lifecycle'
            || ! is_int($rules['points_per_money'] ?? null) || $rules['points_per_money'] < 1
            || ! is_int($rules['points_per_paradox'] ?? null) || $rules['points_per_paradox'] < 1
            || ! is_string($rules['name'] ?? null) || $rules['name'] === ''
            || ! is_string($rules['unit'] ?? null) || $rules['unit'] === ''
            || ! is_array($rules['excluded_resource_keys'] ?? null)
            || ! array_is_list($rules['excluded_resource_keys'])) {
            throw new DomainException('The Ecopo rules are invalid.');
        }
        $points = [];
        foreach ($settings['resource_definitions'] as $resource) {
            $key = $resource['key'];
            if (in_array($key, $rules['excluded_resource_keys'], true)) {
                $points[$key] = 0;

                continue;
            }
            $rate = $settings['inventory_sale_rates'][$key] ?? null;
            if (! is_array($rate) || ! is_int($rate['inventory_units'] ?? null) || $rate['inventory_units'] < 1
                || ! is_int($rate['money_units'] ?? null) || $rate['money_units'] < 1
                || $rate['money_units'] > intdiv(PHP_INT_MAX, $rules['points_per_money'])) {
                throw new DomainException("Ecopo requires an inventory sale rate for {$key}.");
            }
            $batchPoints = $rate['money_units'] * $rules['points_per_money'];
            if ($batchPoints % $rate['inventory_units'] !== 0) {
                throw new DomainException("Ecopo requires integral points per stored unit for {$key}.");
            }
            $points[$key] = intdiv($batchPoints, $rate['inventory_units']);
        }
        foreach ($rules['excluded_resource_keys'] as $key) {
            if (! is_string($key) || ! array_key_exists($key, $points)) {
                throw new DomainException('Ecopo excludes an unknown resource.');
            }
        }

        return new self($rules['points_per_money'], $rules['points_per_paradox'], $rules['name'], $rules['unit'], $points);
    }

    public function resourcePoints(string $key, int $discarded): int
    {
        if (! array_key_exists($key, $this->pointsPerResource)) {
            throw new DomainException('Unknown discarded resource.');
        }

        return $this->multiply($discarded, $this->pointsPerResource[$key]);
    }

    public function moneyPoints(int $discarded): int
    {
        return $this->multiply($discarded, $this->pointsPerMoney);
    }

    private function multiply(int $amount, int $rate): int
    {
        if ($amount < 0 || ($rate > 0 && $amount > intdiv(PHP_INT_MAX, $rate))) {
            throw new DomainException('Invalid Ecopo discard amount.');
        }

        return $amount * $rate;
    }
}
