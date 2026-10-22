<?php

namespace App\Application;

use Carbon\CarbonImmutable;
use DomainException;

/** Disaster compensation only: fixed odd-hour JST slots, never a general scheduler. */
final readonly class AdditionalTurnPlan
{
    /** @param list<CarbonImmutable> $slots */
    private function __construct(public int $intervalMinutes, public array $slots) {}

    public static function regular(CarbonImmutable $time): bool
    {
        $local = $time->setTimezone('Asia/Tokyo');

        return $local->format('i:s') === '00:00' && (int) $local->format('G') % 2 === 0;
    }

    public static function additional(CarbonImmutable $time): bool
    {
        $local = $time->setTimezone('Asia/Tokyo');

        return $local->format('i:s') === '00:00' && (int) $local->format('G') % 2 === 1;
    }

    public static function create(CarbonImmutable $start, CarbonImmutable $end, int $count): self
    {
        if ($count < 1 || $count > 500 || $start->format('s') !== '00' || $end->format('s') !== '00'
            || $end->lessThanOrEqualTo($start) || $end->isAfter($start->addDays(31))) {
            throw new DomainException('追加上限は1〜500回、開始・終了は分単位で31日以内です。補填は日本時間の奇数時00分に限ります。');
        }
        $slots = array_slice(self::oddSlots($start, $end), 0, $count);
        if (count($slots) !== $count) {
            throw new DomainException('指定期間の奇数時00分に追加'.$count.'回が入りません（'.count($slots).'回）。開催期間または上限を確認してください。');
        }

        return new self(120, $slots);
    }

    /** @param array<string,mixed> $schedule */
    public static function stored(CarbonImmutable $start, CarbonImmutable $end, array $schedule, int $occurrence = 1): self
    {
        $window = $schedule['windows'][$occurrence - 1] ?? [];
        if (isset($window['additional_scheduled_ats'])) {
            $slots = array_map(static fn (string $time): CarbonImmutable => CarbonImmutable::parse($time)->utc(), $window['additional_scheduled_ats']);
            if ((int) ($schedule['interval_minutes'] ?? 120) !== 120) {
                throw new DomainException('補填の間隔は120分固定です。予約内容を再確認してください。');
            }
            foreach ($slots as $slot) {
                if (! self::additional($slot) || $slot->isBefore($start) || ! $slot->isBefore($end)) {
                    throw new DomainException('補填の自動追加枠は開催期間内の日本時間奇数時00分に限ります。');
                }
            }

            return new self(120, $slots);
        }

        return new self(120, self::oddSlots($start, $end));
    }

    /** @return list<CarbonImmutable> */
    private static function oddSlots(CarbonImmutable $start, CarbonImmutable $end): array
    {
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

        return $slots;
    }

    public function graceSeconds(): int
    {
        return 59; // Only the scheduled 00 minute, allowing command startup seconds.
    }

    public function due(CarbonImmutable $now, CarbonImmutable $end): ?CarbonImmutable
    {
        if ($now->greaterThanOrEqualTo($end)) {
            return null;
        }
        $at = $now->setTimezone('Asia/Tokyo')->startOfHour()->utc();
        if (! self::additional($at) || $now->timestamp - $at->timestamp > $this->graceSeconds()) {
            return null;
        }
        foreach ($this->slots as $slot) {
            if ($slot->equalTo($at)) {
                return $slot;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function timestamps(): array
    {
        return array_map(static fn (CarbonImmutable $slot): string => $slot->toIso8601String(), $this->slots);
    }
}
