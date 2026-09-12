<?php

use App\Application\Ver392RulesetUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(Ver392RulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException('The v3.9.2 Ruleset v24 migration is forward-only.');
    }
};
