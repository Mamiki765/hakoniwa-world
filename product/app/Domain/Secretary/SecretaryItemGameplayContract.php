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

    private const V18_RULESET_KEY = 'hakoniwa-2s-plus-v18';

    private const V19_RULESET_KEY = 'hakoniwa-2s-plus-v19';

    private const V20_RULESET_KEY = 'hakoniwa-2s-plus-v20';

    private const V21_RULESET_KEY = 'hakoniwa-2s-plus-v21';

    private const V22_RULESET_KEY = 'hakoniwa-2s-plus-v22';

    private const V23_RULESET_KEY = 'hakoniwa-2s-plus-v23';

    private const V24_RULESET_KEY = 'hakoniwa-2s-plus-v24';

    private const V25_RULESET_KEY = 'hakoniwa-2s-plus-v25';

    private const V26_RULESET_KEY = 'hakoniwa-2s-plus-v26';

    private const V27_RULESET_KEY = 'hakoniwa-2s-plus-v27';

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
        $formal = in_array($rulesetKey, [self::V16_RULESET_KEY, self::V17_RULESET_KEY, self::V18_RULESET_KEY, self::V19_RULESET_KEY, self::V20_RULESET_KEY, self::V21_RULESET_KEY, self::V22_RULESET_KEY, self::V23_RULESET_KEY, self::V24_RULESET_KEY, self::V25_RULESET_KEY, self::V26_RULESET_KEY, self::V27_RULESET_KEY], true);
        $v17 = in_array($rulesetKey, [self::V17_RULESET_KEY, self::V18_RULESET_KEY, self::V19_RULESET_KEY, self::V20_RULESET_KEY, self::V21_RULESET_KEY, self::V22_RULESET_KEY, self::V23_RULESET_KEY, self::V24_RULESET_KEY, self::V25_RULESET_KEY, self::V26_RULESET_KEY, self::V27_RULESET_KEY], true);
        $v27 = $rulesetKey === self::V27_RULESET_KEY;
        $v26 = $rulesetKey === self::V26_RULESET_KEY || $v27;
        $secretary = $this->map($settings['secretary'] ?? null, 'ruleset.secretary');
        if ($formal) {
            $rarities = $this->map($secretary['item_rarities'] ?? null, 'ruleset.secretary.item_rarities');
            $this->exactDefinitionKeys(
                $rarities,
                $v17
                    ? [SecretaryItemCatalog::RARITY_NOVICE, SecretaryItemCatalog::RARITY_REGULAR, SecretaryItemCatalog::RARITY_CURSED,
                        ...($v26 ? [SecretaryItemCatalog::RARITY_HIGH_QUALITY] : []),
                        ...($v27 ? [SecretaryItemCatalog::RARITY_ARTIFACT, SecretaryItemCatalog::RARITY_RELIC] : [])]
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
        $expectedCategories = $formal
            ? ['accessory', 'bow', 'clothing', ...($v26 ? ['ticket'] : [])]
            : ['bow', 'ring'];
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
                || $this->integer($category['max_equipped'] ?? null, "{$path}.max_equipped", $v26 ? 0 : 1) !== $expectedMaximum) {
                throw new DomainException("{$path} differs from the supported equipment catalog.");
            }
        }

        foreach ($items as $itemKey => $authored) {
            $path = "ruleset.secretary.items.{$itemKey}";
            $item = $this->map($authored, $path);
            if ($formal) {
                $this->exactKeys($item, [
                    'key', 'category', 'rarity', 'tradable', 'npc_tradable', 'max_level', 'effects',
                    ...($v27 ? ['gacha_exception'] : []),
                ], $path);
                $catalog = $catalogDefinitions[$itemKey];
                if (($item['key'] ?? null) !== $itemKey
                    || ($item['category'] ?? null) !== $catalog['category']
                    || $this->integer($item['max_level'] ?? null, "{$path}.max_level", 1) !== $catalog['max_level']
                    || ($item['rarity'] ?? null) !== $catalog['rarity']
                    || ($item['tradable'] ?? null) !== $catalog['tradable']
                    || ($item['npc_tradable'] ?? null) !== $catalog['npc_tradable']
                    || $this->catalog->sameItemMaximum($itemKey) !== 1
                    || ($v27 && ($item['gacha_exception'] ?? null) !== in_array($itemKey, [
                        SecretaryItemCatalog::OLD_BOW,
                        SecretaryItemCatalog::WAKUWAKU_TICKET,
                        SecretaryItemCatalog::DOKIDOKI_TICKET,
                        SecretaryItemCatalog::LOVE_EMBLEM,
                        SecretaryItemCatalog::TWIN_STAR_EMBLEM,
                        SecretaryItemCatalog::CRESCENT_EMBLEM,
                    ], true))) {
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
            $expectedEffectCount = in_array($itemKey, [
                SecretaryItemCatalog::WAKUWAKU_TICKET,
                SecretaryItemCatalog::DOKIDOKI_TICKET,
            ], true) ? 0 : ($itemKey === SecretaryItemCatalog::COLLAR ? 2 : 1);
            if (count($effects) !== $expectedEffectCount) {
                throw new DomainException("{$path}.effects has an invalid effect count.");
            }
            foreach ($effects as $index => $effect) {
                if ($v27 && ($catalogDefinitions[$itemKey]['introduced_version'] ?? 16) === 27) {
                    $this->validateV27Effect($itemKey, $this->map($effect, "{$path}.effects.{$index}"), "{$path}.effects.{$index}");
                } else {
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
        if ($v27) {
            $gacha = $this->map($secretary['ticket_gacha'] ?? null, 'ruleset.secretary.ticket_gacha');
            $this->exactDefinitionKeys($gacha, [SecretaryItemCatalog::WAKUWAKU_TICKET, SecretaryItemCatalog::DOKIDOKI_TICKET], 'ruleset.secretary.ticket_gacha');
            foreach ([
                SecretaryItemCatalog::WAKUWAKU_TICKET => ['regular', 'high_quality', 'artifact'],
                SecretaryItemCatalog::DOKIDOKI_TICKET => ['high_quality', 'artifact'],
            ] as $ticketKey => $rarities) {
                $ticket = $this->map($gacha[$ticketKey] ?? null, "ruleset.secretary.ticket_gacha.{$ticketKey}");
                $this->exactKeys($ticket, ['rarity_weights_basis_points'], "ruleset.secretary.ticket_gacha.{$ticketKey}");
                $weights = $this->map($ticket['rarity_weights_basis_points'], "ruleset.secretary.ticket_gacha.{$ticketKey}.rarity_weights_basis_points");
                $this->exactKeys($weights, $rarities, "ruleset.secretary.ticket_gacha.{$ticketKey}.rarity_weights_basis_points");
                foreach ($weights as $rarity => $weight) {
                    $this->integer($weight, "ruleset.secretary.ticket_gacha.{$ticketKey}.rarity_weights_basis_points.{$rarity}", 0);
                }
                if (array_sum($weights) !== 10_000) {
                    throw new DomainException("ruleset.secretary.ticket_gacha.{$ticketKey} weights must total 10000.");
                }
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
            'disaster_guard' => 'surface_disaster_cell_damage',
            'monster_missile_defense_bypass' => 'surface_missile_interception',
            'nyowamiya_ribbon' => 'surface_monster_interaction',
            'population_growth_percent' => 'surface_population_growth',
            'final_defense_preserve_chance' => 'surface_final_defense_interception',
            'launch_base_experience_double_chance' => 'surface_launch_base_experience',
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
            self::EXPERIENCE_DOUBLE_CHANCE => $this->experienceEffectText($effects[0]['parameters'], $level),
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
            'disaster_guard' => match ($effects[0]['parameters']['disaster_key']) {
                'fire' => '火災', 'tsunami' => '津波', 'typhoon' => '台風',
                'earthquake' => '地震', 'meteor_shower' => '流星群',
                default => throw new DomainException('Unknown Secretary disaster guard.'),
            }.'による自島の被害を1マス防ぐ。発動後にLvが1下がり、Lv1なら壊れる。',
            'monster_missile_defense_bypass' => '装備中、自国の防衛施設は怪獣がいるマスへのミサイルを迎撃しない。',
            'nyowamiya_ribbon' => '防衛施設は怪獣に踏まれても自爆せず、保護範囲内の怪獣を秘書は攻撃しない。',
            'population_growth_percent' => sprintf('通常・誘致の人口増加量が%d%%増える。', $effects[0]['parameters']['percent']),
            'final_defense_preserve_chance' => sprintf('最終防衛ラインで迎撃するとき、%d%%の確率で迎撃回数を消費しない。', $effects[0]['parameters']['chance_percent']),
            'launch_base_experience_double_chance' => sprintf('集落や海賊船へのミサイル攻撃で、%d%%の確率で発射基地の獲得EXPが2倍になる。', $effects[0]['parameters']['chance_percent']),
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
    private function validateV27Effect(string $itemKey, array $effect, string $path): void
    {
        $charms = [
            'fire_charm' => 'fire', 'wave_charm' => 'tsunami',
            'wind_charm' => 'typhoon', 'quake_charm' => 'earthquake',
            'star_charm' => 'meteor_shower',
        ];
        if (isset($charms[$itemKey])) {
            if ($effect != [
                'type' => 'disaster_guard',
                'disaster_key' => $charms[$itemKey],
                'cells_per_charge' => 1,
            ]) {
                throw new DomainException("{$path} differs from the disaster guard contract.");
            }

            return;
        }
        $bows = [
            'gem_bow' => [2400, self::OLD_BOW_TARGET_SCOPE, 'secretary_gem_bow', false],
            'elven_bow' => [4900, self::OLD_BOW_TARGET_SCOPE, 'secretary_elven_bow', false],
            'aquamarine_bow' => [2400, 'owned_territory_or_surface_aoi_inora', 'secretary_aquamarine_bow', false],
            'artemis_bow' => [4900, 'owned_territory_or_surface_aoi_inora', 'secretary_artemis_bow', false],
            'bullseye_bow' => [1900, self::OLD_BOW_TARGET_SCOPE, 'secretary_bullseye_bow', true],
            'shiva_bow' => [3900, self::OLD_BOW_TARGET_SCOPE, 'secretary_shiva_bow', true],
        ];
        if (isset($bows[$itemKey])) {
            [, $scope, $damageType, $finisher] = $bows[$itemKey];
            $base = $this->integer($effect['chance_base_basis_points'] ?? null, "{$path}.chance_base_basis_points", 0);
            $perLevel = $this->integer($effect['chance_basis_points_per_level'] ?? null, "{$path}.chance_basis_points_per_level", 0);
            if ($base + $perLevel * $this->catalog->definition($itemKey)['max_level'] > 10_000) {
                throw new DomainException("{$path} chance exceeds 10000 basis points.");
            }
            $this->validateLevelBow($effect, $path, $damageType, $scope, $base, $finisher, true);

            return;
        }
        $suits = [
            'experienced_suit' => [12, 'all'], 'eternal_suit' => [24, 'all'], 'star_reader_suit' => [38, 'all'],
            'military_suit' => [12, 'combat'], 'marshal_suit' => [24, 'combat'], 'war_suit' => [38, 'combat'],
            'chancellor_suit' => [12, 'peace'], 'grand_chancellor_suit' => [24, 'peace'],
            'star_chancellor_suit' => [38, 'peace'],
        ];
        if (isset($suits[$itemKey])) {
            [, $group] = $suits[$itemKey];
            $base = $this->integer($effect['chance_base_percent'] ?? null, "{$path}.chance_base_percent", 0);
            $perLevel = $this->integer($effect['chance_percent_per_level'] ?? null, "{$path}.chance_percent_per_level", 0);
            $numerator = $this->integer($effect['chance_multiplier_numerator'] ?? null, "{$path}.chance_multiplier_numerator", 1);
            $denominator = $this->integer($effect['chance_multiplier_denominator'] ?? null, "{$path}.chance_multiplier_denominator", 1);
            if (intdiv(($base + $perLevel * $this->catalog->definition($itemKey)['max_level']) * $numerator, $denominator) > 100) {
                throw new DomainException("{$path} chance exceeds 100 percent.");
            }
            $skills = match ($group) {
                'peace' => ['agricultural_policy', 'specialty_development', 'gold_vein_survey', 'forest_management', 'indomitable', 'ship_operations'],
                'combat' => ['final_defense_line', 'navy'],
                default => [],
            };
            if ($effect != [
                'type' => self::EXPERIENCE_DOUBLE_CHANCE,
                'chance_base_percent' => $base,
                'chance_percent_per_level' => $perLevel,
                'chance_multiplier_numerator' => $numerator,
                'chance_multiplier_denominator' => $denominator,
                'multiplier' => 2,
                'sources' => $group === 'peace' ? ['passive_skill_experience'] : ['passive_skill_experience', 'monster_experience'],
                'eligible_skill_keys' => $skills,
                'excluded_skill_keys' => ['declining_birthrate_policy'],
                'draw_unit' => 'canonical_award_event',
                'random_stream_version' => 1,
            ]) {
                throw new DomainException("{$path} differs from the surface suit contract.");
            }

            return;
        }
        foreach (['percent', 'chance_percent', 'foreign_settlement_chance_percent'] as $field) {
            if (array_key_exists($field, $effect)
                && ($this->integer($effect[$field], "{$path}.{$field}", 0) > 100)) {
                throw new DomainException("{$path}.{$field} exceeds 100 percent.");
            }
        }
        $expected = match ($itemKey) {
            'magic_white_flag' => ['type' => 'monster_missile_defense_bypass'],
            SecretaryItemCatalog::NYOWAMIYA_RIBBON => [
                'type' => 'nyowamiya_ribbon', 'nyowamiya_type_weight_bonus' => 1,
            ],
            SecretaryItemCatalog::LOVE_EMBLEM => [
                'type' => 'population_growth_percent', 'percent' => $effect['percent'] ?? null,
                'applies_to' => ['ordinary', 'attraction'],
            ],
            SecretaryItemCatalog::TWIN_STAR_EMBLEM => [
                'type' => 'final_defense_preserve_chance', 'chance_percent' => $effect['chance_percent'] ?? null,
                'random_stream_version' => 1,
            ],
            SecretaryItemCatalog::CRESCENT_EMBLEM => [
                'type' => 'launch_base_experience_double_chance',
                'chance_percent' => $effect['chance_percent'] ?? null,
                'foreign_settlement_chance_percent' => $effect['foreign_settlement_chance_percent'] ?? null,
                'random_stream_version' => 1,
            ],
            default => throw new DomainException("{$path} belongs to an unknown v27 Secretary Item."),
        };
        if ($effect != $expected) {
            throw new DomainException("{$path} differs from the supported surface item contract.");
        }
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
        bool $flexibleNumbers = false,
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
            || (! $flexibleNumbers && ($effect['chance_basis_points_per_level'] ?? null) !== 100)
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
                || (! $flexibleNumbers && ($finisher['chance_multiplier_numerator'] ?? null) !== 2)
                || (! $flexibleNumbers && ($finisher['chance_multiplier_denominator'] ?? null) !== 5)
                || ($finisher['requires_damage_one_safety_rejection'] ?? null) !== true
                || ($finisher['requires_damage_two_kill'] ?? null) !== true) {
                throw new DomainException("{$path}.finisher differs from the Mechanical Bow contract.");
            }
            if ($flexibleNumbers) {
                $this->integer($finisher['chance_multiplier_numerator'] ?? null, "{$path}.finisher.chance_multiplier_numerator", 0);
                $this->integer($finisher['chance_multiplier_denominator'] ?? null, "{$path}.finisher.chance_multiplier_denominator", 1);
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
        $scope = ($parameters['target_scope'] ?? null) === 'owned_territory_or_surface_aoi_inora'
            ? '自領の地上怪獣、または地上の生存中「あおいのら」'
            : '自領の地上にいる怪獣';
        $text = sprintf('%s%%の確率で、%sに%dダメージを与える。', $this->percentage($chance), $scope, $parameters['damage']);
        if (isset($parameters['finisher'])) {
            $text .= sprintf(' 危険HP2の怪獣には%s%%の確率で2ダメージの撃破攻撃を行う。', $this->percentage(intdiv(
                $chance * $parameters['finisher']['chance_multiplier_numerator'],
                $parameters['finisher']['chance_multiplier_denominator'],
            )));
        }

        return $text;
    }

    /** @param array<string, mixed> $parameters */
    private function experienceEffectText(array $parameters, int $level): string
    {
        $base = (int) ($parameters['chance_base_percent'] ?? 0);
        $perLevel = (int) ($parameters['chance_percent_per_level'] ?? 0);
        $numerator = (int) ($parameters['chance_multiplier_numerator'] ?? 1);
        $denominator = (int) ($parameters['chance_multiplier_denominator'] ?? 1);
        $chance = intdiv(($base + $level * $perLevel) * $numerator, $denominator);
        $skills = $parameters['eligible_skill_keys'] ?? [];
        $scope = $skills === [] ? '地上経験値' : (in_array('navy', $skills, true) ? '戦闘系経験値' : '平和系経験値');

        return "{$scope}を得る際、{$chance}%の確率で獲得経験値を2倍にする。";
    }

    /** @return array<string, array<string, mixed>> */
    private function catalogDefinitions(mixed $rulesetKey): array
    {
        if ($rulesetKey === self::V27_RULESET_KEY) {
            return $this->catalog->definitions();
        }
        $definitions = array_filter(
            $this->catalog->definitions(),
            static fn (array $definition): bool => ($definition['introduced_version'] ?? 16) <= 26,
        );
        if ($rulesetKey === self::V26_RULESET_KEY) {
            return $definitions;
        }
        if (in_array($rulesetKey, [self::V17_RULESET_KEY, self::V18_RULESET_KEY, self::V19_RULESET_KEY, self::V20_RULESET_KEY, self::V21_RULESET_KEY, self::V22_RULESET_KEY, self::V23_RULESET_KEY, self::V24_RULESET_KEY, self::V25_RULESET_KEY], true)) {
            unset($definitions[SecretaryItemCatalog::WAKUWAKU_TICKET], $definitions[SecretaryItemCatalog::DOKIDOKI_TICKET]);

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
