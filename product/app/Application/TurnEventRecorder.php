<?php

namespace App\Application;

use App\Domain\Turn\TurnContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class TurnEventRecorder
{
    /** @param array<string, mixed> $metadata */
    public function record(
        TurnContext $context,
        string $eventType,
        ?Model $subject = null,
        array $metadata = [],
    ): void {
        DB::table('audit_events')->insert([
            'actor_user_id' => null,
            'event_type' => $eventType,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => json_encode([
                'turn_run_id' => $context->run->id,
                'world_id' => $context->world->id,
                'target_turn' => $context->targetTurn,
                ...$metadata,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
