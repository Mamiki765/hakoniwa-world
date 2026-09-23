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
            $table->bigInteger('distorted_stone_balance')->default(0);
            $table->date('distorted_stone_purchase_day')->nullable();
            $table->integer('distorted_stone_purchase_count')->default(0);
        });
        DB::statement('ALTER TABLE underground_profiles ADD CONSTRAINT underground_otherworld_balances_check
            CHECK (distorted_stone_balance >= 0 AND distorted_stone_purchase_count >= 0)');
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
    'guide_conversation', 'distorted_stone_purchase'
  ))
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Otherworld assets and entry state must be preserved; this migration is forward-only.');
    }
};
