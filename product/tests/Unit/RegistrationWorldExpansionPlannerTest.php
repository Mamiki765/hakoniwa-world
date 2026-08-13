<?php

namespace Tests\Unit;

use App\Domain\World\MapBounds;
use App\Domain\World\RegistrationWorldExpansionPlanner;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistrationWorldExpansionPlannerTest extends TestCase
{
    #[DataProvider('canonicalSteps')]
    public function test_next_bounds_are_derived_from_the_canonical_rotation(
        array $before,
        array $after,
    ): void {
        $actual = (new RegistrationWorldExpansionPlanner)->nextBounds(new MapBounds(
            $before[0],
            $before[1],
            $before[2],
            $before[3],
            16,
        ));

        self::assertSame($after, [$actual->minX, $actual->maxX, $actual->minY, $actual->maxY]);
    }

    public static function canonicalSteps(): array
    {
        return [
            '60 completes partial chunks and adds LEFT' => [[0, 59, 0, 59], [-16, 63, 0, 63]],
            '64 adds LEFT' => [[0, 63, 0, 63], [-16, 63, 0, 63]],
            'then UP' => [[-16, 63, 0, 63], [-16, 63, -16, 63]],
            'then RIGHT' => [[-16, 63, -16, 63], [-16, 79, -16, 63]],
            'then DOWN' => [[-16, 79, -16, 63], [-16, 79, -16, 79]],
            'next cycle LEFT' => [[-16, 79, -16, 79], [-32, 79, -16, 79]],
        ];
    }

    public function test_noncanonical_bounds_fail_closed(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('canonical LEFT/UP/RIGHT/DOWN rotation');

        (new RegistrationWorldExpansionPlanner)->nextBounds(new MapBounds(-16, 79, 0, 63, 16));
    }
}
