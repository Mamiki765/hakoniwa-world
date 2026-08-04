<?php

use App\Application\RulesetPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGET_KEY = 'roadmap-pr21-v1';

    public function up(): void
    {
        Schema::create('monster_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ruleset_version_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('asset_key');
            $table->string('hardened_asset_key')->nullable();
            $table->unsignedSmallInteger('base_hp');
            $table->unsignedSmallInteger('hp_variation');
            $table->string('skill_key', 32);
            $table->unsignedInteger('movement_limit');
            $table->unsignedSmallInteger('natural_spawn_tier')->nullable();
            $table->unsignedBigInteger('wreckage_value_money');
            $table->unsignedSmallInteger('missile_base_experience');
            $table->string('skill_description');
            $table->string('visibility', 32);
            $table->jsonb('movement_terrain_contract');
            $table->jsonb('trample_contract');
            $table->jsonb('hardening_contract');
            $table->jsonb('source_metadata');
            $table->timestamps();
            $table->unique(['ruleset_version_id', 'key']);
            $table->unique(['ruleset_version_id', 'asset_key']);
        });

        Schema::create('monster_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monster_definition_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('current_hp');
            $table->unsignedSmallInteger('spawned_max_hp');
            $table->string('state', 24)->default('alive');
            $table->unsignedBigInteger('spawned_target_turn');
            $table->unsignedBigInteger('version')->default(1);
            $table->string('removal_reason')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->timestamps();
            $table->index(['world_id', 'state']);
        });

        Schema::create('monster_occupancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monster_instance_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('map_cell_id')->unique()->constrained('map_cells')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('monster_kill_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained()->restrictOnDelete();
            $table->foreignId('monster_instance_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('monster_definition_id')->constrained()->restrictOnDelete();
            $table->foreignId('killer_nation_id')->constrained('nations')->restrictOnDelete();
            $table->foreignId('host_nation_id')->nullable()->constrained('nations')->restrictOnDelete();
            $table->foreignId('firing_base_id')->nullable()->constrained('map_cells')->restrictOnDelete();
            $table->unsignedBigInteger('target_turn');
            $table->string('kill_cause', 64);
            $table->unsignedBigInteger('wreckage_value_money');
            $table->unsignedBigInteger('killer_money_requested');
            $table->unsignedBigInteger('killer_money_applied');
            $table->unsignedBigInteger('killer_money_overflow');
            $table->unsignedBigInteger('host_meat_food_requested');
            $table->unsignedBigInteger('host_meat_food_applied');
            $table->unsignedBigInteger('host_meat_food_overflow');
            $table->unsignedSmallInteger('firing_base_experience_applied')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['killer_nation_id', 'target_turn']);
            $table->index(['killer_nation_id', 'monster_definition_id']);
        });

        $this->installPostgresIntegrityConstraints();

        $published = config('hakoniwa.published_rulesets');
        $settings = is_array($published) ? ($published[self::TARGET_KEY] ?? null) : null;
        if (! is_array($settings)) {
            throw new RuntimeException('The immutable roadmap-pr21-v1 ruleset snapshot is missing.');
        }

        // Pre-release Worlds remain historical and reset-required. Publishing the
        // new catalog never repoints a World or rewrites prior ruleset payloads.
        app(RulesetPublisher::class)->publish($settings);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The roadmap-pr21-v1 monster schema and ruleset publication are forward-only; restore from an explicit backup instead.',
        );
    }

    private function installPostgresIntegrityConstraints(): void
    {
        DB::statement('ALTER TABLE monster_definitions ADD CONSTRAINT monster_definitions_hp_check CHECK (base_hp >= 1 AND hp_variation <= 18 AND base_hp + hp_variation <= 65535)');
        DB::statement("ALTER TABLE monster_definitions ADD CONSTRAINT monster_definitions_skill_check CHECK (skill_key IN ('none', 'move_2', 'move_9999', 'harden_odd', 'harden_even'))");
        DB::statement("ALTER TABLE monster_definitions ADD CONSTRAINT monster_definitions_visibility_check CHECK (visibility = 'public')");
        DB::statement('ALTER TABLE monster_definitions ADD CONSTRAINT monster_definitions_spawn_tier_check CHECK (natural_spawn_tier IS NULL OR natural_spawn_tier BETWEEN 1 AND 3)');
        DB::statement("ALTER TABLE monster_instances ADD CONSTRAINT monster_instances_state_check CHECK ((state = 'alive' AND current_hp BETWEEN 1 AND spawned_max_hp AND removal_reason IS NULL AND removed_at IS NULL) OR (state = 'killed' AND current_hp = 0 AND removal_reason IS NOT NULL AND removed_at IS NOT NULL) OR (state = 'removed' AND current_hp BETWEEN 0 AND spawned_max_hp AND removal_reason IS NOT NULL AND removed_at IS NOT NULL))");
        DB::statement('ALTER TABLE monster_instances ADD CONSTRAINT monster_instances_turn_check CHECK (spawned_target_turn >= 1)');
        DB::statement('ALTER TABLE monster_kill_records ADD CONSTRAINT monster_kill_records_turn_check CHECK (target_turn >= 1)');
        DB::statement('ALTER TABLE monster_kill_records ADD CONSTRAINT monster_kill_records_money_split_check CHECK (killer_money_requested + host_meat_food_requested / 1000 <= wreckage_value_money AND killer_money_applied <= killer_money_requested AND killer_money_overflow = killer_money_requested - killer_money_applied AND host_meat_food_applied <= host_meat_food_requested AND host_meat_food_overflow = host_meat_food_requested - host_meat_food_applied)');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_monster_instance_world_ruleset() RETURNS trigger AS $$
DECLARE
    world_ruleset bigint;
    definition_ruleset bigint;
    definition_min_hp integer;
    definition_max_hp integer;
BEGIN
    SELECT ruleset_version_id INTO world_ruleset FROM worlds WHERE id = NEW.world_id;
    SELECT ruleset_version_id, base_hp, base_hp + hp_variation
      INTO definition_ruleset, definition_min_hp, definition_max_hp
      FROM monster_definitions WHERE id = NEW.monster_definition_id;
    IF world_ruleset IS NULL OR definition_ruleset IS NULL OR world_ruleset <> definition_ruleset THEN
        RAISE EXCEPTION 'monster definition must belong to the World current ruleset';
    END IF;
    IF NEW.spawned_max_hp < definition_min_hp OR NEW.spawned_max_hp > definition_max_hp THEN
        RAISE EXCEPTION 'spawned monster HP is outside its definition range';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER monster_instance_world_ruleset_guard
BEFORE INSERT OR UPDATE OF world_id, monster_definition_id, spawned_max_hp ON monster_instances
FOR EACH ROW EXECUTE FUNCTION validate_monster_instance_world_ruleset();

CREATE OR REPLACE FUNCTION validate_monster_occupancy() RETURNS trigger AS $$
DECLARE
    monster_world bigint;
    monster_state text;
    cell_world bigint;
    cell_facility text;
    cell_space text;
BEGIN
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
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER monster_occupancy_guard
BEFORE INSERT OR UPDATE ON monster_occupancies
FOR EACH ROW EXECUTE FUNCTION validate_monster_occupancy();

CREATE OR REPLACE FUNCTION validate_monster_kill_record() RETURNS trigger AS $$
DECLARE
    monster_world bigint;
    monster_definition bigint;
    monster_state text;
    killer_world bigint;
    host_world bigint;
    base_world bigint;
BEGIN
    SELECT world_id, monster_definition_id, state
      INTO monster_world, monster_definition, monster_state
      FROM monster_instances WHERE id = NEW.monster_instance_id;
    SELECT world_id INTO killer_world FROM nations WHERE id = NEW.killer_nation_id;
    IF NEW.host_nation_id IS NOT NULL THEN
        SELECT world_id INTO host_world FROM nations WHERE id = NEW.host_nation_id;
    END IF;
    IF NEW.firing_base_id IS NOT NULL THEN
        SELECT ms.world_id INTO base_world FROM map_cells mc
          JOIN map_spaces ms ON ms.id = mc.map_space_id WHERE mc.id = NEW.firing_base_id;
    END IF;
    IF monster_state IS DISTINCT FROM 'killed'
       OR monster_world <> NEW.world_id OR monster_definition <> NEW.monster_definition_id
       OR killer_world <> NEW.world_id
       OR (NEW.host_nation_id IS NOT NULL AND host_world <> NEW.world_id)
       OR (NEW.firing_base_id IS NOT NULL AND base_world <> NEW.world_id) THEN
        RAISE EXCEPTION 'monster kill record references inconsistent World state';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER monster_kill_record_guard
BEFORE INSERT ON monster_kill_records
FOR EACH ROW EXECUTE FUNCTION validate_monster_kill_record();

CREATE OR REPLACE FUNCTION reject_monster_kill_record_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'monster kill records are immutable';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER monster_kill_record_immutable
BEFORE UPDATE OR DELETE ON monster_kill_records
FOR EACH ROW EXECUTE FUNCTION reject_monster_kill_record_mutation();
SQL);
    }
};
