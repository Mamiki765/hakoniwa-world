<?php

namespace App\Application;

use App\Models\BoostOccurrence;
use App\Models\World;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class TurnExecutionSchedule
{
    public function nextRegular(World $world): ?CarbonImmutable
    {
        return $world->next_regular_turn_at
            ?? $world->turn_schedule_origin_at?->addHours($world->current_turn * 2)->utc();
    }

    public function debt(World $world, CarbonImmutable $now): int
    {
        $next = $this->nextRegular($world);

        return $next === null || $next->isAfter($now) ? 0 : intdiv($now->timestamp - $next->timestamp, 7200) + 1;
    }

    public function nextAutomatic(World $world, CarbonImmutable $now): ?CarbonImmutable
    {
        $nextRegular = $this->nextRegular($world);
        if ($nextRegular === null || $nextRegular->isAfter($now)) {
            return $nextRegular;
        }
        $current = $this->candidate($world, $now, false);
        if ($current !== null) {
            return $current['scheduled_at'];
        }
        $next = $now->setTimezone('Asia/Tokyo')->startOfHour()->addHour();
        if ((int) $next->format('G') % 2 !== 0) {
            $next = $next->addHour();
        }
        $next = $next->utc();
        foreach ($this->boosts($world, $now, $next) as $boost) {
            foreach ($this->plan($boost)->slots as $slot) {
                if ($slot->isAfter($now) && $slot->isBefore($next)) {
                    $next = $slot;
                    break;
                }
            }
        }

        return $next;
    }

    /** @return list<array{scheduled_at:CarbonImmutable,kind:string,boost_occurrence_id:?int}> */
    private function currentSlots(World $world, CarbonImmutable $now): array
    {
        $slot = $now->setTimezone('Asia/Tokyo')->startOfHour();
        $grace = (int) config('hakoniwa.turn_schedule.grace_minutes', 15);
        $slots = [];
        if (AdditionalTurnPlan::regular($slot) && $now->lessThanOrEqualTo($slot->addMinutes($grace))) {
            $slots[] = ['scheduled_at' => $slot->utc(), 'kind' => 'regular', 'boost_occurrence_id' => null];
        }
        foreach ($this->boosts($world, $now, $now) as $boost) {
            $due = $this->plan($boost)->due($now, $boost->ends_at, $grace);
            if ($due !== null) {
                $slots[] = ['scheduled_at' => $due, 'kind' => 'turn_acceleration', 'boost_occurrence_id' => $boost->id];
            }
        }

        return $slots;
    }

    /** @return Collection<int,BoostOccurrence> */
    private function boosts(World $world, CarbonImmutable $now, CarbonImmutable $startsBefore): Collection
    {
        return BoostOccurrence::query()->join('boost_series', 'boost_series.id', '=', 'boost_occurrences.boost_series_id')
            ->where('boost_occurrences.world_id', $world->id)->where('boost_occurrences.kind', 'turn_acceleration')
            ->whereNull('boost_occurrences.cancelled_at')->where('boost_occurrences.ends_at', '>', $now)
            ->where('boost_occurrences.starts_at', '<=', $startsBefore)
            ->select('boost_occurrences.*', 'boost_series.schedule as series_schedule')->get();
    }

    private function plan(BoostOccurrence $boost): AdditionalTurnPlan
    {
        return AdditionalTurnPlan::stored($boost->starts_at, $boost->ends_at, $boost->series_schedule ?? [], $boost->occurrence_number);
    }

    /** @return array{scheduled_at:CarbonImmutable,kind:string,boost_occurrence_id:?int}|null */
    public function candidate(World $world, CarbonImmutable $now, bool $manual): ?array
    {
        if ($this->debt($world, $now) === 0) {
            return null;
        }
        $slots = $this->currentSlots($world, $now);
        foreach ($slots as $slot) {
            if (! DB::table('turn_execution_slots')->where('world_id', $world->id)->where('scheduled_at', $slot['scheduled_at'])->exists()) {
                return $slot;
            }
        }

        if (! $manual || $slots !== []) {
            return null;
        }
        $at = $now->startOfSecond()->utc();

        return DB::table('turn_execution_slots')->where('world_id', $world->id)->where('scheduled_at', $at)->exists()
            ? null : ['scheduled_at' => $at, 'kind' => 'manual', 'boost_occurrence_id' => null];
    }
}
