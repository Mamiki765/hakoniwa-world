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
        Schema::create('underground_receipt_rollups', function (Blueprint $table): void {
            $table->foreignId('underground_profile_id')->constrained('underground_profiles')->cascadeOnDelete();
            $table->string('stream', 16);
            $table->unsignedBigInteger('verified_through_id');
            $table->unsignedSmallInteger('aggregation_version');
            foreach ([
                'receipt_count', 'battle_count', 'victory_count',
                'damage_dealt_sum', 'damage_dealt_known_count',
                'damage_received_sum', 'damage_received_known_count', 'skip_tickets_used',
            ] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            $table->jsonb('last_batch');
            $table->timestampTz('verified_at');
            $table->timestampsTz();
            $table->primary(['underground_profile_id', 'stream'], 'underground_receipt_rollups_profile_stream_primary');
        });
        DB::statement(<<<'SQL'
ALTER TABLE underground_receipt_rollups
  ADD CONSTRAINT underground_receipt_rollups_stream_check CHECK (stream IN ('battle', 'skip', 'bulk_skip')),
  ADD CONSTRAINT underground_receipt_rollups_counts_check CHECK (
    verified_through_id > 0 AND aggregation_version > 0
    AND receipt_count > 0 AND battle_count BETWEEN 0 AND receipt_count
    AND victory_count BETWEEN 0 AND battle_count
    AND damage_dealt_known_count BETWEEN 0 AND battle_count
    AND damage_received_known_count BETWEEN 0 AND battle_count
    AND damage_dealt_sum >= 0 AND damage_received_sum >= 0 AND skip_tickets_used >= 0
  )
SQL);
        // Prefix scans must not sort a profile's entire lifetime history per batch.
        foreach (['underground_battles', 'underground_skip_settlements', 'underground_skip_batches'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->index(['underground_profile_id', 'id'], $name.'_rollup_cursor_index');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Receipt rollups are permanent progress; use a reviewed forward migration.');
    }
};
