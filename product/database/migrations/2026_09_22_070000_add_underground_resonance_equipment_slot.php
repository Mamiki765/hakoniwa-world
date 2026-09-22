<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment
  DROP CONSTRAINT underground_owned_equipment_slot_check,
  ADD CONSTRAINT underground_owned_equipment_slot_check CHECK (
    equipped_slot IS NULL OR equipped_slot IN (
      'weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3', 'resonance'
    )
  )
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Owned resonance equipment must not be discarded by rollback.');
    }
};
