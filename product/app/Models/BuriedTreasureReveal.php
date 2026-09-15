<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BuriedTreasureReveal extends Model
{
    protected $fillable = ['buried_treasure_id', 'nation_id', 'turn'];

    protected function casts(): array
    {
        return ['turn' => 'integer'];
    }

    /** @return BelongsTo<BuriedTreasure, $this> */
    public function treasure(): BelongsTo
    {
        return $this->belongsTo(BuriedTreasure::class, 'buried_treasure_id');
    }
}
