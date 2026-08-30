<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\CombatResult;
use App\Domain\Underground\Combat\UndergroundCombatRules;
use InvalidArgumentException;

final class UndergroundBattleLogProjector
{
    public function __construct(private UndergroundCombatRules $rules) {}

    /** @return list<array<string, int|string|bool>> */
    public function project(
        CombatResult $result,
        string $playerDisplayName = '秘書',
        string $enemyDisplayName = '対戦相手',
    ): array {
        return array_map(function (array $row) use ($playerDisplayName, $enemyDisplayName): array {
            $amount = (int) ($row['amount'] ?? 0);
            $action = (string) ($row['action'] ?? '');
            $side = (string) ($row['side'] ?? '');
            $actorName = $side === 'player' ? $playerDisplayName : $enemyDisplayName;
            $targetName = $side === 'player' ? $enemyDisplayName : $playerDisplayName;

            return [
                'round' => (int) ($row['round'] ?? 0),
                'side' => $side,
                'actor_name' => $actorName,
                'target_name' => $targetName,
                'action_label' => $this->actionLabel($action),
                'effect' => $amount < 0 ? 'recovery' : ($amount > 0 ? 'damage' : 'none'),
                'amount' => abs($amount),
                'guarded' => (bool) ($row['guarded'] ?? false),
                'player_hp' => (int) ($row['player_hp'] ?? 0),
                'enemy_hp' => (int) ($row['enemy_hp'] ?? 0),
                'player_resource' => (int) ($row['player_resource'] ?? 0),
            ];
        }, $result->actionLog);
    }

    private function actionLabel(string $action): string
    {
        if ($action === 'normal_attack') {
            return '通常攻撃';
        }

        try {
            return $this->rules->skill($action)['label'];
        } catch (InvalidArgumentException) {
            return '戦闘行動';
        }
    }
}
