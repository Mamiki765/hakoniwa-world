<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property int $version
 * @property array<string, mixed> $settings
 * @property bool $is_active
 */
class RulesetVersion extends Model
{
    protected $fillable = ['key', 'version', 'settings', 'is_active'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'settings' => 'array', 'is_active' => 'boolean'];
    }
}
