<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE underground_battles DROP CONSTRAINT underground_battles_activity_type_check, ADD CONSTRAINT underground_battles_activity_type_check CHECK (activity_type IN ('exploration', 'trial', 'tutorial', 'story', 'playtest', 'guide_duel'))");
        DB::statement("ALTER TABLE underground_content_clear_progress DROP CONSTRAINT underground_content_clear_progress_type_check, ADD CONSTRAINT underground_content_clear_progress_type_check CHECK (content_type IN ('hunting_ground', 'trial', 'guide_duel'))");
    }

    public function down(): void
    {
        throw new RuntimeException('Guide duel history and first victories must be preserved.');
    }
};
