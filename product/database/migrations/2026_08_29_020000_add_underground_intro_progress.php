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
        Schema::create('underground_intro_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')
                ->unique()
                ->constrained('underground_profiles')
                ->cascadeOnDelete();
            $table->string('stage', 32)->default('not_started');
            $table->string('shopkeeper_name', 255)->nullable();
            $table->boolean('special_loss_required')->nullable();
            $table->foreignId('tutorial_battle_id')
                ->nullable()
                ->unique()
                ->constrained('underground_battles')
                ->cascadeOnDelete();
            $table->foreignId('scripted_loss_battle_id')
                ->nullable()
                ->unique()
                ->constrained('underground_battles')
                ->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('underground_intro_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')
                ->constrained('underground_profiles')
                ->cascadeOnDelete();
            $table->uuid('request_id');
            $table->char('request_fingerprint', 64);
            $table->string('operation', 32);
            $table->string('resulting_stage', 32);
            $table->foreignId('underground_battle_id')
                ->nullable()
                ->constrained('underground_battles')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(
                ['underground_profile_id', 'request_id'],
                'underground_intro_requests_profile_request_unique',
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_progress
  ADD CONSTRAINT underground_intro_progress_stage_check
  CHECK (stage IN (
    'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
    'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming',
    'special_loss_pending', 'special_loss_complete', 'shop_explanation', 'underground_open'
  )),
  ADD CONSTRAINT underground_intro_progress_tutorial_check
  CHECK (
    (stage IN ('not_started', 'initial_descent', 'tutorial_ready') AND tutorial_battle_id IS NULL)
    OR
    (stage NOT IN ('not_started', 'initial_descent', 'tutorial_ready') AND tutorial_battle_id IS NOT NULL)
  ),
  ADD CONSTRAINT underground_intro_progress_naming_check
  CHECK (
    (stage IN (
      'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
      'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming'
    ) AND shopkeeper_name IS NULL AND special_loss_required IS NULL)
    OR
    (stage NOT IN (
      'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
      'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming'
    ) AND shopkeeper_name IS NOT NULL AND special_loss_required IS NOT NULL)
  ),
  ADD CONSTRAINT underground_intro_progress_special_loss_check
  CHECK (
    (special_loss_required IS DISTINCT FROM TRUE AND scripted_loss_battle_id IS NULL)
    OR
    (stage = 'special_loss_pending' AND special_loss_required = TRUE AND scripted_loss_battle_id IS NULL)
    OR
    (stage IN ('special_loss_complete', 'shop_explanation', 'underground_open')
      AND special_loss_required = TRUE AND scripted_loss_battle_id IS NOT NULL)
  )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_requests
  ADD CONSTRAINT underground_intro_requests_fingerprint_check
  CHECK (request_fingerprint ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT underground_intro_requests_operation_check
  CHECK (operation IN (
    'entry', 'advance', 'tutorial', 'shopkeeper_name', 'scripted_loss'
  )),
  ADD CONSTRAINT underground_intro_requests_stage_check
  CHECK (resulting_stage IN (
    'not_started', 'initial_descent', 'tutorial_ready', 'escape_pending',
    'returned_after_tutorial', 'shopkeeper_encounter', 'shopkeeper_naming',
    'special_loss_pending', 'special_loss_complete', 'shop_explanation', 'underground_open'
  ))
SQL);

        DB::statement('ALTER TABLE underground_battles DROP CONSTRAINT underground_battles_activity_type_check');
        DB::statement(<<<'SQL'
ALTER TABLE underground_battles
  ADD CONSTRAINT underground_battles_activity_type_check
  CHECK (activity_type IN ('exploration', 'trial', 'tutorial', 'story'))
SQL);
        DB::statement('ALTER TABLE underground_battles DROP CONSTRAINT underground_battles_trial_context_check');
        DB::statement(<<<'SQL'
ALTER TABLE underground_battles
  ADD CONSTRAINT underground_battles_trial_context_check
  CHECK (
    (activity_type = 'trial' AND trial_run_key IS NOT NULL AND trial_battle_index IS NOT NULL)
    OR
    (activity_type <> 'trial' AND trial_run_key IS NULL AND trial_battle_index IS NULL)
  )
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Underground intro persistence is forward-only.');
    }
};
