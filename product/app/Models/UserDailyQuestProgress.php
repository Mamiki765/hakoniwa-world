<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserDailyQuestProgress extends Model
{
    protected $table = 'user_daily_quest_progress';

    protected $fillable = [
        'user_id', 'canonical_day', 'quest_key', 'progress', 'target', 'paradox_awarded', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'canonical_day' => 'date',
            'progress' => 'integer',
            'target' => 'integer',
            'paradox_awarded' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
