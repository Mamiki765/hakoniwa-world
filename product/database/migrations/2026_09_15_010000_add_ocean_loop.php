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
        Schema::table('ships', function (Blueprint $table): void {
            $table->unsignedBigInteger('population')->nullable()->after('max_hp');
        });
        DB::statement(<<<'SQL'
ALTER TABLE ships
  ADD CONSTRAINT ships_ocean_population_check
  CHECK (
    (ship_type_key = 'pirate' AND nation_id IS NULL AND population > 0)
    OR
    (ship_type_key <> 'pirate' AND population IS NULL)
  )
SQL);

        Schema::table('secretary_item_instances', function (Blueprint $table): void {
            $table->string('resolved_rarity', 32)->nullable();
            $table->unsignedBigInteger('resolved_fixed_sale_price_money')->nullable();
        });
        DB::statement(<<<'SQL'
ALTER TABLE secretary_item_instances
  ADD CONSTRAINT secretary_item_instances_resolved_economics_check
  CHECK (
    (resolved_rarity IS NULL AND resolved_fixed_sale_price_money IS NULL)
    OR
    (resolved_rarity IS NOT NULL AND length(resolved_rarity) > 0
      AND resolved_fixed_sale_price_money IS NOT NULL AND resolved_fixed_sale_price_money >= 0)
  )
SQL);

        Schema::create('buried_treasures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('map_cell_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);
            $table->jsonb('reward_snapshot');
            $table->unsignedBigInteger('created_turn');
            $table->string('state', 16)->default('active');
            $table->foreignId('resolved_by_nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->unsignedBigInteger('resolved_turn')->nullable();
            $table->string('resolution_reason', 48)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['world_id', 'state']);
            $table->index(['map_cell_id', 'state', 'id']);
        });
        DB::statement(<<<'SQL'
ALTER TABLE buried_treasures
  ADD CONSTRAINT buried_treasures_source_check
    CHECK (source IN ('pirate_sink', 'treasure_ship_sink', 'meteor', 'huge_meteor', 'natural')),
  ADD CONSTRAINT buried_treasures_reward_check
    CHECK (jsonb_typeof(reward_snapshot) = 'object'),
  ADD CONSTRAINT buried_treasures_turn_check
    CHECK (created_turn >= 1 AND (resolved_turn IS NULL OR resolved_turn >= created_turn)),
  ADD CONSTRAINT buried_treasures_state_check
    CHECK (
      (state = 'active' AND resolved_by_nation_id IS NULL AND resolved_turn IS NULL
        AND resolution_reason IS NULL AND resolved_at IS NULL)
      OR
      (state = 'collected' AND resolved_by_nation_id IS NOT NULL AND resolved_turn IS NOT NULL
        AND resolution_reason = 'collected' AND resolved_at IS NOT NULL)
      OR
      (state = 'removed' AND resolved_by_nation_id IS NULL AND resolved_turn IS NOT NULL
        AND resolution_reason IS NOT NULL AND resolved_at IS NOT NULL)
    )
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_buried_treasure_identity()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
  cell_world_id bigint;
  resolving_nation_world_id bigint;
BEGIN
  SELECT space.world_id INTO cell_world_id
    FROM map_cells cell
    JOIN map_spaces space ON space.id = cell.map_space_id
   WHERE cell.id = NEW.map_cell_id;
  IF cell_world_id IS NULL OR cell_world_id <> NEW.world_id THEN
    RAISE EXCEPTION 'Buried Treasure cell must belong to its World.';
  END IF;
  IF NEW.resolved_by_nation_id IS NOT NULL THEN
    SELECT world_id INTO resolving_nation_world_id
      FROM nations
     WHERE id = NEW.resolved_by_nation_id;
    IF resolving_nation_world_id IS NULL OR resolving_nation_world_id <> NEW.world_id THEN
      RAISE EXCEPTION 'Buried Treasure resolving Nation must belong to its World.';
    END IF;
  END IF;

  RETURN NEW;
END;
$$;

CREATE TRIGGER buried_treasure_identity_guard
BEFORE INSERT OR UPDATE ON buried_treasures
FOR EACH ROW EXECUTE FUNCTION validate_buried_treasure_identity();
SQL);

        DB::statement('ALTER TABLE secretary_skills DROP CONSTRAINT IF EXISTS secretary_skills_key_check');
        DB::statement(<<<'SQL'
ALTER TABLE secretary_skills
  ADD CONSTRAINT secretary_skills_key_check
  CHECK (skill_key IN (
    'agricultural_policy',
    'specialty_development',
    'gold_vein_survey',
    'forest_management',
    'final_defense_line',
    'declining_birthrate_policy',
    'indomitable',
    'ship_operations',
    'navy'
  ))
SQL);
        DB::statement(<<<'SQL'
INSERT INTO secretary_skills (secretary_id, skill_key, level, experience, created_at, updated_at)
SELECT secretary.id, 'navy', 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
  FROM secretaries secretary
 WHERE NOT EXISTS (
   SELECT 1 FROM secretary_skills skill
    WHERE skill.secretary_id = secretary.id AND skill.skill_key = 'navy'
 )
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 4.2.0 ocean-loop migration is forward-only; restore the verified pre-migration backup.',
        );
    }
};
