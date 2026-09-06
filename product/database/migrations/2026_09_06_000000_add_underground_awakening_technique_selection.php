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
  ADD COLUMN awakening_technique_key varchar(64),
  ADD CONSTRAINT underground_profiles_awakening_technique_check
  CHECK (
    awakening_technique_key IS NULL
    OR (growth_path_key = 'martial_red' AND awakening_technique_key IN ('decisive_heavenrend', 'shura_bloodline'))
    OR (growth_path_key = 'guardianship_blue' AND awakening_technique_key IN ('absolute_aegis', 'fortress_strike'))
    OR (growth_path_key = 'blessing_green' AND awakening_technique_key IN ('life_requiem', 'judgment_light'))
    OR (growth_path_key = 'free_black' AND awakening_technique_key IN ('limitless_reprise', 'formless_strike'))
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
    'respec', 'equipment_bulk_sell', 'ai_configuration', 'awakening_technique'
  ))
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.6.0 Underground awakening technique migration is forward-only; restore the verified pre-migration backup.',
        );
    }
};
