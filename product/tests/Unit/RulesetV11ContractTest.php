<?php

namespace Tests\Unit;

use App\Domain\Ruleset\CurrentRulesetAuthoringInspector;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Domain\Ruleset\RulesetUpgradeAuthoringCatalog;
use Tests\Support\V11SecretaryItemRulesetFixture;
use Tests\TestCase;

final class RulesetV11ContractTest extends TestCase
{
    public const V11_CHECKSUM = '5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8';

    public const V12_CHECKSUM = 'cf55370616b56822fe6807f29cdaec6cb0fd3d9bcc12849d3e61df015bdf656e';

    public const V13_CHECKSUM = '27c5d58d80e55bf2807cecd147b99b80e57ea0e1afd836eea150982445723b1f';

    public const V14_CHECKSUM = 'af9afe5bf055f4d2ecc4349de058f6dfc6281194dd3d52238167ced07c9d8274';

    public const V15_CHECKSUM = 'd361856e81bb6fe8752a5f1c448d8cbbdb87b6471d5142b36a06b756923fda70';

    public const V16_CHECKSUM = '331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d';

    public const V15_FILE_SHA256 = '4a033f2f0fd2ff3e241162f18842360f133741de07ceb32f9eb65a0e606b4283';

    public function test_normal_config_loads_only_the_standalone_current_payload(): void
    {
        $normalConfig = require config_path('hakoniwa.php');
        $current = $normalConfig['ruleset'];
        $source = file_get_contents(config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v16.php'));

        $this->assertIsString($source);
        $this->assertSame(10, substr_count($source, "require __DIR__.'/current/"));
        $this->assertLessThan(100, substr_count($source, "\n"));
        $this->assertSame(['hakoniwa-2s-plus-v16'], array_keys($normalConfig['published_rulesets']));
        $this->assertSame($current, $normalConfig['published_rulesets']['hakoniwa-2s-plus-v16']);
        $this->assertSame($current['secretary'], $normalConfig['current_catalogs']['secretary']);
        $this->assertArrayNotHasKey('behavior', $current);
        $this->assertArrayNotHasKey('data', $current);
        $this->assertArrayNotHasKey('flavor', $current);
        $this->assertSame(
            self::V16_CHECKSUM,
            hash('sha256', json_encode($current, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        );

        $upgradeRulesets = app(RulesetUpgradeAuthoringCatalog::class)->all();
        $this->assertCount(26, $upgradeRulesets);
        $this->assertSame($current, $upgradeRulesets['hakoniwa-2s-plus-v16']);
        $this->assertSame(
            self::V15_CHECKSUM,
            hash('sha256', json_encode(
                $upgradeRulesets['hakoniwa-2s-plus-v15'],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            )),
        );
        $this->assertSame(
            self::V15_FILE_SHA256,
            hash_file('sha256', config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v15.php')),
        );
        $this->assertSame('novice', $current['secretary']['items']['ring']['rarity']);
        $this->assertSame('accessory', $current['secretary']['items']['ring']['category']);
        $this->assertArrayNotHasKey('same_item_max_equipped', $current['secretary']['items']['ring']);
        $this->assertCount(9, $current['secretary']['items']);
        $this->assertSame('ノービス', $current['secretary']['item_rarities']['novice']['name']);
        $this->assertFalse($current['secretary']['items']['old_bow']['npc_tradable']);
        $this->assertSame(3, $current['trading_post']['player']['active_listing_limit']);
        $this->assertSame([9, 10, 'floor'], [
            $current['trading_post']['player']['seller_proceeds_numerator'],
            $current['trading_post']['player']['seller_proceeds_denominator'],
            $current['trading_post']['player']['seller_proceeds_rounding'],
        ]);
        $this->assertSame(
            self::V14_CHECKSUM,
            hash('sha256', json_encode(
                $upgradeRulesets['hakoniwa-2s-plus-v14'],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            )),
        );
        $this->assertSame(
            self::V12_CHECKSUM,
            hash('sha256', json_encode(
                $upgradeRulesets['hakoniwa-2s-plus-v12'],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            )),
        );
    }

    public function test_current_domain_authoring_classifies_every_leaf_exactly_once(): void
    {
        $this->assertSame([
            'domains' => 10,
            'leaves' => 1841,
            'behavior' => 1153,
            'data' => 506,
            'flavor' => 182,
        ], app(CurrentRulesetAuthoringInspector::class)->inspect(config('hakoniwa.ruleset')));
    }

    public function test_formal_v11_is_the_current_immutable_c1_through_c4_payload(): void
    {
        $settings = app(RulesetUpgradeAuthoringCatalog::class)->get('hakoniwa-2s-plus-v11');
        $this->assertIsArray($settings);
        $this->assertSame('hakoniwa-2s-plus-v11', $settings['key']);
        $this->assertSame(11, $settings['version']);
        $this->assertSame('hakoniwa-2s-plus-v16', config('hakoniwa.ruleset.key'));
        $this->assertSame(16, config('hakoniwa.ruleset.version'));
        $this->assertFileExists(config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v11.php'));
        $this->assertSame(
            [
                '2026_08_22_000000_rebaseline_ver_2_4_install_and_upgrade.php',
                '2026_08_23_000000_add_nation_dormancy_and_publish_v12.php',
                '2026_08_23_010000_add_nation_karma_and_publish_v13.php',
                '2026_08_24_000000_add_secretary_profiles_and_publish_v14.php',
                '2026_08_24_010000_add_monster_experience_and_publish_v15.php',
                '2026_08_25_000000_add_oil_resource_and_publish_v16.php',
            ],
            array_map('basename', glob(database_path('migrations/*.php')) ?: []),
        );
        $this->assertSame(
            self::V11_CHECKSUM,
            hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        );
        $fixture = V11SecretaryItemRulesetFixture::settings();
        $fixture['key'] = 'hakoniwa-2s-plus-v11';
        $this->assertSame($settings, $fixture);

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);
        $this->assertSame('hakoniwa-2s-plus-v11', $summary['key']);
        $this->assertSame(11, $summary['version']);
        $this->assertSame(25, $summary['commands']);
        $this->assertSame(3, $summary['production']);
    }

    public function test_formal_v11_has_exact_monster_dispatch_and_secretary_contracts(): void
    {
        $settings = app(RulesetUpgradeAuthoringCatalog::class)->get('hakoniwa-2s-plus-v11');
        $monsters = collect($settings['monster_definitions'])->keyBy('key');
        $this->assertSame([
            'mecha_inora',
            'mecha_inora_zero',
            'inora',
            'sanjira',
            'red_inora',
            'dark_inora',
            'aoi_inora',
            'inora_ghost',
            'whale',
            'king_inora',
        ], $monsters->sortBy('display_order')->keys()->values()->all());
        $this->assertSame([0, 50, 100, 200, 300, 400, 450, 500, 600, 700],
            $monsters->sortBy('display_order')->pluck('display_order')->values()->all());

        $aoi = $monsters->get('aoi_inora');
        $this->assertSame([2, 1, 1, 1_200, 18], [
            $aoi['base_hp'], $aoi['hp_variation'], $aoi['movement_limit'],
            $aoi['wreckage_value_money'], $aoi['missile_base_experience'],
        ]);
        $this->assertSame('hostless_full_killer_money', $aoi['source_metadata']['reward_policy']);
        $this->assertSame('world_aoi_disaster', $aoi['source_metadata']['behavior']['world_spawn']['type']);
        $this->assertSame(4, $aoi['source_metadata']['behavior']['world_spawn']['minimum_land_distance']);
        $this->assertSame([
            'candidate_attempts_per_action' => 3,
            'blocked_terrain_keys' => ['mountain'],
            'blocked_facility_keys' => ['mine', 'monument', 'capital'],
            'defense_facility_key' => 'defense',
            'destination_terrain_key' => 'sea',
            'clear_owner' => true,
        ], $aoi['movement_terrain_contract']);

        $zero = $monsters->get('mecha_inora_zero');
        $this->assertSame([4, 0, 0, 35], [
            $zero['base_hp'], $zero['hp_variation'], $zero['wreckage_value_money'],
            $zero['missile_base_experience'],
        ]);
        $this->assertSame('nuclear_self_destruct_at_hp_one',
            $zero['source_metadata']['behavior']['special_action']);

        $dispatches = collect($settings['command_definitions'])->where('key', 'monster_dispatch')->values();
        $this->assertCount(1, $dispatches);
        $dispatch = $dispatches->first();
        $this->assertSame(3_000, $dispatch['cost_money']);
        $this->assertSame(1, $dispatch['metadata']['default_selector_value']);
        $this->assertSame([
            [1, 'mecha_inora', 'メカいのら', 3_000],
            [2, 'mecha_inora_zero', 'メカいのら零式', 9_999],
        ], array_map(static fn (array $option): array => [
            $option['value'], $option['monster_key'], $option['label'], $option['cost_money'],
        ], $dispatch['metadata']['monster_dispatch_options']));

        $oldBow = $settings['secretary']['items']['old_bow']['effects'][0];
        $this->assertSame([1_000, 1, 'owned_territory', ['surface']], [
            $oldBow['chance_basis_points'], $oldBow['damage'],
            $oldBow['target_scope'], $oldBow['target_map_space_keys'],
        ]);
        $this->assertSame('after_missile_finalization_before_normal_monsters', $oldBow['timing']);
        $this->assertSame(1, $settings['secretary']['items']['ring']['effects'][0]['bonus_money_per_level']);
        $this->assertSame('after_ordinary_surface_cell_events',
            $settings['turn_resolution']['normal_monster_stage']);

        $v15 = app(RulesetUpgradeAuthoringCatalog::class)->get('hakoniwa-2s-plus-v15');
        $this->assertSame([
            'mecha_inora' => 3,
            'mecha_inora_zero' => 9,
            'inora' => 4,
            'sanjira' => 5,
            'red_inora' => 4,
            'dark_inora' => 6,
            'aoi_inora' => 8,
            'inora_ghost' => 10,
            'whale' => 5,
            'king_inora' => 6,
        ], collect($v15['monster_definitions'])->mapWithKeys(
            static fn (array $monster): array => [$monster['key'] => $monster['experience_per_damage']],
        )->all());
        $this->assertSame(
            'actual_damage_times_monster_definition.experience_per_damage',
            $v15['military']['launch_base_experience']['monster_damage_experience'],
        );
        $this->assertSame(0, $v15['military']['launch_base_experience']['monster_final_blow_experience']);
        $forestManagement = $v15['secretary']['skills']['forest_management'];
        $this->assertSame([
            'type' => 'forest_management',
            'percent_per_level' => 1,
            'rounding' => 'floor_after_multiplier',
            'logging_base' => 'canonical_logging_income',
            'forest_growth_base' => 'terrain_quantities.forest.growth_increment',
        ], $forestManagement['effect']);
        $this->assertSame(['logging', 'plant_forest'], $forestManagement['experience_source']['command_keys']);
        $this->assertFalse($forestManagement['experience_source']['historical_backfill']);
    }
}
