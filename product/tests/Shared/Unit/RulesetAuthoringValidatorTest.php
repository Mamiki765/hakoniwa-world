<?php

namespace Tests\Shared\Unit;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Domain\Ship\SurfaceShipCatalog;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RulesetAuthoringValidatorTest extends TestCase
{
    public function test_underground_amounts_and_missile_prices_are_authored_data(): void
    {
        $settings = config('hakoniwa.ruleset');
        foreach (['underground_city', 'underground_farm', 'underground_factory'] as $key) {
            $effect = &$settings['underground_facility_development']['facility_definitions'][$key]['effect'];
            $effect[array_key_first($effect)]++;
            unset($effect);
        }
        foreach ($settings['underground_facility_development']['command_definitions'] as &$command) {
            $command['cost_money']++;
        }
        unset($command);
        foreach ($settings['military']['missiles'] as &$missile) {
            $missile['cost_money_per_shot']++;
        }
        unset($missile);
        $settings['underground_market']['listing_limit']++;
        $settings['underground_market']['proceeds_numerator']--;
        $settings['underground_market']['page_size']++;
        $settings['secretary']['warehouse']['expansion_cost_paradox']++;
        $settings['secretary']['warehouse']['expansion_slots']++;
        $settings['secretary']['synthesis']['recipes']['stack:fire_charm']['level_cap']--;
        $settings['secretary']['synthesis']['recipes']['succubus_emblem']['inputs'][0]['count']++;

        app(RulesetAuthoringValidator::class)->validate($settings);
        $this->addToAssertionCount(1);
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
        $settings['surface_ships']['definitions']['fishing']['movement_reward_resource_key'] = 'minerals';
        $settings['surface_ships']['definitions']['research'] = [
            'name' => '調査船',
            'asset_key' => 'ship.research',
            'player_buildable' => false,
            'build_selector' => null,
            'sort_order' => max(array_column($settings['surface_ships']['definitions'], 'sort_order')) + 1,
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

        app(RulesetAuthoringValidator::class)->validate($settings);
        $this->assertContains('research', array_column(app(SurfaceShipCatalog::class)->definitions($settings), 'key'));
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
            'market listings have no expiration' => [
                static function (array $settings): array {
                    $settings['underground_market']['listing_expiration'] = 'timed';

                    return $settings;
                },
                'listing_expiration must be none',
            ],
            'market proceeds cannot create money' => [
                static function (array $settings): array {
                    $settings['underground_market']['proceeds_numerator'] = $settings['underground_market']['proceeds_denominator'] + 1;

                    return $settings;
                },
                'proceeds_numerator cannot exceed proceeds_denominator',
            ],
            'warehouse expansions fit persisted range' => [
                static function (array $settings): array {
                    $settings['secretary']['warehouse']['max_expansions'] = 5;

                    return $settings;
                },
                'max_expansions exceeds the persisted range',
            ],
            'stack level cap fits retained item levels' => [
                static function (array $settings): array {
                    $settings['secretary']['synthesis']['recipes']['stack:fire_charm']['level_cap'] = 10000;

                    return $settings;
                },
                'level_cap cannot exceed the retained item cap or 9999',
            ],
            'fixed recipe cannot count one material twice' => [
                static function (array $settings): array {
                    $inputs = &$settings['secretary']['synthesis']['recipes']['succubus_emblem']['inputs'];
                    $inputs[1] = $inputs[0];

                    return $settings;
                },
                'inputs must not repeat an item_key',
            ],
            'repeated quantity must consume one turn' => [
                static function (array $settings): array {
                    foreach ($settings['command_definitions'] as &$command) {
                        if ($command['key'] === 'attraction') {
                            $command['metadata']['repeat_quantity'] = true;
                            $command['metadata']['consumes_turn'] = false;
                        }
                    }
                    unset($command);

                    return $settings;
                },
                'repeat_quantity is only supported for turn-consuming attraction',
            ],
            'repeated quantity flag must be boolean' => [
                static function (array $settings): array {
                    foreach ($settings['command_definitions'] as &$command) {
                        if ($command['key'] === 'attraction') {
                            $command['metadata']['repeat_quantity'] = 'true';
                        }
                    }
                    unset($command);

                    return $settings;
                },
                'metadata.repeat_quantity must be a boolean',
            ],
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
            'unsupported surface ship movement terrain' => [
                static function (array $settings): array {
                    $settings['surface_ships']['movement']['terrain_key'] = 'shallow';

                    return $settings;
                },
                'must use the supported deep-sea handler',
            ],
            'non-zero initial origin' => [
                static function (array $settings): array {
                    $settings['initial_x_min'] = 1;

                    return $settings;
                },
                'must start at x=0 and y=0',
            ],
        ];
    }
}
