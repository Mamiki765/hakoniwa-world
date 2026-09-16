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
            $table->timestampTz('villa_purchased_at')->nullable();
            $table->timestampTz('mirror_purchased_at')->nullable();
            $table->unsignedSmallInteger('exchange_intro_page')->default(0);
            $table->timestampTz('mirror_event_completed_at')->nullable();
            $table->string('home_background_key', 120)->nullable();
        });
        DB::statement(<<<'SQL'
ALTER TABLE underground_profiles
  DROP CONSTRAINT underground_profiles_custom_ai_rules_check,
  ADD CONSTRAINT underground_profiles_custom_ai_rules_check
  CHECK (custom_ai_rules IS NULL
    OR (jsonb_typeof(custom_ai_rules) = 'array' AND jsonb_array_length(custom_ai_rules) <= 20))
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
    'recollection_read', 'rental_party', 'residence_purchase', 'lounge_event', 'home_background'
  ))
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Residence ownership and event progress must be preserved; this migration is forward-only.');
    }
};
