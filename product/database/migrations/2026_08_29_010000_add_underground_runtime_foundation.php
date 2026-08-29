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
        Schema::table('underground_profiles', function (Blueprint $table): void {
            $table->integer('combat_level')->default(1);
            $table->bigInteger('combat_xp')->default(0);
            $table->bigInteger('shard_balance')->default(0);
            $table->timestampTz('next_battle_at')->nullable();
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  ADD CONSTRAINT underground_profiles_combat_level_positive
  CHECK (combat_level >= 1),
  ADD CONSTRAINT underground_profiles_combat_xp_non_negative
  CHECK (combat_xp >= 0),
  ADD CONSTRAINT underground_profiles_shard_balance_non_negative
  CHECK (shard_balance >= 0)
SQL);

        Schema::create('underground_trial_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')->constrained('underground_profiles')->cascadeOnDelete();
            $table->string('trial_key', 64);
            $table->timestampTz('unlocked_at');
            $table->timestampTz('first_cleared_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['underground_profile_id', 'trial_key'],
                'underground_trial_progress_profile_trial_unique',
            );
        });

        Schema::create('underground_trial_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')
                ->unique()
                ->constrained('underground_profiles')
                ->cascadeOnDelete();
            $table->uuid('run_key')->unique();
            $table->string('trial_key', 64);
            $table->unsignedSmallInteger('next_battle_index')->default(1);
            $table->string('status', 16);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();
            $table->index(
                ['underground_profile_id', 'status'],
                'underground_trial_runs_profile_status_index',
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_trial_runs
  ADD CONSTRAINT underground_trial_runs_status_check
  CHECK (status IN ('active', 'withdrawn', 'defeated', 'cleared')),
  ADD CONSTRAINT underground_trial_runs_next_battle_index_positive
  CHECK (next_battle_index >= 1)
SQL);

        Schema::create('underground_battles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')->constrained('underground_profiles')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->char('request_fingerprint', 64);
            $table->string('runtime_identity', 64);
            $table->string('activity_type', 16);
            $table->string('activity_key', 64);
            $table->string('encounter_key', 64);
            $table->uuid('trial_run_key')->nullable();
            $table->unsignedSmallInteger('trial_battle_index')->nullable();
            $table->string('result', 16);
            $table->unsignedSmallInteger('rounds');
            $table->unsignedInteger('xp_awarded')->default(0);
            $table->bigInteger('shard_delta')->default(0);
            $table->integer('combat_level_before');
            $table->integer('combat_level_after');
            $table->bigInteger('combat_xp_before');
            $table->bigInteger('combat_xp_after');
            $table->bigInteger('shard_balance_before');
            $table->bigInteger('shard_balance_after');
            $table->unsignedInteger('private_seed');
            $table->jsonb('snapshot');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');
            $table->timestamps();
            $table->unique(
                ['underground_profile_id', 'request_id'],
                'underground_battles_profile_request_unique',
            );
            $table->index(
                ['underground_profile_id', 'finished_at'],
                'underground_battles_profile_finished_at_index',
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_battles
  ADD CONSTRAINT underground_battles_request_fingerprint_check
  CHECK (request_fingerprint ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT underground_battles_activity_type_check
  CHECK (activity_type IN ('exploration', 'trial')),
  ADD CONSTRAINT underground_battles_result_check
  CHECK (result IN ('victory', 'defeat', 'withdrawal')),
  ADD CONSTRAINT underground_battles_rounds_range
  CHECK (rounds >= 1 AND rounds <= 100),
  ADD CONSTRAINT underground_battles_xp_awarded_non_negative
  CHECK (xp_awarded >= 0),
  ADD CONSTRAINT underground_battles_private_seed_range
  CHECK (private_seed >= 0 AND private_seed <= 2147483647),
  ADD CONSTRAINT underground_battles_trial_battle_index_positive
  CHECK (trial_battle_index IS NULL OR trial_battle_index >= 1),
  ADD CONSTRAINT underground_battles_trial_context_check
  CHECK (
    (activity_type = 'trial' AND trial_run_key IS NOT NULL AND trial_battle_index IS NOT NULL)
    OR
    (activity_type = 'exploration' AND trial_run_key IS NULL AND trial_battle_index IS NULL)
  ),
  ADD CONSTRAINT underground_battles_combat_level_before_positive
  CHECK (combat_level_before >= 1),
  ADD CONSTRAINT underground_battles_combat_level_after_positive
  CHECK (combat_level_after >= 1),
  ADD CONSTRAINT underground_battles_combat_xp_before_non_negative
  CHECK (combat_xp_before >= 0),
  ADD CONSTRAINT underground_battles_combat_xp_after_non_negative
  CHECK (combat_xp_after >= 0),
  ADD CONSTRAINT underground_battles_shard_balance_before_non_negative
  CHECK (shard_balance_before >= 0),
  ADD CONSTRAINT underground_battles_shard_balance_after_non_negative
  CHECK (shard_balance_after >= 0)
SQL);

        Schema::create('underground_battle_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_battle_id')
                ->unique()
                ->constrained('underground_battles')
                ->cascadeOnDelete();
            $table->jsonb('actions');
            $table->timestampTz('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Underground runtime persistence is forward-only.');
    }
};
