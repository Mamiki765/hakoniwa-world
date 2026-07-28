<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turn_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('target_turn');
            $table->foreignId('ruleset_version_id')->constrained()->restrictOnDelete();
            $table->char('random_seed', 64);
            $table->string('source', 16);
            $table->boolean('is_dry_run')->default(false);
            $table->string('status', 24);
            $table->unsignedInteger('attempt_count')->default(1);
            $table->jsonb('pipeline')->default('[]');
            $table->jsonb('phase_results')->default('[]');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->jsonb('failure_context')->default('{}');
            $table->timestamps();
            $table->index(['world_id', 'created_at']);
            $table->index(['world_id', 'status']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX turn_runs_world_target_live_unique '
            .'ON turn_runs (world_id, target_turn) WHERE is_dry_run = false',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('turn_runs');
    }
};
