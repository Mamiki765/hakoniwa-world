<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationCommandQueueItem extends Model
{
    protected $fillable = [
        'nation_command_queue_id', 'command_definition_id', 'queue_position', 'target_q', 'target_r',
        'parameters', 'status', 'queued_by_membership_id', 'request_key', 'queued_at', 'cancelled_at',
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
            'queue_position' => 'integer', 'target_q' => 'integer', 'target_r' => 'integer',
            'parameters' => 'array', 'failure_metadata' => 'array', 'queued_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'execution_started_at' => 'immutable_datetime',
            'execution_completed_at' => 'immutable_datetime', 'execution_failed_at' => 'immutable_datetime',
        ];
    }
}
