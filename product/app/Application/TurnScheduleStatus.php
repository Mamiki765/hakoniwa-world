<?php

namespace App\Application;

use App\Models\TurnRun;
use App\Models\World;
use Carbon\CarbonImmutable;
use RuntimeException;

final class TurnScheduleStatus
{
    /** @return array{status: string, last_successful_turn_at: ?string, next_scheduled_turn_at: string, timezone: string} */
    public function forWorld(World $world, ?CarbonImmutable $now = null): array
    {
        $timezone = (string) config('hakoniwa.turn_schedule.timezone', 'Asia/Tokyo');
        $intervalHours = (int) config('hakoniwa.turn_schedule.interval_hours', 2);
        $graceMinutes = (int) config('hakoniwa.turn_schedule.grace_minutes', 15);
        if ($timezone !== 'Asia/Tokyo' || $intervalHours !== 2 || $graceMinutes < 0 || $graceMinutes > 60) {
            throw new RuntimeException('The production turn schedule configuration is invalid.');
        }

        $now = ($now ?? CarbonImmutable::instance(now()))->utc();
        $lastSuccessful = TurnRun::query()
            ->where('world_id', $world->id)
            ->where('is_dry_run', false)
            ->where('status', TurnRun::STATUS_COMPLETED)
            ->whereNotNull('completed_at')
            ->orderByDesc('target_turn')
            ->orderByDesc('id')
            ->first();
        $lastSuccessfulAt = $lastSuccessful?->completed_at?->toImmutable()->utc();
        $expected = $lastSuccessfulAt === null
            ? $this->scheduledAtOrBefore($now, $timezone)
            : $this->scheduledAfter($lastSuccessfulAt, $timezone);

        $unresolvedStatus = TurnRun::query()
            ->where('world_id', $world->id)
            ->where('target_turn', $world->current_turn + 1)
            ->where('is_dry_run', false)
            ->whereIn('status', [TurnRun::STATUS_FAILED, TurnRun::STATUS_BLOCKED])
            ->value('status');

        $status = match ($unresolvedStatus) {
            TurnRun::STATUS_FAILED => 'failed',
            TurnRun::STATUS_BLOCKED => 'blocked',
            default => $now->greaterThan($expected->addMinutes($graceMinutes)) ? 'delayed' : 'normal',
        };

        return [
            'status' => $status,
            'last_successful_turn_at' => $lastSuccessfulAt?->toIso8601String(),
            'next_scheduled_turn_at' => $expected->toIso8601String(),
            'timezone' => $timezone,
        ];
    }

    private function scheduledAtOrBefore(CarbonImmutable $instant, string $timezone): CarbonImmutable
    {
        $local = $instant->setTimezone($timezone)->startOfHour();
        if (((int) $local->format('G')) % 2 !== 0) {
            $local = $local->subHour();
        }

        return $local->utc();
    }

    private function scheduledAfter(CarbonImmutable $instant, string $timezone): CarbonImmutable
    {
        $local = $instant->setTimezone($timezone)->startOfHour();
        if ($local->lessThanOrEqualTo($instant->setTimezone($timezone))) {
            $local = $local->addHour();
        }
        if (((int) $local->format('G')) % 2 !== 0) {
            $local = $local->addHour();
        }

        return $local->utc();
    }
}
