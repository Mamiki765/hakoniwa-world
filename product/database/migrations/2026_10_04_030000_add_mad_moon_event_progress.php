<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('underground_profiles', function (Blueprint $table): void {
            $table->timestampTz('mad_moon_unlocked_at')->nullable();
            $table->timestampTz('mad_moon_intro_completed_at')->nullable();
            $table->timestampTz('mad_moon_cleared_at')->nullable();
            $table->timestampTz('mad_moon_victory_scene_completed_at')->nullable();
        });
        DB::statement('ALTER TABLE underground_battles DROP CONSTRAINT underground_battles_activity_type_check');
        DB::statement("ALTER TABLE underground_battles ADD CONSTRAINT underground_battles_activity_type_check CHECK (activity_type IN ('exploration', 'trial', 'tutorial', 'story', 'playtest', 'guide_duel', 'event_battle'))");
    }

    public function down(): void
    {
        // Existing event receipts deliberately prevent an unsafe rollback of their type.
        DB::statement('ALTER TABLE underground_battles DROP CONSTRAINT underground_battles_activity_type_check');
        DB::statement("ALTER TABLE underground_battles ADD CONSTRAINT underground_battles_activity_type_check CHECK (activity_type IN ('exploration', 'trial', 'tutorial', 'story', 'playtest', 'guide_duel'))");
        Schema::table('underground_profiles', function (Blueprint $table): void {
            $table->dropColumn(['mad_moon_unlocked_at', 'mad_moon_intro_completed_at', 'mad_moon_cleared_at', 'mad_moon_victory_scene_completed_at']);
        });
    }
};
