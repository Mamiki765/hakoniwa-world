<?php

namespace Tests\Support;

use App\Models\RulesetVersion;

final class SyntheticHistoricalRulesetSnapshot
{
    /** @param (callable(array<string, mixed>): array<string, mixed>)|null $mutate */
    public static function create(string $key, int $version, ?callable $mutate = null): RulesetVersion
    {
        $settings = CurrentRulesetFixture::withIdentity($key, $version);
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
        $oldBow = $settings['secretary']['items']['old_bow'];
        unset($oldBow['rarity'], $oldBow['tradable'], $oldBow['npc_tradable']);
        $oldBow['same_item_max_equipped'] = 1;
        $ring = $settings['secretary']['items']['ring'];
        unset($ring['rarity'], $ring['tradable'], $ring['npc_tradable']);
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
