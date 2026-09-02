<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment
  DROP CONSTRAINT underground_owned_equipment_slot_check
SQL);

        DB::table('underground_owned_equipment')
            ->where('equipped_slot', 'accessory')
            ->update(['equipped_slot' => 'accessory_1']);

        Schema::table('underground_owned_equipment', function (Blueprint $table): void {
            $table->string('instance_kind', 16)->default('fixed');
            $table->string('instance_identity', 64)->nullable();
            $table->string('generator_identity', 100)->nullable();
            $table->jsonb('generated_payload')->nullable();
            $table->foreignId('source_battle_id')
                ->nullable()
                ->constrained('underground_battles')
                ->cascadeOnDelete();
            $table->unique('instance_identity', 'underground_equipment_instance_identity_unique');
            $table->unique('source_battle_id', 'underground_equipment_source_battle_unique');
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_owned_equipment
  ADD CONSTRAINT underground_owned_equipment_slot_check
  CHECK (
    equipped_slot IS NULL
    OR equipped_slot IN ('weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3')
  ),
  ADD CONSTRAINT underground_owned_equipment_instance_check
  CHECK (
    (
      instance_kind = 'fixed'
      AND instance_identity IS NULL
      AND generator_identity IS NULL
      AND generated_payload IS NULL
      AND source_battle_id IS NULL
    )
    OR
    (
      instance_kind = 'generated'
      AND instance_identity IS NOT NULL
      AND generator_identity IS NOT NULL
      AND generated_payload IS NOT NULL
      AND source_battle_id IS NOT NULL
      AND grant_key IS NOT NULL
    )
  )
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.2.0 Underground equipment expansion is forward-only; restore the verified 3.1.0 backup.',
        );
    }
};
