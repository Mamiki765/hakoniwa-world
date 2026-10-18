<?php

namespace App\Application;

use App\Models\World;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CurrentDatabaseBaseline
{
    public const MIGRATION = '2026_10_11_020000_install_5_0_1_baseline';

    public const MONSTER_COUNT_MIGRATION = '2026_10_11_030000_add_world_monster_count_snapshot';

    public const SHIP_DESTINATION_MIGRATION = '2026_10_14_000000_add_ship_destinations';

    public const CAPITAL_RELOCATION_MIGRATION = '2026_10_14_000000_add_last_capital_relocation_turn_to_nations';

    public const ECOPO_MIGRATION = '2026_10_15_000000_add_nation_ecopo';

    public const QUEUE_RULESET_RESOLUTION_MIGRATION = '2026_10_17_010000_resolve_queued_commands_in_current_world_ruleset';

    public const MONSTER_SPAWN_PROVENANCE_MIGRATION = '2026_10_17_020000_preserve_monster_spawn_provenance_across_rulesets';

    public const MARKET_SYNTHESIS_MIGRATION = '2026_10_18_000000_add_market_synthesis_and_warehouse_expansion';

    /** @return array<string, string> */
    public function structure(bool $excludeCapitalRelocationTurn = false, bool $excludeEcopo = false): array
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
             AND NOT (c.relname = 'nations' AND a.attname = 'last_capital_relocation_turn' AND CAST(? AS boolean))
             AND NOT (c.relname = 'nations' AND a.attname = 'ecopo' AND CAST(? AS boolean))
         ), '[]'::jsonb),
         'constraints', COALESCE((
           SELECT jsonb_agg(jsonb_build_array(
             k.conname, k.contype, k.convalidated, k.condeferrable, k.condeferred,
             pg_get_constraintdef(k.oid)
           ) ORDER BY k.conname)
           FROM pg_constraint k WHERE k.conrelid = c.oid
             AND NOT (c.relname = 'nations' AND k.conname IN ('nations_ecopo_nonnegative', 'nations_ecopo_not_null') AND CAST(? AS boolean))
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
SQL, [$excludeCapitalRelocationTurn, $excludeEcopo, $excludeEcopo]);
        $objects = [];
        foreach ($rows as $row) {
            $objects[$row->object_key] = hash('sha256', $row->definition);
        }

        return $objects;
    }

    public function assertStructure(): void
    {
        $expected = json_decode(
            (string) file_get_contents(database_path('baselines/5_0_1_structure.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $applied = DB::table('migrations')->pluck('migration')->all();
        $hasEcopo = in_array(self::ECOPO_MIGRATION, $applied, true);
        if ($hasEcopo) {
            $this->assertEcopoExtension();
        }
        $hasCapitalRelocationTurn = in_array(self::CAPITAL_RELOCATION_MIGRATION, $applied, true);
        if ($hasCapitalRelocationTurn) {
            if (! in_array(self::MIGRATION, $applied, true)) {
                throw new RuntimeException('Capital relocation history requires the accepted 5.0.1 baseline marker first.');
            }
            $column = DB::selectOne(<<<'SQL'
SELECT jsonb_build_array(
    format_type(a.atttypid, a.atttypmod), a.attnotnull,
    pg_get_expr(d.adbin, d.adrelid), a.attidentity, a.attgenerated, coll.collname,
    a.attnum = (SELECT max(attnum) FROM pg_attribute WHERE attrelid = c.oid AND NOT attisdropped
        AND NOT (attname = 'ecopo' AND CAST(? AS boolean)))
)::text AS definition
FROM pg_attribute a
JOIN pg_class c ON c.oid = a.attrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
LEFT JOIN pg_collation coll ON coll.oid = a.attcollation
WHERE n.nspname = 'public' AND c.relname = 'nations'
  AND a.attname = 'last_capital_relocation_turn' AND NOT a.attisdropped
SQL, [$hasEcopo]);
            if ($column?->definition !== '["bigint", false, null, "", "", null, true]') {
                throw new RuntimeException('Database differs from the capital relocation history extension.');
            }
        }
        // Validate the appended column separately so every accepted baseline's
        // original column order, defaults, constraints, indexes and triggers
        // still match their complete fingerprints without rebuilding a baseline.
        $actual = $this->structure(excludeCapitalRelocationTurn: $hasCapitalRelocationTurn, excludeEcopo: $hasEcopo);
        if (in_array(self::MONSTER_COUNT_MIGRATION, $applied, true)) {
            if (! in_array(self::MIGRATION, $applied, true)) {
                throw new RuntimeException('Monster count snapshots require the accepted 5.0.1 baseline marker first.');
            }
            // Only the exact, measured worlds definition changes after this
            // forward migration. Every other baseline object remains strict.
            $extension = json_decode(
                (string) file_get_contents(database_path('baselines/monster_count_structure.json')),
                true, 512, JSON_THROW_ON_ERROR,
            );
            foreach ($expected as $key => $variant) {
                $expected[$key] = array_replace($variant, $extension);
            }
        }
        if (in_array(self::SHIP_DESTINATION_MIGRATION, $applied, true)) {
            if (! in_array(self::MIGRATION, $applied, true)) {
                throw new RuntimeException('Ship destinations require the accepted 5.0.1 baseline marker first.');
            }
            $extension = json_decode(
                (string) file_get_contents(database_path('baselines/ship_destination_structure.json')),
                true, 512, JSON_THROW_ON_ERROR,
            );
            foreach ($expected as $key => $variant) {
                $expected[$key] = array_replace($variant, $extension[$key]);
            }
        }
        if (in_array('2026_10_14_130000_publish_undersea_construction_ship_ruleset', $applied, true)) {
            $extension = json_decode(
                (string) file_get_contents(database_path('baselines/undersea_construction_ship_structure.json')),
                true, 512, JSON_THROW_ON_ERROR,
            );
            foreach ($expected as $key => $variant) {
                $expected[$key] = array_replace($variant, $extension);
            }
        }
        if (in_array(self::QUEUE_RULESET_RESOLUTION_MIGRATION, $applied, true)) {
            if (! in_array(self::MIGRATION, $applied, true)) {
                throw new RuntimeException('Current World queue command resolution requires the accepted 5.0.1 baseline marker first.');
            }
            $extension = json_decode(
                (string) file_get_contents(database_path('baselines/current_world_queue_ruleset_structure.json')),
                true, 512, JSON_THROW_ON_ERROR,
            );
            foreach ($expected as $key => $variant) {
                $expected[$key] = array_replace($variant, $extension);
            }
        }
        if (in_array(self::MONSTER_SPAWN_PROVENANCE_MIGRATION, $applied, true)) {
            if (! in_array(self::MIGRATION, $applied, true)) {
                throw new RuntimeException('Monster spawn provenance requires the accepted 5.0.1 baseline marker first.');
            }
            $extension = json_decode(
                (string) file_get_contents(database_path('baselines/monster_spawn_provenance_structure.json')),
                true, 512, JSON_THROW_ON_ERROR,
            );
            foreach ($expected as $key => $variant) {
                $expected[$key] = array_replace($variant, $extension);
            }
        }
        if (in_array(self::MARKET_SYNTHESIS_MIGRATION, $applied, true)) {
            if (! in_array(self::MIGRATION, $applied, true)) {
                throw new RuntimeException('Market and synthesis require the accepted 5.0.1 baseline marker first.');
            }
            $extension = json_decode(
                (string) file_get_contents(database_path('baselines/market_synthesis_structure.json')),
                true, 512, JSON_THROW_ON_ERROR,
            );
            foreach ($expected as $key => $variant) {
                $expected[$key] = array_replace($variant, $extension[$key]);
                ksort($expected[$key]);
            }
        }
        // Production's migration ancestry and a fresh dump differ in physical
        // column order and PostgreSQL's literal-array CHECK representation.
        // Match complete reviewed structures; never normalize or omit guarantees.
        foreach ($expected as $variant) {
            if ($actual === $variant) {
                return;
            }
        }
        $production = $expected['production_5_0_1'];
        $differences = array_keys(array_diff_assoc($production, $actual) + array_diff_assoc($actual, $production));
        throw new RuntimeException('Database differs from the accepted 5.0.1 baseline: '.implode(', ', $differences).'. Do not reset or repair data automatically.');
    }

    private function assertEcopoExtension(): void
    {
        $column = DB::selectOne(<<<'SQL'
SELECT jsonb_build_array(
    format_type(a.atttypid, a.atttypmod), a.attnotnull,
    pg_get_expr(d.adbin, d.adrelid), a.attidentity, a.attgenerated, coll.collname,
    a.attnum = (SELECT max(attnum) FROM pg_attribute WHERE attrelid = c.oid AND NOT attisdropped)
)::text AS definition
FROM pg_attribute a
JOIN pg_class c ON c.oid = a.attrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
LEFT JOIN pg_collation coll ON coll.oid = a.attcollation
WHERE n.nspname = 'public' AND c.relname = 'nations' AND a.attname = 'ecopo' AND NOT a.attisdropped
SQL);
        $constraint = DB::selectOne(<<<'SQL'
SELECT pg_get_constraintdef(oid) AS definition, convalidated
FROM pg_constraint WHERE conrelid = 'public.nations'::regclass AND conname = 'nations_ecopo_nonnegative'
SQL);
        $expectedColumn = ['bigint', true, "'0'::bigint", '', '', null, true];
        if (json_decode($column->definition ?? 'null', true, 512, JSON_THROW_ON_ERROR) !== $expectedColumn
            || $constraint?->definition !== 'CHECK ((ecopo >= 0))' || ! $constraint->convalidated) {
            throw new RuntimeException('Database differs from the Ecopo extension.');
        }
    }

    public function assertExisting(): void
    {
        $this->assertStructure();
        $applied = DB::table('migrations')->pluck('migration')->all();
        if (! in_array(self::MIGRATION, $applied, true)) {
            $required = json_decode(
                (string) file_get_contents(database_path('baselines/5_0_1_migrations.json')),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $missing = array_diff($required, $applied);
            if ($missing !== []) {
                throw new RuntimeException('Complete 5.0.1 with its fixed release code before adopting this baseline. Missing: '.implode(', ', $missing));
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
