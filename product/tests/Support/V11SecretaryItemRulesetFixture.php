<?php

namespace Tests\Support;

use App\Domain\Monster\MonsterRewardPolicyResolver;
use App\Domain\Secretary\SecretaryItemTargetSafetyPolicy;

final class V11SecretaryItemRulesetFixture
{
    public static function displayOrderFor(string $key): int
    {
        $orders = array_column(self::settings()['monster_definitions'], 'display_order', 'key');

        return $orders[$key];
    }

    /** @return list<array<string, mixed>> */
    public static function newMonsterDefinitions(): array
    {
        return array_values(array_filter(
            self::settings()['monster_definitions'],
            static fn (array $definition): bool => in_array(
                $definition['key'],
                ['mecha_inora_zero', 'aoi_inora'],
                true,
            ),
        ));
    }

    /** @return array<string, mixed> */
    public static function settings(): array
    {
        $settings = config('hakoniwa.ruleset');
        $settings['key'] = 'test-hakoniwa-2s-plus-v11-secretary-items';
        $settings['version'] = 11;
        $displayOrders = [
            'mecha_inora' => 0,
            'inora' => 100,
            'sanjira' => 200,
            'red_inora' => 300,
            'dark_inora' => 400,
            'inora_ghost' => 500,
            'whale' => 600,
            'king_inora' => 700,
        ];
        $manual = [
            'mecha_inora' => ['appearance' => '怪獣派遣', 'special' => '特殊能力なし'],
            'inora' => ['appearance' => '人口10万人以上で自然発生', 'special' => '特殊能力なし'],
            'sanjira' => ['appearance' => '人口10万人以上で自然発生', 'special' => '奇数ターンは硬化'],
            'red_inora' => ['appearance' => '人口25万人以上で自然発生', 'special' => '特殊能力なし'],
            'dark_inora' => ['appearance' => '人口25万人以上で自然発生', 'special' => '最大2歩移動'],
            'inora_ghost' => ['appearance' => '人口25万人以上で自然発生', 'special' => '高速移動'],
            'whale' => ['appearance' => '人口40万人以上で自然発生', 'special' => '偶数ターンは硬化'],
            'king_inora' => ['appearance' => '人口40万人以上で自然発生', 'special' => '特殊能力なし'],
        ];
        foreach ($settings['monster_definitions'] as &$definition) {
            $definition['display_order'] = $displayOrders[$definition['key']];
            $definition['source_metadata'][MonsterRewardPolicyResolver::METADATA_KEY]
                = MonsterRewardPolicyResolver::STANDARD_SPLIT;
            $definition['source_metadata']['manual'] = $manual[$definition['key']];
        }
        unset($definition);

        $template = $settings['monster_definitions'][0];
        $zero = $template;
        $zero['key'] = 'mecha_inora_zero';
        $zero['name'] = 'メカいのら零式';
        $zero['asset_key'] = 'hakoniwa_custom.monster.mecha_inora_zero';
        $zero['base_hp'] = 4;
        $zero['missile_base_experience'] = 35;
        $zero['display_order'] = 50;
        $zero['skill_description'] = 'HP1で核爆発';
        $zero['source_metadata'] = [
            MonsterRewardPolicyResolver::METADATA_KEY => MonsterRewardPolicyResolver::STANDARD_SPLIT,
            'secretary_item_target_safety' => [
                'policy' => SecretaryItemTargetSafetyPolicy::CERTAIN_SELF_ACTION_AT_REMAINING_HP,
                'remaining_hp' => 1,
            ],
            'manual' => ['appearance' => '怪獣派遣（9,999億円）', 'special' => 'HP1で核爆発'],
        ];

        $aoi = $template;
        $aoi['key'] = 'aoi_inora';
        $aoi['name'] = 'あおいのら';
        $aoi['asset_key'] = 'hakoniwa_custom.monster.aoi_inora';
        $aoi['base_hp'] = 2;
        $aoi['hp_variation'] = 1;
        $aoi['wreckage_value_money'] = 1_200;
        $aoi['missile_base_experience'] = 18;
        $aoi['display_order'] = 450;
        $aoi['skill_description'] = '海を移動し、中立海上では撃破者へ残骸資金を全額付与';
        $aoi['source_metadata'] = [
            MonsterRewardPolicyResolver::METADATA_KEY => MonsterRewardPolicyResolver::HOSTLESS_FULL_KILLER_MONEY,
            'manual' => ['appearance' => 'Worldの海上災害', 'special' => '海を移動。中立海上の通常撃破は撃破者へ残骸資金100%'],
        ];

        $settings['monster_definitions'][] = $zero;
        $settings['monster_definitions'][] = $aoi;
        usort(
            $settings['monster_definitions'],
            static fn (array $left, array $right): int => $left['display_order'] <=> $right['display_order'],
        );
        unset($settings['monster_system']['kill_stats']['maximum_species_rows_per_nation']);
        $settings['secretary']['item_categories'] = [
            'bow' => ['key' => 'bow', 'max_equipped' => 1],
            'ring' => ['key' => 'ring', 'max_equipped' => 5],
        ];
        $settings['secretary']['items'] = [
            'old_bow' => [
                'key' => 'old_bow',
                'category' => 'bow',
                'max_level' => 1,
                'same_item_max_equipped' => 1,
                'effects' => [[
                    'type' => 'pre_normal_monster_attack',
                    'timing' => 'after_missile_finalization_before_normal_monsters',
                    'chance_basis_points' => 1_000,
                    'damage' => 1,
                    'damage_type' => 'secretary_old_bow',
                    'target_scope' => 'owned_territory',
                    'target_map_space_keys' => ['surface'],
                    'target_safety_policy' => 'avoid_ineffective_or_immediate_hazard',
                    'random_stream_version' => 1,
                ]],
            ],
            'ring' => [
                'key' => 'ring',
                'category' => 'ring',
                'max_level' => 10,
                'same_item_max_equipped' => 5,
                'effects' => [[
                    'type' => 'finance_income_bonus',
                    'bonus_money_per_level' => 1,
                    'stacking' => 'sum_equipped_levels',
                ]],
            ],
        ];

        return $settings;
    }
}
