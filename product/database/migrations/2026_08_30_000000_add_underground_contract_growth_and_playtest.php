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
            $table->timestampTz('underground_contract_completed_at')->nullable();
            $table->string('growth_path_key', 32)->nullable();
            $table->string('growth_path_identity', 64)->nullable();
            $table->timestampTz('growth_path_selected_at')->nullable();
        });

        Schema::table('underground_intro_progress', function (Blueprint $table): void {
            $table->string('branch_identity', 32)->nullable();
        });

        DB::statement(<<<'SQL'
UPDATE underground_intro_progress
   SET branch_identity = CASE
       WHEN special_loss_required = TRUE THEN 'legacy_temporary'
       ELSE 'normal'
   END
 WHERE shopkeeper_name IS NOT NULL
   AND branch_identity IS NULL
SQL);

        DB::statement(<<<'SQL'
UPDATE underground_intro_progress
   SET stage = 'shop_explanation'
 WHERE stage = 'underground_open'
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  ADD CONSTRAINT underground_profiles_growth_path_check
  CHECK (
    (growth_path_key IS NULL AND growth_path_identity IS NULL AND growth_path_selected_at IS NULL)
    OR
    (
      underground_contract_completed_at IS NOT NULL
      AND growth_path_key IN ('martial_red', 'guardianship_blue', 'blessing_green', 'free_black')
      AND growth_path_identity = 'secretary-underground-growth-alpha-v1'
      AND growth_path_selected_at IS NOT NULL
      AND growth_path_selected_at >= underground_contract_completed_at
    )
  )
SQL);

        DB::statement('ALTER TABLE underground_intro_progress DROP CONSTRAINT underground_intro_progress_stage_check');
        DB::statement('ALTER TABLE underground_intro_progress DROP CONSTRAINT underground_intro_progress_naming_check');
        DB::statement('ALTER TABLE underground_intro_progress DROP CONSTRAINT underground_intro_progress_special_loss_check');
        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_progress
  ADD CONSTRAINT underground_intro_progress_stage_check
  CHECK (stage IN (
    'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
    'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming',
    'special_loss_pending', 'special_loss_complete', 'shop_explanation',
    'contract_ready', 'crystal_selection', 'growth_path_selected', 'underground_open'
  )),
  ADD CONSTRAINT underground_intro_progress_branch_identity_check
  CHECK (branch_identity IS NULL OR branch_identity IN ('normal', 'legacy_temporary', 'true_name')),
  ADD CONSTRAINT underground_intro_progress_naming_check
  CHECK (
    (stage IN (
      'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
      'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming'
    ) AND shopkeeper_name IS NULL AND special_loss_required IS NULL AND branch_identity IS NULL)
    OR
    (stage NOT IN (
      'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
      'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming'
    ) AND shopkeeper_name IS NOT NULL AND special_loss_required IS NOT NULL AND branch_identity IS NOT NULL)
  ),
  ADD CONSTRAINT underground_intro_progress_special_loss_check
  CHECK (
    (branch_identity = 'normal' AND special_loss_required = FALSE AND scripted_loss_battle_id IS NULL)
    OR
    (
      branch_identity IN ('legacy_temporary', 'true_name')
      AND special_loss_required = TRUE
      AND (
        (stage = 'special_loss_pending' AND scripted_loss_battle_id IS NULL)
        OR
        (stage IN (
          'special_loss_complete', 'shop_explanation', 'contract_ready',
          'crystal_selection', 'growth_path_selected', 'underground_open'
        ) AND scripted_loss_battle_id IS NOT NULL)
      )
    )
    OR
    (branch_identity IS NULL AND special_loss_required IS NULL AND scripted_loss_battle_id IS NULL)
  )
SQL);

        DB::statement('ALTER TABLE underground_intro_requests DROP CONSTRAINT underground_intro_requests_operation_check');
        DB::statement('ALTER TABLE underground_intro_requests DROP CONSTRAINT underground_intro_requests_stage_check');
        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_requests
  ADD CONSTRAINT underground_intro_requests_operation_check
  CHECK (operation IN (
    'entry', 'advance', 'tutorial', 'shopkeeper_name', 'scripted_loss', 'contract', 'growth_path'
  )),
  ADD CONSTRAINT underground_intro_requests_stage_check
  CHECK (resulting_stage IN (
    'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
    'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming',
    'special_loss_pending', 'special_loss_complete', 'shop_explanation',
    'contract_ready', 'crystal_selection', 'growth_path_selected', 'underground_open'
  ))
SQL);

        DB::statement('ALTER TABLE underground_battles DROP CONSTRAINT underground_battles_activity_type_check');
        DB::statement(<<<'SQL'
ALTER TABLE underground_battles
  ADD CONSTRAINT underground_battles_activity_type_check
  CHECK (activity_type IN ('exploration', 'trial', 'tutorial', 'story', 'playtest'))
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Underground contract and growth persistence is forward-only.');
    }
};
