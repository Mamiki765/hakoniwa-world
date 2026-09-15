<?php

use App\Application\Ver420RulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement('ALTER TABLE ships ALTER COLUMN nation_id DROP NOT NULL');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_surface_ship_identity()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
  nation_world_id bigint;
  nation_state varchar;
  world_ruleset_id bigint;
  ship_rules jsonb;
  ship_definition jsonb;
  definition_player_buildable boolean;
  type_capacity integer;
  cell_world_id bigint;
  cell_map_space_key varchar;
  cell_terrain_key varchar;
  cell_facility_id bigint;
  facility_visibility_policy varchar;
  facility_disguise_terrain_key varchar;
BEGIN
  SELECT ruleset_version_id INTO world_ruleset_id
    FROM worlds
   WHERE id = NEW.world_id;
  SELECT settings -> 'surface_ships' INTO ship_rules
    FROM ruleset_versions
   WHERE id = NEW.ruleset_version_id;
  IF world_ruleset_id IS NULL OR ship_rules IS NULL THEN
    RAISE EXCEPTION 'Ship ruleset provenance must reference a World and authored Surface Ship contract.';
  END IF;
  IF TG_OP = 'INSERT' AND NEW.ruleset_version_id <> world_ruleset_id THEN
    RAISE EXCEPTION 'A new Ship must bind the current World Ruleset snapshot.';
  END IF;
  IF TG_OP = 'UPDATE' AND NEW.ruleset_version_id <> OLD.ruleset_version_id THEN
    RAISE EXCEPTION 'Ship Ruleset provenance is immutable.';
  END IF;
  ship_definition := ship_rules -> 'definitions' -> NEW.ship_type_key;
  IF ship_definition IS NULL OR jsonb_typeof(ship_definition) <> 'object' THEN
    RAISE EXCEPTION 'Ship type must be authored by its Ruleset snapshot.';
  END IF;
  IF NEW.max_hp <> (ship_definition ->> 'maximum_hp')::integer THEN
    RAISE EXCEPTION 'Ship maximum HP must match its Ruleset snapshot.';
  END IF;
  IF ship_definition ? 'player_buildable' THEN
    IF jsonb_typeof(ship_definition -> 'player_buildable') <> 'boolean' THEN
      RAISE EXCEPTION 'Ship ownership contract must be authored as a boolean.';
    END IF;
    definition_player_buildable := (ship_definition ->> 'player_buildable')::boolean;
  ELSE
    definition_player_buildable := true;
  END IF;
  IF NEW.nation_id IS NULL AND definition_player_buildable THEN
    RAISE EXCEPTION 'A Player-buildable Ship must belong to a Nation.';
  END IF;
  IF NEW.nation_id IS NOT NULL AND NOT definition_player_buildable THEN
    RAISE EXCEPTION 'An NPC-only Ship cannot belong to a Nation.';
  END IF;
  type_capacity := (ship_rules ->> 'capacity_per_type')::integer;
  IF type_capacity IS NULL OR type_capacity < 1 THEN
    RAISE EXCEPTION 'Ship type capacity must be authored by its Ruleset snapshot.';
  END IF;

  IF NEW.nation_id IS NOT NULL THEN
    SELECT world_id, state INTO nation_world_id, nation_state
      FROM nations
     WHERE id = NEW.nation_id
     FOR UPDATE;
    IF nation_world_id IS NULL OR nation_world_id <> NEW.world_id THEN
      RAISE EXCEPTION 'Ship Nation must belong to the same World.';
    END IF;
  END IF;

  IF NEW.state = 'active' THEN
    PERFORM 1 FROM map_cells WHERE id = NEW.map_cell_id FOR UPDATE;
    SELECT space.world_id, space.key, terrain.key, cell.facility_definition_id,
           facility.visibility_policy, facility.disguise_terrain_key
      INTO cell_world_id, cell_map_space_key, cell_terrain_key, cell_facility_id,
           facility_visibility_policy, facility_disguise_terrain_key
      FROM map_cells cell
      JOIN map_spaces space ON space.id = cell.map_space_id
      JOIN terrain_definitions terrain ON terrain.id = cell.terrain_definition_id
      LEFT JOIN facility_definitions facility ON facility.id = cell.facility_definition_id
     WHERE cell.id = NEW.map_cell_id;
    IF cell_world_id IS NULL OR cell_world_id <> NEW.world_id OR cell_map_space_key <> 'surface' THEN
      RAISE EXCEPTION 'An active Ship must occupy a Surface cell in its World.';
    END IF;
    IF cell_terrain_key <> 'sea' THEN
      RAISE EXCEPTION 'An active Ship must occupy deep sea.';
    END IF;
    IF cell_facility_id IS NOT NULL
       AND (facility_visibility_policy IS DISTINCT FROM 'disguised'
         OR facility_disguise_terrain_key IS DISTINCT FROM 'sea') THEN
      RAISE EXCEPTION 'A Ship may coexist only with a facility canonically disguised as sea.';
    END IF;
    IF EXISTS (
      SELECT 1 FROM monster_occupancies occupancy WHERE occupancy.map_cell_id = NEW.map_cell_id
    ) THEN
      RAISE EXCEPTION 'A Ship cannot share a cell with a Monster.';
    END IF;
    IF NEW.nation_id IS NOT NULL AND nation_state = 'abandoned' THEN
      RAISE EXCEPTION 'An abandoned Nation cannot own an active Ship.';
    END IF;
    IF NEW.nation_id IS NOT NULL AND (
      SELECT count(*)
        FROM ships ship
       WHERE ship.nation_id = NEW.nation_id
         AND ship.ship_type_key = NEW.ship_type_key
         AND ship.state = 'active'
         AND ship.id IS DISTINCT FROM NEW.id
    ) >= type_capacity THEN
      RAISE EXCEPTION 'A Nation exceeded the active Ship capacity authored by its Ruleset snapshot.';
    END IF;
  END IF;

  RETURN NEW;
END;
$$;
SQL);

        app(Ver420RulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 4.2.0 NPC Surface Ship migration is forward-only; restore the verified pre-migration backup.',
        );
    }
};
