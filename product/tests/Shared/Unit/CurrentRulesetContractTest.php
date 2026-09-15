<?php

namespace Tests\Shared\Unit;

use App\Domain\Ruleset\CurrentRulesetAuthoringInspector;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Services\AssetManifestResolver;
use DomainException;
use Tests\TestCase;

final class CurrentRulesetContractTest extends TestCase
{
    private const V25_CHECKSUM = 'c03af0ca57f167207740ad5bc5e201568335b9c45d417c0c865440a9548967de';

    public function test_normal_config_loads_and_validates_the_v25_contract(): void
    {
        $normalConfig = require config_path('hakoniwa.php');
        $current = $normalConfig['ruleset'];

        $this->assertSame(['hakoniwa-2s-plus-v25'], array_keys($normalConfig['published_rulesets']));
        $this->assertSame($current, $normalConfig['published_rulesets']['hakoniwa-2s-plus-v25']);
        $this->assertSame($current['secretary'], $normalConfig['current_catalogs']['secretary']);
        $this->assertSame('hakoniwa-2s-plus-v25', $current['key']);
        $this->assertSame(25, $current['version']);
        $this->assertArrayNotHasKey('behavior', $current);
        $this->assertArrayNotHasKey('data', $current);
        $this->assertArrayNotHasKey('flavor', $current);
        $this->assertSame(self::V25_CHECKSUM, $this->checksum($current));
        $this->assertSame([
            'basis' => 'next_level_linear',
            'multiplier' => 100,
        ], $current['secretary']['skills']['ship_operations']['level_requirement']);
        $underseaCity = collect($current['command_definitions'])->firstWhere('key', 'build_undersea_city');
        $territoryAbandon = collect($current['command_definitions'])->firstWhere('key', 'territory_abandon');
        $this->assertSame(125, $underseaCity['sort_order']);
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
        $this->assertSame([
            'build_fast_farm',
            'build_fast_factory',
            'build_fast_mine',
            'build_central_bank',
            'build_central_granary',
        ], collect($current['command_definitions'])
            ->whereIn('key', [
                'build_fast_farm', 'build_fast_factory', 'build_fast_mine',
                'build_central_bank', 'build_central_granary',
            ])->pluck('key')->values()->all());
        $this->assertSame([100, 300, 1000], collect($current['command_definitions'])
            ->whereIn('key', ['build_fast_farm', 'build_fast_factory', 'build_fast_mine'])
            ->pluck('cost_money')->values()->all());
        $this->assertSame([20, 20, 20], collect($current['command_definitions'])
            ->whereIn('key', ['build_fast_farm', 'build_fast_factory', 'build_fast_mine'])
            ->pluck('metadata.cost_paradox')->values()->all());
        $this->assertSame([
            'central-bank.gif',
            'central-granary.gif',
        ], [
            app(AssetManifestResolver::class)->filenameForAssetKey('tile.central_bank'),
            app(AssetManifestResolver::class)->filenameForAssetKey('tile.central_granary'),
        ]);
        $this->assertSame(1000, $current['central_facilities']['definitions']['central_bank']['capacity_per_level']);
        $this->assertSame(100000, $current['central_facilities']['definitions']['central_granary']['capacity_per_level']);
        $this->assertSame([true, true], collect($current['command_definitions'])
            ->whereIn('key', ['build_central_bank', 'build_central_granary'])
            ->pluck('metadata.settlement_overbuild')->values()->all());
        $this->assertSame([
            'reservation_terrain_keys' => ['sea', 'shallow', 'wasteland', 'mountain'],
            'ship_relocation' => 'final_empty_sea_within_reservation',
            'candidate_evaluation' => 'stable_batched_until_safe',
        ], $current['initial_island_placement']);
        $this->assertSame(
            'land_subsidence_safe_land_cells',
            $current['turn_processing']['territory_influence']['acquisition_land_limit'],
        );

        $summary = app(RulesetAuthoringValidator::class)->validate($current);
        $this->assertSame('hakoniwa-2s-plus-v25', $summary['key']);
        $this->assertSame(25, $summary['version']);
        $this->assertSame(count($current['command_definitions']), $summary['commands']);
        $this->assertSame(count($current['production_definitions']), $summary['production']);
    }

    public function test_current_domain_authoring_classifies_every_scalar_leaf_exactly_once(): void
    {
        $this->assertSame(
            14,
            app(CurrentRulesetAuthoringInspector::class)->inspect(config('hakoniwa.ruleset'))['domains'],
        );
        $coverage = app(CurrentRulesetAuthoringInspector::class)->inspect(config('hakoniwa.ruleset'));
        $this->assertSame($coverage['leaves'], $coverage['behavior'] + $coverage['data'] + $coverage['flavor']);
    }

    public function test_public_inspector_rejects_string_and_numeric_associative_collections(): void
    {
        $inspector = app(CurrentRulesetAuthoringInspector::class);
        $current = config('hakoniwa.ruleset');
        $variants = [
            'string map' => array_column($current['command_definitions'], null, 'key'),
            'numeric associative map' => array_combine(
                range(1, count($current['command_definitions'])),
                $current['command_definitions'],
            ),
        ];

        foreach ($variants as $label => $definitions) {
            $invalid = $current;
            $invalid['command_definitions'] = $definitions;
            try {
                $inspector->inspect($invalid);
                $this->fail("Public inspector accepted a {$label} where the authored contract requires a list.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString(
                    'domain leaves do not exactly match the published payload',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_scalar_classification_does_not_replace_the_container_checksum_contract(): void
    {
        $current = config('hakoniwa.ruleset');
        $withAdditionalEmptyContainer = $current;
        $withAdditionalEmptyContainer['classification_boundary_probe'] = [];

        $this->assertSame(
            app(CurrentRulesetAuthoringInspector::class)->inspect($current),
            app(CurrentRulesetAuthoringInspector::class)->inspect($withAdditionalEmptyContainer),
        );
        $this->assertSame(self::V25_CHECKSUM, $this->checksum($current));
        $this->assertNotSame($this->checksum($current), $this->checksum($withAdditionalEmptyContainer));
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
