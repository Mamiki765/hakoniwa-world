<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $auction_listing_id
 * @property int $bidder_nation_id
 * @property int $amount
 * @property string $status
 * @property int $placed_turn
 * @property Carbon|null $refunded_at
 */
final class AuctionBid extends Model
{
    public const STATUS_HIGHEST = 'highest';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_WON = 'won';

    protected $fillable = [
        'auction_listing_id', 'bidder_nation_id', 'amount', 'status', 'placed_turn', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer', 'placed_turn' => 'integer', 'refunded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AuctionListing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(AuctionListing::class, 'auction_listing_id');
    }

    /** @return BelongsTo<Nation, $this> */
    public function bidderNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'bidder_nation_id');
    }
}
