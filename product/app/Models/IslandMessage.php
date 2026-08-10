<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $world_id
 * @property int $target_nation_id
 * @property int $author_user_id
 * @property string $author_kind
 * @property int|null $author_nation_id
 * @property int|null $secret_sender_nation_id
 * @property string $message_type
 * @property string $body
 * @property Carbon $created_at
 * @property-read User $authorUser
 * @property-read Nation|null $authorNation
 * @property-read Nation|null $secretSenderNation
 * @property-read Nation $targetNation
 */
final class IslandMessage extends Model
{
    public const TYPE_PUBLIC = 'public';

    public const TYPE_SECRET = 'secret';

    public const AUTHOR_NATION = 'nation';

    public const AUTHOR_VISITOR = 'visitor';

    protected $fillable = [
        'public_id', 'world_id', 'target_nation_id', 'author_user_id', 'author_kind',
        'author_nation_id', 'secret_sender_nation_id', 'message_type', 'body',
    ];

    /** @return BelongsTo<User, $this> */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<Nation, $this> */
    public function authorNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'author_nation_id');
    }

    /** @return BelongsTo<Nation, $this> */
    public function secretSenderNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'secret_sender_nation_id');
    }

    /** @return BelongsTo<Nation, $this> */
    public function targetNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'target_nation_id');
    }
}
