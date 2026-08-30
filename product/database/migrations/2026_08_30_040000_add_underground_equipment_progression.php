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
        Schema::create('underground_owned_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('underground_profile_id')
                ->constrained('underground_profiles')
                ->cascadeOnDelete();
            $table->string('definition_key', 100);
            $table->string('catalog_identity', 100);
            $table->string('equipped_slot', 16)->nullable();
            $table->string('grant_key', 100)->nullable();
            $table->timestampTz('acquired_at');
            $table->timestamps();

            $table->unique(
                ['underground_profile_id', 'equipped_slot'],
                'underground_equipment_profile_slot_unique',
            );
            $table->unique(
                ['underground_profile_id', 'grant_key'],
                'underground_equipment_profile_grant_unique',
            );
            $table->index(
                ['underground_profile_id', 'acquired_at', 'id'],
                'underground_equipment_vault_page_index',
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment
  ADD CONSTRAINT underground_owned_equipment_slot_check
  CHECK (equipped_slot IS NULL OR equipped_slot IN ('weapon', 'armor', 'accessory')),
  ADD CONSTRAINT underground_owned_equipment_identity_check
  CHECK (definition_key <> '' AND catalog_identity <> '')
SQL);

        DB::statement(<<<'SQL'
INSERT INTO underground_owned_equipment (
  underground_profile_id, definition_key, catalog_identity, equipped_slot,
  grant_key, acquired_at, created_at, updated_at
)
SELECT p.id,
       'starter_knife',
       'secretary-underground-shop-equipment-alpha-v1',
       'weapon',
       'starter-knife-alpha-v1',
       CURRENT_TIMESTAMP,
       CURRENT_TIMESTAMP,
       CURRENT_TIMESTAMP
  FROM underground_profiles p
  JOIN underground_intro_progress i ON i.underground_profile_id = p.id
 WHERE i.stage = 'underground_open'
   AND p.growth_path_key IS NOT NULL
SQL);

        DB::statement('ALTER TABLE underground_intro_requests DROP CONSTRAINT underground_intro_requests_operation_check');
        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_requests
  ADD CONSTRAINT underground_intro_requests_operation_check
  CHECK (operation IN (
    'entry', 'advance', 'tutorial', 'shopkeeper_name', 'scripted_loss',
    'contract', 'growth_path', 'inn_rest', 'bank_transfer', 'playtest',
    'stp_allocate', 'skill_acquire', 'active_loadout',
    'equipment_purchase', 'equipment_sell', 'equipment_equip', 'equipment_unequip'
  ))
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Underground equipment persistence is forward-only.');
    }
};
