<?php

namespace Tests\Support;

final class V11SecretaryItemRulesetFixture
{
    /** @return array<string, mixed> */
    public static function settings(): array
    {
        $settings = config('hakoniwa.ruleset');
        $settings['key'] = 'test-hakoniwa-2s-plus-v11-secretary-items';
        $settings['version'] = 11;
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
