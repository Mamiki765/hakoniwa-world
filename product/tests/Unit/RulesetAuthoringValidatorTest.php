<?php

namespace Tests\Unit;

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
