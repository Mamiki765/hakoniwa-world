<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $compensation_grant_id
 * @property string $asset_key
 * @property int $amount
 * @property int $claimed_amount
 * @property-read CompensationGrant $grant
 */
final class CompensationGrantItem extends Model
{
    protected $fillable = ['compensation_grant_id', 'asset_key', 'amount', 'claimed_amount'];

    protected function casts(): array
    {
        return [
            'compensation_grant_id' => 'integer',
            'amount' => 'integer',
            'claimed_amount' => 'integer',
        ];
    }

    /** @return BelongsTo<CompensationGrant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(CompensationGrant::class, 'compensation_grant_id');
    }
}
