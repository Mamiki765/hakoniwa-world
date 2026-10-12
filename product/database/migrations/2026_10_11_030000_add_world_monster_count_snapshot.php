<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('worlds', function (Blueprint $table): void {
            $table->jsonb('monster_count_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Monster count snapshots are forward-only. Restore a reviewed backup instead.');
    }
};
