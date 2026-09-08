<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SecretaryLendingDailyReward extends Model
{
    protected $fillable = ['owner_user_id', 'canonical_day', 'participation_count', 'tickets_awarded'];

    protected function casts(): array
    {
        return ['participation_count' => 'integer', 'tickets_awarded' => 'integer', 'canonical_day' => 'date'];
    }
}
