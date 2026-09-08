<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class UndergroundParty extends Model
{
    protected $fillable = ['leader_user_id', 'leader_secretary_id', 'content_type', 'content_key', 'content_identity', 'party_size', 'leader_combat_level', 'snapshot'];

    protected function casts(): array
    {
        return ['party_size' => 'integer', 'leader_combat_level' => 'integer', 'snapshot' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    /** @return HasMany<UndergroundPartyMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(UndergroundPartyMember::class);
    }
}
