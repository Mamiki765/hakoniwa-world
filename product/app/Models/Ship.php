<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $world_id
 * @property int $ruleset_version_id
 * @property int|null $nation_id
 * @property int|null $map_cell_id
 * @property string $ship_type_key
 * @property int $current_hp
 * @property int $max_hp
 * @property int|null $population
 * @property int|null $heading
 * @property int|null $destination_x
 * @property int|null $destination_y
 * @property string $state
 * @property int $version
 * @property string|null $removal_reason
 * @property Carbon|null $removed_at
 * @property-read World $world
 * @property-read RulesetVersion $rulesetVersion
 * @property-read Nation|null $nation
 * @property-read MapCell|null $cell
 */
final class Ship extends Model
{
    public const STATE_ACTIVE = 'active';

    public const STATE_REMOVED = 'removed';

    protected $fillable = [
        'world_id', 'ruleset_version_id', 'nation_id', 'map_cell_id', 'ship_type_key', 'current_hp', 'max_hp',
        'population', 'heading', 'destination_x', 'destination_y', 'state', 'version', 'removal_reason', 'removed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ruleset_version_id' => 'integer',
            'current_hp' => 'integer',
            'max_hp' => 'integer',
            'population' => 'integer',
            'heading' => 'integer',
            'destination_x' => 'integer',
            'destination_y' => 'integer',
            'version' => 'integer',
            'removed_at' => 'datetime',
        ];
    }

    /** @return Builder<Ship> */
    public static function activeForNation(int $worldId, int $nationId): Builder
    {
        return self::query()->where('world_id', $worldId)
            ->where('nation_id', $nationId)->where('state', self::STATE_ACTIVE);
    }

    /** @return array{heading: int|null, destination: array{x: int, y: int}|null, version: int} */
    public function course(): array
    {
        return [
            'heading' => $this->heading,
            'destination' => $this->destination_x === null || $this->destination_y === null
                ? null
                : ['x' => $this->destination_x, 'y' => $this->destination_y],
            'version' => (int) $this->version,
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
