<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $nation_id
 * @property int $layer
 * @property int $slot_index
 * @property string $facility_key
 * @property-read Nation $nation
 */
final class NationUndergroundFacility extends Model
{
    protected $fillable = ['nation_id', 'layer', 'slot_index', 'facility_key'];

    protected function casts(): array
    {
        return [
            'nation_id' => 'integer',
            'layer' => 'integer',
            'slot_index' => 'integer',
        ];
    }

    /** @return BelongsTo<Nation, $this> */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}
