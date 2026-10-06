<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property string $achievement_key
 * @property string $title_key
 * @property Carbon $acquired_at
 * @property Carbon|null $maria_sent_at
 */
final class UserAchievement extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'achievement_key', 'title_key', 'acquired_at', 'maria_sent_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['acquired_at' => 'immutable_datetime', 'maria_sent_at' => 'immutable_datetime'];
    }
}
