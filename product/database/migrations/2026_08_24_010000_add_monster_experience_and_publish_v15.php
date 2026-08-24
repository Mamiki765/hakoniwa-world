<?php

use App\Application\Ver250MonsterExperienceRulesetUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Ver250MonsterExperienceRulesetUpgrade::installSchema();
        app(Ver250MonsterExperienceRulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The v14 to v15 monster experience migration is forward-only; restore the exact supported v14 backup and re-upgrade.',
        );
    }
};
