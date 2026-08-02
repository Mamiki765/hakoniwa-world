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

    public function test_nutrition_accepts_decimal_12_4_integer_boundary(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['resource_definitions'][0]['nutrition_per_unit'] = 99_999_999;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_nutrition_rejects_values_outside_decimal_12_4(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['resource_definitions'][0]['nutrition_per_unit'] = 100_000_000;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.resource_definitions.0.nutrition_per_unit must fit decimal(12,4) without rounding',
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

    public function test_existing_and_pr11_command_queue_limits_are_valid(): void
    {
        $validator = app(RulesetAuthoringValidator::class);

        $legacy = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $pr11 = config('hakoniwa.published_rulesets.roadmap-pr11-v1');

        $this->assertSame(20, $legacy['command_queue_limit']);
        $this->assertSame('roadmap-pr7-v1', $validator->validate($legacy)['key']);
        $this->assertSame(30, $pr11['command_queue_limit']);
        $this->assertSame('roadmap-pr11-v1', $validator->validate($pr11)['key']);
    }

    public function test_pr14_seabed_oil_contract_is_valid_and_bounded_by_universal_quantity(): void
    {
        $validator = app(RulesetAuthoringValidator::class);
        $settings = config('hakoniwa.published_rulesets.roadmap-pr14-v1');

        $this->assertSame('roadmap-pr14-v1', $validator->validate($settings)['key']);

        $settings['turn_processing']['command_random_effects']['seabed_oil_search']['success_threshold_per_cost_unit'] = 2;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('maximum quantity threshold cannot exceed draw_denominator');
        $validator->validate($settings);
    }

    public function test_pr14_seabed_oil_contract_rejects_an_unknown_facility(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr14-v1');
        $settings['turn_processing']['command_random_effects']['seabed_oil_search']['facility_key'] = 'missing-oil';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('references missing catalog or definition missing-oil');
        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_command_queue_authoring_safety_maximum_is_valid(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['command_queue_limit'] = 168;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    #[DataProvider('invalidCommandQueueLimitProvider')]
    public function test_command_queue_limits_outside_the_authoring_safety_range_are_rejected(mixed $limit): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['command_queue_limit'] = $limit;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ruleset.command_queue_limit');

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidCommandQueueLimitProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'one hundred sixty-nine' => [169],
            'postgresql integer overflow' => [2_147_483_648],
            'numeric string' => ['30'],
            'float' => [30.0],
        ];
    }

    public function test_pr11_turn_processing_rejects_invalid_probability_and_stage_ranges(): void
    {
        $validator = app(RulesetAuthoringValidator::class);
        $settings = config('hakoniwa.published_rulesets.roadmap-pr11-v1');
        $settings['turn_processing']['settlement']['appearance_probability']['numerator'] = 101;

        try {
            $validator->validate($settings);
            $this->fail('An invalid probability must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('numerator cannot exceed denominator', $exception->getMessage());
        }

        $settings = config('hakoniwa.published_rulesets.roadmap-pr11-v1');
        $settings['turn_processing']['settlement']['stages']['town']['minimum_population'] = 3001;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('contiguous population thresholds');
        $validator->validate($settings);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[DataProvider('invalidPr11TurnContractProvider')]
    public function test_pr11_turn_contract_rejects_incompatible_authored_values(
        callable $mutate,
        string $message,
    ): void {
        $settings = $mutate(config('hakoniwa.published_rulesets.roadmap-pr11-v1'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($message);
        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function invalidPr11TurnContractProvider(): array
    {
        return [
            'food must use canonical tons' => [
                static function (array $settings): array {
                    $settings['resource_definitions'][0]['unit'] = 'legacy_unit';

                    return $settings;
                },
                'canonical ton units',
            ],
            'automatic finance must be nonnegative' => [
                static function (array $settings): array {
                    $settings['turn_processing']['automatic_finance_money'] = -1;

                    return $settings;
                },
                'automatic_finance_money must be at least 0',
            ],
            'food nutrition must be integer' => [
                static function (array $settings): array {
                    $settings['resource_definitions'][0]['nutrition_per_unit'] = 1.5;

                    return $settings;
                },
                'nutrition_per_unit must be an integer',
            ],
            'food priority is canonical' => [
                static function (array $settings): array {
                    $settings['turn_processing']['food']['consumption_priority'] = ['fish', 'wheat', 'monster_meat'];

                    return $settings;
                },
                'must be wheat, fish, monster_meat',
            ],
            'worker output must match production scale' => [
                static function (array $settings): array {
                    $settings['production_definitions'][0]['production_per_scale'] = 999;

                    return $settings;
                },
                'farm output must match production per scale',
            ],
            'production output mapping is canonical' => [
                static function (array $settings): array {
                    $settings['production_definitions'][1]['output_resource_key'] = 'minerals';

                    return $settings;
                },
                'factory production mapping is incompatible',
            ],
            'production workforce units match facility scale' => [
                static function (array $settings): array {
                    $settings['facility_definitions']['mine']['workforce_per_scale_people'] = 999;

                    return $settings;
                },
                'mine scale and workforce units must match',
            ],
            'all tradable resources require a sale rate' => [
                static function (array $settings): array {
                    unset($settings['inventory_sale_rates']['fish']);

                    return $settings;
                },
                'missing tradable resource fish',
            ],
            'sale revenue rate must be positive' => [
                static function (array $settings): array {
                    $settings['inventory_sale_rates']['wheat']['money_units'] = 0;

                    return $settings;
                },
                'money_units must be at least 1',
            ],
            'wheat sell all must be forbidden' => [
                static function (array $settings): array {
                    $settings['turn_processing']['sale_policy']['sell_all_forbidden_resource_keys'] = [];

                    return $settings;
                },
                'must forbid sell_all for wheat',
            ],
            'wheat safe default is stockpile' => [
                static function (array $settings): array {
                    $settings['default_sale_policy'] = 'sell_all';

                    return $settings;
                },
                'requires stockpile as the wheat-safe default',
            ],
            'sea edge bands must be descending' => [
                static function (array $settings): array {
                    $settings['turn_processing']['settlement']['sea_edge_bands'][1]['minimum_sea_cells'] = 25;

                    return $settings;
                },
                'must use descending minimums',
            ],
            'settlement stage facility cannot use workforce scale' => [
                static function (array $settings): array {
                    $settings['turn_processing']['settlement']['stages']['village']['facility_key'] = 'farm';

                    return $settings;
                },
                'must be population-derived',
            ],
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
    public function test_supported_facility_visibility_policies_are_valid_for_other_facilities(string $policy): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['farm']['visibility_policy'] = $policy;

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
        $settings['facility_definitions']['farm']['visibility_policy'] = 'disgused';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.facility_definitions.farm.visibility_policy must be one of public, disguised',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_missile_base_must_use_disguised_visibility_policy(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['facility_definitions']['missile_base']['visibility_policy'] = 'public';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.facility_definitions.missile_base.visibility_policy must be disguised',
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
        $settings['initial_island_minimum_shallow_cells'] = 0;

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
        $settings['minimum_capital_distance'] = 1;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_minimum_capital_distance_may_equal_maximum_candidate_separation(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['initial_island_reservation_radius'] = 5;
        $settings['minimum_capital_distance'] = 74;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    #[DataProvider('oversizedMinimumCapitalDistanceProvider')]
    public function test_minimum_capital_distance_cannot_exceed_maximum_candidate_separation(int $distance): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['initial_island_reservation_radius'] = 5;
        $settings['minimum_capital_distance'] = $distance;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.minimum_capital_distance must be at most 74 '
            .'for the initial bounds and reservation radius',
        );

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{int}> */
    public static function oversizedMinimumCapitalDistanceProvider(): array
    {
        return [
            'one beyond the maximum' => [75],
            'oversized authored value' => [1000],
        ];
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

    public function test_minimum_shallow_cells_may_equal_guaranteed_coastal_candidate_capacity(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['initial_island_reservation_radius'] = 5;
        $settings['initial_island_land_radius'] = 2;
        $settings['initial_island_growth_steps'] = 0;
        $settings['initial_island_minimum_shallow_cells'] = 18;

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    public function test_minimum_shallow_cells_cannot_exceed_guaranteed_coastal_candidate_capacity(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr7-v1');
        $settings['initial_island_reservation_radius'] = 5;
        $settings['initial_island_land_radius'] = 2;
        $settings['initial_island_growth_steps'] = 0;
        $settings['initial_island_minimum_shallow_cells'] = 19;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'ruleset.initial_island_minimum_shallow_cells cannot exceed '
            .'the guaranteed coastal candidate capacity 18',
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

    #[DataProvider('persistedSortOrderProvider')]
    public function test_definition_sort_orders_accept_postgresql_integer_maximum(
        callable $mutate,
        string $_path,
    ): void {
        $settings = $mutate(
            config('hakoniwa.published_rulesets.roadmap-pr7-v1'),
            2_147_483_647,
        );

        $summary = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr7-v1', $summary['key']);
    }

    #[DataProvider('persistedSortOrderProvider')]
    public function test_definition_sort_orders_reject_values_above_postgresql_integer_maximum(
        callable $mutate,
        string $path,
    ): void {
        $settings = $mutate(
            config('hakoniwa.published_rulesets.roadmap-pr7-v1'),
            2_147_483_648,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("{$path} must fit the PostgreSQL integer range");

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /**
     * @return array<string, array{
     *     callable(array<string, mixed>, int): array<string, mixed>,
     *     string
     * }>
     */
    public static function persistedSortOrderProvider(): array
    {
        return [
            'resource definition' => [
                static function (array $settings, int $value): array {
                    $settings['resource_definitions'][0]['sort_order'] = $value;

                    return $settings;
                },
                'ruleset.resource_definitions.0.sort_order',
            ],
            'command definition' => [
                static function (array $settings, int $value): array {
                    $settings['command_definitions'][0]['sort_order'] = $value;

                    return $settings;
                },
                'ruleset.command_definitions.0.sort_order',
            ],
        ];
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
