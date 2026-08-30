<?php

$currentRuleset = require __DIR__.'/hakoniwa/rulesets/hakoniwa-2s-plus-v18.php';

return [
    'application_version' => '3.0.0-alpha.5',
    'ruleset' => $currentRuleset,
    'published_rulesets' => [$currentRuleset['key'] => $currentRuleset],
    'current_catalogs' => [
        'secretary' => $currentRuleset['secretary'],
    ],
    'world' => [
        'key' => 'shared-world',
        'name' => '箱庭諸島２S＋',
        'map_space_key' => 'surface',
        'map_space_name' => '地上',
        'generator_id' => 'ocean-world',
        'generator_version' => '3',
        'seed' => 'hakoniwa-staggered-xy-v3',
    ],
    'initial_island' => [
        'generator_id' => 'legacy-inspired-initial-island',
        'generator_version' => '3',
    ],
    'assets' => [
        'base_url' => env('HAKONIWA_TILE_ASSET_BASE_URL', env('HAKONIWA_ORIGINAL_ASSET_BASE_URL', '/assets/hakoniwa-tiles')),
        'path' => env('HAKONIWA_TILE_ASSET_PATH', env('HAKONIWA_ORIGINAL_ASSET_PATH', '/srv/hakoniwa-assets/tiles')),
        'allowed_extensions' => ['gif', 'png', 'webp'],
        'themes' => [
            'snow' => 'snow',
            'peridot' => 'peridot',
        ],
    ],
    'community' => [
        'contact_url' => env('HAKONIWA_MODERATION_CONTACT_URL'),
    ],
    'admin' => [
        'discord_user_id' => env('HAKONIWA_ADMIN_DISCORD_USER_ID'),
    ],
    'inquiries' => [
        'attachment_base_url' => env('HAKONIWA_INQUIRY_ATTACHMENT_BASE_URL', '/hakoniwa-inquiries'),
    ],
    'secretary_profile' => [
        'image_base_url' => env('HAKONIWA_SECRETARY_IMAGE_BASE_URL', '/hakoniwa-secretaries'),
    ],
    'turn_schedule' => [
        'timezone' => 'Asia/Tokyo',
        'interval_hours' => 2,
        'grace_minutes' => (int) env('HAKONIWA_TURN_SCHEDULE_GRACE_MINUTES', 15),
    ],
];
