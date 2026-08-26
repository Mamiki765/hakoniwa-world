<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryItemGameplayContract
{
    public const PRE_NORMAL_MONSTER_ATTACK = 'pre_normal_monster_attack';

    public const FINANCE_INCOME_BONUS = 'finance_income_bonus';

    public const EXPERIENCE_DOUBLE_CHANCE = 'secretary_experience_double_chance';

    public const NATURAL_MONSTER_SPAWN_PERCENT = 'natural_monster_spawn_percent';

    public const CAPACITY_PERCENT = 'capacity_percent';

    public const KARMA_MINIMUM_DELTA = 'karma_minimum_delta';

    public const SOURCE_GENRE_ITEM = 'item';

    public const CAPACITY_ALL_RESOURCES = 'all_nation_resources';

    public const CAPACITY_MONEY = 'money';

    public const CAPACITY_FOOD = 'food_aggregate';

    public const OLD_BOW_TIMING = 'after_missile_finalization_before_normal_monsters';

    public const REQUIRED_NORMAL_MONSTER_STAGE = 'after_ordinary_surface_cell_events';

    public const OLD_BOW_DAMAGE_TYPE = 'secretary_old_bow';

    public const OLD_BOW_TARGET_SCOPE = 'owned_territory';

    public const OLD_BOW_TARGET_SAFETY = 'avoid_ineffective_or_immediate_hazard';

    public const RING_STACKING = 'sum_equipped_levels';

    private const CURRENT_RULESET_KEY = 'hakoniwa-2s-plus-v16';

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

        $current = ($settings['key'] ?? null) === self::CURRENT_RULESET_KEY;
        $secretary = $this->map($settings['secretary'] ?? null, 'ruleset.secretary');
        if ($current) {
            $rarities = $this->map($secretary['item_rarities'] ?? null, 'ruleset.secretary.item_rarities');
            $this->exactDefinitionKeys(
                $rarities,
                [SecretaryItemCatalog::RARITY_NOVICE],
                'ruleset.secretary.item_rarities',
            );
            $novice = $this->map(
                $rarities[SecretaryItemCatalog::RARITY_NOVICE],
                'ruleset.secretary.item_rarities.novice',
            );
            $this->exactKeys($novice, ['key', 'name'], 'ruleset.secretary.item_rarities.novice');
            if ($novice !== ['key' => SecretaryItemCatalog::RARITY_NOVICE, 'name' => 'ノービス']) {
                throw new DomainException('ruleset.secretary.item_rarities.novice differs from the v16 contract.');
            }
        }

        $categories = $this->map($secretary['item_categories'] ?? null, 'ruleset.secretary.item_categories');
        $items = $this->map($secretary['items'] ?? null, 'ruleset.secretary.items');
        $expectedCategories = $current ? ['accessory', 'bow', 'clothing'] : ['bow', 'ring'];
        $expectedItems = $current
            ? array_keys($this->catalog->definitions())
            : [SecretaryItemCatalog::OLD_BOW, SecretaryItemCatalog::RING];
        $this->exactDefinitionKeys($categories, $expectedCategories, 'ruleset.secretary.item_categories');
        $this->exactDefinitionKeys($items, $expectedItems, 'ruleset.secretary.items');

        foreach ($categories as $categoryKey => $authored) {
            $path = "ruleset.secretary.item_categories.{$categoryKey}";
            $category = $this->map($authored, $path);
            $this->exactKeys($category, ['key', 'max_equipped'], $path);
            $expectedMaximum = $current
                ? $this->catalog->maximumEquipped($categoryKey)
                : ($categoryKey === 'bow' ? 1 : 5);
            if (($category['key'] ?? null) !== $categoryKey
                || $this->integer($category['max_equipped'] ?? null, "{$path}.max_equipped", 1) !== $expectedMaximum) {
                throw new DomainException("{$path} differs from the supported equipment catalog.");
            }
        }

        foreach ($items as $itemKey => $authored) {
            $path = "ruleset.secretary.items.{$itemKey}";
            $item = $this->map($authored, $path);
            if ($current) {
                $this->exactKeys($item, [
                    'key', 'category', 'rarity', 'tradable', 'npc_tradable', 'max_level', 'effects',
                ], $path);
                $catalog = $this->catalog->definition($itemKey);
                if (($item['key'] ?? null) !== $itemKey
                    || ($item['category'] ?? null) !== $catalog['category']
                    || $this->integer($item['max_level'] ?? null, "{$path}.max_level", 1) !== $catalog['max_level']
                    || ($item['rarity'] ?? null) !== $catalog['rarity']
                    || ($item['tradable'] ?? null) !== $catalog['tradable']
                    || ($item['npc_tradable'] ?? null) !== $catalog['npc_tradable']
                    || $this->catalog->sameItemMaximum($itemKey) !== 1) {
                    throw new DomainException("{$path} differs from the global equipment catalog.");
                }
            } else {
                $this->exactKeys($item, [
                    'key', 'category', 'max_level', 'same_item_max_equipped', 'effects',
                ], $path);
                $legacy = $itemKey === SecretaryItemCatalog::OLD_BOW
                    ? ['category' => 'bow', 'max_level' => 1, 'same_item_max_equipped' => 1]
                    : ['category' => 'ring', 'max_level' => 10, 'same_item_max_equipped' => 5];
                if (($item['key'] ?? null) !== $itemKey
                    || ($item['category'] ?? null) !== $legacy['category']
                    || ($item['max_level'] ?? null) !== $legacy['max_level']
                    || ($item['same_item_max_equipped'] ?? null) !== $legacy['same_item_max_equipped']) {
                    throw new DomainException("{$path} differs from the retained historical equipment contract.");
                }
            }

            $effects = $this->list($item['effects'] ?? null, "{$path}.effects");
            if (count($effects) !== 1) {
                throw new DomainException("{$path}.effects must contain exactly one effect.");
            }
            $this->validateEffect($itemKey, $this->map($effects[0], "{$path}.effects.0"), "{$path}.effects.0");
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    public function resolvedEffects(array $settings, string $itemKey, int $level): array
    {
        $effects = $this->validatedEffectCatalog($settings);
        if ($effects === []) {
            return [];
        }
        $catalog = $this->catalog->definition($itemKey);
        if ($level < 1 || $level > $catalog['max_level']) {
            throw new DomainException("Secretary item {$itemKey} level is outside the global catalog.");
        }

        return $effects[$itemKey]
            ?? throw new DomainException("Ruleset Secretary item {$itemKey} is missing.");
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, list<array<string, mixed>>>
     */
    public function validatedEffectCatalog(array $settings): array
    {
        $this->validate($settings);
        if (! $this->exists($settings)) {
            return [];
        }

        $effects = [];
        foreach (array_keys($settings['secretary']['items']) as $itemKey) {
            $effect = $settings['secretary']['items'][$itemKey]['effects'][0] ?? null;
            if (! is_array($effect)) {
                throw new DomainException("Ruleset Secretary item {$itemKey} is missing its effect.");
            }
            $effects[$itemKey] = $this->resolvedAuthoredEffect($effect);
        }

        return $effects;
    }

    /**
     * @param  array<string, mixed>  $effect
     * @return list<array<string, mixed>>
     */
    private function resolvedAuthoredEffect(array $effect): array
    {
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

        $type = $effect['type'];
        $parameters = $effect;
        unset($parameters['type'], $parameters['random_stream_version']);
        $timing = match ($type) {
            self::FINANCE_INCOME_BONUS => 'finance_resolution',
            self::EXPERIENCE_DOUBLE_CHANCE => 'secretary_experience_award',
            self::NATURAL_MONSTER_SPAWN_PERCENT => 'normal_monster_natural_spawn',
            self::CAPACITY_PERCENT => 'capacity_resolution',
            self::KARMA_MINIMUM_DELTA => 'karma_turn_start',
            default => throw new DomainException('Unknown Secretary Item effect type.'),
        };

        return [[
            'type' => $type,
            'timing' => $timing,
            'parameters' => $parameters,
            'target_map_space_keys' => [],
            'random_stream_version' => $effect['random_stream_version'] ?? null,
        ]];
    }

    /** @param array<string, mixed> $settings */
    public function effectText(array $settings, string $itemKey, int $level): ?string
    {
        $effects = $this->resolvedEffects($settings, $itemKey, $level);
        if ($effects === []) {
            return null;
        }

        return match ($effects[0]['type']) {
            self::PRE_NORMAL_MONSTER_ATTACK => sprintf(
                '%s%%の確率で、自領の地上にいる怪獣に%dダメージを与える。',
                $this->percentage((int) $effects[0]['parameters']['chance_basis_points']),
                $effects[0]['parameters']['damage'],
            ),
            self::FINANCE_INCOME_BONUS => sprintf(
                '資金繰りの際、追加で%d億円を得る。',
                $level * (int) $effects[0]['parameters']['bonus_money_per_level'],
            ),
            self::EXPERIENCE_DOUBLE_CHANCE => "秘書本人が経験値を得る際、{$level}%の確率でその獲得経験値を2倍にする。",
            self::NATURAL_MONSTER_SPAWN_PERCENT => sprintf(
                '自島の通常怪獣自然出現率 %s%d%%',
                $effects[0]['parameters']['percent_per_level'] > 0 ? '+' : '-',
                abs($level * $effects[0]['parameters']['percent_per_level']),
            ),
            self::CAPACITY_PERCENT => match ($effects[0]['parameters']['target']) {
                self::CAPACITY_ALL_RESOURCES => "あらゆる国家資源の最大保有量 +{$level}%",
                self::CAPACITY_MONEY => "資金最大値 +{$level}%",
                self::CAPACITY_FOOD => '食料最大値 +'.($level * 2).'%',
                default => throw new DomainException('Unknown Secretary Item capacity target.'),
            },
            self::KARMA_MINIMUM_DELTA => "カルマの下限を{$level}低くする。",
            default => throw new DomainException('Unknown Secretary Item effect type.'),
        };
    }

    /** @param array<string, mixed> $effect */
    private function validateEffect(string $itemKey, array $effect, string $path): void
    {
        match ($itemKey) {
            SecretaryItemCatalog::OLD_BOW => $this->validateOldBow($effect, $path),
            SecretaryItemCatalog::RING => $this->validateRing($effect, $path),
            SecretaryItemCatalog::SECRETARY_SUIT => $this->validateExperienceDouble($effect, $path),
            SecretaryItemCatalog::INORA_BRACELET => $this->validateNaturalSpawn($effect, $path, 10),
            SecretaryItemCatalog::MONSTER_REPELLENT_INCENSE => $this->validateNaturalSpawn($effect, $path, -1),
            SecretaryItemCatalog::HOARDER_TALISMAN => $this->validateCapacity(
                $effect, $path, self::CAPACITY_ALL_RESOURCES, 1,
            ),
            SecretaryItemCatalog::VAULT_KEY => $this->validateCapacity(
                $effect, $path, self::CAPACITY_MONEY, 1,
            ),
            SecretaryItemCatalog::FULLNESS_HERB => $this->validateCapacity(
                $effect, $path, self::CAPACITY_FOOD, 2,
            ),
            SecretaryItemCatalog::GOOD_PERSON_TREASURE => $this->validateKarmaMinimum($effect, $path),
            default => throw new DomainException("{$path} belongs to an unknown Secretary Item."),
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
        if ($this->list($effect['target_map_space_keys'] ?? null, "{$path}.target_map_space_keys") !== ['surface']) {
            throw new DomainException("{$path}.target_map_space_keys must contain only surface.");
        }
    }

    /** @param array<string, mixed> $effect */
    private function validateRing(array $effect, string $path): void
    {
        $this->exactKeys($effect, ['type', 'bonus_money_per_level', 'stacking'], $path);
        if (($effect['type'] ?? null) !== self::FINANCE_INCOME_BONUS
            || ($effect['stacking'] ?? null) !== self::RING_STACKING
            || ($effect['bonus_money_per_level'] ?? null) !== 1) {
            throw new DomainException("{$path} is not the supported Ring effect contract.");
        }
    }

    /** @param array<string, mixed> $effect */
    private function validateExperienceDouble(array $effect, string $path): void
    {
        $this->exactKeys($effect, [
            'type', 'chance_percent_per_level', 'multiplier', 'sources', 'draw_unit', 'random_stream_version',
        ], $path);
        if (($effect['type'] ?? null) !== self::EXPERIENCE_DOUBLE_CHANCE
            || ($effect['chance_percent_per_level'] ?? null) !== 1
            || ($effect['multiplier'] ?? null) !== 2
            || ($effect['sources'] ?? null) !== ['passive_skill_experience', 'monster_experience']
            || ($effect['draw_unit'] ?? null) !== 'canonical_award_event'
            || ($effect['random_stream_version'] ?? null) !== 1) {
            throw new DomainException("{$path} is not the supported Secretary Suit effect contract.");
        }
    }

    /** @param array<string, mixed> $effect */
    private function validateNaturalSpawn(array $effect, string $path, int $percentPerLevel): void
    {
        $this->exactKeys($effect, [
            'type', 'source_genre', 'target', 'percent_per_level', 'minimum_final_probability',
        ], $path);
        if (($effect['type'] ?? null) !== self::NATURAL_MONSTER_SPAWN_PERCENT
            || ($effect['source_genre'] ?? null) !== self::SOURCE_GENRE_ITEM
            || ($effect['target'] ?? null) !== 'normal_nation_natural_spawn'
            || ($effect['percent_per_level'] ?? null) !== $percentPerLevel
            || ($effect['minimum_final_probability'] ?? null) !== 0) {
            throw new DomainException("{$path} is not a supported natural monster spawn modifier.");
        }
    }

    /** @param array<string, mixed> $effect */
    private function validateCapacity(
        array $effect,
        string $path,
        string $target,
        int $percentPerLevel,
    ): void {
        $this->exactKeys($effect, ['type', 'source_genre', 'target', 'percent_per_level', 'rounding'], $path);
        if (($effect['type'] ?? null) !== self::CAPACITY_PERCENT
            || ($effect['source_genre'] ?? null) !== self::SOURCE_GENRE_ITEM
            || ($effect['target'] ?? null) !== $target
            || ($effect['percent_per_level'] ?? null) !== $percentPerLevel
            || ($effect['rounding'] ?? null) !== 'floor_after_all_source_genres') {
            throw new DomainException("{$path} is not a supported capacity modifier.");
        }
    }

    /** @param array<string, mixed> $effect */
    private function validateKarmaMinimum(array $effect, string $path): void
    {
        $this->exactKeys($effect, ['type', 'lower_minimum_per_level', 'snapshot_timing'], $path);
        if (($effect['type'] ?? null) !== self::KARMA_MINIMUM_DELTA
            || ($effect['lower_minimum_per_level'] ?? null) !== 1
            || ($effect['snapshot_timing'] ?? null) !== 'turn_start') {
            throw new DomainException("{$path} is not the supported Karma minimum modifier.");
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
            throw new DomainException("{$path} must contain the exact supported keys.");
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
