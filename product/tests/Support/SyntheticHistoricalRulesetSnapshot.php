<?php

namespace Tests\Support;

use App\Models\RulesetVersion;

final class SyntheticHistoricalRulesetSnapshot
{
    /** @param (callable(array<string, mixed>): array<string, mixed>)|null $mutate */
    public static function create(string $key, int $version, ?callable $mutate = null): RulesetVersion
    {
        /** @var array<string, mixed> $settings */
        $settings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v27.php');
        $settings['key'] = $key;
        $settings['version'] = $version;
        if ($mutate !== null) {
            $settings = $mutate($settings);
        }

        return RulesetVersion::query()->create([
            'key' => $key,
            'version' => $version,
            'settings' => $settings,
            'is_active' => false,
        ]);
    }

    /** @return array<string, mixed> */
    public static function withLegacySecretaryItems(array $settings): array
    {
        $settings['secretary']['skills'] = array_intersect_key($settings['secretary']['skills'], array_flip([
            'agricultural_policy', 'specialty_development', 'gold_vein_survey',
            'forest_management', 'final_defense_line',
        ]));
        $oldBow = $settings['secretary']['items']['old_bow'];
        unset($oldBow['rarity'], $oldBow['tradable'], $oldBow['npc_tradable'], $oldBow['gacha_exception']);
        $oldBow['same_item_max_equipped'] = 1;
        $ring = $settings['secretary']['items']['ring'];
        unset($ring['rarity'], $ring['tradable'], $ring['npc_tradable'], $ring['gacha_exception']);
        $ring['category'] = 'ring';
        $ring['same_item_max_equipped'] = 5;
        unset($settings['secretary']['item_rarities']);
        $settings['secretary']['item_categories'] = [
            'bow' => ['key' => 'bow', 'max_equipped' => 1],
            'ring' => ['key' => 'ring', 'max_equipped' => 5],
        ];
        $settings['secretary']['items'] = [
            'old_bow' => $oldBow,
            'ring' => $ring,
        ];

        return $settings;
    }
}
