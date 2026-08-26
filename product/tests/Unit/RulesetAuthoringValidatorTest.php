<?php

namespace Tests\Unit;

use App\Domain\Ruleset\RulesetAuthoringValidator;
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
        $this->assertSame('hakoniwa-2s-plus-v17', $summary['key']);
        $this->assertSame(17, $summary['version']);
        $this->assertSame(count($settings['command_definitions']), $summary['commands']);
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
