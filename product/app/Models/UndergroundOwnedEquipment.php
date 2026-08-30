<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $underground_profile_id
 * @property string $definition_key
 * @property string $catalog_identity
 * @property string|null $equipped_slot
 * @property string|null $grant_key
 * @property Carbon $acquired_at
 * @property-read UndergroundProfile $profile
 */
final class UndergroundOwnedEquipment extends Model
{
    protected $table = 'underground_owned_equipment';

    protected $fillable = [
        'underground_profile_id', 'definition_key', 'catalog_identity',
        'equipped_slot', 'grant_key', 'acquired_at',
    ];

    protected function casts(): array
    {
        return [
            'underground_profile_id' => 'integer',
            'acquired_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<UndergroundProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(UndergroundProfile::class, 'underground_profile_id');
    }
}
