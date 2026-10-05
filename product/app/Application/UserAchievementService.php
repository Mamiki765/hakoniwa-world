<?php

namespace App\Application;

use App\Models\Secretary;
use App\Models\User;
use App\Models\UserAchievement;
use DomainException;

class UserAchievementService
{
    public const ISLAND_SECRETARY = 'island_secretary';

    // 呼出元の命名transactionで、実績と肩書きを同時に保存する。
    // unique(user_id, achievement_key)により再実行でも取得は一回だけ。
    public function grantIslandSecretary(Secretary $secretary): void
    {
        if ($secretary->name === null || $secretary->named_at === null) {
            throw new DomainException('秘書を命名する前に「島の秘書」は取得できません。');
        }
        $definition = config('hakoniwa.ruleset.user_achievements.'.self::ISLAND_SECRETARY);
        if (! is_array($definition) || $definition['condition'] !== 'secretary_named') {
            throw new DomainException('「島の秘書」の命名条件が現在のRulesetにありません。');
        }
        UserAchievement::query()->insertOrIgnore([
            'user_id' => $secretary->user_id,
            'achievement_key' => self::ISLAND_SECRETARY,
            'title_key' => $definition['title_key'],
            'acquired_at' => $secretary->named_at,
        ]);
    }

    /** @return array{achievements: list<array<string, mixed>>, titles: list<array<string, mixed>>} */
    public function presentFor(User $user): array
    {
        $achievements = [];
        $titles = [];
        foreach (UserAchievement::query()->where('user_id', $user->id)->orderBy('id')->get() as $receipt) {
            $definition = config('hakoniwa.ruleset.user_achievements.'.$receipt->achievement_key);
            if (! is_array($definition) || $receipt->title_key !== $definition['title_key']) {
                throw new DomainException('取得済み実績の定義が現在のRulesetと一致しません。');
            }
            $achievements[] = [
                'key' => $receipt->achievement_key,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'acquired_at' => $receipt->acquired_at->toIso8601String(),
            ];
            $titles[$receipt->title_key] = [
                'key' => $receipt->title_key,
                'name' => $definition['title_name'],
            ];
        }

        return ['achievements' => $achievements, 'titles' => array_values($titles)];
    }
}
