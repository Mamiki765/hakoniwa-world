<?php

return [
    'schema_version' => 1,
    'runtime_identity' => 'secretary-underground-runtime-alpha-v0',
    'combat_rules_identity' => 'secretary-underground-alpha-v0',
    'combat' => [
        'max_rounds' => 100,
        'cooldown_seconds' => 10,
        'battle_log_retention_hours' => 1,
        'actor_key' => 'knife_initiate',
        'loadout' => [
            'quick_slash',
            'piercing_thrust',
            'mending_light',
            'crystal_burst',
        ],
        'ai_preset' => 'built_in_v0',
    ],
    'xp_curve' => [
        'first_level_cost' => 100,
        'cost_increment_per_level' => 50,
    ],
    'encounters' => [
        'cave_crawler' => ['type' => 'combat', 'enemy_key' => 'cave_crawler', 'xp' => 40, 'shards' => 12],
        'needle_bat' => ['type' => 'combat', 'enemy_key' => 'needle_bat', 'xp' => 45, 'shards' => 14],
        'stone_shell' => ['type' => 'combat', 'enemy_key' => 'stone_shell', 'xp' => 55, 'shards' => 18],
        'gloom_herald' => ['type' => 'combat', 'enemy_key' => 'gloom_herald', 'xp' => 65, 'shards' => 22],
    ],
    'hunting_grounds' => [
        'shallow_caves' => [
            'minimum_combat_level' => 1,
            'encounters' => ['cave_crawler', 'needle_bat'],
        ],
        'lower_galleries' => [
            'minimum_combat_level' => 4,
            'encounters' => ['stone_shell', 'gloom_herald'],
        ],
    ],
    'trials' => [
        'trial_01' => [
            'content_identity' => 'trial-01-v1',
            'encounters' => [
                'cave_crawler', 'needle_bat', 'cave_crawler', 'stone_shell', 'needle_bat',
                'cave_crawler', 'stone_shell', 'needle_bat', 'gloom_herald', 'gloom_herald',
            ],
        ],
        'trial_02' => [
            'content_identity' => 'trial-02-v1',
            'encounters' => [
                'needle_bat', 'stone_shell', 'needle_bat', 'gloom_herald', 'stone_shell',
                'gloom_herald', 'stone_shell', 'gloom_herald', 'stone_shell', 'gloom_herald',
            ],
        ],
    ],
];
