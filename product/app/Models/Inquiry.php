<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $submission_key
 * @property int $user_id
 * @property int $world_id
 * @property int|null $nation_id
 * @property int $submitted_turn
 * @property string $application_version
 * @property string $category
 * @property string $subject
 * @property string $body
 * @property string|null $attachment_token
 * @property string|null $attachment_path
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read World $world
 * @property-read Nation|null $nation
 */
final class Inquiry extends Model
{
    protected $fillable = [
        'submission_key', 'user_id', 'world_id', 'nation_id', 'submitted_turn',
        'application_version', 'category', 'subject', 'body', 'attachment_token', 'attachment_path',
    ];

    protected function casts(): array
    {
        return [
            'submitted_turn' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function managementId(): string
    {
        return sprintf('INQ-%06d', $this->id);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return BelongsTo<Nation, $this> */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}
