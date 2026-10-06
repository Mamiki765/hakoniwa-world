<?php

namespace App\Application;

use App\Models\Secretary;
use App\Models\User;
use App\Models\UserAchievement;
use DomainException;
use Illuminate\Support\Facades\DB;

class UserAchievementService
{
    public const ISLAND_SECRETARY = 'island_secretary';

    // 呼出元の命名transactionで、実績と肩書きを同時に保存する。
    // unique(user_id, achievement_key)により再実行でも取得は一回だけ。
    /** @return array{name: string, title_name: string}|null */
    public function grantIslandSecretary(Secretary $secretary): ?array
    {
        if ($secretary->name === null || $secretary->named_at === null) {
            throw new DomainException('秘書を命名する前に「島の秘書」は取得できません。');
        }
        $definition = config('hakoniwa.ruleset.user_achievements.'.self::ISLAND_SECRETARY);
        if (! is_array($definition) || $definition['condition'] !== 'secretary_named') {
            throw new DomainException('「島の秘書」の命名条件が現在のRulesetにありません。');
        }
        $inserted = UserAchievement::query()->insertOrIgnore([
            'user_id' => $secretary->user_id,
            'achievement_key' => self::ISLAND_SECRETARY,
            'title_key' => $definition['title_key'],
            'acquired_at' => $secretary->named_at,
        ]);
        if ($secretary->equipped_title_key === null) {
            $secretary->update(['equipped_title_key' => $definition['title_key']]);
        }

        return $inserted === 1 ? ['name' => $definition['name'], 'title_name' => $definition['title_name']] : null;
    }

    /** @return array{key: string, name: string}|null */
    public function equippedTitle(Secretary $secretary): ?array
    {
        if ($secretary->equipped_title_key === null) {
            return null;
        }
        $definitions = config('hakoniwa.ruleset.user_achievements');
        foreach (is_array($definitions) ? $definitions : [] as $key => $definition) {
            if ($definition['title_key'] === $secretary->equipped_title_key
                && UserAchievement::query()->where('user_id', $secretary->user_id)->where('achievement_key', $key)->exists()) {
                return ['key' => $definition['title_key'], 'name' => $definition['title_name']];
            }
        }

        throw new DomainException('秘書に装備した肩書きが取得済み実績と一致しません。');
    }

    public function equipTitle(User $user, string $titleKey): Secretary
    {
        return DB::transaction(function () use ($user, $titleKey): Secretary {
            $secretary = Secretary::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $secretary instanceof Secretary || $secretary->name === null || $secretary->named_at === null) {
                throw new DomainException('命名済みの秘書だけが肩書きを装備できます。');
            }
            if (! UserAchievement::query()->where('user_id', $user->id)->where('title_key', $titleKey)->exists()) {
                throw new DomainException('取得済み実績に対応する肩書きだけを装備できます。');
            }
            $secretary->update(['equipped_title_key' => $titleKey]);

            return $secretary->load('skills');
        });
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
