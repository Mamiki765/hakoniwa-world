<?php

namespace App\Domain\Economy;

use App\Domain\Turn\TurnRandomStreamFactory;
use DomainException;

/** Shared, side-effect-free MW settlement for the 4.11 release draft. */
final class PowerEconomyCalculator
{
    /** @param array<string, mixed> $settings */
    public function storageCapacity(array $settings, int $condenserCount): int
    {
        $rules = $this->rules($settings);
        if ($condenserCount < 0) {
            throw new DomainException('Power storage inputs must be non-negative.');
        }

        return $condenserCount * $rules['condenser_capacity_mw'];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<int>  $windCellIds
     */
    public function windGeneration(array $settings, array $windCellIds, TurnRandomStreamFactory $random): int
    {
        $rules = $this->rules($settings);
        $this->assertCellIds($windCellIds);
        $generated = 0;
        foreach ($windCellIds as $cellId) {
            $generated += $random->stream('nation_economy:wind:cell:'.$cellId.':v1')->integer(
                $rules['wind_minimum_mw'],
                $rules['wind_maximum_mw'],
            );
        }

        return $generated;
    }

    /**
     * Oil first, then coal at half output and half oil-equivalent sale value.
     * Inventory restricts the rational operating amount before either rounding draw.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<int>  $scales
     * @return array<string, int>
     */
    public function thermalGeneration(array $settings, array $scales, int $oil, int $minerals, int $oilRoll, int $coalRoll): array
    {
        $rules = $this->rules($settings);
        if (min($oil, $minerals) < 0) {
            throw new DomainException('Thermal fuel inventory must be non-negative.');
        }
        foreach ($scales as $scale) {
            if ($scale < 2 || $scale > 6) {
                throw new DomainException('Thermal scale is outside its release contract.');
            }
        }
        $ticksPerMw = $rules['thermal_coal_tons_per_oil_unit'];
        $oilDenominator = $rules['thermal_oil_mw_per_unit'] * $ticksPerMw;
        $coalDenominator = intdiv($rules['thermal_oil_mw_per_unit'], 2);
        if ($oilRoll < 0 || $oilRoll >= $oilDenominator || $coalRoll < 0 || $coalRoll >= $coalDenominator) {
            throw new DomainException('Thermal fuel rounding roll is outside its denominator.');
        }
        $capacityTicks = array_sum($scales) * $rules['thermal_power_mw_per_scale'] * $ticksPerMw;
        $oilTicks = min($capacityTicks, $oil * $oilDenominator);
        $coalTicks = min(intdiv($capacityTicks - $oilTicks, 2), $minerals * $coalDenominator);
        $generated = intdiv($oilTicks + $coalTicks, $ticksPerMw);
        // Tiny inventories that cannot produce one whole MW never pay for zero output.
        if ($generated === 0) {
            $oilTicks = $coalTicks = 0;
        }

        return [
            'generated_mw' => $generated,
            'oil_consumed' => intdiv($oilTicks, $oilDenominator) + (int) ($oilRoll < $oilTicks % $oilDenominator),
            'minerals_consumed' => intdiv($coalTicks, $coalDenominator) + (int) ($coalRoll < $coalTicks % $coalDenominator),
            'oil_display' => intdiv($oilTicks, $oilDenominator) + (int) ($oilTicks % $oilDenominator > 0),
            'minerals_display' => intdiv($coalTicks, $coalDenominator) + (int) ($coalTicks % $coalDenominator > 0),
        ];
    }

    /** @param array<string, mixed> $settings
     * @param  list<int>  $scales
     * @return array<string, int>
     */
    public function thermalGenerationForTurn(array $settings, array $scales, int $oil, int $minerals, int $nationId, TurnRandomStreamFactory $random): array
    {
        $rules = $this->rules($settings);

        return $this->thermalGeneration($settings, $scales, $oil, $minerals,
            $random->stream('nation_economy:thermal_oil:nation:'.$nationId.':v1')->integer(0, $rules['thermal_oil_mw_per_unit'] * $rules['thermal_coal_tons_per_oil_unit'] - 1),
            $random->stream('nation_economy:thermal_coal:nation:'.$nationId.':v1')->integer(0, intdiv($rules['thermal_oil_mw_per_unit'], 2) - 1),
        );
    }

    /**
     * Round the combined Nation demand once, using its own retry-stable stream.
     * Generation and transfers never become consumption or Secretary experience.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<int>  $pizzeriaScales
     * @return array<string, int>
     */
    public function settleTurn(
        array $settings,
        int $nationId,
        int $storedMw,
        int $generatedMw,
        int $capacityMw,
        int $foodAvailableTons,
        array $pizzeriaScales,
        TurnRandomStreamFactory $random,
        int $energySavingLevel = 0,
    ): array {
        if ($nationId < 1) {
            throw new DomainException('Power settlement needs a Nation identity.');
        }
        $rules = $this->rules($settings);
        [$numerator, $denominator] = $this->demandRatio($settings, $energySavingLevel);
        $roll = $random->stream('nation_economy:power_demand:nation:'.$nationId.':v1')
            ->integer(0, $denominator - 1);

        return $this->settle(
            $settings, $storedMw, $generatedMw, $capacityMw,
            $foodAvailableTons, $pizzeriaScales, $roll, $energySavingLevel,
        );
    }

    /**
     * All amounts are game resource units, with no clock-time conversion.
     * Food input is the existing economy's wheat-equivalent tons, after population
     * consumption. Callers apply this plan atomically with the normal turn transaction.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<int>  $pizzeriaScales
     * @return array<string, int>
     */
    public function settle(
        array $settings,
        int $storedMw,
        int $generatedMw,
        int $capacityMw,
        int $foodAvailableTons,
        array $pizzeriaScales,
        int $roundingRoll,
        int $energySavingLevel = 0,
    ): array {
        $rules = $this->rules($settings);
        if (min($storedMw, $generatedMw, $capacityMw, $foodAvailableTons) < 0) {
            throw new DomainException('Power economy balances must be non-negative.');
        }
        $scaleTotal = 0;
        foreach ($pizzeriaScales as $scale) {
            if ($scale < 1 || $scale > $rules['pizzeria_maximum_scale']) {
                throw new DomainException('Pizzeria scale is outside the release contract.');
            }
            $scaleTotal += $scale;
        }
        [$numerator, $denominator] = $this->demandRatio($settings, $energySavingLevel);
        if ($roundingRoll < 0 || $roundingRoll >= $denominator) {
            throw new DomainException('Power demand rounding roll is outside its denominator.');
        }
        // Direct generation is usable even with zero storage capacity. Only the
        // remainder is capped, after supply has restricted the operating fraction.
        $supplyMw = $storedMw + $generatedMw;
        $foodRequested = min(
            $scaleTotal * $rules['pizzeria_food_tons_per_scale'],
            $foodAvailableTons,
            intdiv($supplyMw * $denominator, $numerator),
        );
        $demandNumerator = $foodRequested * $numerator;
        $consumedMw = intdiv($demandNumerator, $denominator)
            + (int) ($roundingRoll < $demandNumerator % $denominator);
        // A rounded-down charge cannot grant unpowered processing or revenue.
        $foodConsumed = min($foodRequested, intdiv($consumedMw * $denominator, $numerator));
        $remainderMw = $supplyMw - $consumedMw;

        return [
            'generated_mw' => $generatedMw,
            'consumed_mw' => $consumedMw,
            'stored_before_mw' => $storedMw,
            'stored_after_mw' => min($capacityMw, $remainderMw),
            'discarded_mw' => max(0, $remainderMw - $capacityMw),
            'food_consumed_tons' => $foodConsumed,
            'pizzeria_revenue' => intdiv(
                $foodConsumed * $rules['pizzeria_revenue_at_maximum'],
                $rules['pizzeria_maximum_scale'] * $rules['pizzeria_food_tons_per_scale'],
            ),
        ];
    }

    /**
     * Forecast envelopes use the same settlement, including rounding uncertainty.
     * Only wind generation has an authored mean; storage/revenue at mean wind are
     * not presented as expected values because the shortage/cap functions are nonlinear.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<int>  $pizzeriaScales
     * @return array{wind_expected_mw: float, ranges: array<string, array{minimum: int, maximum: int}>}
     */
    public function forecast(
        array $settings,
        int $storedMw,
        int $capacityMw,
        int $foodAvailableTons,
        array $pizzeriaScales,
        int $windCount,
        int $thermalMw = 0,
        int $energySavingLevel = 0,
    ): array {
        $rules = $this->rules($settings);
        if ($windCount < 0) {
            throw new DomainException('Wind count must be non-negative.');
        }
        [$numerator, $denominator] = $this->demandRatio($settings, $energySavingLevel);
        $ranges = [];
        foreach ([$rules['wind_minimum_mw'], $rules['wind_maximum_mw']] as $windMw) {
            foreach ([0, $denominator - 1] as $roll) {
                $plan = $this->settle(
                    $settings, $storedMw, $windCount * $windMw + $thermalMw, $capacityMw,
                    $foodAvailableTons, $pizzeriaScales, $roll, $energySavingLevel,
                );
                foreach ($plan as $key => $amount) {
                    $range = $ranges[$key] ?? ['minimum' => $amount, 'maximum' => $amount];
                    $ranges[$key] = [
                        'minimum' => min($range['minimum'], $amount),
                        'maximum' => max($range['maximum'], $amount),
                    ];
                }
            }
        }

        return [
            'wind_expected_mw' => (float) ($windCount * ($rules['wind_minimum_mw'] + $rules['wind_maximum_mw'])) / 2,
            'ranges' => $ranges,
        ];
    }

    /** Rational food-to-MW ratio; reduce it so existing level-zero rounding stays unchanged.
     * @param  array<string, mixed>  $settings
     * @return array{int, int}
     */
    private function demandRatio(array $settings, int $level): array
    {
        if ($level < 0) {
            throw new DomainException('Energy saving level must be non-negative.');
        }
        $rules = $this->rules($settings);
        $numerator = $rules['pizzeria_power_mw_per_scale'];
        $denominator = $rules['pizzeria_food_tons_per_scale'];
        if ($level > 0) {
            $effect = $settings['secretary']['skills']['energy_saving']['effect'] ?? null;
            if (! is_array($effect)) {
                throw new DomainException('Energy saving effect is missing.');
            }
            $numerator *= $effect['base'] + $effect['numerator_per_level'] * $level;
            $denominator *= $effect['base'] + $effect['denominator_per_level'] * $level;
        }
        $a = $numerator;
        $b = $denominator;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return [intdiv($numerator, $a), intdiv($denominator, $a)];
    }

    /** @param list<int> $cellIds */
    private function assertCellIds(array $cellIds): void
    {
        if (count($cellIds) !== count(array_unique($cellIds))) {
            throw new DomainException('Wind cells must be unique.');
        }
        foreach ($cellIds as $cellId) {
            if ($cellId < 1) {
                throw new DomainException('Wind cell identity must be positive.');
            }
        }
    }

    /** @param array<string, mixed> $settings
     * @return array<string, int>
     */
    private function rules(array $settings): array
    {
        $rules = $settings['power_economy'] ?? null;
        if (! is_array($rules)) {
            throw new DomainException('Power economy draft settings are missing.');
        }
        $keys = [
            'wind_minimum_mw', 'wind_maximum_mw', 'condenser_capacity_mw',
            'thermal_power_mw_per_scale', 'thermal_oil_mw_per_unit', 'thermal_coal_tons_per_oil_unit',
            'pizzeria_food_tons_per_scale', 'pizzeria_power_mw_per_scale',
            'pizzeria_maximum_scale', 'pizzeria_revenue_at_maximum',
        ];
        $validated = [];
        foreach ($keys as $key) {
            if (! is_int($rules[$key] ?? null) || $rules[$key] < 1) {
                throw new DomainException("Power economy setting {$key} must be a positive integer.");
            }
            $validated[$key] = $rules[$key];
        }
        if ($validated['wind_minimum_mw'] > $validated['wind_maximum_mw']
            || $validated['thermal_oil_mw_per_unit'] % 2 !== 0
            || $validated['pizzeria_food_tons_per_scale'] % $validated['pizzeria_power_mw_per_scale'] !== 0) {
            throw new DomainException('Power economy draft ratios are invalid.');
        }

        return $validated;
    }
}
