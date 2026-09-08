<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UndergroundPartyMember extends Model
{
    protected $fillable = ['underground_party_id', 'source_type', 'secretary_id', 'source_owner_user_id', 'combatant_id', 'original_level', 'effective_level', 'snapshot'];

    protected function casts(): array
    {
        return ['original_level' => 'integer', 'effective_level' => 'integer', 'snapshot' => 'array'];
    }

    /** @return BelongsTo<UndergroundParty, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(UndergroundParty::class, 'underground_party_id');
    }
}
