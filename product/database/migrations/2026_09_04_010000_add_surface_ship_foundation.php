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
        Schema::create('ships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ruleset_version_id')->constrained('ruleset_versions')->restrictOnDelete();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('map_cell_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('ship_type_key', 32);
            $table->unsignedSmallInteger('current_hp');
            $table->unsignedSmallInteger('max_hp');
            $table->unsignedSmallInteger('heading')->nullable();
            $table->string('state', 24)->default('active');
            $table->unsignedBigInteger('version')->default(1);
            $table->string('removal_reason')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->timestamps();

            $table->index(['world_id', 'state']);
            $table->index(['nation_id', 'state', 'ship_type_key']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE ships
  ADD CONSTRAINT ships_heading_check
    CHECK (heading IS NULL OR heading BETWEEN 0 AND 5),
  ADD CONSTRAINT ships_hp_check
    CHECK (max_hp BETWEEN 1 AND 32767 AND current_hp BETWEEN 0 AND max_hp),
  ADD CONSTRAINT ships_version_check
    CHECK (version >= 1),
  ADD CONSTRAINT ships_state_check
    CHECK (
      (state = 'active' AND map_cell_id IS NOT NULL AND current_hp >= 1
        AND removal_reason IS NULL AND removed_at IS NULL)
      OR
      (state = 'removed' AND map_cell_id IS NULL
        AND removal_reason IS NOT NULL AND removed_at IS NOT NULL)
    )
SQL);

        DB::statement("CREATE UNIQUE INDEX ships_active_map_cell_unique ON ships (map_cell_id) WHERE state = 'active'");

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
  type_capacity := (ship_rules ->> 'capacity_per_type')::integer;
  IF type_capacity IS NULL OR type_capacity < 1 THEN
    RAISE EXCEPTION 'Ship type capacity must be authored by its Ruleset snapshot.';
  END IF;

  SELECT world_id, state INTO nation_world_id, nation_state
    FROM nations
   WHERE id = NEW.nation_id
   FOR UPDATE;
  IF nation_world_id IS NULL OR nation_world_id <> NEW.world_id THEN
    RAISE EXCEPTION 'Ship Nation must belong to the same World.';
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
    IF nation_state = 'abandoned' THEN
      RAISE EXCEPTION 'An abandoned Nation cannot own an active Ship.';
    END IF;
    IF (
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

CREATE TRIGGER surface_ship_identity_guard
BEFORE INSERT OR UPDATE OF world_id, ruleset_version_id, nation_id, map_cell_id, ship_type_key, max_hp, state
ON ships
FOR EACH ROW
EXECUTE FUNCTION validate_surface_ship_identity();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_monster_occupancy()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    monster_world bigint;
    monster_state text;
    cell_world bigint;
    cell_facility text;
    cell_space text;
BEGIN
    PERFORM 1 FROM map_cells WHERE id = NEW.map_cell_id FOR UPDATE;
    SELECT world_id, state INTO monster_world, monster_state
      FROM monster_instances WHERE id = NEW.monster_instance_id;
    SELECT ms.world_id, fd.key, ms.key INTO cell_world, cell_facility, cell_space
      FROM map_cells mc
      JOIN map_spaces ms ON ms.id = mc.map_space_id
      LEFT JOIN facility_definitions fd ON fd.id = mc.facility_definition_id
      WHERE mc.id = NEW.map_cell_id;
    IF monster_state IS DISTINCT FROM 'alive' THEN
        RAISE EXCEPTION 'only an alive monster may occupy a cell';
    END IF;
    IF monster_world IS NULL OR cell_world IS NULL OR monster_world <> cell_world THEN
        RAISE EXCEPTION 'monster occupancy cannot cross World boundaries';
    END IF;
    IF cell_space IS DISTINCT FROM 'surface' THEN
        RAISE EXCEPTION 'monster occupancy is limited to the surface map';
    END IF;
    IF cell_facility = 'capital' THEN
        RAISE EXCEPTION 'Capital cells cannot contain monster occupancy';
    END IF;
    IF EXISTS (
      SELECT 1 FROM ships ship WHERE ship.map_cell_id = NEW.map_cell_id AND ship.state = 'active'
    ) THEN
        RAISE EXCEPTION 'A Monster cannot share a cell with a Ship';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.5.0 Surface Ship foundation migration is forward-only; restore the verified pre-migration backup.',
        );
    }
};
