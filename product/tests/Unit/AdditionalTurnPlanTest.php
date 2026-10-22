<?php

namespace Tests\Unit;

use App\Application\AdditionalTurnPlan;
use Carbon\CarbonImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AdditionalTurnPlanTest extends TestCase
{
    public function test_seven_additional_slots_require_seven_distinct_odd_hours(): void
    {
        $start = CarbonImmutable::parse('2026-11-01T09:00:00+09:00');
        $end = $start->addHours(14);
        $plan = AdditionalTurnPlan::create($start, $end, 7);
        $this->assertSame(['09:00', '11:00', '13:00', '15:00', '17:00', '19:00', '21:00'],
            array_map(fn ($slot) => $slot->setTimezone('Asia/Tokyo')->format('H:i'), $plan->slots));
        $this->assertSame(120, $plan->intervalMinutes);
        $this->assertNull($plan->due($end, $end));
        $this->assertNull($plan->due($end->subMinute(), $end));
        $this->assertTrue(AdditionalTurnPlan::regular($start->addHour()));
    }

    public function test_seven_slots_cannot_be_compressed_into_a_short_evening(): void
    {
        $this->expectException(DomainException::class);
        AdditionalTurnPlan::create(CarbonImmutable::parse('2026-11-01T18:00:00+09:00'),
            CarbonImmutable::parse('2026-11-01T23:00:00+09:00'), 7);
    }

    public function test_only_the_scheduled_odd_zero_minute_is_eligible_without_replay(): void
    {
        $start = CarbonImmutable::parse('2026-11-01T19:00:00+09:00');
        $end = $start->addHours(4);
        $plan = AdditionalTurnPlan::create($start, $end, 2);
        $this->assertTrue($start->equalTo($plan->due($start, $end)));
        $this->assertTrue($start->equalTo($plan->due($start->addSeconds(59), $end)));
        $this->assertNull($plan->due($start->addMinute(), $end));
        $this->assertNull($plan->due($start->addHour(), $end));
        $this->assertTrue($start->addHours(2)->equalTo($plan->due($start->addHours(2), $end)));
        $this->assertNull($plan->due($end, $end));
    }

    public function test_saved_slots_and_start_fraction_have_fixed_odd_hour_boundaries(): void
    {
        $start = CarbonImmutable::parse('2026-11-01T18:30:00+09:00');
        $end = $start->addHours(20);
        $plan = AdditionalTurnPlan::create($start, $end, 10);
        $this->assertSame('19:00', $plan->slots[0]->setTimezone('Asia/Tokyo')->format('H:i'));
        $saved = AdditionalTurnPlan::stored($start, $end, ['interval_minutes' => 120,
            'windows' => [['additional_scheduled_ats' => $plan->timestamps()]]]);
        $this->assertSame($plan->timestamps(), $saved->timestamps());
    }

    public function test_old_variable_interval_payload_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        AdditionalTurnPlan::stored(CarbonImmutable::parse('2026-11-01T18:00:00+09:00'),
            CarbonImmutable::parse('2026-11-01T23:00:00+09:00'), ['interval_minutes' => 30,
                'windows' => [['additional_scheduled_ats' => ['2026-11-01T09:30:00Z']]]]);
    }
}
