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
            $table->unsignedInteger('skill_points_total')->default(0);
            $table->unsignedInteger('skill_points_unspent')->default(0);
            $table->string('skill_tree_identity', 100)->nullable();
        });

        DB::statement(<<<'SQL'
UPDATE underground_profiles
   SET skill_points_total = 20,
       skill_points_unspent = 20,
       skill_tree_identity = 'secretary-underground-skill-tree-alpha-v1'
 WHERE growth_path_key IS NOT NULL
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  ADD CONSTRAINT underground_profiles_skill_points_check
  CHECK (
    skill_points_total >= 0
    AND skill_points_unspent >= 0
    AND skill_points_unspent <= skill_points_total
    AND (
      (
        growth_path_key IS NULL
        AND skill_points_total = 0
        AND skill_points_unspent = 0
        AND skill_tree_identity IS NULL
      )
      OR
      (
        growth_path_key IS NOT NULL
        AND skill_points_total >= 20
        AND skill_tree_identity IS NOT NULL
      )
    )
  )
SQL);

        Schema::create('underground_skill_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')
                ->constrained('underground_profiles')
                ->cascadeOnDelete();
            $table->string('node_key', 100);
            $table->unsignedSmallInteger('rank');
            $table->unsignedSmallInteger('active_slot')->nullable();
            $table->timestamps();

            $table->unique(['underground_profile_id', 'node_key'], 'underground_skill_profile_node_unique');
            $table->unique(['underground_profile_id', 'active_slot'], 'underground_skill_profile_slot_unique');
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_skill_allocations
  ADD CONSTRAINT underground_skill_allocations_rank_positive
  CHECK (rank >= 1),
  ADD CONSTRAINT underground_skill_allocations_active_slot_range
  CHECK (active_slot IS NULL OR active_slot BETWEEN 1 AND 5)
SQL);

        DB::statement('ALTER TABLE underground_intro_requests DROP CONSTRAINT underground_intro_requests_operation_check');
        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_requests
  ADD CONSTRAINT underground_intro_requests_operation_check
  CHECK (operation IN (
    'entry', 'advance', 'tutorial', 'shopkeeper_name', 'scripted_loss',
    'contract', 'growth_path', 'inn_rest', 'bank_transfer',
    'stp_allocate', 'skill_acquire', 'active_loadout'
  ))
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Underground status and skill progression is forward-only.');
    }
};
