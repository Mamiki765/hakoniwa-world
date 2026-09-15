<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $secretary_id
 * @property string $item_key
 * @property int $level
 * @property int|null $equipped_slot
 * @property bool $is_escrowed
 * @property string|null $grant_key
 * @property string|null $resolved_rarity
 * @property int|null $resolved_fixed_sale_price_money
 * @property Carbon $obtained_at
 * @property-read Secretary $secretary
 */
final class SecretaryItemInstance extends Model
{
    protected $fillable = [
        'secretary_id', 'item_key', 'level', 'equipped_slot', 'is_escrowed', 'grant_key',
        'resolved_rarity', 'resolved_fixed_sale_price_money', 'obtained_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'equipped_slot' => 'integer',
            'is_escrowed' => 'boolean',
            'resolved_fixed_sale_price_money' => 'integer',
            'obtained_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Secretary, $this> */
    public function secretary(): BelongsTo
    {
        return $this->belongsTo(Secretary::class);
    }
}
