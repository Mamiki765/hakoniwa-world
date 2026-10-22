<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * Raw JSON that produced $decodedSettings. Turn phases read settings
     * thousands of times per turn; an `array` cast decodes the whole Ruleset
     * on every read, which dominated turn runtime.
     */
    private ?string $decodedSettingsSource = null;

    /** @var array<string, mixed>|null */
    private ?array $decodedSettings = null;

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return Attribute<array<string, mixed>|null, mixed> */
    protected function settings(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): ?array {
                if ($value === null) {
                    return null;
                }
                // Keyed on the exact raw string, so a later assignment or
                // refresh can never return stale settings.
                if ($value !== $this->decodedSettingsSource) {
                    $this->decodedSettings = $this->fromJson($value);
                    $this->decodedSettingsSource = $value;
                }

                return $this->decodedSettings;
            },
            set: fn (mixed $value): array => ['settings' => $value === null ? null : $this->asJson($value)],
        );
    }
}
