<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ruleset_version_id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property string $target_type
 * @property list<string> $target_terrain_keys
 * @property list<string> $target_facility_keys
 * @property bool $requires_empty_facility
 * @property int $cost_money
 * @property array<string, int> $required_resources
 * @property string $execution_phase
 * @property string|null $result_terrain_key
 * @property string|null $result_facility_key
 * @property bool $enabled
 * @property int $sort_order
 * @property array<string, mixed> $metadata
 */
class CommandDefinition extends Model
{
    protected $fillable = [
        'ruleset_version_id', 'key', 'name', 'description', 'target_type', 'target_terrain_keys',
        'target_facility_keys', 'requires_empty_facility', 'cost_money', 'required_resources',
        'execution_phase', 'result_terrain_key', 'result_facility_key', 'enabled', 'sort_order', 'metadata',
    ];

    /** @return BelongsTo<RulesetVersion, $this> */
    public function rulesetVersion(): BelongsTo
    {
        return $this->belongsTo(RulesetVersion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_terrain_keys' => 'array', 'target_facility_keys' => 'array',
            'requires_empty_facility' => 'boolean', 'cost_money' => 'integer', 'required_resources' => 'array',
            'enabled' => 'boolean', 'metadata' => 'array',
        ];
    }
}
