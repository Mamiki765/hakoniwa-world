<?php

namespace Tests\Unit;

use App\Domain\Ruleset\CurrentRulesetAuthoringInspector;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use ReflectionMethod;
use Tests\TestCase;

final class CurrentRulesetContractTest extends TestCase
{
    private const V16_CHECKSUM = '331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d';

    private const V17_CHECKSUM = '8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3';

    private const V18_CHECKSUM = '40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b';

    private const V19_CHECKSUM = 'b65752b88e9daf3c9b64e6d28b72847315d521dfe65b704f4cd8fd622e1368c9';

    private const V20_CHECKSUM = 'fdc8ca06a567aaa5a17860ad26fcecca50c4aa5a25a7ad430f6017178d485b5e';

    private const V21_CHECKSUM = 'e78017aad4840639d6f17b22d908d293b855f992974e0d934a88c2a961340a33';

    /** @var array{domains: int, leaves: int, behavior: int, data: int, flavor: int} */
    private const V16_COVERAGE = [
        'domains' => 10,
        'leaves' => 1841,
        'behavior' => 1210,
        'data' => 455,
        'flavor' => 176,
    ];

    public function test_normal_config_loads_v21_while_preserving_the_explicit_v16_through_v20_contracts(): void
    {
        $normalConfig = require config_path('hakoniwa.php');
        $current = $normalConfig['ruleset'];
        $v16 = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v16.php');
        $v17 = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v17.php');
        $v18 = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v18.php');
        $v19 = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v19.php');
        $v20 = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v20.php');
        $source = file_get_contents(config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v16.php'));

        $this->assertIsString($source);
        $this->assertSame(10, substr_count($source, "require __DIR__.'/current/"));
        $this->assertLessThan(100, substr_count($source, "\n"));
        $this->assertSame(['hakoniwa-2s-plus-v21'], array_keys($normalConfig['published_rulesets']));
        $this->assertSame($current, $normalConfig['published_rulesets']['hakoniwa-2s-plus-v21']);
        $this->assertSame($current['secretary'], $normalConfig['current_catalogs']['secretary']);
        $this->assertSame('hakoniwa-2s-plus-v21', $current['key']);
        $this->assertSame(21, $current['version']);
        $this->assertArrayNotHasKey('behavior', $current);
        $this->assertArrayNotHasKey('data', $current);
        $this->assertArrayNotHasKey('flavor', $current);
        $this->assertSame(self::V16_CHECKSUM, $this->checksum($v16));
        $this->assertSame(self::V17_CHECKSUM, $this->checksum($v17));
        $this->assertSame(self::V18_CHECKSUM, $this->checksum($v18));
        $this->assertSame(self::V19_CHECKSUM, $this->checksum($v19));
        $this->assertSame(self::V20_CHECKSUM, $this->checksum($v20));
        $this->assertSame(self::V21_CHECKSUM, $this->checksum($current));
        $v18UnderseaCity = collect($v18['command_definitions'])->firstWhere('key', 'build_undersea_city');
        $v19UnderseaCity = collect($v19['command_definitions'])->firstWhere('key', 'build_undersea_city');
        $v20UnderseaCity = collect($current['command_definitions'])->firstWhere('key', 'build_undersea_city');
        $territoryAbandon = collect($current['command_definitions'])->firstWhere('key', 'territory_abandon');
        $this->assertSame(260, $v18UnderseaCity['sort_order']);
        $this->assertSame(125, $v19UnderseaCity['sort_order']);
        $this->assertSame(125, $v20UnderseaCity['sort_order']);
        $this->assertSame(
            ['build_defense_facility', 'build_undersea_city', 'build_seabed_base', 'build_monument'],
            collect($current['command_definitions'])
                ->whereIn('key', ['build_defense_facility', 'build_undersea_city', 'build_seabed_base', 'build_monument'])
                ->sortBy('sort_order')->pluck('key')->values()->all(),
        );
        $this->assertSame(['sea', 'shallow', 'wasteland', 'plain'], $territoryAbandon['target_terrain_keys']);
        $this->assertFalse($territoryAbandon['metadata']['consumes_turn']);
        $this->assertSame(3, $current['surface_ships']['capacity_per_type']);
        $this->assertSame(['fishing', 'tourist', 'exploration'], array_keys(
            $current['surface_ships']['definitions'],
        ));
        $this->assertSame([500, 1500, 1000], array_column(
            $current['surface_ships']['definitions'],
            'build_cost_money',
        ));
        $this->assertSame([1, 2, 3], array_column(
            $current['surface_ships']['definitions'],
            'build_selector',
        ));
        $this->assertSame([1, 2, 2], array_column(
            $current['surface_ships']['definitions'],
            'maximum_hp',
        ));
        $buildShip = collect($current['command_definitions'])->firstWhere('key', 'build_ship');
        $this->assertSame('船建造', $buildShip['name']);
        $this->assertSame('surface_ship_definitions', $buildShip['metadata']['quantity_selects_catalog']);
        $this->assertSame(1, $buildShip['metadata']['default_selector_value']);
        $scuttleShip = collect($current['command_definitions'])->firstWhere('key', 'scuttle_ship');
        $this->assertSame([
            '廃船', 'cell', ['sea'], 0, 'operations', true,
        ], [
            $scuttleShip['name'], $scuttleShip['target_type'], $scuttleShip['target_terrain_keys'],
            $scuttleShip['cost_money'], $scuttleShip['execution_phase'],
            $scuttleShip['metadata']['consumes_turn'],
        ]);
        $underground = $current['underground_facility_development'];
        $this->assertSame([
            'underground_city',
            'underground_farm',
            'underground_factory',
            'underground_missile_base',
        ], array_keys($underground['facility_definitions']));
        $this->assertSame([
            'build_underground_city',
            'build_underground_farm',
            'build_underground_factory',
            'build_underground_missile_base',
            'remove_underground_facility',
        ], array_column($underground['command_definitions'], 'key'));
        $this->assertSame([], array_values(array_intersect(
            array_column($current['command_definitions'], 'key'),
            array_column($underground['command_definitions'], 'key'),
        )));
        $this->assertSame(
            ['capital_maximum_population_bonus' => 10_000],
            $underground['facility_definitions']['underground_city']['effect'],
        );
        $this->assertSame(
            ['missile_launch_capacity' => 1],
            $underground['facility_definitions']['underground_missile_base']['effect'],
        );
        $this->assertSame(['farm', 'factory', 'mine'], array_keys(
            $current['facility_rank_system']['definitions'],
        ));
        $this->assertSame(100, $current['facility_rank_system']['definitions']['farm']['rank_two_maximum_scale']);
        $this->assertSame(200, $current['facility_rank_system']['definitions']['factory']['rank_two_maximum_scale']);
        $this->assertSame(400, $current['facility_rank_system']['definitions']['mine']['rank_two_maximum_scale']);
        $this->assertSame([
            'tile.large_farm',
            'tile.large_factory',
            'tile.large_mine',
        ], array_column($current['facility_rank_system']['definitions'], 'rank_two_asset_key'));
        $tier = $current['monster_system']['natural_spawn']['population_tiers'][3];
        $this->assertSame(500_000, $tier['minimum_population']);
        $this->assertSame(['nyowamiya', 'mecha_inora_zero'], array_slice($tier['monster_keys'], -2));
        $this->assertSame(
            'single_uniform_draw_no_retry',
            $current['monster_system']['natural_spawn']['rank_two_condition']['fallback_selection'],
        );

        $summary = app(RulesetAuthoringValidator::class)->validate($current);
        $this->assertSame('hakoniwa-2s-plus-v21', $summary['key']);
        $this->assertSame(21, $summary['version']);
        $this->assertSame(count($current['command_definitions']), $summary['commands']);
        $this->assertSame(count($current['production_definitions']), $summary['production']);
    }

    public function test_current_domain_authoring_classifies_every_scalar_leaf_exactly_once(): void
    {
        $this->assertSame(
            13,
            app(CurrentRulesetAuthoringInspector::class)->inspect(config('hakoniwa.ruleset'))['domains'],
        );
        $coverage = app(CurrentRulesetAuthoringInspector::class)->inspect(config('hakoniwa.ruleset'));
        $this->assertSame($coverage['leaves'], $coverage['behavior'] + $coverage['data'] + $coverage['flavor']);
    }

    public function test_absolute_selector_wildcard_rejects_string_and_numeric_associative_keys(): void
    {
        $leaves = new ReflectionMethod(CurrentRulesetAuthoringInspector::class, 'leaves');
        $matches = new ReflectionMethod(CurrentRulesetAuthoringInspector::class, 'matches');
        $inspector = app(CurrentRulesetAuthoringInspector::class);
        $listLeaf = $leaves->invoke($inspector, ['definitions' => [['key' => 'oil']]])['/definitions/0/key'];
        $numericMapLeaf = $leaves->invoke($inspector, ['definitions' => [1 => ['key' => 'oil']]])['/definitions/1/key'];

        $this->assertTrue($matches->invoke(
            $inspector,
            '/definitions/*/key',
            '/definitions/0/key',
            'key',
            $listLeaf['list_index_segments'],
        ));
        $this->assertFalse($matches->invoke(
            $inspector,
            '/definitions/*/key',
            '/definitions/1/key',
            'key',
            $numericMapLeaf['list_index_segments'],
        ));
        $this->assertFalse($matches->invoke(
            $inspector,
            '/definitions/*/key',
            '/definitions/oil/key',
            'key',
            [],
        ));
    }

    public function test_scalar_classification_does_not_replace_the_container_checksum_contract(): void
    {
        $current = config('hakoniwa.ruleset');
        $v16 = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v16.php');
        $withAdditionalEmptyContainer = $current;
        $withAdditionalEmptyContainer['classification_boundary_probe'] = [];

        $this->assertSame(
            app(CurrentRulesetAuthoringInspector::class)->inspect($current),
            app(CurrentRulesetAuthoringInspector::class)->inspect($withAdditionalEmptyContainer),
        );
        $this->assertSame(self::V16_CHECKSUM, $this->checksum($v16));
        $this->assertNotSame($this->checksum($current), $this->checksum($withAdditionalEmptyContainer));
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
