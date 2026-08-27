<?php

namespace Tests\Unit;

use App\Domain\Economy\CappedAddition;
use App\Domain\Economy\InventorySalePlanner;
use App\Domain\Economy\UnderseaCityMaintenancePlanner;
use DomainException;
use PHPUnit\Framework\TestCase;

class EconomyBoundaryTest extends TestCase
{
    public function test_money_credit_is_integer_capped_and_reports_overflow(): void
    {
        $addition = new CappedAddition;

        $this->assertSame([
            'before' => 9_998,
            'requested' => 1,
            'applied' => 1,
            'overflow' => 0,
            'after' => 9_999,
            'capacity' => 9_999,
        ], $addition->calculate(9_998, 1, 9_999)->toArray());
        $this->assertSame(1, $addition->calculate(9_998, 10, 9_999)->applied);
        $this->assertSame(9, $addition->calculate(9_998, 10, 9_999)->overflow);
        $this->assertSame(0, $addition->calculate(9_999, 1, 9_999)->applied);
        $this->assertSame(1, $addition->calculate(9_999, 1, 9_999)->overflow);
    }

    public function test_negative_credit_is_not_a_payment_operation(): void
    {
        $this->expectException(DomainException::class);

        (new CappedAddition)->calculate(100, -1, 9_999);
    }

    public function test_inventory_sale_keeps_sub_billion_and_capacity_blocked_inventory(): void
    {
        $planner = new InventorySalePlanner(new CappedAddition);

        $normal = $planner->plan(2_750, 100, 9_999);
        $this->assertSame(2_000, $normal->inventorySold);
        $this->assertSame(750, $normal->inventoryRemaining);
        $this->assertSame(2, $normal->appliedMoney);

        $capacityBound = $planner->plan(3_500, 9_998, 9_999);
        $this->assertSame(1_000, $capacityBound->inventorySold);
        $this->assertSame(2_500, $capacityBound->inventoryRemaining);
        $this->assertSame(1, $capacityBound->appliedMoney);
        $this->assertSame(2, $capacityBound->overflowMoney);
    }

    public function test_inventory_sale_requires_room_for_the_complete_authored_revenue_batch(): void
    {
        $planner = new InventorySalePlanner(new CappedAddition);

        $blocked = $planner->plan(2_500, 9_998, 9_999, 1_000, 2);
        $this->assertSame(0, $blocked->inventorySold);
        $this->assertSame(2_500, $blocked->inventoryRemaining);
        $this->assertSame(0, $blocked->appliedMoney);
        $this->assertSame(4, $blocked->overflowMoney);

        $sold = $planner->plan(2_500, 9_997, 9_999, 1_000, 2);
        $this->assertSame(1_000, $sold->inventorySold);
        $this->assertSame(1_500, $sold->inventoryRemaining);
        $this->assertSame(2, $sold->appliedMoney);
        $this->assertSame(2, $sold->overflowMoney);
    }

    public function test_undersea_city_maintenance_is_all_or_nothing_with_one_way_two_for_one_substitution(): void
    {
        $planner = new UnderseaCityMaintenancePlanner;
        $ruleset = ['turn_processing' => ['undersea_city_maintenance' => [
            'facility_key' => 'undersea_city',
            'resource_keys' => ['industrial_goods', 'minerals'],
            'base_units_per_resource' => 1000,
            'substitution_units_per_shortage' => 2,
            'payment_policy' => 'all_or_nothing',
            'settlement_order' => 'map_cell_id_ascending',
        ]]];

        foreach ([
            [1000, 1000, 1000, 1000, true],
            [500, 2000, 500, 2000, true],
            [2000, 500, 2000, 500, true],
            [900, 1200, 900, 1200, true],
            [500, 1500, 0, 0, false],
            [900, 900, 0, 0, false],
        ] as [$industrial, $minerals, $expectedIndustrial, $expectedMinerals, $paid]) {
            $plan = $planner->plan($ruleset, $industrial, $minerals, [7]);
            $this->assertSame($expectedIndustrial, $plan['industrial_goods_consumed']);
            $this->assertSame($expectedMinerals, $plan['minerals_consumed']);
            $this->assertSame($paid, $plan['settlements'][0]['paid']);
        }

        $sequential = $planner->plan($ruleset, 500, 3000, [9, 3]);
        $this->assertSame([3, 9], array_column($sequential['settlements'], 'cell_id'));
        $this->assertSame([true, false], array_column($sequential['settlements'], 'paid'));
        $this->assertSame(500, $sequential['industrial_goods_consumed']);
        $this->assertSame(2000, $sequential['minerals_consumed']);
    }
}
