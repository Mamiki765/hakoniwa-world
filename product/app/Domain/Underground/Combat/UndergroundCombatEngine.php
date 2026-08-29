<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;

final readonly class UndergroundCombatEngine
{
    public function __construct(
        private UndergroundCombatRules $rules,
        private BuiltInCombatAi $ai,
    ) {}

    /** @param list<string> $skillKeys */
    public function fight(
        string $actorKey,
        array $skillKeys,
        string $enemyKey,
        string $aiPreset,
        int $seed,
        int $maxRounds,
    ): CombatResult {
        if ($aiPreset !== UndergroundCombatRules::AI_PRESET) {
            throw new InvalidArgumentException("Unsupported Underground AI preset [{$aiPreset}].");
        }
        $this->rules->assertLoadout($skillKeys);
        $this->rules->assertMaxRounds($maxRounds);
        $actorDefinition = $this->rules->actor($actorKey);
        foreach ($skillKeys as $skillKey) {
            if (! in_array($skillKey, $actorDefinition['available_skills'], true)) {
                throw new InvalidArgumentException("Actor [{$actorKey}] cannot equip [{$skillKey}].");
            }
        }
        $enemyDefinition = $this->rules->enemy($enemyKey);

        return $this->fightSnapshots(
            $actorDefinition,
            $skillKeys,
            $enemyDefinition,
            $aiPreset,
            $seed,
            $maxRounds,
        );
    }

    /**
     * Execute the canonical combat path with one immutable, context-authored actor/enemy input.
     *
     * @param  array<string, mixed>  $actorDefinition
     * @param  list<string>  $skillKeys
     * @param  array<string, mixed>  $enemyDefinition
     */
    public function fightSnapshots(
        array $actorDefinition,
        array $skillKeys,
        array $enemyDefinition,
        string $aiPreset,
        int $seed,
        int $maxRounds,
    ): CombatResult {
        if ($aiPreset !== UndergroundCombatRules::AI_PRESET) {
            throw new InvalidArgumentException("Unsupported Underground AI preset [{$aiPreset}].");
        }
        $this->rules->assertActorSnapshot($actorDefinition);
        $this->rules->assertEnemySnapshot($enemyDefinition);
        $this->rules->assertLoadout($skillKeys);
        $this->rules->assertMaxRounds($maxRounds);
        foreach ($skillKeys as $skillKey) {
            if (! in_array($skillKey, $actorDefinition['available_skills'], true)) {
                throw new InvalidArgumentException(
                    "Actor [{$actorDefinition['key']}] cannot equip [{$skillKey}].",
                );
            }
        }

        $actor = new CombatantState(
            'player',
            $actorDefinition['key'],
            $actorDefinition['label'],
            $actorDefinition['max_hp'],
            $actorDefinition['attack'],
            $actorDefinition['defense'],
            $actorDefinition['speed'],
            $skillKeys,
            'built_in',
        );
        $enemy = new CombatantState(
            'enemy',
            $enemyDefinition['key'],
            $enemyDefinition['label'],
            $enemyDefinition['max_hp'],
            $enemyDefinition['attack'],
            $enemyDefinition['defense'],
            $enemyDefinition['speed'],
            [],
            $enemyDefinition['behavior'],
        );
        $random = new UndergroundRandom($seed);
        $metrics = [
            'damage_dealt' => 0,
            'damage_received' => 0,
            'healing_done' => 0,
            'normal_attack_usage' => 0,
            'defend_usage' => 0,
            'ai_fallback_usage' => 0,
            'resource_overflow' => 0,
        ];
        $skillUsage = array_fill_keys($skillKeys, 0);
        $resourceHistory = [];
        $actionLog = [];
        $completedRounds = 0;

        for ($round = 1; $round <= $maxRounds && $actor->alive() && $enemy->alive(); $round++) {
            $completedRounds = $round;
            $actor->tickCooldowns();
            $enemy->tickCooldowns();
            $turnOrder = $this->turnOrder($actor, $enemy, $random, $round);

            foreach ($turnOrder as $actingSide) {
                if (! $actor->alive() || ! $enemy->alive()) {
                    break;
                }

                if ($actingSide === 'player') {
                    $action = $this->ai->playerAction($actor, $enemy);
                    $this->executePlayerAction(
                        $actor,
                        $enemy,
                        $action,
                        $random,
                        $round,
                        $metrics,
                        $skillUsage,
                        $resourceHistory,
                        $actionLog,
                    );
                } else {
                    $action = $this->ai->enemyAction($enemy, $round);
                    $this->executeEnemyAction($enemy, $actor, $action, $random, $round, $metrics, $actionLog);
                }
            }
        }

        $abnormal = $this->abnormalState($actor, $enemy);
        $winner = ! $actor->alive() ? 'enemy' : (! $enemy->alive() ? 'player' : 'stalemate');

        return new CombatResult(
            UndergroundCombatRules::IDENTITY,
            $seed,
            $actorDefinition['key'],
            $enemyDefinition['key'],
            $winner,
            $completedRounds,
            $actor->hp,
            $enemy->hp,
            $metrics['damage_dealt'],
            $metrics['damage_received'],
            $metrics['healing_done'],
            $skillUsage,
            $metrics['normal_attack_usage'],
            $metrics['defend_usage'],
            $metrics['ai_fallback_usage'],
            $metrics['resource_overflow'],
            $actor->resource,
            $resourceHistory,
            $abnormal,
            $actionLog,
        );
    }

    /** @return list<'player'|'enemy'> */
    private function turnOrder(
        CombatantState $actor,
        CombatantState $enemy,
        UndergroundRandom $random,
        int $round,
    ): array {
        if ($actor->speed > $enemy->speed) {
            return ['player', 'enemy'];
        }
        if ($enemy->speed > $actor->speed) {
            return ['enemy', 'player'];
        }

        return $random->integer("initiative:round:{$round}", 0, 1) === 0
            ? ['player', 'enemy']
            : ['enemy', 'player'];
    }

    /**
     * @param  array{type: string, key: string|null, reason: string, fallback: bool}  $action
     * @param  array<string, int>  $metrics
     * @param  array<string, int>  $skillUsage
     * @param  list<array{round: int, before: int, after: int, gained: int, spent: int, overflow: int}>  $resourceHistory
     * @param  list<array<string, int|string|bool|null>>  $actionLog
     */
    private function executePlayerAction(
        CombatantState $actor,
        CombatantState $enemy,
        array $action,
        UndergroundRandom $random,
        int $round,
        array &$metrics,
        array &$skillUsage,
        array &$resourceHistory,
        array &$actionLog,
    ): void {
        if ($action['fallback']) {
            $metrics['ai_fallback_usage']++;
        }

        if ($action['type'] === 'defend') {
            $actor->guarding = true;
            $metrics['defend_usage']++;
            $this->changeResource(
                $actor,
                UndergroundCombatRules::DEFEND_RESOURCE_GAIN,
                0,
                $round,
                $metrics,
                $resourceHistory,
            );
            $actionLog[] = $this->logRow($round, 'player', 'defend', $action['reason'], 0, $actor, $enemy);

            return;
        }

        if ($action['type'] === 'normal_attack') {
            $guarded = $enemy->guarding;
            $damage = $this->damage(
                $actor,
                $enemy,
                UndergroundCombatRules::NORMAL_ATTACK_POWER,
                0,
                $random,
                'player:normal_attack',
            );
            $metrics['damage_dealt'] += $damage;
            $metrics['normal_attack_usage']++;
            $this->changeResource(
                $actor,
                UndergroundCombatRules::NORMAL_ATTACK_RESOURCE_GAIN,
                0,
                $round,
                $metrics,
                $resourceHistory,
            );
            $actionLog[] = $this->logRow(
                $round,
                'player',
                'normal_attack',
                $action['reason'],
                $damage,
                $actor,
                $enemy,
                $guarded,
            );

            return;
        }

        $skillKey = $action['key'];
        if (! is_string($skillKey) || ! $actor->skillReady($skillKey)) {
            throw new InvalidArgumentException('Built-in Underground AI selected an unavailable skill.');
        }
        $skill = $this->rules->skill($skillKey);
        if ($actor->resource < $skill['resource_cost']) {
            throw new InvalidArgumentException('Built-in Underground AI selected a skill without enough resource.');
        }
        $skillUsage[$skillKey]++;
        $actor->cooldowns[$skillKey] = $skill['cooldown'];

        if ($skill['kind'] === 'heal') {
            $missing = $actor->maxHp - $actor->hp;
            $healing = min($missing, max(1, intdiv($actor->attack * $skill['power'], 100)));
            $actor->hp += $healing;
            $metrics['healing_done'] += $healing;
            $this->changeResource(
                $actor,
                $skill['resource_gain'],
                $skill['resource_cost'],
                $round,
                $metrics,
                $resourceHistory,
            );
            $actionLog[] = $this->logRow($round, 'player', $skillKey, $action['reason'], -$healing, $actor, $enemy);

            return;
        }

        $this->changeResource(
            $actor,
            $skill['resource_gain'],
            $skill['resource_cost'],
            $round,
            $metrics,
            $resourceHistory,
        );
        $guarded = $enemy->guarding;
        $damage = $this->damage(
            $actor,
            $enemy,
            $skill['power'],
            $skill['defense_ignore_percent'],
            $random,
            'player:skill:'.$skillKey,
        );
        $metrics['damage_dealt'] += $damage;
        $actionLog[] = $this->logRow(
            $round,
            'player',
            $skillKey,
            $action['reason'],
            $damage,
            $actor,
            $enemy,
            $guarded,
        );
    }

    /**
     * @param  array{type: string, key: null, reason: string, fallback: bool}  $action
     * @param  array<string, int>  $metrics
     * @param  list<array<string, int|string|bool|null>>  $actionLog
     */
    private function executeEnemyAction(
        CombatantState $enemy,
        CombatantState $actor,
        array $action,
        UndergroundRandom $random,
        int $round,
        array &$metrics,
        array &$actionLog,
    ): void {
        if ($action['type'] === 'defend') {
            $enemy->guarding = true;
            $actionLog[] = $this->logRow($round, 'enemy', 'defend', $action['reason'], 0, $actor, $enemy);

            return;
        }
        if ($action['type'] === 'enemy_telegraph') {
            $enemy->telegraphing = true;
            $actionLog[] = $this->logRow($round, 'enemy', 'telegraph', $action['reason'], 0, $actor, $enemy);

            return;
        }

        $power = match ($action['type']) {
            'enemy_fast_strike' => 122,
            'enemy_heavy_strike' => 205,
            default => UndergroundCombatRules::NORMAL_ATTACK_POWER,
        };
        if ($action['type'] === 'enemy_heavy_strike') {
            $enemy->telegraphing = false;
        }
        $guarded = $actor->guarding;
        $damage = $this->damage($enemy, $actor, $power, 0, $random, 'enemy:'.$action['type']);
        $metrics['damage_received'] += $damage;
        $actionLog[] = $this->logRow(
            $round,
            'enemy',
            $action['type'],
            $action['reason'],
            $damage,
            $actor,
            $enemy,
            $guarded,
        );
    }

    private function damage(
        CombatantState $attacker,
        CombatantState $defender,
        int $power,
        int $defenseIgnorePercent,
        UndergroundRandom $random,
        string $stream,
    ): int {
        $guarding = $defender->guarding;
        $damage = $this->rules->damage(
            $attacker->attack,
            $defender->defense,
            $defender->hp,
            $power,
            $defenseIgnorePercent,
            $random,
            $stream,
            $guarding,
        );
        if ($guarding) {
            $defender->guarding = false;
        }
        $defender->hp -= $damage;

        return $damage;
    }

    /**
     * @param  array<string, int>  $metrics
     * @param  list<array{round: int, before: int, after: int, gained: int, spent: int, overflow: int}>  $history
     */
    private function changeResource(
        CombatantState $actor,
        int $gain,
        int $cost,
        int $round,
        array &$metrics,
        array &$history,
    ): void {
        $before = $actor->resource;
        $afterSpend = $before - $cost;
        if ($afterSpend < 0) {
            throw new InvalidArgumentException('Underground combat resource cost exceeded the current value.');
        }
        $overflow = max(0, $afterSpend + $gain - UndergroundCombatRules::RESOURCE_CAP);
        $actor->resource = min(UndergroundCombatRules::RESOURCE_CAP, $afterSpend + $gain);
        $metrics['resource_overflow'] += $overflow;
        $history[] = [
            'round' => $round,
            'before' => $before,
            'after' => $actor->resource,
            'gained' => $gain,
            'spent' => $cost,
            'overflow' => $overflow,
        ];
    }

    /** @return list<string> */
    private function abnormalState(CombatantState $actor, CombatantState $enemy): array
    {
        $abnormal = [];
        if ($actor->hp < 0 || $actor->hp > $actor->maxHp) {
            $abnormal[] = 'player_hp_out_of_range';
        }
        if ($enemy->hp < 0 || $enemy->hp > $enemy->maxHp) {
            $abnormal[] = 'enemy_hp_out_of_range';
        }
        if ($actor->resource < 0 || $actor->resource > UndergroundCombatRules::RESOURCE_CAP) {
            $abnormal[] = 'player_resource_out_of_range';
        }

        return $abnormal;
    }

    /** @return array<string, int|string|bool|null> */
    private function logRow(
        int $round,
        string $side,
        string $action,
        string $reason,
        int $amount,
        CombatantState $actor,
        CombatantState $enemy,
        bool $guarded = false,
    ): array {
        return [
            'round' => $round,
            'side' => $side,
            'action' => $action,
            'reason' => $reason,
            'amount' => $amount,
            'guarded' => $guarded,
            'player_hp' => $actor->hp,
            'enemy_hp' => $enemy->hp,
            'player_resource' => $actor->resource,
            'enemy_telegraphing' => $enemy->telegraphing,
        ];
    }
}
