<?php

namespace App\Domain\Award;

final class NationAwardCatalog
{
    /**
     * @var array<string, array{name: string, asset_key: string, recurring: bool, sort_order: int}>
     */
    private const DEFINITIONS = [
        'award.turn' => ['name' => 'ターン賞', 'asset_key' => 'award.turn', 'recurring' => true, 'sort_order' => 0],
        'award.prosperity' => ['name' => '繁栄賞', 'asset_key' => 'award.prosperity', 'recurring' => false, 'sort_order' => 1],
        'award.prosperity_great' => ['name' => '超繁栄賞', 'asset_key' => 'award.prosperity_great', 'recurring' => false, 'sort_order' => 2],
        'award.prosperity_ultimate' => ['name' => '究極繁栄賞', 'asset_key' => 'award.prosperity_ultimate', 'recurring' => false, 'sort_order' => 3],
        'award.peace' => ['name' => '平和賞', 'asset_key' => 'award.peace', 'recurring' => false, 'sort_order' => 4],
        'award.peace_great' => ['name' => '超平和賞', 'asset_key' => 'award.peace_great', 'recurring' => false, 'sort_order' => 5],
        'award.peace_ultimate' => ['name' => '究極平和賞', 'asset_key' => 'award.peace_ultimate', 'recurring' => false, 'sort_order' => 6],
        'award.calamity' => ['name' => '災難賞', 'asset_key' => 'award.calamity', 'recurring' => false, 'sort_order' => 7],
        'award.calamity_great' => ['name' => '超災難賞', 'asset_key' => 'award.calamity_great', 'recurring' => false, 'sort_order' => 8],
        'award.calamity_ultimate' => ['name' => '究極災難賞', 'asset_key' => 'award.calamity_ultimate', 'recurring' => false, 'sort_order' => 9],
        'award.monster_turn' => ['name' => '討伐ターン賞', 'asset_key' => 'award.monster_turn', 'recurring' => true, 'sort_order' => 10],
    ];

    /**
     * ver 1.3.0 owner-approved thresholds use current application person units.
     * Each series grants at most its first unowned eligible tier in one target turn.
     *
     * @var list<array{metric: string, tiers: list<array{key: string, threshold: int}>}>
     */
    private const CONDITION_SERIES = [
        ['metric' => 'population_loss', 'tiers' => [
            ['key' => 'award.calamity', 'threshold' => 50_000],
            ['key' => 'award.calamity_great', 'threshold' => 100_000],
            ['key' => 'award.calamity_ultimate', 'threshold' => 200_000],
        ]],
        ['metric' => 'population', 'tiers' => [
            ['key' => 'award.prosperity', 'threshold' => 300_000],
            ['key' => 'award.prosperity_great', 'threshold' => 500_000],
            ['key' => 'award.prosperity_ultimate', 'threshold' => 1_000_000],
        ]],
        ['metric' => 'refugees_received', 'tiers' => [
            ['key' => 'award.peace', 'threshold' => 20_000],
            ['key' => 'award.peace_great', 'threshold' => 50_000],
            ['key' => 'award.peace_ultimate', 'threshold' => 80_000],
        ]],
    ];

    /** @return array<string, array{name: string, asset_key: string, recurring: bool, sort_order: int}> */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /** @return array{name: string, asset_key: string, recurring: bool, sort_order: int}|null */
    public static function definition(string $key): ?array
    {
        return self::DEFINITIONS[$key] ?? null;
    }

    /** @return list<array{metric: string, tiers: list<array{key: string, threshold: int}>}> */
    public static function conditionSeries(): array
    {
        return self::CONDITION_SERIES;
    }

    /** @return list<string> */
    public static function oneTimeKeys(): array
    {
        return array_keys(array_filter(
            self::DEFINITIONS,
            static fn (array $definition): bool => ! $definition['recurring'],
        ));
    }
}
