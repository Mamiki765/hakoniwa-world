<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserSkipTicketBalance extends Model
{
    protected $fillable = ['user_id', 'balance', 'lifetime_participation_count'];

    protected function casts(): array
    {
        return ['balance' => 'integer', 'lifetime_participation_count' => 'integer'];
    }
}
