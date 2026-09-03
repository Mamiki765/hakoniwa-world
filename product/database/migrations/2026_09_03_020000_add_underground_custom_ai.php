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
  ADD COLUMN custom_ai_rules jsonb,
  ADD CONSTRAINT underground_profiles_custom_ai_rules_check
  CHECK (
    custom_ai_rules IS NULL
    OR (jsonb_typeof(custom_ai_rules) = 'array' AND jsonb_array_length(custom_ai_rules) <= 16)
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
    'equipment_purchase', 'equipment_sell', 'equipment_equip', 'equipment_unequip',
    'respec', 'equipment_bulk_sell', 'ai_configuration'
  ))
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.4.0 Underground custom AI migration is forward-only; restore the verified pre-migration backup.',
        );
    }
};
