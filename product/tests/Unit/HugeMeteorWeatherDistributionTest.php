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
        $distribution = new HugeMeteorWeatherDistribution($bounds, 2, ['numerator' => 1, 'denominator' => 20]);
        $this->assertSame(20, $bounds->chunkCount());
        $this->assertSame(5712, $distribution->paddedArea);
        $this->assertSame(592, $distribution->outsideArea);
        $expected = $this->value($distribution->expectedCount);
        $inside = $this->value($distribution->internalProbability(256));
        $outside = $this->value($distribution->outsideExpectedCount());
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
        // A synthetic legacy probability puts the outside expectation at 1 + 0.4976 on one chunk.
        $distribution = new HugeMeteorWeatherDistribution(new MapBounds(0, 15, 0, 15, 16), 2,
            ['numerator' => 117, 'denominator' => 2]);
        $expected = $distribution->outsideExpectedCount();
        $this->assertSame(1, intdiv($expected['numerator'], $expected['denominator']));
        $this->assertNotSame(0, $expected['numerator'] % $expected['denominator']);
        $seen = [];
        for ($candidate = 0; $candidate < 8; $candidate++) {
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
        $distribution = new HugeMeteorWeatherDistribution(new MapBounds(0, 15, 0, 15, 16), 2,
            ['numerator' => 1, 'denominator' => 20]);
        $probability = $distribution->internalProbability(256);
        $intervals = (new SeaAreaWeatherLottery)->disasterIntervals($settings, $probability);

        // The denominator widens to keep the rational huge meteor probability exact.
        $this->assertSame(0, $intervals['denominator'] % $probability['denominator']);
        $this->assertSame(
            $probability['numerator'] * $intervals['denominator'],
            $intervals['huge_meteor'] * $probability['denominator'],
        );
    }

    /** @param array{numerator: int, denominator: int} $fraction */
    private function value(array $fraction): float
    {
        return $fraction['numerator'] / $fraction['denominator'];
    }
}
