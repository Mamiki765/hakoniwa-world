<?php

namespace Tests\Unit;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use DomainException;
use Tests\TestCase;

class ResourceCapacityRulesetContractTest extends TestCase
{
    public function test_production_resource_units_capacities_and_economy_rates_are_validated(): void
    {
        $settings = config('hakoniwa.ruleset');
        $validated = app(RulesetAuthoringValidator::class)->validate($settings);
        $resources = collect($settings['resource_definitions'])->keyBy('key');

        $this->assertSame('hakoniwa-2s-plus-v1', $validated['key']);
        $this->assertSame(['unit', 'ユニット'], [
            $resources['industrial_goods']['unit'], $resources['industrial_goods']['unit_label'],
        ]);
        $this->assertSame(['ton', 'トン'], [
            $resources['minerals']['unit'], $resources['minerals']['unit_label'],
        ]);
        foreach (['wheat', 'fish', 'monster_meat'] as $key) {
            $this->assertSame('ton', $resources[$key]['unit']);
            $this->assertSame('トン', $resources[$key]['unit_label']);
        }
        $this->assertSame(999_900, $settings['base_food_capacity_tons']);
        $this->assertSame([
            'industrial_goods' => 9_999_000,
            'minerals' => 9_999_000,
        ], $settings['resource_capacities']);
        $this->assertSame(['inventory_units' => 1_000, 'money_units' => 1],
            $settings['inventory_sale_rates']['industrial_goods']);
        $this->assertSame(['inventory_units' => 1_000, 'money_units' => 1],
            $settings['inventory_sale_rates']['minerals']);
        $this->assertSame(1, $settings['turn_processing']['workforce']['factory_output_per_worker']);
        $this->assertSame(1, $settings['turn_processing']['workforce']['mine_output_per_worker']);
        $this->assertSame([
            'behavior' => 'sell_stockpile_overflow_then_discard_unsold',
            'applies_after_sale_policy' => true,
            'converts_unsold_to_money' => false,
            'event_type' => 'capacity.overflow',
        ], $settings['resource_capacity_overflow']);

    }

    public function test_resource_capacity_map_rejects_unknown_food_and_invalid_overflow_contracts(): void
    {
        $base = config('hakoniwa.ruleset');
        $cases = [
            'unknown key' => function (array $settings): array {
                $settings['resource_capacities']['unknown'] = 1;

                return $settings;
            },
            'aggregate food replacement' => function (array $settings): array {
                $settings['resource_capacities']['wheat'] = 999_900;

                return $settings;
            },
            'unsold money conversion' => function (array $settings): array {
                $settings['resource_capacity_overflow']['converts_unsold_to_money'] = true;

                return $settings;
            },
            'non-tradable capacity' => function (array $settings): array {
                foreach ($settings['resource_definitions'] as &$definition) {
                    if ($definition['key'] === 'industrial_goods') {
                        $definition['tradable'] = false;
                    }
                }
                unset($definition);

                return $settings;
            },
            'missing overflow contract' => function (array $settings): array {
                unset($settings['resource_capacity_overflow']);

                return $settings;
            },
        ];

        foreach ($cases as $label => $mutate) {
            try {
                app(RulesetAuthoringValidator::class)->validate($mutate($base));
                $this->fail("Invalid production capacity contract was accepted: {$label}");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
