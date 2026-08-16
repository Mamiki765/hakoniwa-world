<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $secretary_id
 * @property string $skill_key
 * @property int $level
 * @property int $experience
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Secretary $secretary
 */
final class SecretarySkill extends Model
{
    protected $fillable = ['secretary_id', 'skill_key', 'level', 'experience'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'experience' => 'integer'];
    }

    /** @return BelongsTo<Secretary, $this> */
    public function secretary(): BelongsTo
    {
        return $this->belongsTo(Secretary::class);
    }
}
