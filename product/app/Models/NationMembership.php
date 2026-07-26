<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $world_id
 * @property int $nation_id
 * @property string $role
 * @property-read Nation $nation
 */
class NationMembership extends Model
{
    protected $fillable = ['user_id', 'world_id', 'nation_id', 'role'];

    /** @return BelongsTo<Nation, $this> */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}
