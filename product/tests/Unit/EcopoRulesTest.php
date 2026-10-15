<?php

namespace Tests\Unit;

use App\Domain\Economy\EcopoRules;
use DomainException;
use Tests\TestCase;

final class EcopoRulesTest extends TestCase
{
    public function test_resource_points_follow_the_same_value_as_the_canonical_sale_batch(): void
    {
        $settings = config('hakoniwa.ruleset');
        $rules = EcopoRules::fromSettings($settings);
        foreach ($settings['inventory_sale_rates'] as $key => $rate) {
            $this->assertSame($rules->moneyPoints($rate['money_units']), $rules->resourcePoints($key, $rate['inventory_units']));
        }
        foreach ($settings['ecopo']['excluded_resource_keys'] as $key) {
            $this->assertSame(0, $rules->resourcePoints($key, 100));
        }
        $settings['inventory_sale_rates']['wheat']['inventory_units'] = $settings['ecopo']['points_per_money'] + 1;
        $this->expectException(DomainException::class);
        EcopoRules::fromSettings($settings);
    }
}
