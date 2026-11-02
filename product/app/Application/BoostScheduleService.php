<?php

namespace App\Application;

use App\Domain\World\WorldMutationLock;
use App\Models\BoostOccurrence;
use App\Models\User;
use App\Models\World;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class BoostScheduleService
{
    public function __construct(private WorldMutationLock $lock, private AnnouncementAdminAuthorizer $authorizer,
        private TurnExecutionSchedule $turns) {}

    /** @return array<string,mixed> */
    public function publicStatus(World $world): array
    {
        $now = CarbonImmutable::now();
        $rows = BoostOccurrence::query()->where('world_id', $world->id)->whereNull('cancelled_at')
            ->where('ends_at', '>', $now)->orderBy('starts_at')->orderBy('id')->get();
        $nextByKind = $rows->groupBy('kind')->map(fn ($group) => $group->first())->values();

        return ['server_now' => $now->utc()->toIso8601String(), 'timezone' => 'Asia/Tokyo',
            'occurrences' => $this->presentOccurrences($nextByKind, $now)];
    }

    /**
     * @param  Collection<int, BoostOccurrence>  $rows
     * @return list<array<string,mixed>>
     */
    private function presentOccurrences(Collection $rows, CarbonImmutable $now): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $series = DB::table('boost_series')->whereIn('id', $rows->pluck('boost_series_id')->unique()->all())
            ->get(['id', 'title', 'schedule'])->keyBy('id');

        return $rows->map(fn (BoostOccurrence $row): array => $this->present($row, $now,
            $series->get($row->boost_series_id) ?? throw new \RuntimeException('Boost series is missing.')))->values()->all();
    }

    /** @return array<string,mixed> */
    private function present(BoostOccurrence $row, CarbonImmutable $now, stdClass $series): array
    {
        $plan = $row->kind === 'turn_acceleration' ? AdditionalTurnPlan::stored($row->starts_at, $row->ends_at,
            json_decode((string) $series->schedule, true, 512, JSON_THROW_ON_ERROR), $row->occurrence_number) : null;

        return ['id' => $row->id, 'series_id' => $row->boost_series_id, 'occurrence_number' => $row->occurrence_number,
            'kind' => $row->kind, 'title' => $series->title, 'starts_at' => $row->starts_at->utc()->toIso8601String(),
            'ends_at' => $row->ends_at->utc()->toIso8601String(),
            'description' => $row->kind === 'hunting_ground'
                ? '狩場のレア出現率・通常敵の鍵出現率・獲得XPが2倍（Gや鍵の個数は変わりません）'
                : '大規模障害の補填として、通常の日本時間偶数時00分に加え、期間内の奇数時00分に未補填分だけ追加します（追加上限'.count($plan->slots).'回）',
            'turn_interval_minutes' => $plan?->intervalMinutes,
            'multiplier' => $row->kind === 'hunting_ground' ? 2 : null,
            'status' => $row->cancelled_at !== null ? 'cancelled' : ($now->isBefore($row->starts_at) ? 'scheduled' : ($now->isBefore($row->ends_at) ? 'active' : 'ended')),
            'effects' => $row->kind === 'hunting_ground'
                ? ['rare_encounter_multiplier' => 2, 'normal_key_chance_multiplier' => 2, 'xp_multiplier' => 2, 'scope' => 'hunting_ground']
                : ['additional_turn_interval_minutes' => $plan->intervalMinutes, 'additional_turn_limit' => count($plan->slots),
                    'additional_turn_scheduled_ats' => $plan->timestamps(), 'compensation_only' => true]];
    }

    /** @return array<string,mixed> */
    public function adminStatus(User $actor, World $world): array
    {
        $this->authorize($actor);
        $now = CarbonImmutable::now();
        $rows = BoostOccurrence::query()->where('world_id', $world->id)->orderByDesc('id')->limit(500)->get();

        return ['server_now' => $now->utc()->toIso8601String(), 'timezone' => 'Asia/Tokyo',
            'uncompensated_turns' => $this->turns->debt($world, $now),
            'current_turn' => $world->current_turn,
            'occurrences' => $this->presentOccurrences($rows, $now)];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function preview(User $actor, World $world, array $input): array
    {
        $this->authorize($actor);
        $world = $world->fresh();
        $now = CarbonImmutable::now();
        $start = $this->timestamp($input['starts_at']);
        if ($start->lessThan($now) || $start->isAfter($now->addYear())) {
            throw new DomainException('開始は現在以降、1年以内で指定してください。');
        }
        $kind = $input['kind'];
        $debt = $this->turns->debt($world, $now);
        $count = (int) ($input['repeat_count'] ?? 1);
        $frequency = $input['repeat'] ?? 'none';
        $explicitTime = isset($input['ends_at']) || isset($input['additional_turns']) || isset($input['interval_minutes']);
        if ($kind === 'turn_acceleration') {
            if ($this->turns->nextRegular($world) === null || $debt < 1 || $count !== 1 || $frequency !== 'none') {
                throw new DomainException('時間加速はカレンダー設定済みの未補填遅延に対する単発予約です。');
            }
            if (BoostOccurrence::query()->where('world_id', $world->id)->where('kind', $kind)->whereNull('cancelled_at')->where('ends_at', '>', $now)->exists()) {
                throw new DomainException('既存の時間加速が終了または取消されてから予約してください。');
            }
            if (($input['interval_minutes'] ?? 120) !== 120) {
                throw new DomainException('補填の間隔は120分固定です。');
            }
            if ($explicitTime && (! isset($input['ends_at'], $input['additional_turns']))) {
                throw new DomainException('時間加速の終了日時・追加上限を両方指定してください。');
            }
            $end = $explicitTime ? $this->timestamp($input['ends_at']) : $start->addHours($debt * 2);
        } else {
            $end = $this->timestamp($input['ends_at'] ?? '');
        }
        if ($count < 1 || $count > 52 || ! in_array($frequency, ['none', 'daily', 'weekly'], true)
            || ($frequency === 'none' && $count !== 1) || $end->lessThanOrEqualTo($start) || $end->isAfter($start->addDays(31))) {
            throw new DomainException('期間は31日以内、繰り返しは毎日または毎週で最大52回です。');
        }
        $windows = [];
        $plan = $kind !== 'turn_acceleration' ? null : ($explicitTime
            ? AdditionalTurnPlan::create($start, $end, (int) $input['additional_turns'])
            : AdditionalTurnPlan::stored($start, $end, []));
        for ($index = 0; $index < $count; $index++) {
            $days = $frequency === 'weekly' ? $index * 7 : $index;
            $from = $start->addDays($days);
            $to = $end->addDays($days);
            if ($index > 0 && $windows[$index - 1]['ends_at'] > $from->toIso8601String()) {
                throw new DomainException('繰り返し期間が重複しています。');
            }
            $windows[] = ['starts_at' => $from->toIso8601String(), 'ends_at' => $to->toIso8601String(),
                'additional_slots' => $plan === null ? 0 : count($plan->slots), 'additional_scheduled_ats' => $plan?->timestamps() ?? []];
        }
        $this->assertNoOverlap($world, $kind, $windows);
        $payload = ['kind' => $kind, 'title' => trim($input['title']), 'repeat' => $frequency,
            'repeat_count' => $count, 'windows' => $windows, 'uncompensated_turns' => $debt,
            'current_turn' => $world->current_turn, 'next_regular_turn_at' => $this->turns->nextRegular($world)?->toIso8601String(),
            'additional_turns' => $plan === null ? null : count($plan->slots), 'interval_minutes' => $plan?->intervalMinutes,
            'effective_additional_turns' => $plan === null ? 0 : min(count($plan->slots), $debt),
            'grace_seconds' => $plan?->graceSeconds(),
            'duration_hours' => ($end->timestamp - $start->timestamp) / 3600];
        $claims = ['actor_id' => $actor->id, 'world_id' => $world->id, 'expires_at' => $now->addMinutes(15)->timestamp,
            'request_id' => (string) Str::uuid(), 'payload' => $payload];

        return [...$payload, 'token' => Crypt::encryptString(json_encode($claims, JSON_THROW_ON_ERROR))];
    }

    private function timestamp(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new DomainException('日時は秒とタイムゾーン付きのISO形式で指定してください。画面の入力は日本時間です。');
        }
        try {
            $parsed = CarbonImmutable::parse($value);
            $canonical = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
            if ($parsed->format('Y-m-d\TH:i:sP') !== $canonical) {
                throw new DomainException('日時が無効です。');
            }

            return $parsed->utc();
        } catch (\Throwable) {
            throw new DomainException('日時が無効です。');
        }
    }

    public function additionalSlots(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return count(AdditionalTurnPlan::stored($start, $end, [])->slots);
    }

    /** @param list<array<string,mixed>> $windows */
    private function assertNoOverlap(World $world, string $kind, array $windows): void
    {
        foreach ($windows as $window) {
            if (BoostOccurrence::query()->where('world_id', $world->id)->where('kind', $kind)->whereNull('cancelled_at')
                ->where('starts_at', '<', $window['ends_at'])->where('ends_at', '>', $window['starts_at'])->exists()) {
                throw new DomainException('同じ種類の開催期間が重複しています。');
            }
        }
    }

    /** @return array<string,mixed> */
    public function reserve(User $actor, World $world, string $token): array
    {
        $this->authorize($actor);
        try {
            $claims = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new DomainException('予約確認情報が無効です。');
        }
        if (! is_array($claims) || ($claims['actor_id'] ?? null) !== $actor->id || ($claims['world_id'] ?? null) !== $world->id) {
            throw new DomainException('予約確認情報の対象が違います。');
        }
        $this->lock->acquire($world);
        try {
            return DB::transaction(function () use ($actor, $world, $claims): array {
                $this->lock->assertHeld($world);
                $existing = DB::table('boost_series')->where('request_id', $claims['request_id'])->first();
                if ($existing !== null) {
                    return ['series_id' => $existing->id, 'duplicate' => true];
                }
                $now = CarbonImmutable::now();
                $payload = $claims['payload'];
                $world = $world->fresh();
                try {
                    if ($payload['kind'] === 'turn_acceleration') {
                        AdditionalTurnPlan::stored(CarbonImmutable::parse($payload['windows'][0]['starts_at']),
                            CarbonImmutable::parse($payload['windows'][0]['ends_at']), $payload);
                    }
                    if ($claims['expires_at'] <= $now->timestamp || $now->isAfter($payload['windows'][0]['starts_at'])) {
                        throw new DomainException('確認期限または開始時刻を過ぎました。再確認してください。');
                    }
                    if ($payload['kind'] === 'turn_acceleration'
                        && ($payload['current_turn'] !== $world->current_turn
                            || $payload['uncompensated_turns'] !== $this->turns->debt($world, $now)
                            || $payload['next_regular_turn_at'] !== $this->turns->nextRegular($world)?->toIso8601String()
                            || BoostOccurrence::query()->where('world_id', $world->id)->where('kind', 'turn_acceleration')->whereNull('cancelled_at')->where('ends_at', '>', $now)->exists())) {
                        throw new DomainException('未補填遅延または予約が変わりました。再確認してください。');
                    }
                    $this->assertNoOverlap($world, $payload['kind'], $payload['windows']);
                } catch (DomainException $exception) {
                    throw new BoostReservationRejectedException($exception->getMessage(), previous: $exception);
                }
                $id = DB::table('boost_series')->insertGetId(['world_id' => $world->id, 'request_id' => $claims['request_id'],
                    'created_by' => $actor->id, 'kind' => $payload['kind'], 'title' => $payload['title'],
                    'schedule' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
                foreach ($payload['windows'] as $index => $window) {
                    BoostOccurrence::query()->create(['boost_series_id' => $id, 'world_id' => $world->id,
                        'occurrence_number' => $index + 1, 'kind' => $payload['kind'], 'starts_at' => $window['starts_at'],
                        'ends_at' => $window['ends_at']]);
                }
                $this->audit($actor, $world, 'boost.reserved', ['series_id' => $id, 'schedule' => $payload]);

                return ['series_id' => $id, 'duplicate' => false];
            });
        } finally {
            $this->lock->release($world);
        }
    }

    public function cancel(User $actor, World $world, int $occurrenceId, bool $futureSeries): void
    {
        $this->authorize($actor);
        $this->lock->acquire($world);
        try {
            DB::transaction(function () use ($actor, $world, $occurrenceId, $futureSeries): void {
                $this->lock->assertHeld($world);
                $occurrence = BoostOccurrence::query()->where('world_id', $world->id)->findOrFail($occurrenceId);
                $query = BoostOccurrence::query()->where('world_id', $world->id)->whereNull('cancelled_at')->where('ends_at', '>', now());
                if ($futureSeries) {
                    $query->where('boost_series_id', $occurrence->boost_series_id)->where('starts_at', '>=', $occurrence->starts_at);
                } else {
                    $query->whereKey($occurrence->id);
                }
                $ids = $query->pluck('id')->all();
                $query->update(['cancelled_at' => now(), 'cancelled_by' => $actor->id]);
                $this->audit($actor, $world, 'boost.cancelled', ['occurrence_ids' => $ids]);
            });
        } finally {
            $this->lock->release($world);
        }
    }

    /** @param array<string,mixed> $metadata */
    private function audit(User $actor, World $world, string $type, array $metadata): void
    {
        DB::table('audit_events')->insert(['actor_user_id' => $actor->id, 'world_id' => $world->id, 'turn' => $world->current_turn,
            'event_type' => $type, 'visibility' => 'admin', 'severity' => 'info', 'subject_type' => World::class, 'subject_id' => $world->id,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function authorize(User $actor): void
    {
        if (! $this->authorizer->allows($actor)) {
            throw new AuthorizationException('管理者だけが操作できます。');
        }
    }
}
