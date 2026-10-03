<?php

namespace App\Application;

use App\Models\World;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CurrentDatabaseBaseline
{
    public const MIGRATION = '2026_10_02_000000_install_4_9_0_baseline';

    /** @return array<string, string> */
    public function structure(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The current baseline requires PostgreSQL.');
        }

        $rows = DB::select(<<<'SQL'
SELECT 'table:' || c.relname AS object_key,
       jsonb_build_object(
         'kind', c.relkind, 'row_security', c.relrowsecurity,
         'force_row_security', c.relforcerowsecurity,
         'columns', COALESCE((
           SELECT jsonb_agg(jsonb_build_array(
             a.attname, format_type(a.atttypid, a.atttypmod), a.attnotnull,
             pg_get_expr(d.adbin, d.adrelid), a.attidentity, a.attgenerated,
             coll.collname
           ) ORDER BY a.attnum)
           FROM pg_attribute a
           LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
           LEFT JOIN pg_collation coll ON coll.oid = a.attcollation
           WHERE a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
         ), '[]'::jsonb),
         'constraints', COALESCE((
           SELECT jsonb_agg(jsonb_build_array(
             k.conname, k.contype, k.convalidated, k.condeferrable, k.condeferred,
             pg_get_constraintdef(k.oid)
           ) ORDER BY k.conname)
           FROM pg_constraint k WHERE k.conrelid = c.oid
         ), '[]'::jsonb),
         'indexes', COALESCE((
           SELECT jsonb_agg(jsonb_build_array(
             i.relname, ix.indisvalid, ix.indisready, pg_get_indexdef(ix.indexrelid)
           ) ORDER BY i.relname)
           FROM pg_index ix JOIN pg_class i ON i.oid = ix.indexrelid
           WHERE ix.indrelid = c.oid
         ), '[]'::jsonb),
         'triggers', COALESCE((
           SELECT jsonb_agg(jsonb_build_array(
             t.tgname, t.tgenabled, pg_get_triggerdef(t.oid)
           ) ORDER BY t.tgname)
           FROM pg_trigger t WHERE t.tgrelid = c.oid AND NOT t.tgisinternal
         ), '[]'::jsonb)
       )::text AS definition
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND c.relname <> 'migrations'
UNION ALL
SELECT 'sequence:' || c.relname,
       jsonb_build_array(
         format_type(s.seqtypid, NULL), s.seqstart, s.seqincrement,
         s.seqmax, s.seqmin, s.seqcache, s.seqcycle
       )::text
FROM pg_sequence s JOIN pg_class c ON c.oid = s.seqrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relname <> 'migrations_id_seq'
UNION ALL
SELECT 'function:' || p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')',
       pg_get_functiondef(p.oid)
FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'public' AND p.prokind = 'f'
ORDER BY object_key
SQL);
        $objects = [];
        foreach ($rows as $row) {
            $objects[$row->object_key] = hash('sha256', $row->definition);
        }

        return $objects;
    }

    public function assertStructure(): void
    {
        $expected = json_decode(
            (string) file_get_contents(database_path('baselines/4_9_0_structure.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $actual = $this->structure();
        // Production's migration ancestry and a fresh dump differ in physical
        // column order and PostgreSQL's literal-array CHECK representation.
        // Match complete reviewed structures; never normalize or omit guarantees.
        foreach ($expected as $variant) {
            if ($actual === $variant) {
                return;
            }
        }
        $production = $expected['production_4_9_0'];
        $differences = array_keys(array_diff_assoc($production, $actual) + array_diff_assoc($actual, $production));
        throw new RuntimeException('Database differs from the accepted 4.9.0 baseline: '.implode(', ', $differences).'. Do not reset or repair data automatically.');
    }

    public function assertExisting(): void
    {
        $this->assertStructure();
        $applied = DB::table('migrations')->pluck('migration')->all();
        if (! in_array(self::MIGRATION, $applied, true)) {
            $required = json_decode(
                (string) file_get_contents(database_path('baselines/4_9_0_migrations.json')),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $missing = array_diff($required, $applied);
            if ($missing !== []) {
                throw new RuntimeException('Complete the accepted 4.9.0 migration chain before adopting this baseline. Missing: '.implode(', ', $missing));
            }
        }

        /** @var array<string, mixed> $settings */
        $settings = config('hakoniwa.ruleset');
        app(CurrentCatalogInstaller::class)->assertInstalled($settings);
        $published = app(RulesetPublisher::class)->assertPublished($settings);
        $world = World::query()->where('key', config('hakoniwa.world.key'))->first();
        if ($world !== null && (int) $world->ruleset_version_id !== (int) $published->id) {
            throw new RuntimeException('The shared World has not reached the accepted current Ruleset. No identity conversion was performed.');
        }
    }

    public function install(): void
    {
        if (DB::table('migrations')->exists()) {
            // Adoption only reads existing business data. Laravel appends the new
            // migration record after success; all previous ledger rows are retained.
            $this->assertExisting();

            return;
        }

        $this->assertStructure();
        foreach (array_keys($this->structure()) as $object) {
            if (! str_starts_with($object, 'table:')) {
                continue;
            }
            $table = substr($object, 6);
            if (DB::table($table)->exists()) {
                throw new RuntimeException("A database without a migration ledger contains {$table} data. Fresh baseline installation is refused.");
            }
        }
        /** @var array<string, mixed> $settings */
        $settings = config('hakoniwa.ruleset');
        app(CurrentCatalogInstaller::class)->install($settings);
        app(RulesetPublisher::class)->publish($settings);
        app(CurrentBaselineSeeds::class)->install();
    }
}
