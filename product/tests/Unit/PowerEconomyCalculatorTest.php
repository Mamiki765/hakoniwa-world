<?php

namespace Tests\Unit;

use App\Domain\Economy\PowerEconomyCalculator;
use App\Domain\Turn\TurnRandomStreamFactory;
use PHPUnit\Framework\TestCase;

class PowerEconomyCalculatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function settings(): array
    {
        // Small synthetic units expose aggregate rounding and cap ordering; these
        // are not a copy of the release's balance values.
        return ['power_economy' => [
            'wind_minimum_mw' => 2, 'wind_maximum_mw' => 4,
            'condenser_capacity_mw' => 7,
            'thermal_power_mw_per_scale' => 100, 'thermal_oil_mw_per_unit' => 60, 'thermal_coal_tons_per_oil_unit' => 1000,
            'pizzeria_food_tons_per_scale' => 30, 'pizzeria_power_mw_per_scale' => 3,
            'pizzeria_maximum_scale' => 3,
            'pizzeria_revenue_at_maximum' => 90, 'pizzeria_maintenance' => 1,
        ]];
    }

    public function test_direct_generation_serves_aggregate_demand_before_storage_and_rounding(): void
    {
        $calculator = new PowerEconomyCalculator;
        $settings = $this->settings();
        // Two shops request 1.5 MW together. Per-shop rounding would charge 2 MW
        // in the down case. Storage capacity zero must not erase direct supply.
        $up = $calculator->settle($settings, 0, 5, 0, 15, [1, 1], 4);
        $down = $calculator->settle($settings, 0, 5, 0, 15, [1, 1], 5);
        $this->assertSame(2, $up['consumed_mw']);
        $this->assertSame(15, $up['food_consumed_tons']);
        $this->assertSame(1, $down['consumed_mw']);
        $this->assertSame(10, $down['food_consumed_tons']);
        $this->assertSame($down['food_consumed_tons'], $down['pizzeria_revenue']);
        $this->assertSame(0, $down['stored_after_mw']);
        $this->assertSame(4, $down['discarded_mw']);

        $shortage = $calculator->settle($settings, 0, 1, 0, 60, [1, 1], 0);
        $this->assertSame(1, $shortage['consumed_mw']);
        $this->assertSame(10, $shortage['food_consumed_tons']);
        $this->assertSame(0, $shortage['discarded_mw']);
        $noCharge = $calculator->settle($settings, 0, 5, 0, 1, [1], 9);
        $this->assertSame(0, $noCharge['consumed_mw']);
        $this->assertSame(0, $noCharge['food_consumed_tons']);
        $this->assertSame(0, $noCharge['pizzeria_revenue']);
    }

    public function test_zero_cash_maintenance_funds_all_shops_without_money_while_legacy_charges_remain_replayable(): void
    {
        $calculator = new PowerEconomyCalculator;
        $settings = $this->settings();
        $this->assertSame(['scales' => [], 'maintenance' => 0, 'unfunded' => 2], $calculator->fundedPizzerias($settings, [1, 2], 0));
        $this->assertSame(['scales' => [1], 'maintenance' => 1, 'unfunded' => 1], $calculator->fundedPizzerias($settings, [1, 2], 1));
        $settings['power_economy']['pizzeria_maintenance'] = 0;
        $funded = $calculator->fundedPizzerias($settings, [1, 2], 0);
        $this->assertSame(['scales' => [1, 2], 'maintenance' => 0, 'unfunded' => 0], $funded);
        $plan = $calculator->settle($settings, 0, 9, 0, 90, $funded['scales'], 0);
        $this->assertSame(0, $plan['pizzeria_maintenance']);
        $this->assertSame(9, $plan['consumed_mw']);
        $this->assertSame(90, $plan['food_consumed_tons']);
        $this->assertSame(90, $plan['pizzeria_revenue']);
    }

    public function test_storage_has_no_free_base_and_conserves_supply_after_capacity_loss(): void
    {
        $calculator = new PowerEconomyCalculator;
        $settings = $this->settings();
        $this->assertSame(0, $calculator->storageCapacity($settings, 0));
        $capacity = $calculator->storageCapacity($settings, 2);
        $this->assertSame(14, $capacity);
        // Previous storage can exceed the new cap after a condenser is lost.
        $plan = $calculator->settle($settings, 20, 40, $capacity, 45, [2], 0);
        $this->assertSame(5, $plan['consumed_mw']);
        $this->assertSame(14, $plan['stored_after_mw']);
        $this->assertSame(41, $plan['discarded_mw']);
        $this->assertSame(
            $plan['stored_before_mw'] + $plan['generated_mw'],
            $plan['consumed_mw'] + $plan['stored_after_mw'] + $plan['discarded_mw'],
        );
        $empty = $calculator->settle($settings, 0, 0, 0, 60, [2], 0);
        $this->assertSame(0, $empty['food_consumed_tons']);
        $this->assertSame(0, $empty['pizzeria_revenue']);
    }

    public function test_retry_isolated_streams_and_forecast_use_the_same_settlement(): void
    {
        $calculator = new PowerEconomyCalculator;
        $settings = $this->settings();
        $random = new TurnRandomStreamFactory(str_repeat('ab', 32));
        $retry = new TurnRandomStreamFactory(str_repeat('ab', 32));
        $retry->stream('unrelated_feature')->integer(0, 99);
        $generation = $calculator->windGeneration($settings, [7, 9], $random);
        $this->assertSame($generation, $calculator->windGeneration($settings, [9, 7], $retry));
        $plan = $calculator->settleTurn($settings, 11, 0, $generation, 2, 15, [1, 2], $random);
        $this->assertSame($plan, $calculator->settleTurn($settings, 11, 0, $generation, 2, 15, [1, 2], $retry));

        $forecast = $calculator->forecast($settings, 0, 2, 15, [1, 2], 2);
        $this->assertSame(6.0, $forecast['wind_expected_mw']);
        foreach ($plan as $key => $amount) {
            $range = $forecast['ranges'][$key];
            $this->assertGreaterThanOrEqual($range['minimum'], $amount);
            $this->assertLessThanOrEqual($range['maximum'], $amount);
        }
        $minimum = $calculator->settle($settings, 0, 4, 2, 15, [1, 2], 9);
        $maximum = $calculator->settle($settings, 0, 8, 2, 15, [1, 2], 0);
        $this->assertSame($minimum['food_consumed_tons'], $forecast['ranges']['food_consumed_tons']['minimum']);
        $this->assertSame($maximum['food_consumed_tons'], $forecast['ranges']['food_consumed_tons']['maximum']);
        $this->assertSame($maximum['stored_after_mw'], $forecast['ranges']['stored_after_mw']['maximum']);
    }

    public function test_thermal_oil_priority_inventory_limits_and_retry_stable_fractional_fuel(): void
    {
        $calculator = new PowerEconomyCalculator;
        $settings = $this->settings();
        $settings['secretary']['skills']['energy_saving']['effect'] = ['base' => 10, 'numerator_per_level' => 2, 'denominator_per_level' => 3];
        $up = $calculator->thermalGeneration($settings, [2], 4, 10000, 0, 0);
        $down = $calculator->thermalGeneration($settings, [2], 4, 10000, 59999, 29);
        $this->assertSame(200, $up['generated_mw']);
        $this->assertSame(4, $up['oil_consumed']);
        $this->assertSame(3, $down['oil_consumed']);
        $this->assertSame(4, $down['oil_display']);
        $this->assertSame(0, $up['minerals_consumed']);
        $coal = $calculator->thermalGeneration($settings, [6], 0, 10000, 0, 0);
        $this->assertSame(300, $coal['generated_mw']);
        $this->assertSame(10000, $coal['minerals_consumed']);
        $mixed = $calculator->thermalGeneration($settings, [2], 2, 10000, 0, 0);
        $this->assertSame(160, $mixed['generated_mw']);
        $this->assertSame(2, $mixed['oil_consumed']);
        $this->assertSame(1334, $mixed['minerals_consumed']);
        $this->assertSame(180, $calculator->thermalGeneration($settings, [2], 3, 0, 0, 0)['generated_mw']);
        $this->assertSame(0, $calculator->thermalGeneration($settings, [6], 0, 0, 0, 0)['generated_mw']);
        $seed = str_repeat('cd', 32);
        $this->assertSame(
            $calculator->thermalGenerationForTurn($settings, [2, 3], 8, 10000, 11, new TurnRandomStreamFactory($seed)),
            $calculator->thermalGenerationForTurn($settings, [3, 2], 8, 10000, 11, new TurnRandomStreamFactory($seed)),
        );
        $saving = $calculator->settle($settings, 0, 5, 0, 60, [2], 0, 100);
        $this->assertSame(5, $saving['consumed_mw']);
        $this->assertGreaterThan(50, $saving['food_consumed_tons']);
        $this->assertSame(0, $saving['stored_after_mw']);
    }
}
