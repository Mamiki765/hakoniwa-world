<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;

final class UndergroundAwakening
{
    public const IDENTITY = 'secretary-underground-awakening-v1';

    public const GAUGE_MAX = 1_000;

    public const ROUND_GAIN = 15;

    public const DAMAGING_ENEMY_ACTION_GAIN = 15;

    public const ACTIVATION_HP_BPS = 2_000;

    public const STAT_BONUS_BPS = 3_000;

    public const MESSAGE_MAX_LENGTH = 100;

    public const DEFAULT_MESSAGE = '魔力が{secretary_name}の全身を駆け巡る――！';

    public const GUARDIAN_DURATION_ROUNDS = 2;

    public const GUARDIAN_DAMAGE_REDUCTION_BPS = 9_000;

    public const BLESSING_USE_HP_BPS = 4_500;

    public const FREE_USE_MP_BPS = 5_000;

    public const FREE_USE_COOLDOWN_COUNT = 2;

    public const MARTIAL_POTENCY_BPS = 35_000;

    /** @return array{key: string, name: string, summary: string, consumes_action: bool} */
    public function technique(string $growthPath): array
    {
        return match ($growthPath) {
            'martial_red' => [
                'key' => 'decisive_heavenrend',
                'name' => '天断一閃',
                'summary' => 'current enemy 1体へ極めて大きなdamageを与える。',
                'consumes_action' => true,
            ],
            'guardianship_blue' => [
                'key' => 'absolute_aegis',
                'name' => '絶対護界',
                'summary' => '発動後2ラウンドのあいだ、direct damageを90%軽減する。',
                'consumes_action' => true,
            ],
            'blessing_green' => [
                'key' => 'life_requiem',
                'name' => '生命讃歌',
                'summary' => 'current soloでは自身のHPを全回復する。MPは回復しない。',
                'consumes_action' => true,
            ],
            'free_black' => [
                'key' => 'limitless_reprise',
                'name' => '無窮再演',
                'summary' => 'MPを全回復し、通常active skillのcooldownを全解除。そのまま行動。',
                'consumes_action' => false,
            ],
            default => throw new InvalidArgumentException('Underground awakening growth path is invalid.'),
        };
    }

    public function addGauge(int $current, int $gain): int
    {
        if ($current < 0 || $current > self::GAUGE_MAX || $gain < 0) {
            throw new InvalidArgumentException('Underground awakening gauge input is invalid.');
        }

        return min(self::GAUGE_MAX, $current + $gain);
    }

    public function awakenedStat(int $normal): int
    {
        if ($normal < 1) {
            throw new InvalidArgumentException('Underground awakening stat input is invalid.');
        }

        return $normal + intdiv($normal * self::STAT_BONUS_BPS, 10_000);
    }

    public function tryActivate(BuildCombatState $player, AlphaV1CombatRules $rules): bool
    {
        if (! $player->awakeningUnlocked
            || $player->awakened
            || $player->awakeningGauge < self::GAUGE_MAX
            || ($player->hp * 10_000) > ($player->maxHp * self::ACTIVATION_HP_BPS)) {
            return false;
        }

        foreach (AlphaV1CombatRules::STATS as $stat) {
            $player->stats[$stat] = $this->awakenedStat($player->normalStats[$stat]);
        }
        $player->maxHp = $rules->maxHp($player->stats, 10_000, $player->equipmentMaxHp);
        $player->physicalDefense = $player->equipmentPhysicalDefense + ($player->stats['vitality'] * 4);
        $player->magicalDefense = $player->equipmentMagicalDefense + ($player->stats['spirit'] * 4);
        $player->hp = $player->maxHp;
        $player->mp = AlphaV1CombatRules::MAX_MP;
        $player->awakeningGauge = 0;
        $player->awakened = true;

        return true;
    }

    public function normalizeMessage(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }
        if (mb_strlen($message) > self::MESSAGE_MAX_LENGTH || preg_match('/[\r\n]/u', $message) === 1) {
            throw new InvalidArgumentException('Underground awakening message is invalid.');
        }

        return $message;
    }

    public function renderMessage(?string $message, string $secretaryName): string
    {
        $template = $this->normalizeMessage($message) ?? self::DEFAULT_MESSAGE;

        return str_replace('{secretary_name}', $secretaryName, $template);
    }
}
