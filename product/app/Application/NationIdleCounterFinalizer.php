<?php

namespace App\Application;

use App\Domain\Turn\TurnContext;
use App\Models\Nation;

final class NationIdleCounterFinalizer
{
    public function __construct(
        private readonly TurnEventRecorder $events,
    ) {}

    /** @return 'incremented'|'reset'|'unchanged' */
    public function finalize(TurnContext $context, Nation $nation): string
    {
        $activity = $context->state->nationActivity($nation->id);
        if ($activity['idle_counter_finalized']) {
            return 'unchanged';
        }

        $normalCommandSucceeded = $activity['immediate_normal_command_succeeded']
            || $activity['missile_shots_fired'] > 0;
        $before = (int) $nation->idle_counter;
        if ($normalCommandSucceeded) {
            $after = 0;
            $change = $before === 0 ? 'unchanged' : 'reset';
            $reason = 'normal_command_succeeded';
        } elseif ($activity['missile_intent_pending']) {
            $context->state->markIdleCounterFinalized($nation->id);

            return 'unchanged';
        } elseif ($activity['finance_succeeded']) {
            $after = $before + 1;
            $change = 'incremented';
            $reason = 'finance_only';
        } else {
            $context->state->markIdleCounterFinalized($nation->id);

            return 'unchanged';
        }

        if ($after !== $before) {
            $nation->update(['idle_counter' => $after]);
        }
        $this->events->record($context, 'nation.idle_counter_changed', $nation, [
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'normal_command_succeeded' => $normalCommandSucceeded,
            'immediate_normal_command_succeeded' => $activity['immediate_normal_command_succeeded'],
            'finance_succeeded' => $activity['finance_succeeded'],
            'missile_intent_pending' => $activity['missile_intent_pending'],
            'missile_shots_fired' => $activity['missile_shots_fired'],
            'maximum_increment_per_target_turn' => 1,
        ]);
        $context->state->markIdleCounterFinalized($nation->id);

        return $change;
    }
}
