<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT_NAME = 'nation_command_queue_items_world_ruleset_match';

    private const FUNCTION_NAME = 'enforce_queue_item_world_ruleset_match';

    public function up(): void
    {
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
            $$;
            SQL);

        DB::unprepared(sprintf(
            <<<'SQL'
                CREATE CONSTRAINT TRIGGER %s
                AFTER INSERT OR UPDATE OF nation_command_queue_id, command_definition_id
                ON nation_command_queue_items
                DEFERRABLE INITIALLY IMMEDIATE
                FOR EACH ROW
                EXECUTE FUNCTION %s()
                SQL,
            self::CONSTRAINT_NAME,
            self::FUNCTION_NAME,
        ));
    }

    public function down(): void
    {
        DB::unprepared(sprintf(
            'DROP TRIGGER IF EXISTS %s ON nation_command_queue_items',
            self::CONSTRAINT_NAME,
        ));
        DB::unprepared(sprintf('DROP FUNCTION IF EXISTS %s()', self::FUNCTION_NAME));
    }
};
