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

    public const REFUGEE_GENERATION_PERCENT = 'refugee_generation_percent';

    public const KARMA_CRIME_DOUBLE_CHANCE = 'karma_crime_double_chance';

    public const SOURCE_GENRE_ITEM = 'item';

    public const CAPACITY_ALL_RESOURCES = 'all_nation_resources';

    public const CAPACITY_MONEY = 'money';

    public const CAPACITY_FOOD = 'food_aggregate';

    public const OLD_BOW_TIMING = 'after_missile_finalization_before_normal_monsters';

    public const REQUIRED_NORMAL_MONSTER_STAGE = 'after_ordinary_surface_cell_events';

    public const OLD_BOW_DAMAGE_TYPE = 'secretary_old_bow';

    public const ELF_BOW_DAMAGE_TYPE = 'secretary_elf_bow';

    public const LONGSHOT_BOW_DAMAGE_TYPE = 'secretary_longshot_bow';

    public const MECHANICAL_BOW_DAMAGE_TYPE = 'secretary_mechanical_bow';

    public const OLD_BOW_TARGET_SCOPE = 'owned_territory';

    public const OLD_BOW_TARGET_SAFETY = 'avoid_ineffective_or_immediate_hazard';

    public const RING_STACKING = 'sum_equipped_levels';

    private const V16_RULESET_KEY = 'hakoniwa-2s-plus-v16';

    private const V17_RULESET_KEY = 'hakoniwa-2s-plus-v17';

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

        $rulesetKey = $settings['key'] ?? null;
        $formal = in_array($rulesetKey, [self::V16_RULESET_KEY, self::V17_RULESET_KEY], true);
        $v17 = $rulesetKey === self::V17_RULESET_KEY;
        $secretary = $this->map($settings['secretary'] ?? null, 'ruleset.secretary');
        if ($formal) {
            $rarities = $this->map($secretary['item_rarities'] ?? null, 'ruleset.secretary.item_rarities');
            $this->exactDefinitionKeys(
                $rarities,
                $v17
                    ? [SecretaryItemCatalog::RARITY_NOVICE, SecretaryItemCatalog::RARITY_REGULAR, SecretaryItemCatalog::RARITY_CURSED]
                    : [SecretaryItemCatalog::RARITY_NOVICE],
                'ruleset.secretary.item_rarities',
            );
            foreach ($rarities as $rarityKey => $authoredRarity) {
                $path = "ruleset.secretary.item_rarities.{$rarityKey}";
                $rarity = $this->map($authoredRarity, $path);
                $this->exactKeys($rarity, $v17
                    ? ['key', 'name', 'fixed_sale_price_money']
                    : ['key', 'name'], $path);
                $expected = $v17
                    ? $this->catalog->rarities()[$rarityKey]
                    : ['key' => SecretaryItemCatalog::RARITY_NOVICE, 'name' => 'ノービス'];
                if ($rarity !== $expected) {
                    throw new DomainException("{$path} differs from the supported rarity contract.");
                }
            }
        }

        $categories = $this->map($secretary['item_categories'] ?? null, 'ruleset.secretary.item_categories');
        $items = $this->map($secretary['items'] ?? null, 'ruleset.secretary.items');
        $expectedCategories = $formal ? ['accessory', 'bow', 'clothing'] : ['bow', 'ring'];
        $catalogDefinitions = $this->catalogDefinitions($rulesetKey);
        $expectedItems = $formal
            ? array_keys($catalogDefinitions)
            : [SecretaryItemCatalog::OLD_BOW, SecretaryItemCatalog::RING];
        $this->exactDefinitionKeys($categories, $expectedCategories, 'ruleset.secretary.item_categories');
        $this->exactDefinitionKeys($items, $expectedItems, 'ruleset.secretary.items');

        foreach ($categories as $categoryKey => $authored) {
            $path = "ruleset.secretary.item_categories.{$categoryKey}";
            $category = $this->map($authored, $path);
            $this->exactKeys($category, ['key', 'max_equipped'], $path);
            $expectedMaximum = $formal
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
            if ($formal) {
                $this->exactKeys($item, [
                    'key', 'category', 'rarity', 'tradable', 'npc_tradable', 'max_level', 'effects',
                ], $path);
                $catalog = $catalogDefinitions[$itemKey];
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
            $expectedEffectCount = $itemKey === SecretaryItemCatalog::COLLAR ? 2 : 1;
            if (count($effects) !== $expectedEffectCount) {
                throw new DomainException("{$path}.effects has an invalid effect count.");
            }
            foreach ($effects as $index => $effect) {
                $this->validateEffect(
                    $itemKey,
                    $this->map($effect, "{$path}.effects.{$index}"),
                    "{$path}.effects.{$index}",
                    $index,
                    $v17,
                );
            }
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
            $authoredEffects = $settings['secretary']['items'][$itemKey]['effects'] ?? null;
            if (! is_array($authoredEffects) || ! array_is_list($authoredEffects)) {
                throw new DomainException("Ruleset Secretary item {$itemKey} is missing its effects.");
            }
            $effects[$itemKey] = [];
            foreach ($authoredEffects as $effect) {
                $effects[$itemKey] = [...$effects[$itemKey], ...$this->resolvedAuthoredEffect($effect)];
            }
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
            $parameters = [
                'damage' => $effect['damage'],
                'damage_type' => $effect['damage_type'],
                'target_scope' => $effect['target_scope'],
                'target_safety_policy' => $effect['target_safety_policy'],
            ];
            foreach (['chance_basis_points', 'chance_base_basis_points', 'chance_basis_points_per_level', 'finisher'] as $field) {
                if (array_key_exists($field, $effect)) {
                    $parameters[$field] = $effect[$field];
                }
            }

            return [[
                'type' => self::PRE_NORMAL_MONSTER_ATTACK,
                'timing' => $effect['timing'],
                'parameters' => $parameters,
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
            self::REFUGEE_GENERATION_PERCENT => 'missile_refugee_generation',
            self::KARMA_CRIME_DOUBLE_CHANCE => 'missile_impact_karma',
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
            self::PRE_NORMAL_MONSTER_ATTACK => $this->bowEffectText($itemKey, $level, $effects[0]['parameters']),
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
            self::REFUGEE_GENERATION_PERCENT => sprintf(
                'Turn開始時KARMAが1以上なら、得られる難民を%d%%増加し、街への攻撃で得る正のcrime pointsを%d%%の確率で2倍にする。',
                4 + $level,
                4 + $level,
            ),
            default => throw new DomainException('Unknown Secretary Item effect type.'),
        };
    }

    /** @param array<string, mixed> $effect */
    private function validateEffect(
        string $itemKey,
        array $effect,
        string $path,
        int $index = 0,
        bool $v17 = false,
    ): void {
        match ($itemKey) {
            SecretaryItemCatalog::OLD_BOW => $this->validateOldBow($effect, $path),
            SecretaryItemCatalog::RING => $this->validateRing($effect, $path),
            SecretaryItemCatalog::SECRETARY_SUIT => $this->validateExperienceDouble($effect, $path, $v17),
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
            SecretaryItemCatalog::ELF_BOW => $this->validateLevelBow(
                $effect, $path, self::ELF_BOW_DAMAGE_TYPE, self::OLD_BOW_TARGET_SCOPE, 1100, false,
            ),
            SecretaryItemCatalog::LONGSHOT_BOW => $this->validateLevelBow(
                $effect, $path, self::LONGSHOT_BOW_DAMAGE_TYPE, 'owned_territory_or_surface_aoi_inora', 1100, false,
            ),
            SecretaryItemCatalog::MECHANICAL_BOW => $this->validateLevelBow(
                $effect, $path, self::MECHANICAL_BOW_DAMAGE_TYPE, self::OLD_BOW_TARGET_SCOPE, 900, true,
            ),
            SecretaryItemCatalog::COLLAR => $index === 0
                ? $this->validateCollarRefugee($effect, $path)
                : $this->validateCollarKarma($effect, $path),
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
    private function validateExperienceDouble(array $effect, string $path, bool $v17): void
    {
        $this->exactKeys($effect, [
            'type', 'chance_percent_per_level', 'multiplier', 'sources', 'draw_unit', 'random_stream_version',
            ...($v17 ? ['excluded_skill_keys'] : []),
        ], $path);
        if (($effect['type'] ?? null) !== self::EXPERIENCE_DOUBLE_CHANCE
            || ($effect['chance_percent_per_level'] ?? null) !== 1
            || ($effect['multiplier'] ?? null) !== 2
            || ($effect['sources'] ?? null) !== ['passive_skill_experience', 'monster_experience']
            || ($v17 && ($effect['excluded_skill_keys'] ?? null) !== ['declining_birthrate_policy'])
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

    /** @param array<string, mixed> $effect */
    private function validateLevelBow(
        array $effect,
        string $path,
        string $damageType,
        string $targetScope,
        int $chanceBase,
        bool $mechanical,
    ): void {
        $keys = [
            'type', 'timing', 'chance_base_basis_points', 'chance_basis_points_per_level',
            'damage', 'damage_type', 'target_scope', 'target_map_space_keys',
            'target_safety_policy', 'random_stream_version',
        ];
        if ($mechanical) {
            $keys[] = 'finisher';
        }
        $this->exactKeys($effect, $keys, $path);
        if (($effect['type'] ?? null) !== self::PRE_NORMAL_MONSTER_ATTACK
            || ($effect['timing'] ?? null) !== self::OLD_BOW_TIMING
            || ($effect['chance_base_basis_points'] ?? null) !== $chanceBase
            || ($effect['chance_basis_points_per_level'] ?? null) !== 100
            || ($effect['damage'] ?? null) !== 1
            || ($effect['damage_type'] ?? null) !== $damageType
            || ($effect['target_scope'] ?? null) !== $targetScope
            || ($effect['target_map_space_keys'] ?? null) !== ['surface']
            || ($effect['target_safety_policy'] ?? null) !== self::OLD_BOW_TARGET_SAFETY
            || ($effect['random_stream_version'] ?? null) !== 1) {
            throw new DomainException("{$path} is not a supported level Bow contract.");
        }
        if ($mechanical) {
            $finisher = is_array($effect['finisher'] ?? null) ? $effect['finisher'] : [];
            $this->exactKeys($finisher, [
                'current_hp', 'damage', 'chance_multiplier_numerator', 'chance_multiplier_denominator',
                'requires_damage_one_safety_rejection', 'requires_damage_two_kill',
            ], "{$path}.finisher");
            if (($finisher['current_hp'] ?? null) !== 2
                || ($finisher['damage'] ?? null) !== 2
                || ($finisher['chance_multiplier_numerator'] ?? null) !== 2
                || ($finisher['chance_multiplier_denominator'] ?? null) !== 5
                || ($finisher['requires_damage_one_safety_rejection'] ?? null) !== true
                || ($finisher['requires_damage_two_kill'] ?? null) !== true) {
                throw new DomainException("{$path}.finisher differs from the Mechanical Bow contract.");
            }
        }
    }

    /** @param array<string, mixed> $effect */
    private function validateCollarRefugee(array $effect, string $path): void
    {
        $this->exactKeys($effect, [
            'type', 'minimum_start_karma', 'base_percent', 'percent_per_level', 'rounding', 'apply_after',
        ], $path);
        if (($effect['type'] ?? null) !== self::REFUGEE_GENERATION_PERCENT
            || ($effect['minimum_start_karma'] ?? null) !== 1
            || ($effect['base_percent'] ?? null) !== 4
            || ($effect['percent_per_level'] ?? null) !== 1
            || ($effect['rounding'] ?? null) !== 'floor'
            || ($effect['apply_after'] ?? null) !== 'karma_refugee_generation') {
            throw new DomainException("{$path} differs from the Collar refugee contract.");
        }
    }

    /** @param array<string, mixed> $effect */
    private function validateCollarKarma(array $effect, string $path): void
    {
        $this->exactKeys($effect, [
            'type', 'base_percent', 'percent_per_level', 'multiplier',
            'facility_keys', 'draw_unit', 'snapshot_timing', 'random_stream_version',
        ], $path);
        if (($effect['type'] ?? null) !== self::KARMA_CRIME_DOUBLE_CHANCE
            || ($effect['base_percent'] ?? null) !== 4
            || ($effect['percent_per_level'] ?? null) !== 1
            || ($effect['multiplier'] ?? null) !== 2
            || ($effect['facility_keys'] ?? null) !== ['village', 'town', 'city', 'capital']
            || ($effect['draw_unit'] ?? null) !== 'qualifying_impact'
            || ($effect['snapshot_timing'] ?? null) !== 'turn_start'
            || ($effect['random_stream_version'] ?? null) !== 1) {
            throw new DomainException("{$path} differs from the Collar KARMA contract.");
        }
    }

    /** @param array<string, mixed> $parameters */
    private function bowEffectText(string $itemKey, int $level, array $parameters): string
    {
        $chance = isset($parameters['chance_basis_points'])
            ? (int) $parameters['chance_basis_points']
            : (int) $parameters['chance_base_basis_points'] + ($level * (int) $parameters['chance_basis_points_per_level']);
        $scope = $itemKey === SecretaryItemCatalog::LONGSHOT_BOW
            ? '自領の地上怪獣、または地上の生存中「あおいのら」'
            : '自領の地上にいる怪獣';
        $text = sprintf('%s%%の確率で、%sに%dダメージを与える。', $this->percentage($chance), $scope, $parameters['damage']);
        if ($itemKey === SecretaryItemCatalog::MECHANICAL_BOW) {
            $text .= sprintf(' 危険HP2の怪獣には%s%%の確率で2ダメージの撃破攻撃を行う。', $this->percentage(intdiv($chance * 2, 5)));
        }

        return $text;
    }

    /** @return array<string, array<string, mixed>> */
    private function catalogDefinitions(mixed $rulesetKey): array
    {
        $definitions = $this->catalog->definitions();
        if ($rulesetKey === self::V17_RULESET_KEY) {
            return $definitions;
        }
        if ($rulesetKey !== self::V16_RULESET_KEY) {
            return [];
        }
        $v16Keys = [
            SecretaryItemCatalog::OLD_BOW,
            SecretaryItemCatalog::RING,
            SecretaryItemCatalog::SECRETARY_SUIT,
            SecretaryItemCatalog::INORA_BRACELET,
            SecretaryItemCatalog::HOARDER_TALISMAN,
            SecretaryItemCatalog::GOOD_PERSON_TREASURE,
            SecretaryItemCatalog::VAULT_KEY,
            SecretaryItemCatalog::MONSTER_REPELLENT_INCENSE,
            SecretaryItemCatalog::FULLNESS_HERB,
        ];
        $v16 = array_intersect_key($definitions, array_flip($v16Keys));
        $v16[SecretaryItemCatalog::OLD_BOW]['tradable'] = true;

        return $v16;
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
