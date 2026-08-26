<?php

use App\Application\Ver270SecretaryItemRulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        app(Ver270SecretaryItemRulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new \RuntimeException('Ruleset v17 publication is forward-only.');
    }
};
