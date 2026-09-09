<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserDailyLoginClaim extends Model
{
    protected $fillable = [
        'user_id', 'canonical_day', 'paradox_awarded', 'skip_tickets_awarded', 'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'canonical_day' => 'date',
            'paradox_awarded' => 'integer',
            'skip_tickets_awarded' => 'integer',
            'claimed_at' => 'datetime',
        ];
    }
}
