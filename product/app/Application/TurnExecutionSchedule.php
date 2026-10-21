<?php

namespace App\Application;

use App\Models\BoostOccurrence;
use App\Models\World;
use Carbon\CarbonImmutable;
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

    /** Only the current wall-clock slot is eligible; missed slots never form an automatic catch-up loop.
     * @return array{scheduled_at:CarbonImmutable,kind:string,boost_occurrence_id:?int}|null
     */
    public function currentSlot(World $world, CarbonImmutable $now): ?array
    {
        $slot = $now->setTimezone('Asia/Tokyo')->startOfHour();
        if ($now->isAfter($slot->addMinutes((int) config('hakoniwa.turn_schedule.grace_minutes', 15)))) {
            return null;
        }
        $boost = null;
        $kind = 'regular';
        if ((int) $slot->format('G') % 2 !== 0) {
            $boost = BoostOccurrence::query()->where('world_id', $world->id)->where('kind', 'turn_acceleration')
                ->whereNull('cancelled_at')->where('starts_at', '<=', $slot->utc())->where('ends_at', '>', $slot->utc())->first();
            if ($boost === null) {
                return null;
            }
            $kind = 'turn_acceleration';
        }

        return ['scheduled_at' => $slot->utc(), 'kind' => $kind, 'boost_occurrence_id' => $boost?->id];
    }

    /** @return array{scheduled_at:CarbonImmutable,kind:string,boost_occurrence_id:?int}|null */
    public function candidate(World $world, CarbonImmutable $now, bool $manual): ?array
    {
        if ($this->debt($world, $now) === 0) {
            return null;
        }
        $slot = $this->currentSlot($world, $now);
        if ($slot === null) {
            if (! $manual) {
                return null;
            }
            $slot = ['scheduled_at' => $now->startOfSecond()->utc(), 'kind' => 'manual', 'boost_occurrence_id' => null];
        }
        if (DB::table('turn_execution_slots')->where('world_id', $world->id)->where('scheduled_at', $slot['scheduled_at'])->exists()) {
            return null;
        }

        return $slot;
    }
}
