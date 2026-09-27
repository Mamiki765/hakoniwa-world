<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('underground_content_clear_progress', function (Blueprint $table): void {
            $table->timestampTz('first_cleared_at')->nullable();
        });

        // Only a surviving victory receipt's finished_at establishes the first-clear time.
        // A clear count alone cannot establish when the first victory occurred.
        DB::statement(<<<'SQL'
UPDATE underground_content_clear_progress AS progress
SET first_cleared_at = victories.first_finished_at
FROM (
    SELECT underground_profile_id, activity_key, MIN(finished_at) AS first_finished_at
    FROM underground_battles
    WHERE activity_type = 'exploration'
      AND result = 'victory'
      AND finished_at IS NOT NULL
      AND activity_key IN (
          'bahamul_beginner_1', 'bahamul_beginner_2',
          'bahamul_beginner_3', 'bahamul_intermediate_1'
      )
    GROUP BY underground_profile_id, activity_key
) AS victories
WHERE progress.underground_profile_id = victories.underground_profile_id
  AND progress.content_type = 'hunting_ground'
  AND progress.content_key = victories.activity_key
  AND progress.first_cleared_at IS NULL
SQL);

        if (DB::table('underground_content_clear_progress')
            ->where('content_type', 'hunting_ground')
            ->whereIn('content_key', [
                'bahamul_beginner_1', 'bahamul_beginner_2',
                'bahamul_beginner_3', 'bahamul_intermediate_1',
            ])
            ->where('actual_clear_count', '>', 0)
            ->whereNull('first_cleared_at')
            ->exists()) {
            throw new RuntimeException('An otherworld first-clear time has no surviving victory receipt.');
        }
    }

    public function down(): void
    {
        Schema::table('underground_content_clear_progress', function (Blueprint $table): void {
            $table->dropColumn('first_cleared_at');
        });
    }
};
