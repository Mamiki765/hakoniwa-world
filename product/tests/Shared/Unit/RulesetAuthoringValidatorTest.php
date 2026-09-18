<?php

namespace Tests\Shared\Unit;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Domain\Ship\SurfaceShipCatalog;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RulesetAuthoringValidatorTest extends TestCase
{
    public function test_current_authoring_source_passes_the_shared_validator(): void
    {
        $validator = app(RulesetAuthoringValidator::class);
        $settings = config('hakoniwa.ruleset');

        $summary = $validator->validate($settings);
        $this->assertSame('hakoniwa-2s-plus-v26', $summary['key']);
        $this->assertSame(26, $summary['version']);
        $this->assertSame(count($settings['command_definitions']), $summary['commands']);
    }

    public function test_balance_values_and_additional_ship_are_validated_from_the_authored_payload(): void
    {
        $settings = config('hakoniwa.ruleset');
        $settings['initial_x_max'] = 75;
        $settings['initial_y_max'] = 75;
        $settings['nation_lifecycle']['recovery_duration_turns'] = 96;
        $settings['nation_lifecycle']['dormant_visual_theme'] = 'frost';
        $settings['facility_definitions']['port']['name'] = '新港';
        $settings['central_facilities']['definitions']['central_bank']['capacity_per_level'] = 2_000;
        $settings['surface_ships']['capacity_per_type'] = 4;
        $settings['surface_ships']['definitions']['fishing']['maximum_hp'] = 2;
        $settings['surface_ships']['definitions']['research'] = [
            'name' => '調査船',
            'asset_key' => 'ship.research',
            'player_buildable' => false,
            'build_selector' => null,
            'sort_order' => 70,
            'build_cost_money' => 0,
            'maximum_hp' => 2,
            'movement_oil_units' => 0,
            'movement_reward_resource_key' => null,
            'movement_reward_resource_units' => 0,
            'movement_reward_money' => 0,
            'visibility_radius' => 2,
            'movement_mode' => 'random_drift',
            'combat_role' => 'none',
        ];
        $settings['monster_definitions'][10]['name'] = '珍獣ニョワミヤ改';
        $settings['monster_definitions'][10]['experience_per_damage'] = 21;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('hakoniwa-2s-plus-v26', $summary['key']);
        $this->assertCount(7, app(SurfaceShipCatalog::class)->definitions($settings));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[DataProvider('invalidCurrentRulesetProvider')]
    public function test_representative_current_payload_corruption_fails_closed(
        callable $mutate,
        string $message,
    ): void {
        $settings = $mutate(config('hakoniwa.ruleset'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($message);

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function invalidCurrentRulesetProvider(): array
    {
        return [
            'missing required top-level key' => [
                static function (array $settings): array {
                    unset($settings['initial_money']);

                    return $settings;
                },
                'missing required key initial_money',
            ],
            'float where integer is required' => [
                static function (array $settings): array {
                    $settings['initial_money'] = 100.0;

                    return $settings;
                },
                'ruleset.initial_money must be an integer',
            ],
            'negative unsigned value' => [
                static function (array $settings): array {
                    $settings['initial_resources']['wheat'] = -1;

                    return $settings;
                },
                'ruleset.initial_resources.wheat must be at least 0',
            ],
            'missing catalog reference' => [
                static function (array $settings): array {
                    $settings['production_definitions'][0]['facility_key'] = 'missing-facility';

                    return $settings;
                },
                'references missing catalog or definition missing-facility',
            ],
            'duplicate monster display order' => [
                static function (array $settings): array {
                    $settings['monster_definitions'][1]['display_order']
                        = $settings['monster_definitions'][0]['display_order'];

                    return $settings;
                },
                'display_order duplicates another effective monster order',
            ],
        ];
    }
}
