<?php

use App\Application\Ver270SecretaryItemRulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (! Schema::hasColumn('nations', 'population_high_water')) {
            Schema::table('nations', function (Blueprint $table): void {
                $table->unsignedBigInteger('population_high_water')->default(0);
            });
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
    'indomitable'
  ))
SQL);
        app(Ver270SecretaryItemRulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException('Ruleset v17 publication is forward-only.');
    }
};
