<?php

namespace Tests\Support;

final class HistoricalRulesetFixture
{
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
