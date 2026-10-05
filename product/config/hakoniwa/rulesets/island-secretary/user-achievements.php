<?php

return [
    'payload' => [
        'user_achievements' => [
            'island_secretary' => [
                'condition' => 'secretary_named',
                'name' => '島の秘書',
                'description' => '秘書に名前をつける。',
                'title_key' => 'island_secretary',
                'title_name' => '島の秘書',
            ],
        ],
    ],
    'classification' => [
        'behavior' => ['condition', 'title_key'],
        'data' => [],
        'flavor' => ['name', 'description', 'title_name'],
    ],
];
