<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class GuideConversationTopic extends Model
{
    protected $fillable = [
        'initial_line',
        'choice_1',
        'reply_1',
        'choice_2',
        'reply_2',
        'choice_3',
        'reply_3',
        'unlock_key',
        'enabled',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }
}
