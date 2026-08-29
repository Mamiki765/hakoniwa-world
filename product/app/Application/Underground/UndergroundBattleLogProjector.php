<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\CombatResult;

final class UndergroundBattleLogProjector
{
    /** @return list<array<string, int|string|bool>> */
    public function project(CombatResult $result): array
    {
        return array_map(static function (array $row): array {
            $amount = (int) ($row['amount'] ?? 0);

            return [
                'round' => (int) ($row['round'] ?? 0),
                'side' => (string) ($row['side'] ?? ''),
                'action' => (string) ($row['action'] ?? ''),
                'effect' => $amount < 0 ? 'recovery' : ($amount > 0 ? 'damage' : 'none'),
                'amount' => abs($amount),
                'guarded' => (bool) ($row['guarded'] ?? false),
                'player_hp' => (int) ($row['player_hp'] ?? 0),
                'enemy_hp' => (int) ($row['enemy_hp'] ?? 0),
                'player_resource' => (int) ($row['player_resource'] ?? 0),
            ];
        }, $result->actionLog);
    }
}
