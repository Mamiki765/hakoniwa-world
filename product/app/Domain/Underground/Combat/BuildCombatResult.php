<?php

namespace App\Domain\Underground\Combat;

final readonly class BuildCombatResult
{
    /**
     * @param  'player'|'enemy'|'stalemate'  $winner
     * @param  array<string, int>  $actionUsage
     * @param  array<string, int>  $statusUptime
     * @param  array{fighting_spirit: int, grace: int}  $finalRoleStacks
     * @param  list<array<string, int>>  $mpHistory
     * @param  list<string>  $abnormalState
     * @param  list<array<string, mixed>>  $actionLog
     * @param  list<array<string, mixed>>  $generatedEquipment
     */
    public function __construct(
        public string $rulesIdentity,
        public string $generatorIdentity,
        public int $seed,
        public string $buildKey,
        public string $enemyKey,
        public string $tierKey,
        public string $winner,
        public int $rounds,
        public int $playerRemainingHp,
        public int $enemyRemainingHp,
        public int $damageDealt,
        public int $damageReceived,
        public int $effectiveHealing,
        public int $damagePrevented,
        public int $mpSpent,
        public int $mpNaturalRecovery,
        public int $mpSkillRecovery,
        public int $mpOverflow,
        public ?int $mpExhaustionRound,
        public int $skillUnavailableDueToMp,
        public int $finalMp,
        public array $actionUsage,
        public array $statusUptime,
        public array $finalRoleStacks,
        public array $mpHistory,
        public array $abnormalState,
        public array $actionLog,
        public array $generatedEquipment,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rules_identity' => $this->rulesIdentity,
            'generator_identity' => $this->generatorIdentity,
            'seed' => $this->seed,
            'build_key' => $this->buildKey,
            'enemy_key' => $this->enemyKey,
            'tier_key' => $this->tierKey,
            'winner' => $this->winner,
            'rounds' => $this->rounds,
            'remaining_hp' => ['player' => $this->playerRemainingHp, 'enemy' => $this->enemyRemainingHp],
            'damage_dealt' => $this->damageDealt,
            'damage_received' => $this->damageReceived,
            'effective_healing' => $this->effectiveHealing,
            'damage_prevented' => $this->damagePrevented,
            'mp' => [
                'spent' => $this->mpSpent,
                'natural_recovery' => $this->mpNaturalRecovery,
                'skill_recovery' => $this->mpSkillRecovery,
                'overflow' => $this->mpOverflow,
                'exhaustion_round' => $this->mpExhaustionRound,
                'skill_unavailable' => $this->skillUnavailableDueToMp,
                'final' => $this->finalMp,
                'history' => $this->mpHistory,
            ],
            'action_usage' => $this->actionUsage,
            'status_uptime' => $this->statusUptime,
            'final_role_stacks' => $this->finalRoleStacks,
            'abnormal_state' => $this->abnormalState,
            'action_log' => $this->actionLog,
            'generated_equipment' => $this->generatedEquipment,
        ];
    }
}
