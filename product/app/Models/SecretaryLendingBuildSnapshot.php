<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $secretary_id
 * @property string $build_identity
 * @property string $source_fingerprint
 * @property array<string, mixed> $source_snapshot
 */
final class SecretaryLendingBuildSnapshot extends Model
{
    protected $fillable = [
        'secretary_id',
        'build_identity',
        'source_fingerprint',
        'source_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'source_snapshot' => 'array',
        ];
    }
}
