<?php

namespace Tests\Unit;

use App\Domain\Secretary\SecretarySkillProgression;
use PHPUnit\Framework\TestCase;

final class SecretarySkillProgressionTest extends TestCase
{
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
