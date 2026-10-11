<?php

namespace Tests\Unit;

use App\Domain\Disaster\SeaAreaWeatherLottery;
use Tests\TestCase;

final class SeaAreaWeatherLotteryTest extends TestCase
{
    public function test_exclusive_disaster_probability_boundaries_ignore_normal_weight_changes(): void
    {
        $settings = [
            'denominator' => 100,
            'fixed_probabilities' => ['typhoon' => 3, 'meteor_shower' => 2],
            'normal_weights' => ['sunny' => 4, 'cloudy' => 3, 'rain' => 2, 'snow' => 1, 'thunder' => 1],
        ];
        $lottery = new SeaAreaWeatherLottery;
        $terminal = ['numerator' => 1, 'denominator' => 40];
        $intervals = $lottery->disasterIntervals($settings, $terminal);
        $this->assertSame(['denominator' => 200, 'typhoon' => 6, 'meteor_shower' => 4, 'huge_meteor' => 5], $intervals);
        // Each side of all exclusive intervals catches conditional-rate reduction and overlaps.
        $cases = [0 => 'typhoon', 5 => 'typhoon', 6 => 'meteor_shower', 9 => 'meteor_shower',
            10 => 'huge_meteor', 14 => 'huge_meteor', 15 => null, 199 => null];
        foreach ($cases as $draw => $expected) {
            $this->assertSame($expected, $lottery->selectDisaster($intervals, $draw));
        }
        $settings['fixed_probabilities'] = array_reverse($settings['fixed_probabilities'], true);
        $settings['normal_weights'] = array_reverse($settings['normal_weights'], true);
        $this->assertSame($intervals, $lottery->disasterIntervals($settings, $terminal));
        $settings['normal_weights'] = ['sunny' => 0, 'cloudy' => 0, 'rain' => 9, 'snow' => 0, 'thunder' => 0];
        $this->assertSame($intervals, $lottery->disasterIntervals($settings, $terminal));
    }

    public function test_normal_weather_is_selected_only_from_the_final_relative_weights(): void
    {
        $weights = ['sunny' => 3, 'cloudy' => 2, 'rain' => 1, 'snow' => 1, 'thunder' => 2];
        $lottery = new SeaAreaWeatherLottery;
        $counts = array_fill_keys(array_keys($weights), 0);
        for ($draw = 0; $draw < array_sum($weights); $draw++) {
            $counts[$lottery->selectNormal($weights, $draw)]++;
        }
        $this->assertSame($weights, $counts);
        foreach ([2 => 'sunny', 3 => 'cloudy', 4 => 'cloudy', 5 => 'rain', 6 => 'snow',
            7 => 'thunder', 8 => 'thunder'] as $draw => $expected) {
            $this->assertSame($expected, $lottery->selectNormal(array_reverse($weights, true), $draw));
        }
    }
}
