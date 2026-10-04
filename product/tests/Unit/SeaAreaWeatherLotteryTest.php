<?php

namespace Tests\Unit;

use App\Domain\Disaster\SeaAreaWeatherLottery;
use Tests\TestCase;

final class SeaAreaWeatherLotteryTest extends TestCase
{
    public function test_exclusive_disaster_probability_boundaries_ignore_normal_weight_changes(): void
    {
        $settings = config('hakoniwa.ruleset.turn_processing.sea_area_weather');
        $lottery = new SeaAreaWeatherLottery;
        $terminal = ['numerator' => 1, 'denominator' => 200];
        $intervals = $lottery->disasterIntervals($settings, $terminal);
        $this->assertSame(['denominator' => 10000, 'typhoon' => 140, 'meteor_shower' => 55, 'huge_meteor' => 50], $intervals);
        // Each side of all exclusive intervals catches conditional-rate reduction and overlaps.
        $cases = [0 => 'typhoon', 139 => 'typhoon', 140 => 'meteor_shower', 194 => 'meteor_shower',
            195 => 'huge_meteor', 244 => 'huge_meteor', 245 => null, 9999 => null];
        foreach ($cases as $draw => $expected) {
            $this->assertSame($expected, $lottery->selectDisaster($intervals, $draw));
        }
        $settings['fixed_probabilities'] = array_reverse($settings['fixed_probabilities'], true);
        $settings['normal_weights'] = array_reverse($settings['normal_weights'], true);
        $this->assertSame($intervals, $lottery->disasterIntervals($settings, $terminal));
        $settings['normal_weights'] = ['sunny' => 0, 'cloudy' => 0, 'rain' => 9, 'snow' => 0, 'thunder' => 0];
        $this->assertSame($intervals, $lottery->disasterIntervals($settings, $terminal));
        foreach ($cases as $draw => $expected) {
            $this->assertSame($expected, $lottery->selectDisaster($intervals, $draw));
        }
    }

    public function test_normal_weather_is_selected_only_from_the_final_relative_weights(): void
    {
        $settings = config('hakoniwa.ruleset.turn_processing.sea_area_weather');
        $weights = $settings['normal_weights'];
        $this->assertSame(['sunny' => 45, 'cloudy' => 25, 'rain' => 20, 'snow' => 5, 'thunder' => 3], $weights);
        $lottery = new SeaAreaWeatherLottery;
        $counts = array_fill_keys(array_keys($weights), 0);
        for ($draw = 0; $draw < 98; $draw++) {
            $counts[$lottery->selectNormal($weights, $draw)]++;
        }
        $this->assertSame($weights, $counts);
        foreach ([44 => 'sunny', 45 => 'cloudy', 69 => 'cloudy', 70 => 'rain', 89 => 'rain',
            90 => 'snow', 94 => 'snow', 95 => 'thunder', 97 => 'thunder'] as $draw => $expected) {
            $this->assertSame($expected, $lottery->selectNormal(array_reverse($weights, true), $draw));
        }
    }
}
