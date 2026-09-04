<?php

use App\Application\Ver350RulesetUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        app(Ver350RulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException('The ver 3.5.0 Surface Ruleset migration is forward-only.');
    }
};
