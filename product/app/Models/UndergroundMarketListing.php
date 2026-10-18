<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $seller_profile_id
 * @property int|null $buyer_profile_id
 * @property int $equipment_id
 * @property int $price
 * @property int $fee
 * @property int $proceeds
 * @property string $status
 * @property array<string,mixed> $item_snapshot
 * @property array<string,mixed> $seller_snapshot
 * @property Carbon|null $expires_at
 * @property Carbon|null $sold_at
 * @property Carbon|null $expired_at
 * @property Carbon|null $proceeds_claimed_at
 * @property Carbon|null $seen_by_seller_at
 */
final class UndergroundMarketListing extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'seller_profile_id' => 'integer', 'buyer_profile_id' => 'integer', 'equipment_id' => 'integer',
            'price' => 'integer', 'fee' => 'integer', 'proceeds' => 'integer',
            'item_snapshot' => 'array', 'seller_snapshot' => 'array',
            'listed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'sold_at' => 'immutable_datetime', 'expired_at' => 'immutable_datetime',
            'proceeds_claimed_at' => 'immutable_datetime', 'seen_by_seller_at' => 'immutable_datetime',
        ];
    }
}
