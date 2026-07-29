<?php

namespace Tests\Unit;

use App\Domain\Economy\SalePolicy;
use App\Domain\Ruleset\RulesetAuthoringCollection;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RulesetAuthoringValidatorTest extends TestCase
{
    /** @var array<string, string> */
    private const PRE_SPLIT_PAYLOAD_HASHES = [
        'roadmap-pr2-v1' => '091494cae4988c2517417f91bb9810e277ee665525c98ff67eeb305b23592fe3',
        'roadmap-pr6-v1' => 'e037bec2bb55672fa0497c8238d31f5217f1f17ff48ad153a61993f20ac0fc39',
        'roadmap-pr7-v1' => 'fa9819d1deed15db3c394eb94f0fba5fc1645add2b1e39af2e74873b95a9c7df',
    ];

    public function test_split_authoring_files_preserve_every_existing_payload_byte_for_byte_as_json(): void
    {
        foreach (self::PRE_SPLIT_PAYLOAD_HASHES as $key => $expectedHash) {
            $payload = config("hakoniwa.published_rulesets.{$key}");

            $this->assertIsArray($payload);
            $this->assertSame(
                $expectedHash,
                hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
                $key,
            );
        }
    }

    public function test_all_existing_authoring_versions_pass_the_shared_validator(): void
    {
        $validator = app(RulesetAuthoringValidator::class);

        foreach (self::PRE_SPLIT_PAYLOAD_HASHES as $key => $_hash) {
            $summary = $validator->validate(config("hakoniwa.published_rulesets.{$key}"));

            $this->assertSame($key, $summary['key']);
            $this->assertSame(7, $summary['commands']);
            $this->assertSame(3, $summary['production']);
        }
    }

    public function test_architecture_chunk_size_and_canonical_initial_bounds_are_valid(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['chunk_size'] = 16;
        $settings['initial_x_min'] = 0;
        $settings['initial_x_max'] = 59;
        $settings['initial_y_min'] = 0;
        $settings['initial_y_max'] = 59;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_ruleset_version_accepts_postgresql_integer_maximum(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['version'] = 2_147_483_647;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame(2_147_483_647, $summary['version']);
    }

    public function test_ruleset_version_rejects_values_above_postgresql_integer_maximum(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['version'] = 2_147_483_648;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.version must fit the PostgreSQL integer range 1..2147483647',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    #[DataProvider('invalidChunkSizeProvider')]
    public function test_non_architecture_chunk_sizes_are_rejected(int $chunkSize): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['chunk_size'] = $chunkSize;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ruleset.chunk_size must be exactly 16');

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{int}> */
    public static function invalidChunkSizeProvider(): array
    {
        return [
            'fifteen' => [15],
            'seventeen' => [17],
        ];
    }

    #[DataProvider('invalidInitialBoundsProvider')]
    public function test_noncanonical_initial_bounds_are_rejected(array $bounds): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings = [...$settings, ...$bounds];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Ruleset initial bounds must be x=0..59 and y=0..59');

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{array<string, int>}> */
    public static function invalidInitialBoundsProvider(): array
    {
        return [
            'zero through sixty-three' => [[
                'initial_x_min' => 0,
                'initial_x_max' => 63,
                'initial_y_min' => 0,
                'initial_y_max' => 63,
            ]],
            'negative x minimum' => [['initial_x_min' => -1]],
            'short y maximum' => [['initial_y_max' => 58]],
        ];
    }

    #[DataProvider('supportedDefaultSalePolicyProvider')]
    public function test_supported_default_sale_policies_are_valid(string $policy): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['default_sale_policy'] = $policy;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    /** @return array<string, array{string}> */
    public static function supportedDefaultSalePolicyProvider(): array
    {
        return [
            'sell all' => ['sell_all'],
            'stockpile' => ['stockpile'],
        ];
    }

    #[DataProvider('unsupportedDefaultSalePolicyProvider')]
    public function test_unsupported_default_sale_policy_is_rejected(string $policy): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['default_sale_policy'] = $policy;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.default_sale_policy must be one of sell_all, stockpile',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{string}> */
    public static function unsupportedDefaultSalePolicyProvider(): array
    {
        return [
            'runtime-only keep amount' => ['keep_amount'],
            'typo' => ['stockplie'],
        ];
    }

    public function test_runtime_sale_policy_still_supports_keep_amount(): void
    {
        $this->assertTrue(SalePolicy::isSupported('keep_amount'));
        $this->assertContains('keep_amount', SalePolicy::values());
        $this->assertFalse(SalePolicy::isSupportedRulesetDefault('keep_amount'));
        $this->assertNotContains('keep_amount', SalePolicy::rulesetDefaultValues());
    }

    #[DataProvider('supportedFacilityVisibilityPolicyProvider')]
    public function test_supported_facility_visibility_policies_are_valid(string $policy): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['missile_base']['visibility_policy'] = $policy;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    /** @return array<string, array{string}> */
    public static function supportedFacilityVisibilityPolicyProvider(): array
    {
        return [
            'public' => ['public'],
            'disguised' => ['disguised'],
        ];
    }

    public function test_unsupported_facility_visibility_policy_is_rejected(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['missile_base']['visibility_policy'] = 'disgused';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.facility_definitions.missile_base.visibility_policy must be one of public, disguised',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_forest_terrain_quantity_is_valid(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['terrain_quantities'] = [
            'forest' => $settings['terrain_quantities']['forest'],
        ];

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    #[DataProvider('missingForestTerrainQuantityProvider')]
    public function test_forest_terrain_quantity_is_required(array $quantities): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['terrain_quantities'] = $quantities;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ruleset.terrain_quantities must include forest');

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function missingForestTerrainQuantityProvider(): array
    {
        return [
            'empty' => [[]],
            'only another terrain' => [[
                'plain' => [
                    'key' => 'trees',
                    'label' => '木',
                    'unit' => '本',
                    'initial_quantity' => 1,
                    'minimum_quantity' => 0,
                    'maximum_quantity' => 200,
                    'growth_increment' => 1,
                    'growth_rule_key' => 'forest_growth',
                ],
            ]],
        ];
    }

    public function test_initial_island_required_facilities_are_valid(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
        $this->assertArrayHasKey('village', $settings['facility_definitions']);
        $this->assertArrayHasKey('missile_base', $settings['facility_definitions']);
        $this->assertArrayHasKey('capital', $settings['facility_definitions']);
    }

    #[DataProvider('missingInitialIslandFacilityProvider')]
    public function test_initial_island_required_facility_is_rejected_when_missing(string $facilityKey): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        unset($settings['facility_definitions'][$facilityKey]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            "ruleset.facility_definitions must include {$facilityKey} for initial island generation",
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{string}> */
    public static function missingInitialIslandFacilityProvider(): array
    {
        return [
            'village' => ['village'],
            'missile base' => ['missile_base'],
            'capital' => ['capital'],
        ];
    }

    public function test_missile_base_initial_experience_is_required(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        unset($settings['facility_definitions']['missile_base']['initial_experience']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.facility_definitions.missile_base is missing required key initial_experience',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_missile_base_maximum_experience_is_required(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        unset($settings['facility_definitions']['missile_base']['maximum_experience']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.facility_definitions.missile_base is missing required key maximum_experience',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    #[DataProvider('invalidMissileBaseInitialExperienceProvider')]
    public function test_missile_base_initial_experience_must_be_a_non_negative_integer(mixed $experience): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['missile_base']['initial_experience'] = $experience;

        $this->expectException(DomainException::class);

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidMissileBaseInitialExperienceProvider(): array
    {
        return [
            'float' => [0.0],
            'string' => ['0'],
            'negative integer' => [-1],
            'outside PostgreSQL integer range' => [2_147_483_648],
        ];
    }

    #[DataProvider('invalidMissileBaseMaximumExperienceProvider')]
    public function test_missile_base_maximum_experience_must_be_a_persisted_non_negative_integer(
        mixed $experience,
    ): void {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['missile_base']['maximum_experience'] = $experience;

        $this->expectException(DomainException::class);

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidMissileBaseMaximumExperienceProvider(): array
    {
        return [
            'float' => [200.0],
            'string' => ['200'],
            'negative integer' => [-1],
            'outside PostgreSQL integer range' => [2_147_483_648],
        ];
    }

    public function test_missile_base_initial_experience_cannot_exceed_maximum(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['missile_base']['initial_experience'] = 201;
        $settings['facility_definitions']['missile_base']['maximum_experience'] = 200;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.facility_definitions.missile_base.initial_experience cannot exceed maximum_experience',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_missile_base_experience_accepts_the_persisted_integer_boundary(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['missile_base']['initial_experience'] = 2_147_483_647;
        $settings['facility_definitions']['missile_base']['maximum_experience'] = 2_147_483_647;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_facility_scale_fields_may_be_consistently_null(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertNull($settings['facility_definitions']['village']['scale_unit_people']);
        $this->assertNull($settings['facility_definitions']['village']['initial_scale']);
        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_facility_scale_fields_must_be_a_complete_tuple(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['farm']['scale_increment'] = null;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.facility_definitions.farm scale fields must either all be null or all be non-null',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_facility_initial_scale_cannot_exceed_maximum_scale(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['farm']['initial_scale'] = 51;
        $settings['facility_definitions']['farm']['maximum_scale'] = 50;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.facility_definitions.farm.initial_scale cannot exceed maximum_scale',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_facility_scale_fields_accept_the_persisted_integer_boundary(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        foreach ([
            'scale_unit_people',
            'initial_scale',
            'scale_increment',
            'maximum_scale',
            'workforce_per_scale_people',
        ] as $field) {
            $settings['facility_definitions']['farm'][$field] = 2_147_483_647;
        }

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_facility_scale_fields_reject_values_outside_the_persisted_integer_range(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['farm']['scale_increment'] = 2_147_483_648;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.facility_definitions.farm.scale_increment must fit the PostgreSQL integer range',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_facility_asset_keys_must_be_unique(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['factory']['asset_key'] =
            $settings['facility_definitions']['farm']['asset_key'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.facility_definitions.factory.asset_key duplicates facility asset key',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_equal_initial_island_radius_boundary_is_valid(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['initial_territory_radius'] = 2;
        $settings['initial_island_land_radius'] = 2;
        $settings['initial_island_growth_radius'] = 2;
        $settings['initial_island_reservation_radius'] = 2;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_initial_island_land_radius_must_contain_fixed_starter_cells(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['initial_territory_radius'] = 1;
        $settings['initial_island_land_radius'] = 1;
        $settings['initial_island_growth_radius'] = 1;
        $settings['initial_island_reservation_radius'] = 2;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.initial_island_land_radius must be at least 2 to contain starter cells',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_initial_territory_radius_cannot_exceed_land_radius(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['initial_territory_radius'] = 3;
        $settings['initial_island_land_radius'] = 2;
        $settings['initial_island_growth_radius'] = 2;
        $settings['initial_island_reservation_radius'] = 3;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.initial_island_land_radius must be at least initial_territory_radius',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    #[DataProvider('invalidInitialIslandRadiusProvider')]
    public function test_initial_island_reservation_radius_must_contain_generation_areas(array $radii): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings = [...$settings, ...$radii];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.initial_island_reservation_radius must be at least '
            .'max(initial_island_land_radius, initial_island_growth_radius, 2)',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{array<string, int>}> */
    public static function invalidInitialIslandRadiusProvider(): array
    {
        return [
            'reservation below land radius' => [[
                'initial_island_land_radius' => 3,
                'initial_island_growth_radius' => 2,
                'initial_island_reservation_radius' => 2,
            ]],
            'reservation below growth radius' => [[
                'initial_island_land_radius' => 2,
                'initial_island_growth_radius' => 3,
                'initial_island_reservation_radius' => 2,
            ]],
        ];
    }

    public function test_largest_reservation_radius_that_fits_initial_bounds_is_valid(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['initial_island_reservation_radius'] = 29;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_reservation_radius_must_leave_a_capital_candidate_inside_initial_bounds(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['initial_island_reservation_radius'] = 30;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.initial_island_reservation_radius must be at most 29 '
            .'so the initial bounds contain a Capital candidate',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    #[DataProvider('validProductionDecimalProvider')]
    public function test_production_per_scale_accepts_values_exactly_persistable_as_decimal_16_4(
        int|float $value,
    ): void {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['production_definitions'][0]['production_per_scale'] = $value;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    /** @return array<string, array{int|float}> */
    public static function validProductionDecimalProvider(): array
    {
        return [
            'zero' => [0],
            'integer' => [10],
            'four decimal places' => [1.2345],
            'smallest four-decimal increment' => [0.0001],
            'twelve integer digits' => [999_999_999_999],
            'decimal 16 4 maximum' => [999_999_999_999.9999],
            'large four-decimal value' => [99_999_999_999.1234],
        ];
    }

    #[DataProvider('invalidProductionDecimalProvider')]
    public function test_production_per_scale_rejects_values_not_exactly_persistable_as_decimal_16_4(
        mixed $value,
    ): void {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['production_definitions'][0]['production_per_scale'] = $value;

        $this->expectException(DomainException::class);

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidProductionDecimalProvider(): array
    {
        return [
            'string' => ['1.2345'],
            'negative' => [-0.0001],
            'negative zero' => [-0.0],
            'not finite positive' => [INF],
            'not finite negative' => [-INF],
            'not a number' => [NAN],
            'five decimal places' => [1.23456],
            'thirteen integer digits' => [1_000_000_000_000],
        ];
    }

    public function test_required_workforce_accepts_postgresql_integer_maximum(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['production_definitions'][0]['required_workforce_per_scale'] = 2_147_483_647;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_required_workforce_rejects_values_above_postgresql_integer_maximum(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['production_definitions'][0]['required_workforce_per_scale'] = 2_147_483_648;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.production_definitions.0.required_workforce_per_scale '
            .'must fit the PostgreSQL integer range',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_each_facility_can_have_only_one_production_definition_per_ruleset(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $duplicate = $settings['production_definitions'][0];
        $duplicate['key'] = 'farm_wheat_duplicate';
        $settings['production_definitions'][] = $duplicate;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.production_definitions.3.facility_key duplicates production facility farm',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_authored_strings_must_contain_valid_utf_8(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['resource_definitions'][0]['name'] = "\xC3\x28";

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.resource_definitions.0.name must contain valid UTF-8',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_nested_json_authored_values_accept_valid_utf_8(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['command_definitions'][0]['metadata']['utf8'] = [
            '日本語' => ['emoji' => '🏝️', 'ascii' => 'hakoniwa'],
            'list' => ['平和', 'turn-1'],
        ];

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_nested_json_authored_string_values_must_contain_valid_utf_8(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['command_definitions'][0]['metadata']['nested']['invalid'] = "\xC3\x28";

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.command_definitions.0.metadata.nested.invalid must contain valid UTF-8',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_nested_json_authored_string_keys_must_contain_valid_utf_8(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['command_definitions'][0]['metadata']['nested']["\xC3\x28"] = 'value';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.command_definitions.0.metadata.nested contains a key that must contain valid UTF-8',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_nested_json_authored_string_values_must_not_contain_u_0000(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['command_definitions'][0]['metadata']['nested']['invalid'] = "before\0after";

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.command_definitions.0.metadata.nested.invalid must not contain U+0000',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_nested_json_authored_string_keys_must_not_contain_u_0000(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['command_definitions'][0]['metadata']['nested']["before\0after"] = 'value';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.command_definitions.0.metadata.nested contains a key that must not contain U+0000',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_final_authored_payload_must_be_json_encodable(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['command_definitions'][0]['metadata']['not_finite'] = INF;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ruleset must be JSON encodable');

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    #[DataProvider('persistedVarcharFieldProvider')]
    public function test_persisted_varchar_fields_accept_255_characters(
        callable $mutate,
        string $_path,
    ): void {
        $settings = $mutate(
            config('hakoniwa.published_rulesets.roadmap-pr7-v1'),
            str_repeat('界', 255),
        );

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame(7, $summary['commands']);
    }

    #[DataProvider('persistedVarcharFieldProvider')]
    public function test_persisted_varchar_fields_reject_256_characters(
        callable $mutate,
        string $path,
    ): void {
        $settings = $mutate(
            config('hakoniwa.published_rulesets.roadmap-pr7-v1'),
            str_repeat('界', 256),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("{$path} must be at most 255 characters");

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /**
     * @return array<string, array{
     *     callable(array<string, mixed>, string): array<string, mixed>,
     *     string
     * }>
     */
    public static function persistedVarcharFieldProvider(): array
    {
        return [
            'ruleset key' => [
                static function (array $settings, string $value): array {
                    $settings['key'] = $value;

                    return $settings;
                },
                'ruleset.key',
            ],
            'resource name' => [
                static function (array $settings, string $value): array {
                    $settings['resource_definitions'][0]['name'] = $value;

                    return $settings;
                },
                'ruleset.resource_definitions.0.name',
            ],
            'terrain quantity label' => [
                static function (array $settings, string $value): array {
                    $settings['terrain_quantities']['forest']['label'] = $value;

                    return $settings;
                },
                'ruleset.terrain_quantities.forest.label',
            ],
            'facility name' => [
                static function (array $settings, string $value): array {
                    $settings['facility_definitions']['village']['name'] = $value;

                    return $settings;
                },
                'ruleset.facility_definitions.village.name',
            ],
            'command name' => [
                static function (array $settings, string $value): array {
                    $settings['command_definitions'][0]['name'] = $value;

                    return $settings;
                },
                'ruleset.command_definitions.0.name',
            ],
            'production operating condition' => [
                static function (array $settings, string $value): array {
                    $settings['production_definitions'][0]['operating_condition'] = $value;

                    return $settings;
                },
                'ruleset.production_definitions.0.operating_condition',
            ],
        ];
    }

    public function test_text_and_jsonb_strings_are_not_limited_to_varchar_width(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['command_definitions'][0]['description'] = str_repeat('説', 256);
        $settings['command_definitions'][0]['metadata']['long_text'] = str_repeat('明', 256);

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_initial_balances_may_equal_base_capacities(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['base_money_capacity'] = $settings['initial_money'];
        $settings['base_food_capacity_tons'] = 10_000;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_initial_money_cannot_exceed_base_capacity(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['base_money_capacity'] = $settings['initial_money'] - 1;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.initial_money cannot exceed ruleset.base_money_capacity',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_initial_food_total_cannot_exceed_base_capacity(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['base_food_capacity_tons'] = 9_999;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset initial food total cannot exceed ruleset.base_food_capacity_tons',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_legacy_authoring_version_without_capacity_keys_remains_valid(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr6-v1');
        $this->assertArrayNotHasKey('base_money_capacity', $settings);
        $this->assertArrayNotHasKey('base_food_capacity_tons', $settings);

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr6-v1', $summary['key']);
    }

    public function test_duplicate_authoring_version_keys_are_rejected(): void
    {
        $ruleset = config('hakoniwa.published_rulesets.roadmap-pr7-v1');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Duplicate ruleset authoring key');

        RulesetAuthoringCollection::fromArrays([$ruleset, $ruleset]);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[DataProvider('invalidRulesetProvider')]
    public function test_invalid_authoring_payloads_fail_closed(callable $mutate, string $message): void
    {
        $settings = $mutate(config('hakoniwa.published_rulesets.roadmap-pr7-v1'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($message);

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function invalidRulesetProvider(): array
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
            'string where integer is required' => [
                static function (array $settings): array {
                    $settings['command_queue_limit'] = '20';

                    return $settings;
                },
                'ruleset.command_queue_limit must be an integer',
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
        ];
    }
}
