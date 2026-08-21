<?php

namespace Tests\Unit;

use App\Application\RulesetV11MigrationService;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use Tests\Support\V11SecretaryItemRulesetFixture;
use Tests\TestCase;

final class RulesetV11ContractTest extends TestCase
{
    public const CHECKSUM = '5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8';

    public function test_formal_v11_is_the_current_immutable_c1_through_c4_payload(): void
    {
        $settings = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v11');
        $this->assertIsArray($settings);
        $this->assertSame('hakoniwa-2s-plus-v11', $settings['key']);
        $this->assertSame(11, $settings['version']);
        $this->assertSame('hakoniwa-2s-plus-v11', config('hakoniwa.ruleset.key'));
        $this->assertSame(11, config('hakoniwa.ruleset.version'));
        $this->assertSame(
            self::CHECKSUM,
            hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        );
        $this->assertSame(self::CHECKSUM, RulesetV11MigrationService::TARGET_CHECKSUM);

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
        $settings = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v11');
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
    }
}
