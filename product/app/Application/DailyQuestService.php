<?php

namespace App\Application;

use App\Models\User;
use App\Models\UserDailyQuestProgress;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class DailyQuestService
{
    public const DEVELOPMENT_OPENED = 'development_opened';

    public const UNDERGROUND_BATTLES = 'underground_battles';

    public const COMMAND_REGISTERED = 'command_registered';

    public const PARADOX_REWARD = 5;

    public function __construct(private ParadoxBalanceService $paradox) {}

    /** @return array<string, int|string|bool> */
    public function recordDevelopmentOpened(User $user): array
    {
        $day = $this->canonicalDay();

        return $this->record(
            $user->id,
            self::DEVELOPMENT_OPENED,
            1,
            'development-opened:'.$user->id.':'.$day,
        );
    }

    /** @return array<string, int|string|bool> */
    public function recordUndergroundBattles(int $userId, int $count, string $sourceKey): array
    {
        if ($count < 1) {
            throw new DomainException('Daily underground battle count must be positive.');
        }

        return $this->record(
            $userId,
            self::UNDERGROUND_BATTLES,
            $count,
            'underground-battles:'.$userId.':'.$sourceKey,
        );
    }

    /** @return array<string, int|string|bool> */
    public function recordCommandRegistered(int $userId, string $sourceKey): array
    {
        if ($sourceKey === '' || strlen($sourceKey) > 120) {
            throw new DomainException('Daily command registration requires a bounded source key.');
        }

        return $this->record(
            $userId,
            self::COMMAND_REGISTERED,
            1,
            'command-registered:'.$userId.':'.$sourceKey,
        );
    }

    /** @return array<string, int|string|bool> */
    public function currentStatus(int $userId, string $questKey): array
    {
        [$target, $label] = $this->definition($questKey);
        $day = $this->canonicalDay();
        $progress = UserDailyQuestProgress::query()
            ->where('user_id', $userId)
            ->where('canonical_day', $day)
            ->where('quest_key', $questKey)
            ->first();
        $hasProgress = $progress instanceof UserDailyQuestProgress;

        return [
            'key' => $questKey,
            'label' => $label,
            'canonical_day' => $day,
            'progress' => $hasProgress ? (int) $progress->progress : 0,
            'target' => $target,
            'paradox_awarded' => $hasProgress ? (int) $progress->paradox_awarded : 0,
            'completed' => $hasProgress && $progress->completed_at !== null,
            'completed_now' => false,
            'paradox_balance' => $this->paradox->balanceFor($userId),
        ];
    }

    /** @return array<string, int|string|bool> */
    private function record(int $userId, string $questKey, int $amount, string $entryKey): array
    {
        [$target, $label] = $this->definition($questKey);
        $day = $this->canonicalDay();

        return DB::transaction(function () use ($userId, $questKey, $amount, $entryKey, $target, $label, $day): array {
            $when = now();
            DB::table('user_daily_quest_progress')->insertOrIgnore([
                'user_id' => $userId,
                'canonical_day' => $day,
                'quest_key' => $questKey,
                'progress' => 0,
                'target' => $target,
                'paradox_awarded' => 0,
                'completed_at' => null,
                'created_at' => $when,
                'updated_at' => $when,
            ]);
            $progress = UserDailyQuestProgress::query()
                ->where('user_id', $userId)
                ->where('canonical_day', $day)
                ->where('quest_key', $questKey)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $progress->target !== $target) {
                throw new DomainException('Daily quest target changed within a canonical day.');
            }
            if ($progress->completed_at !== null) {
                return $this->presentProgress($progress, $questKey, $label, $day, false);
            }

            $inserted = DB::table('user_daily_quest_activities')->insertOrIgnore([
                'user_id' => $userId,
                'canonical_day' => $day,
                'quest_key' => $questKey,
                'entry_key' => $entryKey,
                'amount' => $amount,
                'created_at' => $when,
                'updated_at' => $when,
            ]);
            $completedNow = false;
            if ($inserted === 1) {
                $progress->progress = min($target, (int) $progress->progress + $amount);
                if ((int) $progress->progress === $target) {
                    $this->paradox->credit(
                        $userId,
                        self::PARADOX_REWARD,
                        'daily-quest:'.$userId.':'.$day.':'.$questKey,
                        'daily_quest',
                        $day,
                        ['quest_key' => $questKey],
                    );
                    $progress->paradox_awarded = self::PARADOX_REWARD;
                    $progress->completed_at = $when;
                    $completedNow = true;
                }
                $progress->save();
            }

            return $this->presentProgress($progress, $questKey, $label, $day, $completedNow);
        }, 3);
    }

    /** @return array<string, int|string|bool> */
    private function presentProgress(
        UserDailyQuestProgress $progress,
        string $questKey,
        string $label,
        string $day,
        bool $completedNow,
    ): array {
        return [
            'key' => $questKey,
            'label' => $label,
            'canonical_day' => $day,
            'progress' => (int) $progress->progress,
            'target' => (int) $progress->target,
            'paradox_awarded' => (int) $progress->paradox_awarded,
            'completed' => $progress->completed_at !== null,
            'completed_now' => $completedNow,
            'paradox_balance' => $this->paradox->balanceFor((int) $progress->user_id),
        ];
    }

    /** @return array{int, string} */
    private function definition(string $questKey): array
    {
        return match ($questKey) {
            self::DEVELOPMENT_OPENED => [1, '開発画面を開く'],
            self::UNDERGROUND_BATTLES => [10, '地下で10戦する'],
            self::COMMAND_REGISTERED => [1, 'コマンドを登録する'],
            default => throw new DomainException('Unknown daily quest.'),
        };
    }

    private function canonicalDay(): string
    {
        $timezone = (string) config('hakoniwa.turn_schedule.timezone', 'Asia/Tokyo');

        return now()->setTimezone($timezone)->toDateString();
    }
}
