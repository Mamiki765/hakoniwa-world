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
        // Content-addressed files may be reused by more than one visual role.
        // Retention checks every remaining slot before deleting the file.
        Schema::table('secretary_images', function (Blueprint $table): void {
            $table->dropUnique('secretary_images_path_unique');
        });

        // Production has historical legacy portraits without a credit. Keep
        // that exact metadata during migration; new uploads remain required
        // to provide a credit at the request/service boundary.
        DB::statement('ALTER TABLE secretary_images ALTER COLUMN credit DROP NOT NULL');

        DB::statement(<<<'SQL'
INSERT INTO secretary_images (
  secretary_id, slot, path, mime_type, creation_method, credit,
  created_at, updated_at
)
SELECT
  secretaries.id,
  'full_body',
  secretaries.main_image_path,
  secretaries.main_image_mime_type,
  secretaries.main_image_creation_method,
  secretaries.main_image_credit,
  COALESCE(secretaries.main_image_updated_at, CURRENT_TIMESTAMP),
  COALESCE(secretaries.main_image_updated_at, CURRENT_TIMESTAMP)
FROM secretaries
WHERE secretaries.main_image_path IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM secretary_images
    WHERE secretary_images.secretary_id = secretaries.id
      AND secretary_images.slot = 'full_body'
  )
ON CONFLICT DO NOTHING
SQL);

        Schema::create('underground_skip_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')->constrained('underground_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('request_fingerprint', 64);
            $table->string('skip_identity', 100);
            $table->string('content_type', 24);
            $table->string('content_key', 64);
            $table->string('content_identity', 128);
            $table->unsignedInteger('execution_count');
            $table->unsignedBigInteger('ticket_cost');
            $table->unsignedBigInteger('xp_awarded');
            $table->unsignedBigInteger('shard_awarded');
            $table->integer('combat_level_before');
            $table->integer('combat_level_after');
            $table->unsignedBigInteger('combat_xp_before');
            $table->unsignedBigInteger('combat_xp_after');
            $table->unsignedBigInteger('shard_balance_before');
            $table->unsignedBigInteger('shard_balance_after');
            $table->jsonb('reward_snapshot');
            $table->timestampTz('settled_at');
            $table->timestamps();
            $table->unique(
                ['underground_profile_id', 'request_id'],
                'underground_skip_batches_profile_request_unique',
            );
            $table->index(['underground_profile_id', 'content_type', 'content_key']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_skip_batches
  ADD CONSTRAINT underground_skip_batches_fingerprint_check
  CHECK (request_fingerprint ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT underground_skip_batches_cost_check
  CHECK (
    execution_count >= 1
    AND ticket_cost >= 1
    AND content_type IN ('hunting_ground', 'trial')
  ),
  ADD CONSTRAINT underground_skip_batches_progression_check
  CHECK (
    combat_level_before >= 1
    AND combat_level_after >= combat_level_before
    AND combat_xp_after >= combat_xp_before
    AND shard_balance_after >= shard_balance_before
  )
SQL);

        Schema::table('user_skip_ticket_ledger', function (Blueprint $table): void {
            $table->foreignId('underground_skip_batch_id')->nullable()
                ->constrained('underground_skip_batches')->restrictOnDelete();
            $table->unique('underground_skip_batch_id', 'user_skip_ticket_ledger_skip_batch_unique');
        });
        DB::statement(<<<'SQL'
ALTER TABLE user_skip_ticket_ledger
  ADD CONSTRAINT user_skip_ticket_ledger_skip_source_check
  CHECK (
    underground_skip_settlement_id IS NULL
    OR underground_skip_batch_id IS NULL
  )
SQL);

        DB::statement('ALTER TABLE underground_owned_equipment DROP CONSTRAINT underground_owned_equipment_instance_check');
        DB::statement('ALTER TABLE underground_owned_equipment ALTER COLUMN source_reward_index TYPE bigint');
        Schema::table('underground_owned_equipment', function (Blueprint $table): void {
            $table->foreignId('source_skip_batch_id')->nullable()
                ->constrained('underground_skip_batches')->cascadeOnDelete();
            $table->unique(
                ['source_skip_batch_id', 'source_reward_index'],
                'underground_equipment_source_skip_batch_reward_unique',
            );
        });

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
      AND source_skip_batch_id IS NULL
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
          AND source_skip_batch_id IS NULL
          AND source_reward_index IS NULL
        )
        OR
        (
          source_battle_id IS NULL
          AND source_skip_settlement_id IS NOT NULL
          AND source_skip_batch_id IS NULL
          AND source_reward_index BETWEEN 1 AND 10
        )
        OR
        (
          source_battle_id IS NULL
          AND source_skip_settlement_id IS NULL
          AND source_skip_batch_id IS NOT NULL
          AND source_reward_index >= 1
        )
      )
    )
  )
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.8.1 Secretary image and Underground bulk-skip migration is forward-only.',
        );
    }
};
