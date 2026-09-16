<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('underground_profiles', function (Blueprint $table): void {
            $table->timestampTz('trophy_shelf_purchased_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Trophy shelf ownership must be preserved; this migration is forward-only.');
    }
};
