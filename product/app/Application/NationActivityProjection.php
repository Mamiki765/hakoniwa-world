<?php

namespace App\Application;

use App\Models\Nation;

final class NationActivityProjection
{
    /**
     * @return array{recovery_remaining_turns: int|null, survival_turns: int, finance_only_turns: int, activity_status: string}
     */
    public function forNation(Nation $nation, int $currentTurn): array
    {
        $financeOnlyTurns = (int) $nation->idle_counter;

        return [
            'recovery_remaining_turns' => $nation->state === 'recovery' && $nation->resume_at_turn !== null
                ? max(0, (int) $nation->resume_at_turn - $currentTurn - 1)
                : null,
            'survival_turns' => max(0, $currentTurn - (int) $nation->registered_turn),
            'finance_only_turns' => $financeOnlyTurns,
            'activity_status' => match ($nation->state) {
                'dormant' => 'dormant',
                'recovery' => 'recovery',
                default => $financeOnlyTurns > 0 ? 'finance_only' : 'active',
            },
        ];
    }
}
