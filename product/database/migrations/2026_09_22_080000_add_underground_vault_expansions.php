<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('underground_profiles', function (Blueprint $table): void {
            $table->timestampTz('vault_expansion_purchased_at')->nullable();
            $table->timestampTz('resonance_expansion_purchased_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Purchased vault expansions must be preserved; this migration is forward-only.');
    }
};
