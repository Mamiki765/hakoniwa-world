<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  DROP CONSTRAINT underground_profiles_skill_points_check,
  ADD CONSTRAINT underground_profiles_skill_points_check CHECK (
    skill_points_total >= 0 AND skill_points_unspent >= 0
    AND skill_points_unspent <= skill_points_total
    AND ((growth_path_key IS NULL AND skill_points_total = 0
          AND skill_points_unspent = 0 AND skill_tree_identity IS NULL)
      OR (growth_path_key IS NOT NULL AND skill_tree_identity IS NOT NULL))
  )
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE user_daily_login_claims
  DROP CONSTRAINT user_daily_login_award_check,
  ADD CONSTRAINT user_daily_login_award_check CHECK (
    paradox_awarded > 0 AND skip_tickets_awarded > 0
  )
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE user_daily_quest_progress
  DROP CONSTRAINT user_daily_quest_progress_value_check,
  ADD CONSTRAINT user_daily_quest_progress_value_check CHECK (
    progress <= target AND target > 0
    AND ((completed_at IS NULL AND paradox_awarded = 0)
      OR (completed_at IS NOT NULL AND progress = target AND paradox_awarded > 0))
  )
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE monster_definitions
  DROP CONSTRAINT monster_definitions_hp_check,
  ADD CONSTRAINT monster_definitions_hp_check CHECK (
    base_hp >= 1 AND hp_variation >= 0 AND base_hp + hp_variation <= 32767
  )
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE auction_listings
  DROP CONSTRAINT auction_listings_turn_check,
  ADD CONSTRAINT auction_listings_turn_check CHECK (
    duration_turns > 0 AND ends_turn = started_turn + duration_turns
  )
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE secretary_lending_daily_rewards
  DROP CONSTRAINT secretary_lending_daily_ticket_cap_check,
  ADD CONSTRAINT secretary_lending_daily_ticket_cap_check CHECK (tickets_awarded >= 0)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE secretary_lending_daily_rewards DROP CONSTRAINT secretary_lending_daily_ticket_cap_check, ADD CONSTRAINT secretary_lending_daily_ticket_cap_check CHECK (tickets_awarded BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE auction_listings DROP CONSTRAINT auction_listings_turn_check, ADD CONSTRAINT auction_listings_turn_check CHECK (duration_turns BETWEEN 3 AND 84 AND ends_turn = started_turn + duration_turns)');
        DB::statement('ALTER TABLE monster_definitions DROP CONSTRAINT monster_definitions_hp_check, ADD CONSTRAINT monster_definitions_hp_check CHECK (base_hp >= 1 AND hp_variation <= 18 AND base_hp + hp_variation <= 65535)');
        DB::statement('ALTER TABLE user_daily_quest_progress DROP CONSTRAINT user_daily_quest_progress_value_check, ADD CONSTRAINT user_daily_quest_progress_value_check CHECK (progress <= target AND target > 0 AND paradox_awarded IN (0, 5) AND ((completed_at IS NULL AND paradox_awarded = 0) OR (completed_at IS NOT NULL AND progress = target AND paradox_awarded = 5)))');
        DB::statement('ALTER TABLE user_daily_login_claims DROP CONSTRAINT user_daily_login_award_check, ADD CONSTRAINT user_daily_login_award_check CHECK (paradox_awarded = 10 AND skip_tickets_awarded = 50)');
        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  DROP CONSTRAINT underground_profiles_skill_points_check,
  ADD CONSTRAINT underground_profiles_skill_points_check CHECK (
    skill_points_total >= 0 AND skill_points_unspent >= 0
    AND skill_points_unspent <= skill_points_total
    AND ((growth_path_key IS NULL AND skill_points_total = 0
          AND skill_points_unspent = 0 AND skill_tree_identity IS NULL)
      OR (growth_path_key IS NOT NULL AND skill_points_total >= 20
          AND skill_tree_identity IS NOT NULL))
  )
SQL);
    }
};
