<?php

namespace App\Models;

use App\Domain\Underground\Area\UndergroundAreaCapacity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $secretary_id
 * @property int $unlocked_area_layers
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Secretary $secretary
 */
final class UndergroundProfile extends Model
{
    protected $fillable = ['secretary_id', 'unlocked_area_layers'];

    protected function casts(): array
    {
        return ['unlocked_area_layers' => 'integer'];
    }

    /** @return BelongsTo<Secretary, $this> */
    public function secretary(): BelongsTo
    {
        return $this->belongsTo(Secretary::class);
    }

    public function facilitySlotCapacity(): int
    {
        return UndergroundAreaCapacity::forUnlockedLayers($this->unlocked_area_layers);
    }
}
