<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('underground_profiles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('awakening_gauge')->default(0);
            $table->string('awakening_message', 100)->nullable();
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  ADD CONSTRAINT underground_profiles_awakening_gauge_check
  CHECK (awakening_gauge >= 0 AND awakening_gauge <= 1000),
  ADD CONSTRAINT underground_profiles_awakening_message_check
  CHECK (
    awakening_message IS NULL
    OR (
      char_length(awakening_message) BETWEEN 1 AND 100
      AND awakening_message !~ E'[\\r\\n]'
    )
  )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_requests
  DROP CONSTRAINT underground_intro_requests_operation_check,
  ADD CONSTRAINT underground_intro_requests_operation_check
  CHECK (operation IN (
    'entry', 'advance', 'tutorial', 'shopkeeper_name', 'scripted_loss',
    'contract', 'growth_path', 'inn_rest', 'bank_transfer', 'playtest',
    'stp_allocate', 'skill_acquire', 'active_loadout', 'awakening_message',
    'equipment_purchase', 'equipment_sell', 'equipment_equip', 'equipment_unequip'
  ))
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_requests
  DROP CONSTRAINT underground_intro_requests_operation_check,
  ADD CONSTRAINT underground_intro_requests_operation_check
  CHECK (operation IN (
    'entry', 'advance', 'tutorial', 'shopkeeper_name', 'scripted_loss',
    'contract', 'growth_path', 'inn_rest', 'bank_transfer', 'playtest',
    'stp_allocate', 'skill_acquire', 'active_loadout',
    'equipment_purchase', 'equipment_sell', 'equipment_equip', 'equipment_unequip'
  ))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  DROP CONSTRAINT underground_profiles_awakening_message_check,
  DROP CONSTRAINT underground_profiles_awakening_gauge_check
SQL);

        Schema::table('underground_profiles', function (Blueprint $table): void {
            $table->dropColumn(['awakening_message', 'awakening_gauge']);
        });
    }
};
