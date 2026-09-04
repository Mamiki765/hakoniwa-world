<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $world_id
 * @property int $ruleset_version_id
 * @property int $nation_id
 * @property int|null $map_cell_id
 * @property string $ship_type_key
 * @property int $current_hp
 * @property int $max_hp
 * @property int|null $heading
 * @property string $state
 * @property int $version
 * @property string|null $removal_reason
 * @property Carbon|null $removed_at
 * @property-read World $world
 * @property-read RulesetVersion $rulesetVersion
 * @property-read Nation $nation
 * @property-read MapCell|null $cell
 */
final class Ship extends Model
{
    public const STATE_ACTIVE = 'active';

    public const STATE_REMOVED = 'removed';

    protected $fillable = [
        'world_id', 'ruleset_version_id', 'nation_id', 'map_cell_id', 'ship_type_key', 'current_hp', 'max_hp',
        'heading', 'state', 'version', 'removal_reason', 'removed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ruleset_version_id' => 'integer',
            'current_hp' => 'integer',
            'max_hp' => 'integer',
            'heading' => 'integer',
            'version' => 'integer',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return BelongsTo<Nation, $this> */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /** @return BelongsTo<RulesetVersion, $this> */
    public function rulesetVersion(): BelongsTo
    {
        return $this->belongsTo(RulesetVersion::class);
    }

    /** @return BelongsTo<MapCell, $this> */
    public function cell(): BelongsTo
    {
        return $this->belongsTo(MapCell::class, 'map_cell_id');
    }
}
