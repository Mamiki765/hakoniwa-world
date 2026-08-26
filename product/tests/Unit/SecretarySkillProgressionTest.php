<?php

namespace Tests\Unit;

use App\Domain\Secretary\SecretarySkillProgression;
use PHPUnit\Framework\TestCase;

final class SecretarySkillProgressionTest extends TestCase
{
    public function test_triangular_growth_supports_cumulative_and_consuming_accounting_without_changing_existing_bases(): void
    {
        $progression = new SecretarySkillProgression;
        $cumulative = ['level_requirement' => [
            'basis' => 'triangular_growth',
            'multiplier' => 10_000,
            'accounting' => 'cumulative_non_consuming',
        ]];
        foreach ([
            9_999 => [0, 9_999],
            10_000 => [1, 10_000],
            19_999 => [1, 19_999],
            20_000 => [2, 20_000],
            40_000 => [3, 40_000],
            70_000 => [4, 70_000],
            160_000 => [6, 160_000],
            560_000 => [11, 560_000],
        ] as $experience => [$level, $retained]) {
            $result = $progression->advance($cumulative, 0, 0, $experience);
            $this->assertSame($level, $result['level']);
            $this->assertSame($retained, $result['experience']);
        }

        $consuming = ['level_requirement' => [
            'basis' => 'triangular_growth',
            'multiplier' => 10_000,
            'accounting' => 'consume_required_carry_remainder',
        ]];
        foreach ([
            9_999 => [0, 9_999],
            10_000 => [1, 0],
            12_000 => [1, 2_000],
            35_000 => [2, 5_000],
            70_000 => [3, 0],
            140_000 => [4, 0],
        ] as $experience => [$level, $remainder]) {
            $result = $progression->advance($consuming, 0, 0, $experience);
            $this->assertSame($level, $result['level']);
            $this->assertSame($remainder, $result['experience']);
        }

        $this->assertSame(10_000, $progression->requiredExperience($consuming, 0));
        $this->assertSame(20_000, $progression->requiredExperience($consuming, 1));
        $this->assertSame(40_000, $progression->requiredExperience($consuming, 2));
        $this->assertSame(70_000, $progression->requiredExperience($consuming, 3));
    }

    public function test_development_thresholds_consume_xp_and_carry_overflow_across_multiple_levels(): void
    {
        $definition = ['level_requirement' => [
            'basis' => 'next_level_squared',
            'multiplier' => 1,
        ]];
        $progression = new SecretarySkillProgression;

        $this->assertSame(1, $progression->requiredExperience($definition, 0));
        $this->assertSame(4, $progression->requiredExperience($definition, 1));
        $this->assertSame(9, $progression->requiredExperience($definition, 2));
        $this->assertSame([
            'level' => 3,
            'experience' => 1,
            'levels_gained' => 3,
        ], $progression->advance($definition, 0, 0, 15));
    }

    public function test_final_defense_uses_current_level_squared_times_one_hundred(): void
    {
        $definition = ['level_requirement' => [
            'basis' => 'current_level_squared',
            'multiplier' => 100,
        ]];
        $progression = new SecretarySkillProgression;

        $this->assertSame(100, $progression->requiredExperience($definition, 1));
        $this->assertSame(400, $progression->requiredExperience($definition, 2));
        $this->assertSame([
            'level' => 3,
            'experience' => 0,
            'levels_gained' => 2,
        ], $progression->advance($definition, 1, 0, 500));
    }
}
