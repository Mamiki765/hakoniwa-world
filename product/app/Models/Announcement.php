<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string $body
 * @property string $body_format
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Announcement extends Model
{
    use SoftDeletes;

    public const FORMAT_PLAIN_TEXT = 'plain_text';

    public const FORMAT_MARKDOWN = 'markdown';

    protected $fillable = ['title', 'body', 'body_format'];

    protected $attributes = ['body_format' => self::FORMAT_PLAIN_TEXT];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
