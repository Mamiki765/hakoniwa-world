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
