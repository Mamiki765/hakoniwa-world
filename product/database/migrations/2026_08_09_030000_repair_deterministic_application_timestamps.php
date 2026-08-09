<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->repair('audit_events', 'occurred_at', 'created_at');
            $this->repair('moderation_records', 'occurred_at', 'created_at');
            $this->repair('map_chunks', 'generated_at', 'created_at');
            $this->repair('world_generation_runs', 'completed_at', 'created_at', "status = 'completed'");
            $this->repair('nation_command_queue_items', 'queued_at', 'created_at');
            $this->repair('nation_command_queue_items', 'cancelled_at', 'updated_at', "status = 'cancelled'");
            $this->repair('nation_command_queue_items', 'execution_completed_at', 'updated_at', "status IN ('queued', 'completed')");
            $this->repair('nation_command_queue_items', 'execution_failed_at', 'updated_at', "status = 'failed'");
            $this->repair('turn_runs', 'completed_at', 'updated_at', "status IN ('dry_run', 'completed', 'failed', 'blocked')");
            $this->repair('monster_instances', 'removed_at', 'updated_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The deterministic application timestamp repair is forward-only and cannot be rolled back safely.',
        );
    }

    private function repair(string $table, string $timestampColumn, string $anchorColumn, ?string $extraPredicate = null): void
    {
        $predicate = $extraPredicate === null ? '' : " AND {$extraPredicate}";
        DB::statement(<<<SQL
            UPDATE {$table}
            SET {$timestampColumn} = {$anchorColumn} AT TIME ZONE 'UTC'
            WHERE {$timestampColumn} IS NOT NULL
              AND {$anchorColumn} IS NOT NULL
              AND {$timestampColumn} = {$anchorColumn} AT TIME ZONE 'Asia/Tokyo'
              {$predicate}
            SQL);
    }
};
