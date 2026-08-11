<?php

namespace App\Application;

use App\Domain\Turn\TurnContext;
use App\Models\Nation;
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
        ?string $visibility = null,
        ?string $severity = null,
        ?string $message = null,
    ): void {
        DB::table('audit_events')->insert($this->row(
            $context,
            $eventType,
            $subject,
            $metadata,
            $visibility,
            $severity,
            $message,
            now(),
        ));
    }

    /**
     * @param  list<array{
     *     event_type: string,
     *     subject: Model|null,
     *     metadata: array<string, mixed>,
     *     visibility: string|null,
     *     severity: string|null,
     *     message: string|null
     * }>  $events
     */
    public function recordMany(TurnContext $context, array $events, int $batchSize = 1_000): void
    {
        if ($events === []) {
            return;
        }
        $timestamp = now();
        $rows = array_map(fn (array $event): array => $this->row(
            $context,
            $event['event_type'],
            $event['subject'],
            $event['metadata'],
            $event['visibility'],
            $event['severity'],
            $event['message'],
            $timestamp,
        ), $events);
        foreach (array_chunk($rows, $batchSize) as $batch) {
            DB::table('audit_events')->insert($batch);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function row(
        TurnContext $context,
        string $eventType,
        ?Model $subject,
        array $metadata,
        ?string $visibility,
        ?string $severity,
        ?string $message,
        mixed $timestamp,
    ): array {
        $nationId = isset($metadata['nation_id']) && is_int($metadata['nation_id'])
            ? $metadata['nation_id']
            : ($subject instanceof Nation ? $subject->id : null);
        $x = isset($metadata['x']) && is_int($metadata['x']) ? $metadata['x'] : null;
        $y = isset($metadata['y']) && is_int($metadata['y']) ? $metadata['y'] : null;

        return [
            'actor_user_id' => null,
            'world_id' => $context->world->id,
            'turn' => $context->targetTurn,
            'nation_id' => $nationId,
            'x' => $x,
            'y' => $y,
            'message' => $message,
            'visibility' => $visibility ?? $this->defaultVisibility($eventType, $nationId),
            'event_type' => $eventType,
            'severity' => $severity ?? $this->defaultSeverity($eventType),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => json_encode([
                'turn_run_id' => $context->run->id,
                'world_id' => $context->world->id,
                'target_turn' => $context->targetTurn,
                ...$metadata,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private function defaultVisibility(string $eventType, ?int $nationId): string
    {
        return match ($eventType) {
            'command.queue_removed', 'command.quantity_decremented', 'monster.kill_stat_incremented',
            'monster.spawn_failed_no_settlement' => 'admin',
            'monster.reward_distributed' => 'private',
            'turn.completed', 'disaster.triggered', 'land_subsidence.triggered',
            'monster.spawned', 'monster.moved',
            'monster.trampled', 'monster.stayed', 'monster.damage_blocked', 'monster.damaged',
            'monster.killed', 'monster.defense_self_destructed',
            'monster.removed_by_terrain_event' => 'public',
            default => $nationId === null ? 'public' : 'nation',
        };
    }

    private function defaultSeverity(string $eventType): string
    {
        return match ($eventType) {
            'command.failed', 'capacity.overflow', 'resource.food_shortage', 'facility.riot',
            'disaster.cell_damaged', 'capital.disaster_damaged', 'monster.damaged' => 'warning',
            default => 'info',
        };
    }
}
