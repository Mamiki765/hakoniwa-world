<?php

namespace Tests\Unit;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Domain\Turn\DeterministicRandomStream;
use App\Domain\Turn\TurnRandomStreamFactory;
use DomainException;
use Tests\TestCase;

class DisasterRulesetContractTest extends TestCase
{
    public function test_production_ruleset_publishes_exact_public_and_internal_disaster_rates(): void
    {
        $settings = config('hakoniwa.published_rulesets')['hakoniwa-2s-plus-v1'];
        $validated = app(RulesetAuthoringValidator::class)->validate($settings);
        $disasters = $settings['turn_processing']['disasters'];

        $this->assertSame('hakoniwa-2s-plus-v1', $validated['key']);
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

    public function test_meteor_shower_continuation_must_allow_termination(): void
    {
        $published = config('hakoniwa.published_rulesets');
        $this->assertIsArray($published);
        $settings = $published['hakoniwa-2s-plus-v1'];
        $settings['turn_processing']['disasters']['meteor_shower']['continuation_probability'] = [
            'numerator' => 1,
            'denominator' => 1,
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('must allow the meteor shower to terminate');
        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_disaster_radii_match_the_implemented_bounded_damage_contract(): void
    {
        $published = config('hakoniwa.published_rulesets');
        $this->assertIsArray($published);

        foreach ([
            ['earthquake', 11, 10],
            ['tsunami', 11, 10],
            ['typhoon', 11, 10],
            ['meteor_shower', 11, 10],
            ['huge_meteor', 3, 2],
            ['eruption', 2, 1],
        ] as [$key, $authoredRadius, $expectedRadius]) {
            $settings = $published['hakoniwa-2s-plus-v1'];
            $settings['turn_processing']['disasters'][$key]['radius'] = $authoredRadius;

            try {
                app(RulesetAuthoringValidator::class)->validate($settings);
                $this->fail("{$key} accepted a radius that the implementation does not honor.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString(
                    "radius must be {$expectedRadius} for the implemented damage contract",
                    $exception->getMessage(),
                );
            }
        }

        $settings = $published['hakoniwa-2s-plus-v1'];
        $settings['turn_processing']['command_random_effects']['land_level_earthquake']['radius'] = 11;
        try {
            app(RulesetAuthoringValidator::class)->validate($settings);
            $this->fail('land_level earthquake accepted a radius outside the implemented contract.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'land_level_earthquake.radius must be 10 for the implemented damage contract',
                $exception->getMessage(),
            );
        }
    }

    public function test_internal_disaster_denominators_fit_the_deterministic_stream_range(): void
    {
        $published = config('hakoniwa.published_rulesets');
        $this->assertIsArray($published);
        $maximum = 2_147_483_648;

        $boundary = $published['hakoniwa-2s-plus-v1'];
        $boundary['turn_processing']['disasters']['tsunami']['internal_denominator'] = $maximum;
        $boundary['turn_processing']['disasters']['typhoon']['internal_denominator'] = $maximum;
        $this->assertSame('hakoniwa-2s-plus-v1', app(RulesetAuthoringValidator::class)->validate($boundary)['key']);

        foreach (['tsunami', 'typhoon'] as $key) {
            $settings = $published['hakoniwa-2s-plus-v1'];
            $settings['turn_processing']['disasters'][$key]['internal_denominator'] = $maximum + 1;

            try {
                app(RulesetAuthoringValidator::class)->validate($settings);
                $this->fail("{$key} accepted an internal denominator outside the deterministic stream range.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString(
                    "{$key}.internal_denominator must be at most {$maximum}",
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_disaster_center_padding_keeps_initial_draw_bounds_in_the_stream_range(): void
    {
        $published = config('hakoniwa.published_rulesets');
        $this->assertIsArray($published);
        $maximum = DeterministicRandomStream::MAXIMUM_INTEGER - 59;

        $boundary = $published['hakoniwa-2s-plus-v1'];
        foreach (['earthquake', 'tsunami', 'typhoon', 'meteor_shower', 'huge_meteor', 'eruption'] as $key) {
            $boundary['turn_processing']['disasters'][$key]['center_padding'] = $maximum;
        }
        $this->assertSame('hakoniwa-2s-plus-v1', app(RulesetAuthoringValidator::class)->validate($boundary)['key']);

        foreach (['earthquake', 'tsunami', 'typhoon', 'meteor_shower', 'huge_meteor', 'eruption'] as $key) {
            $settings = $published['hakoniwa-2s-plus-v1'];
            $settings['turn_processing']['disasters'][$key]['center_padding'] = $maximum + 1;

            try {
                app(RulesetAuthoringValidator::class)->validate($settings);
                $this->fail("{$key} accepted center padding outside the deterministic stream range.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString(
                    "{$key}.center_padding must be at most {$maximum}",
                    $exception->getMessage(),
                );
            }
        }
    }
}
