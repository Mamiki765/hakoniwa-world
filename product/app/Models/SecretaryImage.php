<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SecretaryImage extends Model
{
    protected $fillable = [
        'secretary_id', 'slot', 'path', 'mime_type', 'creation_method', 'credit', 'updated_at',
    ];

    /** @return BelongsTo<Secretary, $this> */
    public function secretary(): BelongsTo
    {
        return $this->belongsTo(Secretary::class);
    }
}
