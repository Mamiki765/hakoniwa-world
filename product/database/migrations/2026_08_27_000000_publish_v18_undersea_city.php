<?php

use App\Application\Ver280UnderseaCityRulesetUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        app(Ver280UnderseaCityRulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException('Ruleset v18 publication is forward-only.');
    }
};
