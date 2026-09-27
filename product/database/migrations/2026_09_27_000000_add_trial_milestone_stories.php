<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('underground_trial_progress', function (Blueprint $table): void {
            $table->jsonb('first_milestone_stories')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('underground_trial_progress', function (Blueprint $table): void {
            $table->dropColumn('first_milestone_stories');
        });
    }
};
