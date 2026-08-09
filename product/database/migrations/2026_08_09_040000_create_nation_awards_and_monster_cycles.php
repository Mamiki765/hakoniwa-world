<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nation_awards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->string('award_key', 64);
            $table->unsignedInteger('awarded_turn');
            $table->string('award_occurrence_key', 64);
            $table->timestampTz('created_at');
            $table->unique(
                ['world_id', 'nation_id', 'award_key', 'award_occurrence_key'],
                'nation_awards_occurrence_unique',
            );
            $table->index(['world_id', 'nation_id', 'awarded_turn']);
        });

        Schema::create('nation_monster_cycle_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('cycle_start_turn');
            $table->unsignedInteger('cycle_end_turn');
            $table->unsignedBigInteger('kill_count')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('seeded_at')->nullable();
            $table->timestampsTz();
            $table->unique(
                ['world_id', 'nation_id', 'cycle_start_turn'],
                'nation_monster_cycle_stats_scope_unique',
            );
            $table->index(['world_id', 'cycle_start_turn', 'kill_count']);
        });

        Schema::create('nation_monster_cycle_seed_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('cycle_start_turn');
            $table->unsignedInteger('cycle_end_turn');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at');
            $table->unique(
                ['world_id', 'nation_id', 'cycle_start_turn'],
                'nation_monster_cycle_seed_requirement_unique',
            );
            $table->index(['world_id', 'cycle_start_turn', 'completed_at']);
        });

        DB::statement('ALTER TABLE nation_awards ADD CONSTRAINT nation_awards_positive_turn CHECK (awarded_turn >= 1)');
        DB::statement(<<<'SQL'
ALTER TABLE nation_monster_cycle_stats
ADD CONSTRAINT nation_monster_cycle_stats_valid_interval CHECK (
    cycle_start_turn >= 1
    AND MOD(cycle_start_turn - 1, 100) = 0
    AND cycle_end_turn = cycle_start_turn + 99
    AND kill_count >= 0
    AND version >= 1
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE nation_monster_cycle_seed_requirements
ADD CONSTRAINT nation_monster_cycle_seed_requirement_valid_interval CHECK (
    cycle_start_turn >= 1
    AND MOD(cycle_start_turn - 1, 100) = 0
    AND cycle_end_turn = cycle_start_turn + 99
)
SQL);
        DB::statement(<<<'SQL'
INSERT INTO nation_monster_cycle_seed_requirements (
    world_id, nation_id, cycle_start_turn, cycle_end_turn, completed_at, created_at
)
SELECT
    worlds.id,
    nations.id,
    (FLOOR(worlds.current_turn / 100.0)::integer * 100) + 1,
    (FLOOR(worlds.current_turn / 100.0)::integer * 100) + 100,
    NULL,
    CURRENT_TIMESTAMP
FROM worlds
INNER JOIN nations ON nations.world_id = worlds.id
WHERE worlds.current_turn > 0
  AND MOD(worlds.current_turn, 100) <> 0
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_nation_achievement_world() RETURNS trigger AS $$
DECLARE
    nation_world bigint;
BEGIN
    SELECT world_id INTO nation_world FROM nations WHERE id = NEW.nation_id;
    IF nation_world IS NULL OR nation_world <> NEW.world_id THEN
        RAISE EXCEPTION 'Nation achievement state cannot cross World boundaries';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER nation_award_world_guard
BEFORE INSERT OR UPDATE OF world_id, nation_id ON nation_awards
FOR EACH ROW EXECUTE FUNCTION validate_nation_achievement_world();

CREATE TRIGGER nation_monster_cycle_world_guard
BEFORE INSERT OR UPDATE OF world_id, nation_id ON nation_monster_cycle_stats
FOR EACH ROW EXECUTE FUNCTION validate_nation_achievement_world();

CREATE TRIGGER nation_monster_cycle_seed_requirement_world_guard
BEFORE INSERT OR UPDATE OF world_id, nation_id ON nation_monster_cycle_seed_requirements
FOR EACH ROW EXECUTE FUNCTION validate_nation_achievement_world();

CREATE OR REPLACE FUNCTION reject_nation_award_update() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Nation award occurrences are immutable';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER nation_award_update_guard
BEFORE UPDATE ON nation_awards
FOR EACH ROW EXECUTE FUNCTION reject_nation_award_update();

CREATE OR REPLACE FUNCTION validate_nation_monster_cycle_update() RETURNS trigger AS $$
DECLARE
    current_world_turn bigint;
BEGIN
    SELECT current_turn INTO current_world_turn FROM worlds WHERE id = OLD.world_id;
    IF current_world_turn IS NULL THEN
        RAISE EXCEPTION 'Monster cycle update references a missing World';
    END IF;
    IF OLD.cycle_end_turn <= current_world_turn THEN
        RAISE EXCEPTION 'Completed monster cycle history is immutable';
    END IF;
    IF NEW.world_id <> OLD.world_id
        OR NEW.nation_id <> OLD.nation_id
        OR NEW.cycle_start_turn <> OLD.cycle_start_turn
        OR NEW.cycle_end_turn <> OLD.cycle_end_turn
        OR NEW.seeded_at IS DISTINCT FROM OLD.seeded_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Monster cycle identity and seed audit fields are immutable';
    END IF;
    IF NEW.kill_count <> OLD.kill_count + 1 OR NEW.version <> OLD.version + 1 THEN
        RAISE EXCEPTION 'Monster cycle runtime update must increment count and version by exactly one';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER nation_monster_cycle_update_guard
BEFORE UPDATE ON nation_monster_cycle_stats
FOR EACH ROW EXECUTE FUNCTION validate_nation_monster_cycle_update();

CREATE OR REPLACE FUNCTION validate_nation_monster_cycle_seed_requirement_update() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.world_id <> OLD.world_id
            OR NEW.nation_id <> OLD.nation_id
            OR NEW.cycle_start_turn <> OLD.cycle_start_turn
            OR NEW.cycle_end_turn <> OLD.cycle_end_turn
            OR NEW.created_at IS DISTINCT FROM OLD.created_at
            OR OLD.completed_at IS NOT NULL
            OR NEW.completed_at IS NULL THEN
            RAISE EXCEPTION 'Monster cycle seed requirement may only be completed once';
        END IF;
    END IF;
    IF NEW.completed_at IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM nation_monster_cycle_stats
        WHERE world_id = NEW.world_id
          AND nation_id = NEW.nation_id
          AND cycle_start_turn = NEW.cycle_start_turn
          AND cycle_end_turn = NEW.cycle_end_turn
          AND seeded_at IS NOT NULL
    ) THEN
        RAISE EXCEPTION 'Monster cycle seed requirement completion requires a corresponding seeded stat';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER nation_monster_cycle_seed_requirement_update_guard
BEFORE INSERT OR UPDATE ON nation_monster_cycle_seed_requirements
FOR EACH ROW EXECUTE FUNCTION validate_nation_monster_cycle_seed_requirement_update();

CREATE OR REPLACE FUNCTION reject_nation_achievement_delete() RETURNS trigger AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM worlds WHERE id = OLD.world_id) THEN
        RETURN OLD;
    END IF;
    RAISE EXCEPTION 'Nation achievement state is permanent while its World exists';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER nation_award_delete_guard
BEFORE DELETE ON nation_awards
FOR EACH ROW EXECUTE FUNCTION reject_nation_achievement_delete();

CREATE TRIGGER nation_monster_cycle_delete_guard
BEFORE DELETE ON nation_monster_cycle_stats
FOR EACH ROW EXECUTE FUNCTION reject_nation_achievement_delete();

CREATE TRIGGER nation_monster_cycle_seed_requirement_delete_guard
BEFORE DELETE ON nation_monster_cycle_seed_requirements
FOR EACH ROW EXECUTE FUNCTION reject_nation_achievement_delete();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Nation awards and monster-cycle history are forward-only production gameplay state.',
        );
    }
};
