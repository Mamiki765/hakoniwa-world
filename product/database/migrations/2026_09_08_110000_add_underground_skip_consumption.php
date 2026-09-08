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
        Schema::create('underground_content_clear_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')->constrained('underground_profiles')->cascadeOnDelete();
            $table->string('content_type', 24);
            $table->string('content_key', 64);
            $table->unsignedInteger('actual_clear_count')->default(0);
            $table->unsignedInteger('total_clear_count')->default(0);
            $table->timestamps();
            $table->unique(
                ['underground_profile_id', 'content_type', 'content_key'],
                'underground_content_clear_progress_identity_unique',
            );
        });

        Schema::create('underground_skip_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')->constrained('underground_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('request_fingerprint', 64);
            $table->string('skip_identity', 100);
            $table->string('content_type', 24);
            $table->string('content_key', 64);
            $table->string('content_identity', 128);
            $table->unsignedSmallInteger('ticket_cost');
            $table->unsignedInteger('xp_awarded');
            $table->unsignedBigInteger('shard_awarded');
            $table->integer('combat_level_before');
            $table->integer('combat_level_after');
            $table->unsignedBigInteger('combat_xp_before');
            $table->unsignedBigInteger('combat_xp_after');
            $table->unsignedBigInteger('shard_balance_before');
            $table->unsignedBigInteger('shard_balance_after');
            $table->unsignedInteger('private_seed');
            $table->jsonb('reward_snapshot');
            $table->timestampTz('settled_at');
            $table->timestamps();
            $table->unique(
                ['underground_profile_id', 'request_id'],
                'underground_skip_settlements_profile_request_unique',
            );
            $table->index(['underground_profile_id', 'content_type', 'content_key']);
        });

        Schema::table('user_skip_ticket_ledger', function (Blueprint $table): void {
            $table->foreignId('underground_skip_settlement_id')->nullable()
                ->constrained('underground_skip_settlements')->restrictOnDelete();
            $table->unique('underground_skip_settlement_id', 'user_skip_ticket_ledger_skip_unique');
        });

        DB::statement('ALTER TABLE underground_owned_equipment DROP CONSTRAINT underground_owned_equipment_instance_check');
        Schema::table('underground_owned_equipment', function (Blueprint $table): void {
            $table->foreignId('source_skip_settlement_id')->nullable()
                ->constrained('underground_skip_settlements')->cascadeOnDelete();
            $table->unsignedSmallInteger('source_reward_index')->nullable();
            $table->unique(
                ['source_skip_settlement_id', 'source_reward_index'],
                'underground_equipment_source_skip_reward_unique',
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_content_clear_progress
  ADD CONSTRAINT underground_content_clear_progress_type_check
  CHECK (content_type IN ('hunting_ground', 'trial')),
  ADD CONSTRAINT underground_content_clear_progress_count_check
  CHECK (actual_clear_count >= 0 AND total_clear_count >= actual_clear_count)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE underground_skip_settlements
  ADD CONSTRAINT underground_skip_settlements_fingerprint_check
  CHECK (request_fingerprint ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT underground_skip_settlements_type_cost_check
  CHECK (
    (content_type = 'hunting_ground' AND ticket_cost = 1)
    OR (content_type = 'trial' AND ticket_cost = 10)
  ),
  ADD CONSTRAINT underground_skip_settlements_progression_check
  CHECK (
    combat_level_before >= 1
    AND combat_level_after >= combat_level_before
    AND combat_xp_after >= combat_xp_before
    AND shard_balance_after >= shard_balance_before
  )
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment
  ADD CONSTRAINT underground_owned_equipment_instance_check
  CHECK (
    (
      instance_kind = 'fixed'
      AND instance_identity IS NULL
      AND generator_identity IS NULL
      AND generated_payload IS NULL
      AND source_battle_id IS NULL
      AND source_skip_settlement_id IS NULL
      AND source_reward_index IS NULL
    )
    OR
    (
      instance_kind = 'generated'
      AND instance_identity IS NOT NULL
      AND generator_identity IS NOT NULL
      AND generated_payload IS NOT NULL
      AND grant_key IS NOT NULL
      AND (
        (
          source_battle_id IS NOT NULL
          AND source_skip_settlement_id IS NULL
          AND source_reward_index IS NULL
        )
        OR
        (
          source_battle_id IS NULL
          AND source_skip_settlement_id IS NOT NULL
          AND source_reward_index BETWEEN 1 AND 10
        )
      )
    )
  )
SQL);

        DB::statement(<<<'SQL'
INSERT INTO underground_content_clear_progress (
  underground_profile_id, content_type, content_key,
  actual_clear_count, total_clear_count, created_at, updated_at
)
SELECT
  underground_profile_id,
  CASE activity_type WHEN 'exploration' THEN 'hunting_ground' ELSE 'trial' END,
  activity_key,
  COUNT(*)::integer,
  COUNT(*)::integer,
  CURRENT_TIMESTAMP,
  CURRENT_TIMESTAMP
FROM underground_battles
WHERE result = 'victory'
  AND (
    activity_type = 'exploration'
    OR (activity_type = 'trial' AND snapshot ->> 'trial_status' = 'cleared')
  )
GROUP BY underground_profile_id, activity_type, activity_key
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('The 3.8.0 Underground skip migration is forward-only.');
    }
};
