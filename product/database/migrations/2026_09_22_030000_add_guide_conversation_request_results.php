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
        Schema::table('underground_intro_requests', function (Blueprint $table): void {
            $table->jsonb('result_payload')->nullable();
        });
        // Short-lived response snapshots share the existing request purge stream.
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
    'recollection_read', 'rental_party', 'residence_purchase', 'lounge_event', 'home_background',
    'guide_conversation'
  ))
SQL);
        DB::statement("ALTER TABLE underground_intro_requests ADD CONSTRAINT underground_intro_requests_result_check CHECK ((operation = 'guide_conversation') = (result_payload IS NOT NULL))");
    }

    public function down(): void
    {
        throw new RuntimeException('Request replay results require a reviewed forward migration.');
    }
};
