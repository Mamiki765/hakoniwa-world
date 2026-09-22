<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill and consumer cutover are one transactional release migration.
        DB::statement('LOCK TABLE secretaries IN ACCESS EXCLUSIVE MODE');
        Schema::create('secretary_surface_states', function (Blueprint $table): void {
            $table->foreignId('secretary_id')->primary()->constrained('secretaries')->cascadeOnDelete();
            $table->bigInteger('monster_experience')->default(0);
            $table->bigInteger('equipment_version')->default(1);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE secretary_surface_states ADD CONSTRAINT secretary_surface_experience_check CHECK (monster_experience >= 0), ADD CONSTRAINT secretary_surface_equipment_version_check CHECK (equipment_version >= 1)');
        DB::statement('INSERT INTO secretary_surface_states (secretary_id, monster_experience, equipment_version, created_at, updated_at) SELECT id, monster_experience, equipment_version, created_at, updated_at FROM secretaries');
        Schema::table('secretaries', function (Blueprint $table): void {
            $table->dropColumn(['monster_experience', 'equipment_version']);
        });
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE secretaries, secretary_surface_states IN ACCESS EXCLUSIVE MODE');
        Schema::table('secretaries', function (Blueprint $table): void {
            $table->bigInteger('monster_experience')->default(0);
            $table->bigInteger('equipment_version')->default(1);
        });
        DB::statement('UPDATE secretaries s SET monster_experience = state.monster_experience, equipment_version = state.equipment_version FROM secretary_surface_states state WHERE state.secretary_id = s.id');
        DB::statement('ALTER TABLE secretaries ADD CONSTRAINT secretaries_monster_experience_non_negative CHECK (monster_experience >= 0), ADD CONSTRAINT secretaries_equipment_version_check CHECK (equipment_version >= 1)');
        Schema::drop('secretary_surface_states');
    }
};
