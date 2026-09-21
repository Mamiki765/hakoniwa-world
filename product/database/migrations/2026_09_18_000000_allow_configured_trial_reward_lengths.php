<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE underground_owned_equipment DROP CONSTRAINT underground_owned_equipment_instance_check');
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
          AND source_reward_index >= 1
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
        DB::statement('ALTER TABLE underground_profiles DROP CONSTRAINT underground_profiles_stp_entitlement_check');
        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  ADD CONSTRAINT underground_profiles_stp_entitlement_check
  CHECK (
    growth_path_key IS NOT NULL
    OR unspent_stp + allocated_vitality_stp + allocated_might_stp
      + allocated_finesse_stp + allocated_spirit_stp + allocated_agility_stp = 0
  )
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Configured Underground reward lengths and persisted STP totals must be preserved; this migration is forward-only.',
        );
    }
};
