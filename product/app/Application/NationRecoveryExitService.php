<?php

namespace App\Application;

use App\Domain\Turn\TurnContext;
use App\Models\Nation;
use DomainException;

final class NationRecoveryExitService
{
    public function __construct(private readonly TurnEventRecorder $events) {}

    public function exitForCrime(
        TurnContext $context,
        Nation $nation,
        int $crimePoints,
        int $queueItemId,
    ): void {
        if ($nation->state !== 'recovery' || $crimePoints < 1) {
            throw new DomainException('Only a recovery Nation with canonical crime may exit recovery immediately.');
        }
        $this->exit($context, $nation, 'active', [
            'exit_trigger' => 'karma_crime',
            'crime_points' => $crimePoints,
            'queue_item_id' => $queueItemId,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    public function exit(
        TurnContext $context,
        Nation $nation,
        string $nextState,
        array $metadata,
    ): void {
        if ($nation->state !== 'recovery' || ! in_array($nextState, ['active', 'dormant'], true)) {
            throw new DomainException('Nation cannot exit recovery without a supported lifecycle transition.');
        }
        $beforeStartedTurn = $nation->state_started_turn;
        $beforeResumeTurn = $nation->resume_at_turn;
        $nation->state = $nextState;
        $nation->state_reason = $nextState === 'dormant' ? 'idle' : null;
        $nation->state_started_turn = $nextState === 'dormant' ? $context->targetTurn : null;
        $nation->resume_at_turn = null;
        $nation->save();
        $context->state->markRecoveryExited($nation->id);
        $this->events->record($context, 'nation.recovery_ended', $nation, [
            'nation_id' => $nation->id,
            'nation_name' => $nation->name,
            'before_state' => 'recovery',
            'after_state' => $nextState,
            'state_started_turn' => $beforeStartedTurn,
            'resume_at_turn' => $beforeResumeTurn,
            ...$metadata,
        ], 'public', message: "{$nation->name}の休戦期間が終了しました。");
    }
}
