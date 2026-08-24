<?php

namespace Tests\Unit;

use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnState;
use PHPUnit\Framework\TestCase;

final class SecretaryTurnStateTest extends TestCase
{
    public function test_same_turn_xp_does_not_mutate_loaded_levels_or_final_defense_budget(): void
    {
        $state = new TurnState;
        $state->setSecretarySnapshot(10, 20, null, 0, $this->skills(0, 1));

        $state->awardSecretaryExperience(10, SecretarySkillCatalog::AGRICULTURAL_POLICY);
        $state->awardSecretaryExperience(10, SecretarySkillCatalog::FINAL_DEFENSE_LINE, 100);
        $state->awardSecretaryMonsterExperience(10, 4);
        $state->awardSecretaryMonsterExperience(10, 6);

        $this->assertSame(0, $state->secretarySkillLevel(10, SecretarySkillCatalog::AGRICULTURAL_POLICY));
        $this->assertSame(1, $state->secretarySkillLevel(10, SecretarySkillCatalog::FINAL_DEFENSE_LINE));
        $this->assertTrue($state->consumeFinalDefenseInterception(10));
        $this->assertFalse($state->consumeFinalDefenseInterception(10));
        $this->assertSame([
            10 => [
                SecretarySkillCatalog::AGRICULTURAL_POLICY => 1,
                SecretarySkillCatalog::FINAL_DEFENSE_LINE => 100,
            ],
        ], $state->pendingSecretaryExperience());
        $this->assertSame(0, $state->secretarySnapshot(10)['monster_experience']);
        $this->assertSame([10 => 10], $state->pendingSecretaryMonsterExperience());

        $nextAttempt = new TurnState;
        $nextAttempt->setSecretarySnapshot(10, 20, null, 0, $this->skills(1, 2));
        $this->assertSame(1, $nextAttempt->secretarySkillLevel(10, SecretarySkillCatalog::AGRICULTURAL_POLICY));
        $this->assertTrue($nextAttempt->consumeFinalDefenseInterception(10));
        $this->assertTrue($nextAttempt->consumeFinalDefenseInterception(10));
        $this->assertFalse($nextAttempt->consumeFinalDefenseInterception(10));
    }

    /** @return array<string, array{level: int, experience: int}> */
    private function skills(int $productionLevel, int $defenseLevel): array
    {
        return [
            SecretarySkillCatalog::AGRICULTURAL_POLICY => ['level' => $productionLevel, 'experience' => 0],
            SecretarySkillCatalog::SPECIALTY_DEVELOPMENT => ['level' => 0, 'experience' => 0],
            SecretarySkillCatalog::GOLD_VEIN_SURVEY => ['level' => 0, 'experience' => 0],
            SecretarySkillCatalog::FINAL_DEFENSE_LINE => ['level' => $defenseLevel, 'experience' => 0],
        ];
    }
}
