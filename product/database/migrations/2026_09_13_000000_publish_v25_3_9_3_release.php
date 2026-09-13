<?php

use App\Application\Ver393RulesetUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(Ver393RulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException('The v3.9.3 Ruleset v25 migration is forward-only.');
    }
};
