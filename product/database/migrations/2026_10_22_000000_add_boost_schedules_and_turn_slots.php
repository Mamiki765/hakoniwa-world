<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worlds', function (Blueprint $table): void {
            $table->timestampTz('next_regular_turn_at')->nullable();
        });
        // Before boosts exist the old calendar remains the migration baseline.
        DB::statement("UPDATE worlds SET next_regular_turn_at = turn_schedule_origin_at + current_turn * interval '2 hours' WHERE turn_schedule_origin_at IS NOT NULL");
        Schema::create('boost_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained();
            $table->uuid('request_id')->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->string('kind', 24);
            $table->string('title', 120);
            $table->jsonb('schedule');
            $table->timestampsTz();
        });
        Schema::create('boost_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boost_series_id')->constrained('boost_series');
            $table->foreignId('world_id')->constrained();
            $table->unsignedSmallInteger('occurrence_number');
            $table->string('kind', 24);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->unique(['boost_series_id', 'occurrence_number']);
            $table->index(['world_id', 'kind', 'starts_at', 'ends_at'], 'boost_occurrences_window');
        });
        DB::statement("ALTER TABLE boost_occurrences ADD CONSTRAINT boost_occurrence_period CHECK (ends_at > starts_at), ADD CONSTRAINT boost_occurrence_kind CHECK (kind IN ('turn_acceleration', 'hunting_ground'))");
        Schema::create('turn_execution_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained();
            $table->timestampTz('scheduled_at');
            $table->string('kind', 24);
            $table->foreignId('boost_occurrence_id')->nullable()->constrained();
            $table->foreignId('turn_run_id')->unique()->constrained();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['world_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Boost scheduling is forward-only.');
    }
};
