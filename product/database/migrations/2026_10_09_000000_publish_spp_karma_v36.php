<?php

use App\Application\PowerEconomyUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        app(PowerEconomyUpgrade::class)->increaseSppSetupKarma();
    }

    public function down(): void
    {
        throw new RuntimeException('SPP KARMA changes are forward-only. Restore a reviewed backup instead of changing Turn provenance.');
    }
};
