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
  ADD COLUMN skill_rebuild_required boolean NOT NULL DEFAULT false,
  ADD COLUMN rental_party jsonb NOT NULL DEFAULT '[]'::jsonb,
  ADD CONSTRAINT underground_profiles_rental_party_check
    CHECK (jsonb_typeof(rental_party) = 'array' AND jsonb_array_length(rental_party) <= 3)
SQL);

        // Preserve earned SP, progression, completed requests and historical snapshots.
        // The old identity is the conversion marker; never add the refund to the total.
        DB::statement(<<<'SQL'
DELETE FROM underground_skill_allocations
WHERE underground_profile_id IN (
  SELECT id FROM underground_profiles WHERE skill_tree_identity = 'secretary-underground-skill-tree-alpha-v1'
)
SQL);
        DB::statement(<<<'SQL'
UPDATE underground_profiles
SET skill_points_unspent = skill_points_total,
    skill_tree_identity = 'secretary-underground-skill-tree-alpha-v2',
    custom_ai_rules = NULL,
    skill_rebuild_required = true
WHERE skill_tree_identity = 'secretary-underground-skill-tree-alpha-v1'
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
    'respec', 'equipment_bulk_sell', 'ai_configuration', 'awakening_technique',
    'recollection_read', 'rental_party'
  ))
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('The 3.10.0 skill refund is forward-only; restore the verified pre-migration backup.');
    }
};
