<?php

namespace Tests\Shared\Feature;

use App\Application\CurrentDatabaseBaseline;
use App\Application\NationCreationService;
use App\Application\Underground\UndergroundProfileService;
use App\Models\TurnRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\Support\SyntheticHistoricalRulesetSnapshot;
use Tests\TestCase;

final class DatabaseBaselineAdoptionTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    public function test_adoption_appends_its_marker_without_changing_assets_historical_ids_or_diagnostic_dependencies(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        app(NationCreationService::class)->create($user, $world, '基準採用国', '基準採用島');
        app(UndergroundProfileService::class)->ensureForSecretary($user->secretary()->sole())->update([
            'combat_xp' => 7, 'shard_balance' => 61, 'banked_shard_balance' => 23,
        ]);
        $historical = SyntheticHistoricalRulesetSnapshot::create('baseline-retained-history', 26);
        $historicalWorld = $world->replicate()->fill([
            'key' => 'baseline-retained-history',
            'ruleset_version_id' => $historical->id,
        ]);
        $historicalWorld->save();
        TurnRun::query()->create([
            'world_id' => $historicalWorld->id,
            'target_turn' => 2,
            'ruleset_version_id' => $historical->id,
            'random_seed' => str_repeat('a', 64),
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $this->legacyLedger();
        DB::statement('CREATE SCHEMA hakoniwa_diagnostic');
        DB::statement('CREATE VIEW hakoniwa_diagnostic.baseline_probe AS SELECT secretary_id, monster_experience FROM public.secretary_surface_states');
        $viewBefore = DB::selectOne("SELECT pg_get_viewdef('hakoniwa_diagnostic.baseline_probe'::regclass) AS definition")->definition;
        $before = $this->businessState();
        $ledgerBefore = DB::table('migrations')->orderBy('id')->get()->all();

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame($before, $this->businessState());
        $this->assertEquals($ledgerBefore, DB::table('migrations')->where('migration', '<>', CurrentDatabaseBaseline::MIGRATION)->orderBy('id')->get()->all());
        $this->assertSame(1, DB::table('migrations')->where('migration', CurrentDatabaseBaseline::MIGRATION)->count());
        $this->assertSame($viewBefore, DB::selectOne("SELECT pg_get_viewdef('hakoniwa_diagnostic.baseline_probe'::regclass) AS definition")->definition);
        $this->assertSame($user->secretary()->sole()->surfaceState->monster_experience, (int) DB::table('hakoniwa_diagnostic.baseline_probe')->sole()->monster_experience);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame($before, $this->businessState());
    }

    public function test_adoption_refuses_schema_or_ledger_drift_without_repairing_business_data(): void
    {
        $this->legacyLedger();
        $before = $this->businessState();
        $ledgerBefore = DB::table('migrations')->orderBy('id')->get()->all();
        DB::statement('ALTER TABLE users ALTER COLUMN display_name TYPE text');
        try {
            app(CurrentDatabaseBaseline::class)->install();
            $this->fail('Schema drift must require a reviewed conversion.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Database differs', $exception->getMessage());
        }
        $this->assertSame($before, $this->businessState());
        $this->assertEquals($ledgerBefore, DB::table('migrations')->orderBy('id')->get()->all());
        DB::statement('ALTER TABLE users ALTER COLUMN display_name TYPE character varying(255)');
        DB::table('migrations')->where('migration', '2026_09_23_030000_install_4_4_0')->delete();
        $ledgerBefore = DB::table('migrations')->orderBy('id')->get()->all();
        try {
            app(CurrentDatabaseBaseline::class)->install();
            $this->fail('An incomplete legacy ledger must require baseline completion.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Complete the accepted 4.9.0 migration chain', $exception->getMessage());
        }
        $this->assertSame($before, $this->businessState());
        $this->assertEquals($ledgerBefore, DB::table('migrations')->orderBy('id')->get()->all());
    }

    private function legacyLedger(): void
    {
        // This test owns the pre-v28 baseline boundary; restore its exact schema
        // and skill constraint before adopting its marker. Later upgrades have their own tests.
        Schema::table('underground_owned_equipment', fn ($table) => $table->dropColumn('quality_percent'));
        Schema::table('underground_profiles', static function ($table): void {
            $table->dropColumn([
                'yunagi_harbor_intro_completed_at',
                'mad_moon_unlocked_at',
                'mad_moon_intro_completed_at',
                'mad_moon_cleared_at',
                'mad_moon_victory_scene_completed_at',
            ]);
        });
        DB::statement('ALTER TABLE underground_battles DROP CONSTRAINT underground_battles_activity_type_check');
        DB::statement("ALTER TABLE underground_battles ADD CONSTRAINT underground_battles_activity_type_check CHECK (activity_type = ANY ((ARRAY['exploration'::character varying, 'trial'::character varying, 'tutorial'::character varying, 'story'::character varying, 'playtest'::character varying, 'guide_duel'::character varying])::text[]))");
        DB::statement('ALTER TABLE map_chunks DROP CONSTRAINT map_chunks_weather_check');
        Schema::table('map_chunks', static function ($table): void {
            $table->dropColumn(['weather_key', 'weather_turn']);
        });
        Schema::drop('oil_discovery_backfills');
        Schema::drop('user_achievements');
        Schema::table('secretaries', fn ($table) => $table->dropColumn('equipped_title_key'));
        DB::table('secretary_skills')->whereIn('skill_key', ['energy_saving', 'oil_development'])->delete();
        DB::statement('ALTER TABLE secretary_skills DROP CONSTRAINT secretary_skills_key_check');
        DB::statement("ALTER TABLE secretary_skills ADD CONSTRAINT secretary_skills_key_check CHECK (skill_key = ANY ((ARRAY['agricultural_policy'::character varying, 'specialty_development'::character varying, 'gold_vein_survey'::character varying, 'forest_management'::character varying, 'final_defense_line'::character varying, 'declining_birthrate_policy'::character varying, 'indomitable'::character varying, 'ship_operations'::character varying, 'navy'::character varying])::text[]))");
        DB::table('migrations')->where('migration', CurrentDatabaseBaseline::MIGRATION)->delete();
        $migrations = json_decode((string) file_get_contents(database_path('baselines/4_9_0_migrations.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($migrations as $migration) {
            DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
        }
    }

    private function businessState(): string
    {
        $state = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations' ORDER BY tablename") as $table) {
            $name = $table->tablename;
            $state[$name] = DB::select('SELECT row_to_json(t)::text AS row FROM "'.$name.'" t ORDER BY row_to_json(t)::text');
        }
        foreach (DB::select("SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' AND sequencename <> 'migrations_id_seq' ORDER BY sequencename") as $sequence) {
            $state['sequence:'.$sequence->sequencename] = DB::select('SELECT last_value, is_called FROM "'.$sequence->sequencename.'"');
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
