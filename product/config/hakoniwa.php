<?php

use App\Domain\Ruleset\RulesetAuthoringCollection;

$publishedRulesets = RulesetAuthoringCollection::fromFiles([
    __DIR__.'/hakoniwa/rulesets/roadmap-pr2-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr6-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr7-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr11-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr14-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr15-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr18-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr19-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr21-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr22-v1.php',
    __DIR__.'/hakoniwa/rulesets/hakoniwa-2s-plus-v1.php',
    __DIR__.'/hakoniwa/rulesets/hakoniwa-2s-plus-v2.php',
    __DIR__.'/hakoniwa/rulesets/hakoniwa-2s-plus-v3.php',
    __DIR__.'/hakoniwa/rulesets/hakoniwa-2s-plus-v4.php',
    __DIR__.'/hakoniwa/rulesets/hakoniwa-2s-plus-v5.php',
])->all();

return [
    'ruleset' => $publishedRulesets['hakoniwa-2s-plus-v5'],
    'published_rulesets' => $publishedRulesets,
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
    ],
    'community' => [
        'contact_url' => env('HAKONIWA_MODERATION_CONTACT_URL'),
    ],
    'admin' => [
        'discord_user_id' => env('HAKONIWA_ADMIN_DISCORD_USER_ID'),
    ],
    'turn_schedule' => [
        'timezone' => 'Asia/Tokyo',
        'interval_hours' => 2,
        'grace_minutes' => (int) env('HAKONIWA_TURN_SCHEDULE_GRACE_MINUTES', 15),
    ],
];
