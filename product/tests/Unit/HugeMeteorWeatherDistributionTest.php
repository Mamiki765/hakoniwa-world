<?php

namespace Tests\Unit;

use App\Domain\Disaster\HugeMeteorWeatherDistribution;
use App\Domain\Disaster\SeaAreaWeatherLottery;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\World\MapBounds;
use Tests\TestCase;

final class HugeMeteorWeatherDistributionTest extends TestCase
{
    public function test_full_partial_and_outside_expectations_preserve_each_old_center(): void
    {
        $bounds = new MapBounds(-16, 63, 0, 63, 16);
        $hugeMeteor = config('hakoniwa.ruleset.turn_processing.disasters.huge_meteor');
        $this->assertSame(2, $hugeMeteor['center_padding']);
        $this->assertSame(2, $hugeMeteor['radius']);
        $distribution = new HugeMeteorWeatherDistribution($bounds, $hugeMeteor['center_padding'], $hugeMeteor['probability']);
        $this->assertSame(20, $bounds->chunkCount());
        $this->assertSame(5712, $distribution->paddedArea);
        $this->assertSame(592, $distribution->outsideArea);
        $expected = $this->value($distribution->expectedCount);
        $inside = $this->value($distribution->internalProbability(256));
        $outside = $this->value($distribution->outsideExpectedCount());
        $this->assertEqualsWithDelta(0.0031870526, $inside, 1e-11);
        $this->assertEqualsWithDelta(0.0073700591, $outside, 1e-10);
        $this->assertEqualsWithDelta($expected, 20 * $inside + $outside, 1e-15);
        $this->assertEqualsWithDelta($expected / 5712, $inside / 256, 1e-15);
        $this->assertEqualsWithDelta($inside / 256, $outside / 592, 1e-15);
        $this->assertEqualsWithDelta($inside / 2, $this->value($distribution->internalProbability(128)), 1e-15);

        // Signed, unaligned edges use real areas and canonical chunk count, not ceil(W/16)*ceil(H/16).
        $partial = new MapBounds(-3, 17, -2, 16, 16);
        $partialDistribution = new HugeMeteorWeatherDistribution($partial, 2, ['numerator' => 1, 'denominator' => 20]);
        $this->assertSame(9, $partial->chunkCount());
        $totalInside = 0.0;
        foreach ([-1, 0, 1] as $y) {
            foreach ([-1, 0, 1] as $x) {
                $area = $partial->cellCountWithinChunk($x, $y);
                $totalInside += $this->value($partialDistribution->internalProbability($area));
            }
        }
        $this->assertEqualsWithDelta($this->value($partialDistribution->expectedCount),
            $totalInside + $this->value($partialDistribution->outsideExpectedCount()), 1e-15);
    }

    public function test_outside_mapping_covers_each_border_coordinate_once_including_corners(): void
    {
        $bounds = new MapBounds(-3, 1, -2, 0, 16);
        $distribution = new HugeMeteorWeatherDistribution($bounds, 2, ['numerator' => 1, 'denominator' => 20]);
        $actual = [];
        for ($index = 0; $index < $distribution->outsideArea; $index++) {
            $center = $distribution->outsideCoordinate($index);
            $this->assertFalse($bounds->contains($center->x, $center->y));
            $actual[] = "{$center->x}:{$center->y}";
        }
        $expected = [];
        for ($y = -4; $y <= 2; $y++) {
            for ($x = -5; $x <= 3; $x++) {
                if (! $bounds->contains($x, $y)) {
                    $expected[] = "{$x}:{$y}";
                }
            }
        }
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
        $this->assertCount(count($actual), array_unique($actual));
    }

    public function test_outside_count_above_one_uses_floor_and_remainder_with_exact_retry(): void
    {
        // These bounds fit supported coordinates; no large World fixture or coordinate list is built.
        $distribution = new HugeMeteorWeatherDistribution(new MapBounds(0, 16383, 0, 16383, 16), 2,
            ['numerator' => 1, 'denominator' => 20]);
        $expected = $distribution->outsideExpectedCount();
        $this->assertGreaterThan(1, $this->value($expected));
        $this->assertLessThan(2, $this->value($expected));
        $seen = [];
        for ($candidate = 0; $candidate < 32; $candidate++) {
            $seed = hash('sha256', "outside-count:{$candidate}");
            $stream = (new TurnRandomStreamFactory($seed))->stream(TurnRandomStreamFactory::weatherHugeMeteorOutside(1));
            $count = $distribution->drawOutsideCount($stream);
            $this->assertContains($count, [1, 2]);
            $seen[$count] = true;
            $center = $distribution->drawOutsideCenter($stream);
            $retry = (new TurnRandomStreamFactory($seed))->stream(TurnRandomStreamFactory::weatherHugeMeteorOutside(1));
            $this->assertSame($count, $distribution->drawOutsideCount($retry));
            $this->assertEquals($center, $distribution->drawOutsideCenter($retry));
        }
        $this->assertCount(2, $seen);
    }

    public function test_terminal_disaster_interval_preserves_exact_probability_without_rounding(): void
    {
        $settings = config('hakoniwa.ruleset.turn_processing.sea_area_weather');
        $lottery = new SeaAreaWeatherLottery;
        $distribution = new HugeMeteorWeatherDistribution(new MapBounds(0, 15, 0, 15, 16), 2,
            ['numerator' => 1, 'denominator' => 20]);
        $probability = $distribution->internalProbability(256);
        $intervals = $lottery->disasterIntervals($settings, $probability);
        // (4/1125)*(256/400) = 64/28125; LCM with the fixed 10000 denominator is 450000.
        $this->assertSame(['denominator' => 450000, 'typhoon' => 6300, 'meteor_shower' => 2475, 'huge_meteor' => 1024], $intervals);
        $this->assertEqualsWithDelta($this->value($probability), $intervals['huge_meteor'] / $intervals['denominator'], 1e-15);
        foreach ([6299 => 'typhoon', 6300 => 'meteor_shower', 8774 => 'meteor_shower',
            8775 => 'huge_meteor', 9798 => 'huge_meteor', 9799 => null] as $draw => $expected) {
            $this->assertSame($expected, $lottery->selectDisaster($intervals, $draw));
        }
    }

    /** @param array{numerator: int, denominator: int} $fraction */
    private function value(array $fraction): float
    {
        return $fraction['numerator'] / $fraction['denominator'];
    }
}
