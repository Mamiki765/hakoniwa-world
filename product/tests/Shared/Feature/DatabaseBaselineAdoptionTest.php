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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->sourceReleaseLedger();
        DB::statement('CREATE SCHEMA hakoniwa_diagnostic');
        DB::statement('CREATE VIEW hakoniwa_diagnostic.baseline_probe AS SELECT secretary_id, monster_experience FROM public.secretary_surface_states');
        $viewBefore = DB::selectOne("SELECT pg_get_viewdef('hakoniwa_diagnostic.baseline_probe'::regclass) AS definition")->definition;
        $before = $this->businessState();
        $ledgerBefore = DB::table('migrations')->orderBy('id')->get()->all();

        $this->assertSame(0, Artisan::call('migrate', [
            '--force' => true, '--path' => ['database/migrations/'.CurrentDatabaseBaseline::MIGRATION.'.php'],
        ]));
        $this->assertSame($before, $this->businessState());
        $this->assertEquals($ledgerBefore, DB::table('migrations')->where('migration', '<>', CurrentDatabaseBaseline::MIGRATION)->orderBy('id')->get()->all());
        $this->assertSame(1, DB::table('migrations')->where('migration', CurrentDatabaseBaseline::MIGRATION)->count());
        $this->assertSame($viewBefore, DB::selectOne("SELECT pg_get_viewdef('hakoniwa_diagnostic.baseline_probe'::regclass) AS definition")->definition);
        $this->assertSame($user->secretary()->sole()->surfaceState->monster_experience, (int) DB::table('hakoniwa_diagnostic.baseline_probe')->sole()->monster_experience);
        $this->assertSame(0, Artisan::call('migrate', [
            '--force' => true, '--path' => ['database/migrations/'.CurrentDatabaseBaseline::MIGRATION.'.php'],
        ]));
        $this->assertSame($before, $this->businessState());
    }

    public function test_adoption_refuses_schema_or_ledger_drift_without_repairing_business_data(): void
    {
        $this->sourceReleaseLedger();
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
        DB::table('migrations')->where('migration', '2026_10_11_010000_add_vault_protection_and_bulk_plan_fingerprint')->delete();
        $ledgerBefore = DB::table('migrations')->orderBy('id')->get()->all();
        try {
            app(CurrentDatabaseBaseline::class)->install();
            $this->fail('An incomplete 5.0.1 ledger must require release completion.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Complete 5.0.1 with its fixed release code', $exception->getMessage());
        }
        $this->assertSame($before, $this->businessState());
        $this->assertEquals($ledgerBefore, DB::table('migrations')->orderBy('id')->get()->all());
    }

    public function test_forward_snapshot_migration_preserves_world_data_and_does_not_hide_schema_drift(): void
    {
        $world = $this->lightweightWorld();
        $this->sourceReleaseLedger();
        $before = $world->fresh()->getAttributes();
        app(CurrentDatabaseBaseline::class)->assertExisting();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $after = $world->fresh()->getAttributes();
        $this->assertNull($after['monster_count_snapshot']);
        unset($after['monster_count_snapshot']);
        $this->assertSame($before, $after);
        app(CurrentDatabaseBaseline::class)->assertExisting();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        DB::statement('ALTER TABLE worlds ALTER COLUMN monster_count_snapshot TYPE json USING monster_count_snapshot::json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database differs');
        app(CurrentDatabaseBaseline::class)->assertExisting();
    }

    #[DataProvider('forwardExtensionDriftProvider')]
    public function test_forward_schema_extensions_preserve_strict_validation(string $drift): void
    {
        app(CurrentDatabaseBaseline::class)->assertExisting();
        DB::statement($drift);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database differs');
        app(CurrentDatabaseBaseline::class)->assertExisting();
    }

    public static function forwardExtensionDriftProvider(): array
    {
        return [
            'capital history column' => ['ALTER TABLE nations ALTER COLUMN last_capital_relocation_turn TYPE text'],
            'warehouse expansion constraint' => ['ALTER TABLE secretary_surface_states DROP CONSTRAINT secretary_warehouse_expansions_check'],
            'market receipt replay uniqueness' => ['ALTER TABLE underground_market_receipts DROP CONSTRAINT underground_market_receipts_underground_profile_id_request_id_u'],
        ];
    }

    private function sourceReleaseLedger(): void
    {
        // The supported source has the complete 5.0.1 schema, catalog and
        // immutable current snapshot. Remove later extensions and simulate the
        // source ledger to represent that adoption boundary.
        $source = file_get_contents(database_path('schema/pgsql-schema.sql'));
        foreach (['underground_market_receipts', 'underground_market_listings', 'secretary_synthesis_receipts', 'secretary_warehouse_expansion_receipts'] as $table) {
            Schema::drop($table);
        }
        DB::statement('ALTER TABLE underground_owned_equipment DROP COLUMN is_listed, DROP COLUMN source_market_listing_id');
        DB::statement('ALTER TABLE secretary_surface_states DROP CONSTRAINT secretary_warehouse_expansions_check, DROP COLUMN item_capacity_expansions');
        if ($source === false || preg_match('/^\s*CONSTRAINT user_paradox_ledger_source_check (CHECK[^\r\n]+)\r?$/m', $source, $matches) !== 1) {
            throw new RuntimeException('The canonical Paradox source constraint is missing.');
        }
        DB::statement('ALTER TABLE user_paradox_ledger DROP CONSTRAINT user_paradox_ledger_source_check');
        DB::statement('ALTER TABLE user_paradox_ledger ADD CONSTRAINT user_paradox_ledger_source_check '.$matches[1]);
        DB::table('migrations')->whereIn('migration', [
            CurrentDatabaseBaseline::MARKET_SYNTHESIS_MIGRATION,
            '2026_10_18_010000_publish_market_synthesis_ruleset',
        ])->delete();
        foreach ([
            CurrentDatabaseBaseline::QUEUE_RULESET_RESOLUTION_MIGRATION => 'enforce_queue_item_world_ruleset_match',
            CurrentDatabaseBaseline::MONSTER_SPAWN_PROVENANCE_MIGRATION => 'validate_monster_instance_world_ruleset',
        ] as $migration => $function) {
            if ($source === false || preg_match('/CREATE FUNCTION public\.'.preg_quote($function, '/').'\(\).*?^\$\$;/ms', $source, $matches) !== 1) {
                throw new RuntimeException("The canonical {$function} trigger is missing.");
            }
            DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $matches[0]));
            DB::table('migrations')->where('migration', $migration)->delete();
        }
        DB::statement('ALTER TABLE nations DROP CONSTRAINT nations_ecopo_nonnegative, DROP COLUMN ecopo');
        DB::table('migrations')->where('migration', CurrentDatabaseBaseline::ECOPO_MIGRATION)->delete();
        DB::table('migrations')->where('migration', '2026_10_15_010000_publish_ecopo_ruleset')->delete();
        DB::statement('ALTER TABLE ships DROP CONSTRAINT ships_course_check, DROP COLUMN destination_x, DROP COLUMN destination_y');
        DB::table('migrations')->where('migration', CurrentDatabaseBaseline::SHIP_DESTINATION_MIGRATION)->delete();
        Schema::table('worlds', fn ($table) => $table->dropColumn('monster_count_snapshot'));
        Schema::table('nations', fn ($table) => $table->dropColumn('last_capital_relocation_turn'));
        DB::table('migrations')->where('migration', CurrentDatabaseBaseline::MONSTER_COUNT_MIGRATION)->delete();
        DB::table('migrations')->where('migration', CurrentDatabaseBaseline::CAPITAL_RELOCATION_MIGRATION)->delete();
        DB::table('migrations')->where('migration', CurrentDatabaseBaseline::MIGRATION)->delete();
        $migrations = json_decode((string) file_get_contents(database_path('baselines/5_0_1_migrations.json')), true, 512, JSON_THROW_ON_ERROR);
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
