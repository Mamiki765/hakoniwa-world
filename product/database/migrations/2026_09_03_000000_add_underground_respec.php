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
            $table->timestampTz('last_respec_at')->nullable();
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_requests
  DROP CONSTRAINT underground_intro_requests_operation_check,
  ADD CONSTRAINT underground_intro_requests_operation_check
  CHECK (operation IN (
    'entry', 'advance', 'tutorial', 'shopkeeper_name', 'scripted_loss',
    'contract', 'growth_path', 'inn_rest', 'bank_transfer', 'playtest',
    'stp_allocate', 'skill_acquire', 'active_loadout', 'awakening_message',
    'equipment_purchase', 'equipment_sell', 'equipment_equip', 'equipment_unequip',
    'respec'
  ))
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.3.0 Underground respec migration is forward-only; restore the verified 3.2.0 backup.',
        );
    }
};
