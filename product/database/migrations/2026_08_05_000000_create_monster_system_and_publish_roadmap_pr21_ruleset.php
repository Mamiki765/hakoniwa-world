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

        Schema::create('nation_monster_kill_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monster_definition_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('kill_count');
            $table->unsignedBigInteger('first_killed_turn');
            $table->unsignedBigInteger('last_killed_turn');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['world_id', 'nation_id', 'monster_definition_id'], 'nation_monster_kill_stats_scope_unique');
            $table->index(['world_id', 'nation_id']);
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
        DB::statement('ALTER TABLE nation_monster_kill_stats ADD CONSTRAINT nation_monster_kill_stats_count_check CHECK (kill_count >= 1)');
        DB::statement('ALTER TABLE nation_monster_kill_stats ADD CONSTRAINT nation_monster_kill_stats_turn_check CHECK (first_killed_turn >= 1 AND last_killed_turn >= first_killed_turn)');
        DB::statement('ALTER TABLE nation_monster_kill_stats ADD CONSTRAINT nation_monster_kill_stats_version_check CHECK (version >= 1)');

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

CREATE OR REPLACE FUNCTION validate_nation_monster_kill_stat() RETURNS trigger AS $$
DECLARE
    nation_world bigint;
    world_ruleset bigint;
    definition_ruleset bigint;
BEGIN
    SELECT world_id INTO nation_world FROM nations WHERE id = NEW.nation_id;
    SELECT ruleset_version_id INTO world_ruleset FROM worlds WHERE id = NEW.world_id;
    SELECT ruleset_version_id INTO definition_ruleset
      FROM monster_definitions WHERE id = NEW.monster_definition_id;
    IF nation_world IS NULL OR world_ruleset IS NULL OR definition_ruleset IS NULL
       OR nation_world <> NEW.world_id OR world_ruleset <> definition_ruleset THEN
        RAISE EXCEPTION 'monster kill stat references inconsistent World state';
    END IF;
    IF TG_OP = 'INSERT' AND (NEW.kill_count <> 1 OR NEW.first_killed_turn <> NEW.last_killed_turn OR NEW.version <> 1) THEN
        RAISE EXCEPTION 'first monster kill stat must start at count and version one';
    END IF;
    IF TG_OP = 'UPDATE' AND (
        NEW.world_id <> OLD.world_id
        OR NEW.nation_id <> OLD.nation_id
        OR NEW.monster_definition_id <> OLD.monster_definition_id
        OR NEW.first_killed_turn <> OLD.first_killed_turn
        OR NEW.kill_count <> OLD.kill_count + 1
        OR NEW.last_killed_turn < OLD.last_killed_turn
        OR NEW.version <> OLD.version + 1
    ) THEN
        RAISE EXCEPTION 'monster kill stat updates must be one atomic increment';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER nation_monster_kill_stat_guard
BEFORE INSERT OR UPDATE ON nation_monster_kill_stats
FOR EACH ROW EXECUTE FUNCTION validate_nation_monster_kill_stat();

CREATE OR REPLACE FUNCTION reject_nation_monster_kill_stat_delete() RETURNS trigger AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM worlds WHERE id = OLD.world_id) THEN
        RETURN OLD;
    END IF;
    RAISE EXCEPTION 'monster kill stats are permanent while their World exists';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER nation_monster_kill_stat_delete_guard
BEFORE DELETE ON nation_monster_kill_stats
FOR EACH ROW EXECUTE FUNCTION reject_nation_monster_kill_stat_delete();
SQL);
    }
};
