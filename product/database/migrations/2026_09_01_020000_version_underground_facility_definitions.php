<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    private const V19_KEY = 'hakoniwa-2s-plus-v19';

    private const PRE_VERSIONED_V19_CHECKSUM = '3f6cc0bbede129ab08cd14093de3d19bbd08879cfb6d87cb792b21a46bcc16d0';

    public function up(): void
    {
        $targetSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v19.php');
        $ruleset = DB::table('ruleset_versions')->where('key', self::V19_KEY)->lockForUpdate()->first();
        if ($ruleset === null || (int) $ruleset->version !== 19) {
            throw new RuntimeException('The in-development v19 Ruleset must exist before Underground definition versioning.');
        }
        $storedSettings = json_decode((string) $ruleset->settings, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($storedSettings)) {
            throw new RuntimeException('The stored v19 Ruleset payload is invalid.');
        }
        $expectedPrevious = $targetSettings;
        unset($expectedPrevious['underground_facility_development']);
        if ($this->checksum($expectedPrevious) !== self::PRE_VERSIONED_V19_CHECKSUM) {
            throw new RuntimeException('The approved previous v19 Ruleset source has changed.');
        }
        if ($this->canonicalJson($storedSettings) === $this->canonicalJson($expectedPrevious)) {
            DB::table('ruleset_versions')->where('id', $ruleset->id)->update([
                'settings' => json_encode($targetSettings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'updated_at' => now(),
            ]);
        } elseif ($this->canonicalJson($storedSettings) !== $this->canonicalJson($targetSettings)) {
            throw new RuntimeException('The stored v19 Ruleset is neither the approved previous nor target payload.');
        }

        if (! Schema::hasColumn('nation_underground_facilities', 'ruleset_version_id')) {
            Schema::table('nation_underground_facilities', function (Blueprint $table): void {
                $table->unsignedBigInteger('ruleset_version_id')->nullable();
            });
            DB::table('nation_underground_facilities')->update([
                'ruleset_version_id' => (int) $ruleset->id,
            ]);
            DB::statement(
                'ALTER TABLE nation_underground_facilities '
                .'ALTER COLUMN ruleset_version_id SET NOT NULL',
            );
            Schema::table('nation_underground_facilities', function (Blueprint $table): void {
                $table->foreign('ruleset_version_id')
                    ->references('id')->on('ruleset_versions')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Versioned Underground definitions are forward-only.');
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param array<string, mixed> $payload */
    private function canonicalJson(array $payload): string
    {
        return json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }

        return $value;
    }
};
