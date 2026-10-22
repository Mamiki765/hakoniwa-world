<?php

namespace Tests\Unit;

use App\Application\AdditionalTurnPlan;
use Carbon\CarbonImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AdditionalTurnPlanTest extends TestCase
{
    public function test_seven_additional_slots_fit_sunday_evening_without_duplicating_regular_turns(): void
    {
        $start = CarbonImmutable::parse('2026-11-01T18:00:00+09:00');
        $end = $start->addHours(5);
        $plan = AdditionalTurnPlan::create($start, $end, 7, 30);
        $this->assertSame(['18:30', '19:00', '19:30', '20:30', '21:00', '21:30', '22:30'],
            array_map(fn ($slot) => $slot->setTimezone('Asia/Tokyo')->format('H:i'), $plan->slots));
        $this->assertFalse(AdditionalTurnPlan::regular($plan->slots[0]));
        $this->assertTrue(AdditionalTurnPlan::regular($start->addHours(2)));
        $this->assertNull($plan->due($end, $end, 15));
        $this->assertNull($plan->due($end->subMinute(), $end, 15), 'Unused window time must not create extra slots beyond the cap.');
    }

    public function test_a_window_with_insufficient_distinct_slots_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        AdditionalTurnPlan::create(CarbonImmutable::parse('2026-11-01T18:00:00+09:00'),
            CarbonImmutable::parse('2026-11-01T22:00:00+09:00'), 7, 30);
    }

    public function test_grace_is_shorter_than_interval_and_stops_before_the_next_regular_slot(): void
    {
        $start = CarbonImmutable::parse('2026-11-01T19:50:00+09:00');
        $end = $start->addHour();
        $plan = AdditionalTurnPlan::create($start, $end, 2, 20);
        $this->assertTrue($start->equalTo($plan->due($start->addMinutes(9)->addSeconds(59), $end, 15)));
        $this->assertNull($plan->due($start->addMinutes(10), $end, 15));
        $this->assertNull($plan->due($start->addMinutes(19), $end, 15), 'Missed slots cannot be replayed on later polls.');
        $this->assertTrue($start->addMinutes(20)->equalTo($plan->due($start->addMinutes(20), $end, 15)));
        $short = AdditionalTurnPlan::create($start, $end, 2, 5);
        $this->assertSame(299, $short->graceSeconds(15));
        $this->assertNull($short->due($start->addMinutes(10), $end, 15));
    }

    public function test_saved_slots_and_existing_odd_hour_draft_records_have_deterministic_boundaries(): void
    {
        $start = CarbonImmutable::parse('2026-11-01T18:30:00+09:00');
        $end = $start->addHours(20);
        $legacy = AdditionalTurnPlan::stored($start, $end, []);
        $this->assertCount(10, $legacy->slots);
        $this->assertSame('19:00', $legacy->slots[0]->setTimezone('Asia/Tokyo')->format('H:i'));
        $saved = AdditionalTurnPlan::stored($start, $end, ['interval_minutes' => 120,
            'windows' => [['additional_scheduled_ats' => $legacy->timestamps()]]]);
        $this->assertSame($legacy->timestamps(), $saved->timestamps());
    }
}
