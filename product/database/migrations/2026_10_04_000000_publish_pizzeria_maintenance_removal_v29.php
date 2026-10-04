<?php

use App\Application\PowerEconomyUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        app(PowerEconomyUpgrade::class)->removePizzeriaMaintenance();
    }

    public function down(): void
    {
        throw new RuntimeException('Pizzeria maintenance removal is forward-only. Restore a reviewed backup instead of rolling back player assets.');
    }
};
