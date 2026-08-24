<?php

namespace Tests\Unit;

use App\Domain\Secretary\SecretaryProductionBonus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretaryProductionBonusTest extends TestCase
{
    #[DataProvider('productionCases')]
    public function test_bonus_uses_exact_integer_flooring(int $base, int $level, int $expected): void
    {
        $ruleset = ['secretary' => ['skills' => ['skill' => ['effect' => [
            'per_mille_per_level' => 1,
        ]]]]];

        $service = new SecretaryProductionBonus;
        $this->assertSame($expected, $service->apply($ruleset, 'skill', $level, $base));
        $forestRuleset = ['secretary' => ['skills' => ['forest_management' => ['effect' => [
            'type' => 'forest_management',
            'percent_per_level' => 1,
            'rounding' => 'floor_after_multiplier',
        ]]]]];
        $this->assertSame(
            intdiv($base * (100 + $level), 100),
            $service->applyForestManagement($forestRuleset, $level, $base),
        );
    }

    public static function productionCases(): array
    {
        return [
            'level zero' => [99_999, 0, 99_999],
            'fraction floors' => [999, 1, 999],
            'one per mille exact' => [1_000, 1, 1_001],
            'one percent' => [12_345, 10, 12_468],
        ];
    }
}
