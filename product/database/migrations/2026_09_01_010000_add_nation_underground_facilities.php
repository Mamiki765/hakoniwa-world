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
        Schema::create('nation_underground_facilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ruleset_version_id')->constrained('ruleset_versions')->restrictOnDelete();
            $table->unsignedSmallInteger('layer');
            $table->unsignedSmallInteger('slot_index');
            $table->string('facility_key', 64);
            $table->timestamps();
            $table->unique(['nation_id', 'layer', 'slot_index'], 'nation_underground_facilities_slot_unique');
        });
        DB::statement(<<<'SQL'
ALTER TABLE nation_underground_facilities
  ADD CONSTRAINT nation_underground_facilities_layer_check CHECK (layer >= 1),
  ADD CONSTRAINT nation_underground_facilities_slot_check CHECK (slot_index BETWEEN 0 AND 3),
  ADD CONSTRAINT nation_underground_facilities_key_check CHECK (
    facility_key IN ('underground_city', 'underground_farm', 'underground_factory', 'underground_missile_base')
  )
SQL);

        Schema::table('nation_command_queue_items', function (Blueprint $table): void {
            $table->string('target_context', 32)->default('surface_cell');
            $table->unsignedSmallInteger('target_layer')->nullable();
            $table->unsignedSmallInteger('target_slot_index')->nullable();
            $table->string('underground_command_key', 64)->nullable();
        });
        DB::statement('ALTER TABLE nation_command_queue_items ALTER COLUMN command_definition_id DROP NOT NULL');
        DB::statement('ALTER TABLE nation_command_queue_items ALTER COLUMN target_x DROP NOT NULL');
        DB::statement('ALTER TABLE nation_command_queue_items ALTER COLUMN target_y DROP NOT NULL');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_queue_item_world_ruleset_match()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    world_ruleset_id bigint;
    definition_ruleset_id bigint;
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM nation_command_queue_items
        WHERE id = NEW.id
    ) THEN
        RETURN NEW;
    END IF;

    IF NEW.target_context = 'underground_slot' THEN
        RETURN NEW;
    END IF;

    SELECT worlds.ruleset_version_id, command_definitions.ruleset_version_id
    INTO world_ruleset_id, definition_ruleset_id
    FROM nation_command_queues
    INNER JOIN nations ON nations.id = nation_command_queues.nation_id
    INNER JOIN worlds ON worlds.id = nations.world_id
    INNER JOIN command_definitions ON command_definitions.id = NEW.command_definition_id
    WHERE nation_command_queues.id = NEW.nation_command_queue_id;

    IF NOT FOUND OR world_ruleset_id IS DISTINCT FROM definition_ruleset_id THEN
        RAISE EXCEPTION
            'queue item % command definition ruleset % does not match World ruleset %',
            NEW.id,
            definition_ruleset_id,
            world_ruleset_id
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE nation_command_queue_items
  ADD CONSTRAINT nation_command_queue_items_target_context_check CHECK (
    (target_context = 'surface_cell' AND command_definition_id IS NOT NULL AND underground_command_key IS NULL
      AND target_x IS NOT NULL AND target_y IS NOT NULL AND target_layer IS NULL AND target_slot_index IS NULL)
    OR
    (target_context = 'underground_slot' AND command_definition_id IS NULL
      AND underground_command_key IN (
        'build_underground_city', 'build_underground_farm', 'build_underground_factory',
        'build_underground_missile_base', 'remove_underground_facility'
      ) AND target_x IS NULL AND target_y IS NULL AND target_layer >= 1 AND target_slot_index BETWEEN 0 AND 3)
  )
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Nation-owned Underground facilities and command targets are forward-only.');
    }
};
