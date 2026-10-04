<?php

namespace App\Domain\Disaster;

use App\Domain\Map\GridCoordinate;
use App\Domain\Turn\DeterministicRandomStream;
use App\Domain\World\MapBounds;
use DomainException;

final readonly class HugeMeteorWeatherDistribution
{
    public int $paddedWidth;

    public int $paddedArea;

    public int $outsideArea;

    /** @var array{numerator: int, denominator: int} */
    public array $expectedCount;

    /** @param array{numerator: int, denominator: int} $legacyProbability */
    public function __construct(public MapBounds $bounds, public int $padding, array $legacyProbability)
    {
        $this->paddedWidth = $bounds->width() + 2 * $padding;
        $this->paddedArea = $this->paddedWidth * ($bounds->height() + 2 * $padding);
        $this->outsideArea = $this->paddedArea - $bounds->cellCount();
        // The old World opportunity expectation is 16*C/225; no opportunity is drawn here.
        $this->expectedCount = self::multiply(
            ['numerator' => 16 * $bounds->chunkCount(), 'denominator' => 225], $legacyProbability,
        );
    }

    /** @return array{numerator: int, denominator: int} */
    public function internalProbability(int $area): array
    {
        return self::multiply($this->expectedCount, ['numerator' => $area, 'denominator' => $this->paddedArea]);
    }

    /** @return array{numerator: int, denominator: int} */
    public function outsideExpectedCount(): array
    {
        return self::multiply($this->expectedCount, ['numerator' => $this->outsideArea, 'denominator' => $this->paddedArea]);
    }

    public function drawOutsideCount(DeterministicRandomStream $stream): int
    {
        $expected = $this->outsideExpectedCount();
        $full = intdiv($expected['numerator'], $expected['denominator']);
        $remainder = ['numerator' => $expected['numerator'] % $expected['denominator'], 'denominator' => $expected['denominator']];

        return $full + (self::drawFraction($remainder, $stream) ? 1 : 0);
    }

    public function drawOutsideCenter(DeterministicRandomStream $stream): GridCoordinate
    {
        return $this->outsideCoordinate(self::uniformIndex($this->outsideArea, $stream));
    }

    public function outsideCoordinate(int $index): GridCoordinate
    {
        if ($index < 0 || $index >= $this->outsideArea) {
            throw new DomainException('Outside huge meteor coordinate index is invalid.');
        }
        // Top/bottom own the corners. Left/right cover only the World's y range.
        $horizontalArea = $this->paddedWidth * $this->padding;
        if ($index < 2 * $horizontalArea) {
            $bottom = $index >= $horizontalArea;
            $offset = $index % $horizontalArea;

            return new GridCoordinate(
                $this->bounds->minX - $this->padding + $offset % $this->paddedWidth,
                ($bottom ? $this->bounds->maxY + 1 : $this->bounds->minY - $this->padding) + intdiv($offset, $this->paddedWidth),
            );
        }
        $index -= 2 * $horizontalArea;
        $verticalArea = $this->bounds->height() * $this->padding;
        $right = $index >= $verticalArea;
        $offset = $index % $verticalArea;

        return new GridCoordinate(
            ($right ? $this->bounds->maxX + 1 : $this->bounds->minX - $this->padding) + $offset % $this->padding,
            $this->bounds->minY + intdiv($offset, $this->padding),
        );
    }

    /** @param array{numerator: int, denominator: int} $fraction */
    private static function drawFraction(array $fraction, DeterministicRandomStream $stream): bool
    {
        return $fraction['numerator'] > 0
            && self::uniformIndex($fraction['denominator'], $stream) < $fraction['numerator'];
    }

    public static function uniformIndex(int $count, DeterministicRandomStream $stream): int
    {
        $maximum = $count - 1;
        if ($maximum <= DeterministicRandomStream::MAXIMUM_INTEGER) {
            return $stream->integer(0, $maximum);
        }
        // Exact rejection sampling also covers expanded Worlds whose rational denominator exceeds 32 bits.
        $base = 1_073_741_824;
        do {
            $index = $stream->integer(0, intdiv($maximum, $base)) * $base + $stream->integer(0, $base - 1);
        } while ($index > $maximum);

        return $index;
    }

    /**
     * @param  array{numerator: int, denominator: int}  $left
     * @param  array{numerator: int, denominator: int}  $right
     * @return array{numerator: int, denominator: int}
     */
    private static function multiply(array $left, array $right): array
    {
        $crossLeft = self::gcd($left['numerator'], $right['denominator']);
        $crossRight = self::gcd($right['numerator'], $left['denominator']);
        $numerator = intdiv($left['numerator'], $crossLeft) * intdiv($right['numerator'], $crossRight);
        $denominator = intdiv($left['denominator'], $crossRight) * intdiv($right['denominator'], $crossLeft);
        $common = self::gcd($numerator, $denominator);

        return ['numerator' => intdiv($numerator, $common), 'denominator' => intdiv($denominator, $common)];
    }

    private static function gcd(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left;
    }
}
