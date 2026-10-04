<?php

use App\Application\PowerEconomyUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        app(PowerEconomyUpgrade::class)->apply();
    }

    public function down(): void
    {
        throw new RuntimeException('Power publication is forward-only. Restore a reviewed backup instead of rolling back player assets.');
    }
};
