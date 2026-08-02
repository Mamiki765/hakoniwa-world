<?php

namespace Tests\Unit;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Domain\Turn\TurnRandomStreamFactory;
use Tests\TestCase;

class DisasterRulesetContractTest extends TestCase
{
    public function test_pr15_publishes_exact_legacy_half_rates_and_preserves_internal_rates(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr15-v1');
        $validated = app(RulesetAuthoringValidator::class)->validate($settings);
        $disasters = $settings['turn_processing']['disasters'];

        $this->assertSame('roadmap-pr15-v1', $validated['key']);
        $this->assertSame([
            'earthquake' => [80, 2_000],
            'tsunami' => [300, 2_000],
            'typhoon' => [400, 2_000],
            'meteor_shower' => [200, 2_000],
            'huge_meteor' => [100, 2_000],
            'eruption' => [200, 2_000],
        ], collect($disasters)->only([
            'earthquake', 'tsunami', 'typhoon', 'meteor_shower', 'huge_meteor', 'eruption',
        ])->mapWithKeys(static fn (array $event, string $key): array => [
            $key => [$event['probability']['numerator'], $event['probability']['denominator']],
        ])->all());
        $this->assertSame([10, 2_000], array_values($disasters['fire']['probability']));
        $this->assertSame(
            [5, 2_000],
            array_values($settings['turn_processing']['command_random_effects']['land_level_earthquake']['probability']),
        );

        $this->assertSame([40, 1_000], array_values($settings['turn_processing']['oil_field']['depletion_probability']));
        $this->assertSame([1, 4], array_values($settings['turn_processing']['riot']['probability']));
        $this->assertSame([1, 4], array_values($disasters['earthquake']['damage_probability']));
        $this->assertSame([1, 2], array_values($disasters['meteor_shower']['continuation_probability']));
        $this->assertSame(12, $disasters['tsunami']['internal_denominator']);
        $this->assertSame(12, $disasters['typhoon']['internal_denominator']);
        $this->assertSame(1_000, $settings['turn_processing']['oil_field']['income_money']);
        $this->assertSame(100, $settings['capital_minimum_population']);
        $this->assertSame(25_000, $settings['capital_growth_maximum_population']);
        $this->assertSame([
            'facility_or_wasteland' => 10,
            'excavation_or_shallow' => 30,
            'deep_sea' => 90,
            'eruption_center' => 30,
        ], $settings['capital_damage_percentages']);
    }

    public function test_versioned_streams_are_isolated_from_unrelated_draw_counts(): void
    {
        $seed = hash('sha256', 'pr15-stream-isolation');
        $baseline = new TurnRandomStreamFactory($seed);
        $expected = $baseline->stream(TurnRandomStreamFactory::GLOBAL_TSUNAMI_TRIGGER)->integer(0, 1_999);

        $withAdditionalEarthquakeDraws = new TurnRandomStreamFactory($seed);
        $earthquake = $withAdditionalEarthquakeDraws->stream(TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_EFFECT);
        for ($draw = 0; $draw < 200; $draw++) {
            $earthquake->integer(0, 3);
        }

        $this->assertSame(
            $expected,
            $withAdditionalEarthquakeDraws->stream(TurnRandomStreamFactory::GLOBAL_TSUNAMI_TRIGGER)
                ->integer(0, 1_999),
        );
        $this->assertNotSame(
            TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_TRIGGER,
            TurnRandomStreamFactory::LAND_LEVEL_EARTHQUAKE_TRIGGER,
        );
        $this->assertNotSame(TurnRandomStreamFactory::FIRE, TurnRandomStreamFactory::FACILITY_RIOT);
        $this->assertNotSame(TurnRandomStreamFactory::FIRE, TurnRandomStreamFactory::OIL_DEPLETION);
    }
}
