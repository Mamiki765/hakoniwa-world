<?php

namespace App\Application;

use App\Models\User;
use App\Models\UserDailyLoginClaim;
use App\Models\UserSkipTicketBalance;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class DailyLoginRewardService
{
    public const PARADOX_REWARD = 10;

    public const SKIP_TICKET_REWARD = 50;

    public function __construct(private ParadoxBalanceService $paradox) {}

    /**
     * @return array{awarded_now:bool, canonical_day:string, paradox_awarded:int, skip_tickets_awarded:int, paradox:array{name:string,unit:string,description:string,balance:int}, skip_ticket_balance:int}
     */
    public function claim(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $when = now();
            $timezone = (string) config('hakoniwa.turn_schedule.timezone', 'Asia/Tokyo');
            $day = $when->copy()->setTimezone($timezone)->toDateString();
            $existing = UserDailyLoginClaim::query()
                ->where('user_id', $lockedUser->id)
                ->where('canonical_day', $day)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof UserDailyLoginClaim) {
                return $this->present($lockedUser->id, $day, false, 0, 0);
            }

            $paradoxEntryKey = 'daily-login:'.$lockedUser->id.':'.$day;
            $this->paradox->credit(
                $lockedUser->id,
                self::PARADOX_REWARD,
                $paradoxEntryKey,
                'daily_login',
                $day,
            );

            DB::table('user_skip_ticket_balances')->insertOrIgnore([
                'user_id' => $lockedUser->id,
                'balance' => 0,
                'created_at' => $when,
                'updated_at' => $when,
            ]);
            $tickets = UserSkipTicketBalance::query()->where('user_id', $lockedUser->id)->lockForUpdate()->firstOrFail();
            $before = (int) $tickets->balance;
            if ($before > 4_294_967_295 - self::SKIP_TICKET_REWARD) {
                throw new DomainException('Skip ticket balance would overflow.');
            }
            $tickets->fill(['balance' => $before + self::SKIP_TICKET_REWARD])->save();
            DB::table('user_skip_ticket_ledger')->insert([
                'user_id' => $lockedUser->id,
                'underground_battle_id' => null,
                'underground_party_member_id' => null,
                'underground_skip_settlement_id' => null,
                'underground_skip_batch_id' => null,
                'entry_key' => $paradoxEntryKey,
                'delta' => self::SKIP_TICKET_REWARD,
                'balance_before' => $before,
                'balance_after' => (int) $tickets->balance,
                'canonical_day' => $day,
                'metadata' => json_encode(['source' => 'daily_login'], JSON_THROW_ON_ERROR),
                'created_at' => $when,
                'updated_at' => $when,
            ]);
            UserDailyLoginClaim::query()->create([
                'user_id' => $lockedUser->id,
                'canonical_day' => $day,
                'paradox_awarded' => self::PARADOX_REWARD,
                'skip_tickets_awarded' => self::SKIP_TICKET_REWARD,
                'claimed_at' => $when,
            ]);

            return $this->present(
                $lockedUser->id,
                $day,
                true,
                self::PARADOX_REWARD,
                self::SKIP_TICKET_REWARD,
            );
        }, 3);
    }

    /** @return array{awarded_now:bool, canonical_day:string, paradox_awarded:int, skip_tickets_awarded:int, paradox:array{name:string,unit:string,description:string,balance:int}, skip_ticket_balance:int} */
    private function present(int $userId, string $day, bool $awardedNow, int $paradoxAwarded, int $ticketsAwarded): array
    {
        return [
            'awarded_now' => $awardedNow,
            'canonical_day' => $day,
            'paradox_awarded' => $paradoxAwarded,
            'skip_tickets_awarded' => $ticketsAwarded,
            'paradox' => $this->paradox->presentFor($userId),
            'skip_ticket_balance' => (int) (UserSkipTicketBalance::query()->where('user_id', $userId)->value('balance') ?? 0),
        ];
    }
}
