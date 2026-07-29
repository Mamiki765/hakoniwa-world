<?php

use App\Domain\Ruleset\RulesetAuthoringCollection;

$publishedRulesets = RulesetAuthoringCollection::fromFiles([
    __DIR__.'/hakoniwa/rulesets/roadmap-pr2-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr6-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr7-v1.php',
    __DIR__.'/hakoniwa/rulesets/roadmap-pr11-v1.php',
])->all();

return [
    'ruleset' => $publishedRulesets['roadmap-pr11-v1'],
    'published_rulesets' => $publishedRulesets,
    'world' => [
        'key' => 'shared-world',
        'name' => '共有世界',
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
];
