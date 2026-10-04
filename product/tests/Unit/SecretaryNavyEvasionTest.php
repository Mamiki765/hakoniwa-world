<?php

namespace Tests\Unit;

use App\Application\SecretaryNavyEvasionService;
use PHPUnit\Framework\TestCase;

final class SecretaryNavyEvasionTest extends TestCase
{
    public function test_evasion_curve_starts_at_zero_and_approaches_the_limit(): void
    {
        $service = new SecretaryNavyEvasionService;
        $effect = ['maximum_percent' => 15, 'level_offset' => 20];
        $this->assertSame(0.0, $service->chancePercent($effect, 0));
        $this->assertSame(7.5, $service->chancePercent($effect, 20));
        $this->assertSame(12.5, $service->chancePercent($effect, 100));
        $this->assertLessThan(15.0, $service->chancePercent($effect, 1000));
    }
}
