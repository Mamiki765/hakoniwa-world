<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.enforce_queue_item_world_ruleset_match() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    world_ruleset_id bigint;
    definition_ruleset_id bigint;
    source_command_key text;
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

    SELECT worlds.ruleset_version_id, command_definitions.ruleset_version_id, command_definitions.key
    INTO world_ruleset_id, definition_ruleset_id, source_command_key
    FROM nation_command_queues
    INNER JOIN nations ON nations.id = nation_command_queues.nation_id
    INNER JOIN worlds ON worlds.id = nations.world_id
    INNER JOIN command_definitions ON command_definitions.id = NEW.command_definition_id
    WHERE nation_command_queues.id = NEW.nation_command_queue_id;

    IF NOT FOUND OR NOT EXISTS (
        SELECT 1
        FROM command_definitions AS current_definition
        WHERE current_definition.ruleset_version_id = world_ruleset_id
          AND current_definition.key = source_command_key
    ) THEN
        RAISE EXCEPTION
            'queue item % command definition ruleset % has no matching command key in World ruleset %',
            NEW.id,
            definition_ruleset_id,
            world_ruleset_id
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Current World queue command resolution is forward-only. Restore a reviewed backup instead.');
    }
};
