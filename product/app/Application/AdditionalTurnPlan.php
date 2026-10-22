<?php

namespace App\Application;

use Carbon\CarbonImmutable;
use DomainException;

/** Immutable additional slots; skipped slots are never moved or replayed. */
final readonly class AdditionalTurnPlan
{
    /** @param list<CarbonImmutable> $slots */
    private function __construct(public int $intervalMinutes, public array $slots) {}

    public static function regular(CarbonImmutable $time): bool
    {
        $local = $time->setTimezone('Asia/Tokyo');

        return $local->format('i:s') === '00:00' && (int) $local->format('G') % 2 === 0;
    }

    public static function create(CarbonImmutable $start, CarbonImmutable $end, int $count, int $interval): self
    {
        if ($count < 1 || $count > 500 || $interval < 5 || $interval > 120
            || $start->format('s') !== '00' || $end->format('s') !== '00'
            || $end->lessThanOrEqualTo($start) || $end->isAfter($start->addDays(31))) {
            throw new DomainException('追加上限は1〜500回、間隔は5〜120分、開始・終了は分単位で31日以内です。');
        }
        $slots = [];
        for ($slot = $start; $slot->isBefore($end) && count($slots) < $count; $slot = $slot->addMinutes($interval)) {
            if (! self::regular($slot)) {
                $slots[] = $slot->utc();
            }
        }
        if (count($slots) !== $count) {
            throw new DomainException('指定期間に追加'.$count.'回が入りません（通常枠を除いた追加枠は'.count($slots).'回）。期間・間隔・上限を確認してください。');
        }

        return new self($interval, $slots);
    }

    /** Existing draft records without explicit slots retain odd-hour behavior.
     * @param  array<string,mixed>  $schedule
     */
    public static function stored(CarbonImmutable $start, CarbonImmutable $end, array $schedule, int $occurrence = 1): self
    {
        $window = $schedule['windows'][$occurrence - 1] ?? [];
        if (isset($window['additional_scheduled_ats'])) {
            return new self((int) $schedule['interval_minutes'], array_map(
                static fn (string $time): CarbonImmutable => CarbonImmutable::parse($time)->utc(), $window['additional_scheduled_ats']));
        }
        $slot = $start->setTimezone('Asia/Tokyo')->startOfHour();
        if ($slot->isBefore($start)) {
            $slot = $slot->addHour();
        }
        if ((int) $slot->format('G') % 2 === 0) {
            $slot = $slot->addHour();
        }
        $slots = [];
        for (; $slot->isBefore($end); $slot = $slot->addHours(2)) {
            $slots[] = $slot->utc();
        }

        return new self(120, $slots);
    }

    public function graceSeconds(int $configuredMinutes): int
    {
        return max(0, min($configuredMinutes * 60, $this->intervalMinutes * 60 - 1));
    }

    public function due(CarbonImmutable $now, CarbonImmutable $end, int $graceMinutes): ?CarbonImmutable
    {
        if ($now->greaterThanOrEqualTo($end)) {
            return null;
        }
        foreach (array_reverse($this->slots) as $slot) {
            if ($slot->isAfter($now)) {
                continue;
            }
            $nextRegular = $slot->setTimezone('Asia/Tokyo')->startOfHour()->addHour();
            if ((int) $nextRegular->format('G') % 2 !== 0) {
                $nextRegular = $nextRegular->addHour();
            }
            $grace = min($this->graceSeconds($graceMinutes), $nextRegular->timestamp - $slot->timestamp - 1);

            return $now->timestamp - $slot->timestamp <= $grace ? $slot : null;
        }

        return null;
    }

    /** @return list<string> */
    public function timestamps(): array
    {
        return array_map(static fn (CarbonImmutable $slot): string => $slot->toIso8601String(), $this->slots);
    }
}
