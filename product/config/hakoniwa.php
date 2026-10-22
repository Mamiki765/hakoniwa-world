<?php

$currentRuleset = require __DIR__.'/hakoniwa/rulesets/hakoniwa-2s-plus-v41.php';

return [
    'application_version' => '5.8.0',
    'ruleset' => $currentRuleset,
    'published_rulesets' => [$currentRuleset['key'] => $currentRuleset],
    // Inspection metadata only; these fragments never compose the runtime snapshot.
    'ruleset_authoring_domains' => [
        'v41/world-and-map.php',
        'current/lifecycle-and-karma.php',
        'current/economy-and-resources.php',
        'current/terrain-and-disasters.php',
        'current/facilities.php',
        'v39/commands-and-production.php',
        'v37/turn-pipeline.php',
        'current/monsters-and-military.php',
        'v40/secretary.php',
        'v41/underground-market.php',
        'current/trading-post.php',
        'current/underground-facilities.php',
        'v37/surface-ships.php',
        'current/facility-ranks.php',
        'current/central-facilities.php',
        'current/ocean-loop.php',
        'current/power-and-pizza.php',
        'current/user-achievements.php',
        'v38/ecopo.php',
    ],
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
        'allowed_extensions' => ['gif', 'png', 'webp', 'jpg', 'jpeg'],
        'themes' => [
            'snow' => 'snow',
            'underground' => 'underground',
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
    'monument_design' => [
        'image_base_url' => rtrim(env('HAKONIWA_SECRETARY_IMAGE_BASE_URL', '/hakoniwa-secretaries'), '/').'/monuments',
    ],
    'turn_schedule' => [
        'timezone' => 'Asia/Tokyo',
        'interval_hours' => 2,
        'grace_minutes' => (int) env('HAKONIWA_TURN_SCHEDULE_GRACE_MINUTES', 15),
    ],
];
