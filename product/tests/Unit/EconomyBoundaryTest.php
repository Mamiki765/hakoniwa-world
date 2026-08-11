<?php

namespace Tests\Unit;

use App\Domain\Economy\CappedAddition;
use App\Domain\Economy\InventorySalePlanner;
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
}
