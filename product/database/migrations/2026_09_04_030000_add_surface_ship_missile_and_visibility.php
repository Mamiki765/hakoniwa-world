<?php

use App\Application\Ver350RulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $target = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v20.php');
        $old = $target;
        unset($old['surface_ships']['missile_impact']);
        $ruleset = DB::table('ruleset_versions')->where('key', Ver350RulesetUpgrade::TARGET_KEY)
            ->lockForUpdate()->first(['id', 'version', 'settings']);
        if ($ruleset === null || (int) $ruleset->version !== Ver350RulesetUpgrade::TARGET_VERSION) {
            throw new RuntimeException('The Surface Ship missile and visibility migration requires the authored v20 Ruleset.');
        }
        $stored = json_decode((string) $ruleset->settings, true, 512, JSON_THROW_ON_ERROR);
        if ($this->canonicalJson($stored) === $this->canonicalJson($old)) {
            DB::table('ruleset_versions')->where('id', $ruleset->id)->update([
                'settings' => json_encode($target, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'updated_at' => now(),
            ]);
        } elseif ($this->canonicalJson($stored) !== $this->canonicalJson($target)) {
            throw new RuntimeException('The stored v20 Ruleset is not the exact preceding Ship draft.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The ver 3.5.0 Surface Ship missile and visibility migration is forward-only.');
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }

        return $value;
    }
};
