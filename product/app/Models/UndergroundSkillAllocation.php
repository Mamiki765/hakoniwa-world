<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $underground_profile_id
 * @property string $node_key
 * @property int $rank
 * @property int|null $active_slot
 * @property-read UndergroundProfile $profile
 */
final class UndergroundSkillAllocation extends Model
{
    protected $fillable = ['underground_profile_id', 'node_key', 'rank', 'active_slot'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'underground_profile_id' => 'integer',
            'rank' => 'integer',
            'active_slot' => 'integer',
        ];
    }

    /** @return BelongsTo<UndergroundProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(UndergroundProfile::class, 'underground_profile_id');
    }
}
