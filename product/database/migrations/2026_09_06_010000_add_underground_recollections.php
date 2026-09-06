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
        Schema::table('underground_intro_progress', function (Blueprint $table): void {
            $table->unsignedSmallInteger('guide_recollection_max_completed')->default(0);
        });

        DB::statement(<<<'SQL'
ALTER TABLE underground_intro_progress
  ADD CONSTRAINT underground_intro_progress_recollection_completed_check
  CHECK (guide_recollection_max_completed BETWEEN 0 AND 5)
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
    'recollection_read'
  ))
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.7.0 Underground recollection migration is forward-only; restore the verified pre-migration backup.',
        );
    }
};
