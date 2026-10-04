<?php

namespace Tests\Unit;

use App\Domain\Disaster\SeaAreaWeatherLottery;
use Tests\TestCase;

final class SeaAreaWeatherLotteryTest extends TestCase
{
    public function test_absolute_probability_boundaries_and_normal_weight_changes(): void
    {
        $settings = config('hakoniwa.ruleset.turn_processing.sea_area_weather');
        $lottery = new SeaAreaWeatherLottery;
        // Each side of every interval: catches gaps, overlaps and accidental renormalization.
        $cases = [0 => 'typhoon', 139 => 'typhoon', 140 => 'meteor_shower', 194 => 'meteor_shower',
            195 => 'sunny', 4694 => 'sunny', 4695 => 'cloudy', 7194 => 'cloudy',
            7195 => 'rain', 9194 => 'rain', 9195 => 'snow', 9694 => 'snow', 9695 => 'thunder', 9999 => 'thunder'];
        foreach ($cases as $draw => $expected) {
            $this->assertSame($expected, $lottery->select($settings, $draw));
        }
        $settings['fixed_probabilities'] = array_reverse($settings['fixed_probabilities'], true);
        $settings['normal_weights'] = array_reverse($settings['normal_weights'], true);
        $this->assertSame('typhoon', $lottery->select($settings, 139));
        $this->assertSame('rain', $lottery->select($settings, 7195));
        $settings['normal_weights'] = ['sunny' => 0, 'cloudy' => 0, 'rain' => 9, 'snow' => 0, 'thunder' => 0];
        foreach ([139 => 'typhoon', 140 => 'meteor_shower', 194 => 'meteor_shower', 195 => 'rain', 9999 => 'rain'] as $draw => $expected) {
            $this->assertSame($expected, $lottery->select($settings, $draw));
        }
    }
}
