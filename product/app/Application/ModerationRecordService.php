<?php

namespace App\Application;

use App\Models\Nation;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ModerationRecordService
{
    /** @return array{record_id: int, category: string, target_type: string, target_id: int} */
    public function record(
        string $category,
        string $targetType,
        int $targetId,
        string $operator,
        string $summary,
    ): array {
        $category = trim($category);
        $targetType = trim($targetType);
        $operator = trim($operator);
        $summary = trim($summary);
        $this->validate($category, $targetType, $targetId, $operator, $summary);

        return DB::transaction(function () use ($category, $targetType, $targetId, $operator, $summary): array {
            $target = $this->target($targetType, $targetId);
            $nation = $target instanceof Nation ? $target : null;
            $occurredAt = now();
            $recordId = (int) DB::table('moderation_records')->insertGetId([
                'operator_identifier' => $operator,
                'category' => $category,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'summary' => $summary,
                'metadata' => '{}',
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            DB::table('audit_events')->insert([
                'actor_user_id' => null,
                'world_id' => $nation?->world_id,
                'turn' => $nation?->world()->value('current_turn'),
                'nation_id' => $nation?->id,
                'x' => null,
                'y' => null,
                'message' => null,
                'visibility' => 'admin',
                'event_type' => 'moderation.recorded',
                'severity' => 'info',
                'subject_type' => $target->getMorphClass(),
                'subject_id' => $target->getKey(),
                'metadata' => json_encode([
                    'moderation_record_id' => $recordId,
                    'category' => $category,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'operator_identifier' => $operator,
                    'summary' => $summary,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return [
                'record_id' => $recordId,
                'category' => $category,
                'target_type' => $targetType,
                'target_id' => $targetId,
            ];
        }, 3);
    }

    private function validate(
        string $category,
        string $targetType,
        int $targetId,
        string $operator,
        string $summary,
    ): void {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/', $category) !== 1) {
            throw new DomainException('category must be 1-64 lowercase ASCII identifier characters.');
        }
        if (! in_array($targetType, ['nation', 'user'], true)) {
            throw new DomainException('target-type must be nation or user.');
        }
        if ($targetId < 1) {
            throw new DomainException('target-id must be a positive integer.');
        }
        foreach ([['operator', $operator, 191], ['summary', $summary, 1000]] as [$label, $value, $maximum]) {
            if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\p{C}\r\n]/u', $value) === 1) {
                throw new DomainException("{$label} must be single-line plain text between 1 and {$maximum} characters.");
            }
        }
    }

    private function target(string $targetType, int $targetId): Model
    {
        return $targetType === 'nation'
            ? Nation::query()->whereKey($targetId)->lockForUpdate()->firstOrFail()
            : User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();
    }
}
