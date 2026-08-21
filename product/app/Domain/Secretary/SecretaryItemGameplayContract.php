<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryItemGameplayContract
{
    public const PRE_NORMAL_MONSTER_ATTACK = 'pre_normal_monster_attack';

    public const FINANCE_INCOME_BONUS = 'finance_income_bonus';

    public const OLD_BOW_TIMING = 'after_missile_finalization_before_normal_monsters';

    public const REQUIRED_NORMAL_MONSTER_STAGE = 'after_ordinary_surface_cell_events';

    public const OLD_BOW_DAMAGE_TYPE = 'secretary_old_bow';

    public const OLD_BOW_TARGET_SCOPE = 'owned_territory';

    public const OLD_BOW_TARGET_SAFETY = 'avoid_ineffective_or_immediate_hazard';

    public const RING_STACKING = 'sum_equipped_levels';

    public function __construct(private readonly SecretaryItemCatalog $catalog) {}

    /** @param array<string, mixed> $settings */
    public function exists(array $settings): bool
    {
        $secretary = $settings['secretary'] ?? null;

        return is_array($secretary)
            && (array_key_exists('item_categories', $secretary) || array_key_exists('items', $secretary));
    }

    /** @param array<string, mixed> $settings */
    public function validate(array $settings): void
    {
        if (! $this->exists($settings)) {
            return;
        }

        $turnResolution = $settings['turn_resolution'] ?? null;
        if (! is_array($turnResolution)
            || ($turnResolution['normal_monster_stage'] ?? null) !== self::REQUIRED_NORMAL_MONSTER_STAGE) {
            throw new DomainException(
                'ruleset.turn_resolution.normal_monster_stage must be '
                .self::REQUIRED_NORMAL_MONSTER_STAGE
                .' when Secretary Item definitions exist.',
            );
        }

        $secretary = $this->map($settings['secretary'] ?? null, 'ruleset.secretary');
        $categories = $this->map($secretary['item_categories'] ?? null, 'ruleset.secretary.item_categories');
        $items = $this->map($secretary['items'] ?? null, 'ruleset.secretary.items');
        $this->exactDefinitionKeys($categories, ['bow', 'ring'], 'ruleset.secretary.item_categories');
        $this->exactDefinitionKeys(
            $items,
            [SecretaryItemCatalog::OLD_BOW, SecretaryItemCatalog::RING],
            'ruleset.secretary.items',
        );

        foreach ($categories as $categoryKey => $authored) {
            $path = "ruleset.secretary.item_categories.{$categoryKey}";
            $category = $this->map($authored, $path);
            $this->exactKeys($category, ['key', 'max_equipped'], $path);
            if (($category['key'] ?? null) !== $categoryKey) {
                throw new DomainException("{$path}.key must match its authored key.");
            }
            $expected = $this->catalog->maximumEquipped($categoryKey);
            if ($this->integer($category['max_equipped'] ?? null, "{$path}.max_equipped", 1) !== $expected) {
                throw new DomainException("{$path}.max_equipped differs from the global equipment catalog.");
            }
        }

        foreach ($items as $itemKey => $authored) {
            $path = "ruleset.secretary.items.{$itemKey}";
            $item = $this->map($authored, $path);
            $this->exactKeys(
                $item,
                ['key', 'category', 'max_level', 'same_item_max_equipped', 'effects'],
                $path,
            );
            $catalog = $this->catalog->definition($itemKey);
            if (($item['key'] ?? null) !== $itemKey
                || ($item['category'] ?? null) !== $catalog['category']
                || $this->integer($item['max_level'] ?? null, "{$path}.max_level", 1) !== $catalog['max_level']
                || $this->integer(
                    $item['same_item_max_equipped'] ?? null,
                    "{$path}.same_item_max_equipped",
                    1,
                ) !== $catalog['same_item_max_equipped']) {
                throw new DomainException("{$path} differs from the global equipment catalog.");
            }
            $effects = $this->list($item['effects'] ?? null, "{$path}.effects");
            if (count($effects) !== 1) {
                throw new DomainException("{$path}.effects must contain exactly one C2 effect.");
            }
            $effect = $this->map($effects[0], "{$path}.effects.0");
            if ($itemKey === SecretaryItemCatalog::OLD_BOW) {
                $this->validateOldBow($effect, "{$path}.effects.0");
            } else {
                $this->validateRing($effect, "{$path}.effects.0");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    public function resolvedEffects(array $settings, string $itemKey, int $level): array
    {
        $this->validate($settings);
        if (! $this->exists($settings)) {
            return [];
        }
        $catalog = $this->catalog->definition($itemKey);
        if ($level < 1 || $level > $catalog['max_level']) {
            throw new DomainException("Secretary item {$itemKey} level is outside the global catalog.");
        }
        $item = $settings['secretary']['items'][$itemKey] ?? null;
        if (! is_array($item)) {
            throw new DomainException("Ruleset Secretary item {$itemKey} is missing.");
        }
        $effect = $item['effects'][0];
        if (($effect['type'] ?? null) === self::PRE_NORMAL_MONSTER_ATTACK) {
            return [[
                'type' => self::PRE_NORMAL_MONSTER_ATTACK,
                'timing' => $effect['timing'],
                'parameters' => [
                    'chance_basis_points' => $effect['chance_basis_points'],
                    'damage' => $effect['damage'],
                    'damage_type' => $effect['damage_type'],
                    'target_scope' => $effect['target_scope'],
                    'target_safety_policy' => $effect['target_safety_policy'],
                ],
                'target_map_space_keys' => $effect['target_map_space_keys'],
                'random_stream_version' => $effect['random_stream_version'],
            ]];
        }

        return [[
            'type' => self::FINANCE_INCOME_BONUS,
            'timing' => 'finance_resolution',
            'parameters' => [
                'bonus_money_per_level' => $effect['bonus_money_per_level'],
                'stacking' => $effect['stacking'],
            ],
            'target_map_space_keys' => [],
            'random_stream_version' => null,
        ]];
    }

    /** @param array<string, mixed> $settings */
    public function effectText(array $settings, string $itemKey, int $level): ?string
    {
        $effects = $this->resolvedEffects($settings, $itemKey, $level);
        if ($effects === []) {
            return null;
        }
        $effect = $effects[0];

        return match ($effect['type']) {
            self::PRE_NORMAL_MONSTER_ATTACK => sprintf(
                '%s%%の確率で、自領の地上にいる怪獣に%dダメージを与える。',
                $this->percentage((int) $effect['parameters']['chance_basis_points']),
                $effect['parameters']['damage'],
            ),
            self::FINANCE_INCOME_BONUS => sprintf(
                '資金繰りの際、追加で%d億円を得る。',
                $level * (int) $effect['parameters']['bonus_money_per_level'],
            ),
            default => throw new DomainException('Unknown Secretary Item effect type.'),
        };
    }

    /** @param array<string, mixed> $effect */
    private function validateOldBow(array $effect, string $path): void
    {
        $this->exactKeys($effect, [
            'type', 'timing', 'chance_basis_points', 'damage', 'damage_type', 'target_scope',
            'target_map_space_keys', 'target_safety_policy', 'random_stream_version',
        ], $path);
        if (($effect['type'] ?? null) !== self::PRE_NORMAL_MONSTER_ATTACK
            || ($effect['timing'] ?? null) !== self::OLD_BOW_TIMING
            || ($effect['damage_type'] ?? null) !== self::OLD_BOW_DAMAGE_TYPE
            || ($effect['target_scope'] ?? null) !== self::OLD_BOW_TARGET_SCOPE
            || ($effect['target_safety_policy'] ?? null) !== self::OLD_BOW_TARGET_SAFETY) {
            throw new DomainException("{$path} is not the supported Old Bow effect contract.");
        }
        $chance = $this->integer($effect['chance_basis_points'] ?? null, "{$path}.chance_basis_points", 0);
        if ($chance > 10_000) {
            throw new DomainException("{$path}.chance_basis_points cannot exceed 10000.");
        }
        $this->integer($effect['damage'] ?? null, "{$path}.damage", 1);
        $this->integer($effect['random_stream_version'] ?? null, "{$path}.random_stream_version", 1);
        $targetSpaces = $this->list($effect['target_map_space_keys'] ?? null, "{$path}.target_map_space_keys");
        if ($targetSpaces !== ['surface']) {
            throw new DomainException("{$path}.target_map_space_keys must contain only surface in C2.");
        }
    }

    /** @param array<string, mixed> $effect */
    private function validateRing(array $effect, string $path): void
    {
        $this->exactKeys($effect, ['type', 'bonus_money_per_level', 'stacking'], $path);
        if (($effect['type'] ?? null) !== self::FINANCE_INCOME_BONUS
            || ($effect['stacking'] ?? null) !== self::RING_STACKING) {
            throw new DomainException("{$path} is not the supported Ring effect contract.");
        }
        if ($this->integer($effect['bonus_money_per_level'] ?? null, "{$path}.bonus_money_per_level", 1) !== 1) {
            throw new DomainException("{$path}.bonus_money_per_level must be 1 in C2.");
        }
    }

    /**
     * @param  array<string, mixed>  $definitions
     * @param  list<string>  $expected
     */
    private function exactDefinitionKeys(array $definitions, array $expected, string $path): void
    {
        $actual = array_keys($definitions);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new DomainException("{$path} must contain the exact C2 keys.");
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private function exactKeys(array $value, array $expected, string $path): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new DomainException("{$path} contains missing or unknown fields.");
        }
    }

    /** @return array<string, mixed> */
    private function map(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new DomainException("{$path} must be an object map.");
        }

        return $value;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new DomainException("{$path} must be a list.");
        }

        return $value;
    }

    private function integer(mixed $value, string $path, int $minimum): int
    {
        if (! is_int($value) || $value < $minimum) {
            throw new DomainException("{$path} must be an integer of at least {$minimum}.");
        }

        return $value;
    }

    private function percentage(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.');
    }
}
