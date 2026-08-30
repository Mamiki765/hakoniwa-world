<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;

/**
 * Shared one-action-per-actor round orchestration for versioned Underground models.
 *
 * Formulae, AI, status timing, and result projection remain owned by the selected
 * combat model. This class owns only the stable round/action envelope.
 */
final class CanonicalCombatOrchestrator
{
    /**
     * @param  callable(): bool  $canContinue
     * @param  callable(int): void  $beginRound
     * @param  callable(int): list<string>  $turnOrder
     * @param  callable(string, int): void  $executeAction
     * @param  callable(int): void  $endRound
     */
    public function run(
        int $maxRounds,
        callable $canContinue,
        callable $beginRound,
        callable $turnOrder,
        callable $executeAction,
        callable $endRound,
    ): int {
        if ($maxRounds < 1) {
            throw new InvalidArgumentException('Canonical Underground combat requires at least one round.');
        }

        $completedRounds = 0;
        for ($round = 1; $round <= $maxRounds && $this->canContinue($canContinue); $round++) {
            $completedRounds = $round;
            $beginRound($round);
            foreach ($turnOrder($round) as $actingSide) {
                if (! $this->canContinue($canContinue)) {
                    break;
                }
                $executeAction($actingSide, $round);
            }
            $endRound($round);
        }

        return $completedRounds;
    }

    /**
     * @phpstan-impure
     *
     * @param  callable(): bool  $callback
     */
    private function canContinue(callable $callback): bool
    {
        return $callback();
    }
}
