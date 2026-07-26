<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $map_space_id
 * @property int $map_chunk_id
 * @property int $q
 * @property int $r
 * @property int $chunk_q
 * @property int $chunk_r
 * @property int $local_q
 * @property int $local_r
 * @property int $terrain_definition_id
 * @property int|null $facility_definition_id
 * @property int|null $owner_nation_id
 * @property int $population
 * @property int|null $terrain_quantity
 * @property int|null $facility_scale
 * @property int|null $facility_experience
 * @property string|null $facility_operational_state
 * @property string $state
 * @property int $version
 * @property Carbon|null $updated_at
 * @property-read TerrainDefinition $terrain
 * @property-read FacilityDefinition|null $facility
 * @property-read Nation|null $ownerNation
 */
class MapCell extends Model
{
    protected $fillable = [
        'map_space_id', 'map_chunk_id', 'q', 'r', 'chunk_q', 'chunk_r', 'local_q', 'local_r',
        'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population', 'terrain_quantity',
        'facility_scale', 'facility_experience', 'facility_operational_state', 'state', 'version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'population' => 'integer', 'terrain_quantity' => 'integer', 'facility_scale' => 'integer',
            'facility_experience' => 'integer', 'version' => 'integer',
        ];
    }

    /** @return BelongsTo<TerrainDefinition, $this> */
    public function terrain(): BelongsTo
    {
        return $this->belongsTo(TerrainDefinition::class, 'terrain_definition_id');
    }

    /** @return BelongsTo<FacilityDefinition, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(FacilityDefinition::class, 'facility_definition_id');
    }

    /** @return BelongsTo<Nation, $this> */
    public function ownerNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'owner_nation_id');
    }
}
