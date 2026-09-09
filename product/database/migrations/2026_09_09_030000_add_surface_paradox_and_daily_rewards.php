<?php

use App\Application\Ver390RulesetUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('nation_command_queue_items', function (Blueprint $table): void {
            $table->unsignedInteger('paradox_execution_count')->default(0);
        });

        Schema::create('user_paradox_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();
        });

        Schema::create('user_paradox_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('entry_key', 180)->unique();
            $table->bigInteger('delta');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->string('source_kind', 32);
            $table->date('canonical_day')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'canonical_day']);
        });

        Schema::create('user_daily_login_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('canonical_day');
            $table->unsignedInteger('paradox_awarded');
            $table->unsignedInteger('skip_tickets_awarded');
            $table->timestampTz('claimed_at');
            $table->timestamps();
            $table->unique(['user_id', 'canonical_day']);
        });

        Schema::create('user_daily_quest_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('canonical_day');
            $table->string('quest_key', 64);
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('target');
            $table->unsignedInteger('paradox_awarded')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'canonical_day', 'quest_key'], 'user_daily_quest_progress_unique');
        });

        Schema::create('user_daily_quest_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('canonical_day');
            $table->string('quest_key', 64);
            $table->string('entry_key', 180)->unique();
            $table->unsignedInteger('amount');
            $table->timestamps();
            $table->index(['user_id', 'canonical_day', 'quest_key'], 'user_daily_quest_activity_progress_index');
        });

        DB::statement('ALTER TABLE user_paradox_balances ADD CONSTRAINT user_paradox_balance_nonnegative_check CHECK (balance >= 0)');
        DB::statement("ALTER TABLE user_paradox_ledger ADD CONSTRAINT user_paradox_ledger_delta_check CHECK (delta <> 0 AND balance_before >= 0 AND balance_after >= 0 AND balance_after = balance_before + delta), ADD CONSTRAINT user_paradox_ledger_source_check CHECK (source_kind IN ('daily_login', 'daily_quest', 'command'))");
        DB::statement('ALTER TABLE user_daily_login_claims ADD CONSTRAINT user_daily_login_award_check CHECK (paradox_awarded = 10 AND skip_tickets_awarded = 50)');
        DB::statement("ALTER TABLE user_daily_quest_progress ADD CONSTRAINT user_daily_quest_progress_key_check CHECK (quest_key IN ('development_opened', 'underground_battles', 'command_registered')), ADD CONSTRAINT user_daily_quest_progress_value_check CHECK (progress <= target AND target > 0 AND paradox_awarded IN (0, 5) AND ((completed_at IS NULL AND paradox_awarded = 0) OR (completed_at IS NOT NULL AND progress = target AND paradox_awarded = 5)))");
        DB::statement("ALTER TABLE user_daily_quest_activities ADD CONSTRAINT user_daily_quest_activity_key_check CHECK (quest_key IN ('development_opened', 'underground_battles', 'command_registered')), ADD CONSTRAINT user_daily_quest_activity_amount_check CHECK (amount > 0)");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_monster_instance_world_ruleset()
RETURNS trigger
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
    IF world_ruleset IS NULL OR definition_ruleset IS NULL OR world_ruleset <> definition_ruleset THEN
        RAISE EXCEPTION 'monster definition must belong to the World current ruleset';
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

        app(Ver390RulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException('The 3.9.0 surface Paradox and daily reward migration is forward-only.');
    }
};
