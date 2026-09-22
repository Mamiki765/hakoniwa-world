<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worlds', function (Blueprint $table): void {
            // Existing deployments initialize their actual calendar explicitly; completion times
            // cannot establish an origin when cron or a manual attempt was delayed.
            $table->timestampTz('turn_schedule_origin_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('The World turn calendar migration is forward-only.');
    }
};
