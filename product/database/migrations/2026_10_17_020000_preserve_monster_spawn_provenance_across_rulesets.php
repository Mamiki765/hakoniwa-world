<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.validate_monster_instance_world_ruleset() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    world_ruleset bigint;
    definition_ruleset bigint;
    definition_min_hp integer;
    definition_max_hp integer;
    ruleset_settings jsonb;
    central_contract jsonb;
    central_facility_keys jsonb;
    central_percent_per_level integer;
    central_facility_key_count integer;
    central_facility_definition_count integer;
    central_maximum_level integer;
    maximum_spawned_hp bigint;
BEGIN
    SELECT ruleset_version_id INTO world_ruleset FROM worlds WHERE id = NEW.world_id;
    SELECT definition.ruleset_version_id,
           definition.base_hp,
           definition.base_hp + definition.hp_variation,
           ruleset.settings
      INTO definition_ruleset, definition_min_hp, definition_max_hp, ruleset_settings
      FROM monster_definitions definition
      JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id
     WHERE definition.id = NEW.monster_definition_id;
    IF world_ruleset IS NULL OR definition_ruleset IS NULL THEN
        RAISE EXCEPTION 'monster definition must belong to the World current ruleset';
    END IF;
    -- A living instance retains its spawn definition and HP provenance. New
    -- spawns must use the current definition; an existing stable key may use
    -- current gameplay values without rewriting that provenance.
    IF world_ruleset <> definition_ruleset AND (
        TG_OP = 'INSERT' OR NOT EXISTS (
            SELECT 1 FROM monster_definitions current_definition
            JOIN monster_definitions spawn_definition
              ON spawn_definition.key = current_definition.key
            WHERE spawn_definition.id = NEW.monster_definition_id
              AND current_definition.ruleset_version_id = world_ruleset
        )
    ) THEN
        RAISE EXCEPTION 'monster definition has no current World Ruleset key';
    END IF;

    maximum_spawned_hp := definition_max_hp;
    central_contract := ruleset_settings #> '{central_facilities,natural_monster_hp}';
    IF central_contract IS NOT NULL THEN
        central_facility_keys := central_contract -> 'facility_keys';
        central_percent_per_level := (central_contract ->> 'percent_per_level')::integer;
        IF jsonb_typeof(central_contract) <> 'object'
           OR jsonb_typeof(central_facility_keys) <> 'array'
           OR jsonb_array_length(central_facility_keys) < 1
           OR central_percent_per_level < 1 THEN
            RAISE EXCEPTION 'central facility monster HP contract is invalid';
        END IF;

        SELECT count(*),
               count(ruleset_settings #> ARRAY['facility_definitions', facility_key, 'maximum_scale']),
               COALESCE(sum((ruleset_settings #>> ARRAY['facility_definitions', facility_key, 'maximum_scale'])::integer), 0)
          INTO central_facility_key_count, central_facility_definition_count, central_maximum_level
          FROM jsonb_array_elements_text(central_facility_keys) AS authored(facility_key);
        IF central_facility_key_count <> central_facility_definition_count
           OR central_maximum_level < 1 THEN
            RAISE EXCEPTION 'central facility maximum level contract is invalid';
        END IF;

        maximum_spawned_hp := LEAST(
            32767,
            (
                definition_max_hp::bigint
                * (100::bigint + central_percent_per_level::bigint * central_maximum_level::bigint)
                + 99
            ) / 100
        );
    END IF;

    IF NEW.spawned_max_hp < definition_min_hp OR NEW.spawned_max_hp > maximum_spawned_hp THEN
        RAISE EXCEPTION 'spawned monster HP is outside its Ruleset range';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Current Ruleset key resolution is forward-only.');
    }
};
