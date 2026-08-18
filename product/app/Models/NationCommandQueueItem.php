<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $nation_command_queue_id
 * @property int $command_definition_id
 * @property int|null $queue_position
 * @property int $target_x
 * @property int $target_y
 * @property int $quantity
 * @property array<string, mixed> $parameters
 * @property string $status
 * @property int $queued_by_membership_id
 * @property string $request_key
 * @property string|null $request_fingerprint
 * @property CarbonImmutable|null $queued_at
 * @property-read CommandDefinition $definition
 */
class NationCommandQueueItem extends Model
{
    protected $fillable = [
        'nation_command_queue_id', 'command_definition_id', 'queue_position', 'target_x', 'target_y', 'quantity',
        'parameters', 'status', 'queued_by_membership_id', 'request_key', 'request_fingerprint', 'queued_at', 'cancelled_at',
        'execution_started_at', 'execution_completed_at', 'execution_failed_at', 'failure_code', 'failure_metadata',
    ];

    /** @return BelongsTo<NationCommandQueue, $this> */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(NationCommandQueue::class, 'nation_command_queue_id');
    }

    /** @return BelongsTo<CommandDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(CommandDefinition::class, 'command_definition_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'queue_position' => 'integer', 'target_x' => 'integer', 'target_y' => 'integer', 'quantity' => 'integer',
            'parameters' => 'array', 'failure_metadata' => 'array', 'queued_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'execution_started_at' => 'immutable_datetime',
            'execution_completed_at' => 'immutable_datetime', 'execution_failed_at' => 'immutable_datetime',
        ];
    }
}
