<?php

namespace App\Domain\Nation;

final class NationLifecyclePrepareStateResolver
{
    public function resolve(
        string $state,
        ?string $stateReason,
        ?int $resumeAtTurn,
        int $idleCounter,
        int $targetTurn,
        bool $hasQueuedNonFinanceCommand,
        int $dormantIdleThreshold,
    ): string {
        if ($state === 'recovery') {
            if ($resumeAtTurn === null || $targetTurn < $resumeAtTurn) {
                return 'recovery';
            }

            return ! $hasQueuedNonFinanceCommand && $idleCounter >= $dormantIdleThreshold
                ? 'dormant'
                : 'active';
        }
        if ($state !== 'dormant') {
            return $state;
        }

        $manualDue = $stateReason === 'manual'
            && $resumeAtTurn !== null
            && $targetTurn >= $resumeAtTurn;
        $queuedResume = $stateReason !== 'manual' && $hasQueuedNonFinanceCommand;

        return $manualDue || $queuedResume ? 'active' : 'dormant';
    }
}
