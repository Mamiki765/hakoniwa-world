<?php

namespace Tests\Support;

final class CurrentRulesetFixture
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
        return require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v16.php');
    }

    /** @return array<string, mixed> */
    public static function withIdentity(string $key, int $version): array
    {
        $settings = self::settings();
        $settings['key'] = $key;
        $settings['version'] = $version;

        return $settings;
    }
}
