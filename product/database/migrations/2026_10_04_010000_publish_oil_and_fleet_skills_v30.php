<?php

use App\Application\PowerEconomyUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::create('oil_discovery_backfills', function (Blueprint $table): void {
            $table->foreignId('world_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('cutoff_audit_id');
            $table->unsignedBigInteger('cutoff_turn');
            $table->timestamp('applied_at')->nullable();
        });
        // Capture the old/new XP boundary only; historical XP is never applied here.
        app(PowerEconomyUpgrade::class)->enableOilAndFleetSkills();
    }

    public function down(): void
    {
        throw new RuntimeException('Oil and fleet skills are forward-only. Restore a reviewed backup instead of resetting progression.');
    }
};
