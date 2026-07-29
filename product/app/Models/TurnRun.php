<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $world_id
 * @property int $target_turn
 * @property int $ruleset_version_id
 * @property string $random_seed
 * @property string $source
 * @property bool $is_dry_run
 * @property string $status
 * @property int $attempt_count
 * @property list<array<string, mixed>> $pipeline
 * @property list<array<string, mixed>> $phase_results
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<string, mixed> $failure_context
 */
class TurnRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DRY_RUN = 'dry_run';

    protected $fillable = [
        'world_id', 'target_turn', 'ruleset_version_id', 'random_seed', 'source', 'is_dry_run',
        'status', 'attempt_count', 'pipeline', 'phase_results', 'started_at', 'completed_at',
        'failure_code', 'failure_message', 'failure_context',
    ];

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return BelongsTo<RulesetVersion, $this> */
    public function rulesetVersion(): BelongsTo
    {
        return $this->belongsTo(RulesetVersion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_turn' => 'integer',
            'ruleset_version_id' => 'integer',
            'is_dry_run' => 'boolean',
            'attempt_count' => 'integer',
            'pipeline' => 'array',
            'phase_results' => 'array',
            'failure_context' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
