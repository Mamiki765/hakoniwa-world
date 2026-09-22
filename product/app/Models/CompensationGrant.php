<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $world_id
 * @property int|null $nation_id
 * @property int $recipient_user_id
 * @property string $grant_key
 * @property string $operator_identifier
 * @property string $reason
 * @property string $status
 * @property Carbon|null $claimed_at
 * @property Carbon $expires_at
 * @property-read Nation $nation
 * @property-read Collection<int, CompensationGrantItem> $items
 */
final class CompensationGrant extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'world_id', 'nation_id', 'recipient_user_id', 'grant_key', 'operator_identifier',
        'reason', 'status', 'claimed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'world_id' => 'integer',
            'nation_id' => 'integer',
            'recipient_user_id' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Nation, $this> */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /** @return HasMany<CompensationGrantItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CompensationGrantItem::class);
    }
}
