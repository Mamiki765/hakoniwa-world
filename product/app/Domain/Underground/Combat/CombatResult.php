<?php

namespace App\Domain\Underground\Combat;

final readonly class CombatResult
{
    /**
     * @param  'player'|'enemy'|'stalemate'  $winner
     * @param  array<string, int>  $skillUsage
     * @param  list<array{round: int, before: int, after: int, gained: int, spent: int, overflow: int}>  $resourceHistory
     * @param  list<string>  $abnormalState
     * @param  list<array<string, int|string|bool|null>>  $actionLog
     */
    public function __construct(
        public string $rulesIdentity,
        public int $seed,
        public string $actorKey,
        public string $enemyKey,
        public string $winner,
        public int $rounds,
        public int $playerRemainingHp,
        public int $enemyRemainingHp,
        public int $damageDealt,
        public int $damageReceived,
        public int $healingDone,
        public array $skillUsage,
        public int $normalAttackUsage,
        public int $defendUsage,
        public int $aiFallbackUsage,
        public int $resourceOverflow,
        public int $finalResource,
        public array $resourceHistory,
        public array $abnormalState,
        public array $actionLog,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rules_identity' => $this->rulesIdentity,
            'seed' => $this->seed,
            'actor_key' => $this->actorKey,
            'enemy_key' => $this->enemyKey,
            'winner' => $this->winner,
            'rounds' => $this->rounds,
            'remaining_hp' => [
                'player' => $this->playerRemainingHp,
                'enemy' => $this->enemyRemainingHp,
            ],
            'damage_dealt' => $this->damageDealt,
            'damage_received' => $this->damageReceived,
            'healing_done' => $this->healingDone,
            'skill_usage' => $this->skillUsage,
            'normal_attack_usage' => $this->normalAttackUsage,
            'defend_usage' => $this->defendUsage,
            'ai_fallback_usage' => $this->aiFallbackUsage,
            'resource_overflow' => $this->resourceOverflow,
            'final_resource' => $this->finalResource,
            'resource_history' => $this->resourceHistory,
            'abnormal_state' => $this->abnormalState,
            'action_log' => $this->actionLog,
        ];
    }
}
