<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserSkipTicketBalance extends Model
{
    protected $fillable = ['user_id', 'balance'];

    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }
}
