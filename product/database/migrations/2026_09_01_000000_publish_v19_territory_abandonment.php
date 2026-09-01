<?php

use App\Application\Ver310TerritoryAbandonmentRulesetUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        app(Ver310TerritoryAbandonmentRulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException('Ruleset v19 publication is forward-only.');
    }
};
