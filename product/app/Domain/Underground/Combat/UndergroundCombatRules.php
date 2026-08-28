<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;

final class UndergroundCombatRules
{
    public const IDENTITY = 'secretary-underground-alpha-v0';

    public const SIMULATOR_VERSION = 'underground-balance-v1';

    public const AI_PRESET = 'built_in_v0';

    public const RESOURCE_CAP = 100;

    public const NORMAL_ATTACK_POWER = 100;

    public const NORMAL_ATTACK_RESOURCE_GAIN = 22;

    public const DEFEND_RESOURCE_GAIN = 32;

    public const GUARD_DAMAGE_PERCENT = 55;

    public const MINIMUM_MAX_ROUNDS = 1;

    public const MAXIMUM_MAX_ROUNDS = 200;

    /** @var list<string> */
    public const KNIFE_SKILLS = [
        'quick_slash',
        'piercing_thrust',
        'mending_light',
        'crystal_burst',
    ];

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     weapon_key: string,
     *     max_hp: int,
     *     attack: int,
     *     defense: int,
     *     speed: int,
     *     available_skills: list<string>
     * }
     */
    public function actor(string $key): array
    {
        if ($key !== 'knife_initiate') {
            throw new InvalidArgumentException("Unknown Underground actor [{$key}].");
        }

        return [
            'key' => 'knife_initiate',
            'label' => 'ナイフを手にした秘書',
            'weapon_key' => 'starter_knife',
            'max_hp' => 432,
            'attack' => 112,
            'defense' => 88,
            'speed' => 92,
            'available_skills' => self::KNIFE_SKILLS,
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     role: string,
     *     behavior: string,
     *     max_hp: int,
     *     attack: int,
     *     defense: int,
     *     speed: int
     * }
     */
    public function enemy(string $key): array
    {
        $definition = match ($key) {
            'cave_crawler' => [
                'label' => '穴這い',
                'role' => 'standard',
                'behavior' => 'standard',
                'max_hp' => 429,
                'attack' => 175,
                'defense' => 70,
                'speed' => 70,
            ],
            'needle_bat' => [
                'label' => '針羽コウモリ',
                'role' => 'fast_fragile',
                'behavior' => 'fast',
                'max_hp' => 340,
                'attack' => 158,
                'defense' => 38,
                'speed' => 128,
            ],
            'stone_shell' => [
                'label' => '岩殻虫',
                'role' => 'armored',
                'behavior' => 'armored',
                'max_hp' => 520,
                'attack' => 140,
                'defense' => 165,
                'speed' => 42,
            ],
            'gloom_herald' => [
                'label' => '闇告げ',
                'role' => 'telegraphed_threat',
                'behavior' => 'telegraph',
                'max_hp' => 460,
                'attack' => 175,
                'defense' => 82,
                'speed' => 64,
            ],
            default => throw new InvalidArgumentException("Unknown Underground enemy [{$key}]."),
        };

        return ['key' => $key, ...$definition];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     kind: 'damage'|'heal',
     *     power: int,
     *     cooldown: int,
     *     resource_cost: int,
     *     resource_gain: int,
     *     defense_ignore_percent: int
     * }
     */
    public function skill(string $key): array
    {
        $definition = match ($key) {
            'quick_slash' => [
                'label' => '連続斬り',
                'kind' => 'damage',
                'power' => 138,
                'cooldown' => 2,
                'resource_cost' => 0,
                'resource_gain' => 18,
                'defense_ignore_percent' => 0,
            ],
            'piercing_thrust' => [
                'label' => '鎧通し',
                'kind' => 'damage',
                'power' => 122,
                'cooldown' => 3,
                'resource_cost' => 0,
                'resource_gain' => 12,
                'defense_ignore_percent' => 60,
            ],
            'mending_light' => [
                'label' => '癒やしの灯',
                'kind' => 'heal',
                'power' => 78,
                'cooldown' => 4,
                'resource_cost' => 0,
                'resource_gain' => 8,
                'defense_ignore_percent' => 0,
            ],
            'crystal_burst' => [
                'label' => '輝石一閃',
                'kind' => 'damage',
                'power' => 225,
                'cooldown' => 0,
                'resource_cost' => self::RESOURCE_CAP,
                'resource_gain' => 0,
                'defense_ignore_percent' => 20,
            ],
            default => throw new InvalidArgumentException("Unknown Underground skill [{$key}]."),
        };

        return ['key' => $key, ...$definition];
    }

    /** @return list<string> */
    public function enemyKeys(): array
    {
        return ['cave_crawler', 'needle_bat', 'stone_shell', 'gloom_herald'];
    }

    /** @param array<int, mixed> $skillKeys */
    public function assertLoadout(array $skillKeys): void
    {
        if ($skillKeys === [] || count($skillKeys) > 5 || ! array_is_list($skillKeys)) {
            throw new InvalidArgumentException('Underground actor loadout must contain one to five ordered skills.');
        }
        if (count($skillKeys) !== count(array_unique($skillKeys))) {
            throw new InvalidArgumentException('Underground actor loadout must not contain duplicate skills.');
        }

        foreach ($skillKeys as $skillKey) {
            if (! is_string($skillKey) || ! in_array($skillKey, self::KNIFE_SKILLS, true)) {
                throw new InvalidArgumentException('Underground actor loadout contains an unsupported skill.');
            }
        }
    }

    public function assertMaxRounds(int $maxRounds): void
    {
        if ($maxRounds < self::MINIMUM_MAX_ROUNDS || $maxRounds > self::MAXIMUM_MAX_ROUNDS) {
            throw new InvalidArgumentException('Underground max rounds must be between 1 and 200.');
        }
    }
}
