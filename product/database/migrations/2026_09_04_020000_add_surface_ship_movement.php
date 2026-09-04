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
        $old['secretary'] = (require config_path('hakoniwa/rulesets/v17/secretary.php'))['payload']['secretary'];
        unset($old['surface_ships']['movement'], $old['surface_ships']['forced_displacement']);
        $ruleset = DB::table('ruleset_versions')->where('key', Ver350RulesetUpgrade::TARGET_KEY)
            ->lockForUpdate()->first(['id', 'version', 'settings']);
        if ($ruleset === null || (int) $ruleset->version !== Ver350RulesetUpgrade::TARGET_VERSION) {
            throw new RuntimeException('The Surface Ship movement migration requires the authored v20 Ruleset.');
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

        DB::statement('ALTER TABLE secretary_skills DROP CONSTRAINT IF EXISTS secretary_skills_key_check');
        DB::statement(<<<'SQL'
ALTER TABLE secretary_skills
  ADD CONSTRAINT secretary_skills_key_check
  CHECK (skill_key IN (
    'agricultural_policy',
    'specialty_development',
    'gold_vein_survey',
    'forest_management',
    'final_defense_line',
    'declining_birthrate_policy',
    'indomitable',
    'ship_operations'
  ))
SQL);
        DB::statement(<<<'SQL'
INSERT INTO secretary_skills (secretary_id, skill_key, level, experience, created_at, updated_at)
SELECT secretary.id, 'ship_operations', 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
  FROM secretaries secretary
 WHERE NOT EXISTS (
   SELECT 1 FROM secretary_skills skill
    WHERE skill.secretary_id = secretary.id AND skill.skill_key = 'ship_operations'
 )
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('The ver 3.5.0 Surface Ship movement migration is forward-only.');
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
