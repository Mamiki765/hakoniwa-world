<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SecretaryLendingParticipation extends Model
{
    protected $fillable = ['underground_battle_id', 'underground_party_member_id', 'secretary_id', 'owner_user_id', 'canonical_day', 'result', 'ticket_delta', 'settled_at'];

    protected function casts(): array
    {
        return ['ticket_delta' => 'integer', 'canonical_day' => 'date', 'settled_at' => 'immutable_datetime'];
    }
}
