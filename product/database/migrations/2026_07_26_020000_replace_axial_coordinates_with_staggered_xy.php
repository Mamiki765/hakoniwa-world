<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_spaces', function (Blueprint $table): void {
            $table->integer('min_x')->nullable();
            $table->integer('max_x')->nullable();
            $table->integer('min_y')->nullable();
            $table->integer('max_y')->nullable();
        });
        Schema::table('map_chunks', function (Blueprint $table): void {
            $table->integer('chunk_x')->nullable();
            $table->integer('chunk_y')->nullable();
            $table->integer('chunk_q')->nullable()->change();
            $table->integer('chunk_r')->nullable()->change();
        });
        Schema::table('map_cells', function (Blueprint $table): void {
            $table->integer('x')->nullable();
            $table->integer('y')->nullable();
            $table->integer('chunk_x')->nullable();
            $table->integer('chunk_y')->nullable();
            $table->unsignedSmallInteger('local_x')->nullable();
            $table->unsignedSmallInteger('local_y')->nullable();
        });
        Schema::table('nation_capitals', function (Blueprint $table): void {
            $table->integer('x')->nullable();
            $table->integer('y')->nullable();
        });
        Schema::table('nation_creation_requests', function (Blueprint $table): void {
            $table->integer('reserved_x')->nullable();
            $table->integer('reserved_y')->nullable();
        });
        Schema::table('nation_command_queue_items', function (Blueprint $table): void {
            $table->integer('target_x')->nullable();
            $table->integer('target_y')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE map_cells
            SET x = q + FLOOR((r + 1) / 2.0),
                y = r
            SQL);
        DB::statement(<<<'SQL'
            UPDATE map_cells
            SET chunk_x = FLOOR(x / 16.0),
                chunk_y = FLOOR(y / 16.0),
                local_x = x - FLOOR(x / 16.0) * 16,
                local_y = y - FLOOR(y / 16.0) * 16
            SQL);
        DB::statement(<<<'SQL'
            UPDATE map_spaces
            SET min_x = min_q + FLOOR((min_r + 1) / 2.0),
                max_x = max_q + FLOOR((max_r + 1) / 2.0),
                min_y = min_r,
                max_y = max_r,
                coordinate_system = 'staggered_square_offset'
            SQL);
        DB::statement(<<<'SQL'
            UPDATE map_spaces AS space
            SET min_x = bounds.min_x,
                max_x = bounds.max_x,
                min_y = bounds.min_y,
                max_y = bounds.max_y
            FROM (
                SELECT map_space_id, MIN(x) AS min_x, MAX(x) AS max_x, MIN(y) AS min_y, MAX(y) AS max_y
                FROM map_cells
                GROUP BY map_space_id
            ) AS bounds
            WHERE bounds.map_space_id = space.id
            SQL);
        DB::statement(<<<'SQL'
            UPDATE nation_capitals AS capital
            SET x = cell.x,
                y = cell.y
            FROM map_cells AS cell
            WHERE cell.id = capital.map_cell_id
            SQL);
        DB::statement(<<<'SQL'
            UPDATE nation_creation_requests
            SET reserved_x = reserved_q + FLOOR((reserved_r + 1) / 2.0),
                reserved_y = reserved_r
            WHERE reserved_q IS NOT NULL AND reserved_r IS NOT NULL
            SQL);
        DB::statement(<<<'SQL'
            UPDATE nation_command_queue_items
            SET target_x = target_q + FLOOR((target_r + 1) / 2.0),
                target_y = target_r
            SQL);
        DB::statement(<<<'SQL'
            UPDATE audit_events
            SET metadata = (metadata - 'q' - 'r')
                || jsonb_build_object(
                    'x', (metadata->>'q')::integer + FLOOR(((metadata->>'r')::integer + 1) / 2.0),
                    'y', (metadata->>'r')::integer
                )
            WHERE metadata ?? 'q' AND metadata ?? 'r'
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO map_chunks (
                map_space_id, chunk_q, chunk_r, chunk_x, chunk_y, version,
                generated_at, generator_id, generator_version, generation_seed,
                created_at, updated_at
            )
            SELECT
                cell.map_space_id, NULL, NULL, cell.chunk_x, cell.chunk_y,
                MAX(source.version), MAX(source.generated_at), MAX(source.generator_id),
                MAX(source.generator_version), MAX(source.generation_seed),
                MIN(source.created_at), MAX(source.updated_at)
            FROM map_cells AS cell
            JOIN map_chunks AS source ON source.id = cell.map_chunk_id
            GROUP BY cell.map_space_id, cell.chunk_x, cell.chunk_y
            SQL);
        DB::statement(<<<'SQL'
            UPDATE map_cells AS cell
            SET map_chunk_id = target.id
            FROM map_chunks AS target
            WHERE target.map_space_id = cell.map_space_id
              AND target.chunk_x = cell.chunk_x
              AND target.chunk_y = cell.chunk_y
            SQL);
        DB::statement('DELETE FROM map_chunks WHERE chunk_x IS NULL OR chunk_y IS NULL');

        $this->updateRulesetSettings(
            ['initial_q_min', 'initial_q_max', 'initial_r_min', 'initial_r_max'],
            ['initial_x_min' => 0, 'initial_x_max' => 59, 'initial_y_min' => 0, 'initial_y_max' => 59],
        );

        Schema::table('map_spaces', function (Blueprint $table): void {
            $table->integer('min_x')->nullable(false)->change();
            $table->integer('max_x')->nullable(false)->change();
            $table->integer('min_y')->nullable(false)->change();
            $table->integer('max_y')->nullable(false)->change();
            $table->dropColumn(['min_q', 'max_q', 'min_r', 'max_r']);
        });
        Schema::table('map_chunks', function (Blueprint $table): void {
            $table->integer('chunk_x')->nullable(false)->change();
            $table->integer('chunk_y')->nullable(false)->change();
            $table->dropColumn(['chunk_q', 'chunk_r']);
            $table->unique(['map_space_id', 'chunk_x', 'chunk_y'], 'map_chunks_map_space_xy_unique');
        });
        Schema::table('map_cells', function (Blueprint $table): void {
            $table->integer('x')->nullable(false)->change();
            $table->integer('y')->nullable(false)->change();
            $table->integer('chunk_x')->nullable(false)->change();
            $table->integer('chunk_y')->nullable(false)->change();
            $table->unsignedSmallInteger('local_x')->nullable(false)->change();
            $table->unsignedSmallInteger('local_y')->nullable(false)->change();
            $table->dropColumn(['q', 'r', 'chunk_q', 'chunk_r', 'local_q', 'local_r']);
            $table->unique(['map_space_id', 'x', 'y'], 'map_cells_map_space_xy_unique');
            $table->index(['map_space_id', 'chunk_x', 'chunk_y'], 'map_cells_map_space_chunk_xy_index');
        });
        Schema::table('nation_capitals', function (Blueprint $table): void {
            $table->integer('x')->nullable(false)->change();
            $table->integer('y')->nullable(false)->change();
            $table->dropColumn(['q', 'r']);
        });
        Schema::table('nation_creation_requests', function (Blueprint $table): void {
            $table->dropColumn(['reserved_q', 'reserved_r']);
        });
        Schema::table('nation_command_queue_items', function (Blueprint $table): void {
            $table->integer('target_x')->nullable(false)->change();
            $table->integer('target_y')->nullable(false)->change();
            $table->dropColumn(['target_q', 'target_r']);
        });
    }

    public function down(): void
    {
        Schema::table('map_spaces', function (Blueprint $table): void {
            $table->integer('min_q')->nullable();
            $table->integer('max_q')->nullable();
            $table->integer('min_r')->nullable();
            $table->integer('max_r')->nullable();
        });
        Schema::table('map_chunks', function (Blueprint $table): void {
            $table->integer('chunk_q')->nullable();
            $table->integer('chunk_r')->nullable();
            $table->integer('chunk_x')->nullable()->change();
            $table->integer('chunk_y')->nullable()->change();
        });
        Schema::table('map_cells', function (Blueprint $table): void {
            $table->integer('q')->nullable();
            $table->integer('r')->nullable();
            $table->integer('chunk_q')->nullable();
            $table->integer('chunk_r')->nullable();
            $table->unsignedSmallInteger('local_q')->nullable();
            $table->unsignedSmallInteger('local_r')->nullable();
        });
        Schema::table('nation_capitals', function (Blueprint $table): void {
            $table->integer('q')->nullable();
            $table->integer('r')->nullable();
        });
        Schema::table('nation_creation_requests', function (Blueprint $table): void {
            $table->integer('reserved_q')->nullable();
            $table->integer('reserved_r')->nullable();
        });
        Schema::table('nation_command_queue_items', function (Blueprint $table): void {
            $table->integer('target_q')->nullable();
            $table->integer('target_r')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE map_cells
            SET q = x - FLOOR((y + 1) / 2.0),
                r = y
            SQL);
        DB::statement(<<<'SQL'
            UPDATE map_cells
            SET chunk_q = FLOOR(q / 16.0),
                chunk_r = FLOOR(r / 16.0),
                local_q = q - FLOOR(q / 16.0) * 16,
                local_r = r - FLOOR(r / 16.0) * 16
            SQL);
        DB::statement(<<<'SQL'
            UPDATE map_spaces
            SET min_q = min_x - FLOOR((max_y + 1) / 2.0),
                max_q = max_x - FLOOR((min_y + 1) / 2.0),
                min_r = min_y,
                max_r = max_y,
                coordinate_system = 'pointy_top_axial'
            SQL);
        DB::statement(<<<'SQL'
            UPDATE map_spaces AS space
            SET min_q = bounds.min_q,
                max_q = bounds.max_q,
                min_r = bounds.min_r,
                max_r = bounds.max_r
            FROM (
                SELECT map_space_id, MIN(q) AS min_q, MAX(q) AS max_q, MIN(r) AS min_r, MAX(r) AS max_r
                FROM map_cells
                GROUP BY map_space_id
            ) AS bounds
            WHERE bounds.map_space_id = space.id
            SQL);
        DB::statement(<<<'SQL'
            UPDATE nation_capitals AS capital
            SET q = cell.q,
                r = cell.r
            FROM map_cells AS cell
            WHERE cell.id = capital.map_cell_id
            SQL);
        DB::statement(<<<'SQL'
            UPDATE nation_creation_requests
            SET reserved_q = reserved_x - FLOOR((reserved_y + 1) / 2.0),
                reserved_r = reserved_y
            WHERE reserved_x IS NOT NULL AND reserved_y IS NOT NULL
            SQL);
        DB::statement(<<<'SQL'
            UPDATE nation_command_queue_items
            SET target_q = target_x - FLOOR((target_y + 1) / 2.0),
                target_r = target_y
            SQL);
        DB::statement(<<<'SQL'
            UPDATE audit_events
            SET metadata = (metadata - 'x' - 'y')
                || jsonb_build_object(
                    'q', (metadata->>'x')::integer - FLOOR(((metadata->>'y')::integer + 1) / 2.0),
                    'r', (metadata->>'y')::integer
                )
            WHERE metadata ?? 'x' AND metadata ?? 'y'
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO map_chunks (
                map_space_id, chunk_x, chunk_y, chunk_q, chunk_r, version,
                generated_at, generator_id, generator_version, generation_seed,
                created_at, updated_at
            )
            SELECT
                cell.map_space_id, NULL, NULL, cell.chunk_q, cell.chunk_r,
                MAX(source.version), MAX(source.generated_at), MAX(source.generator_id),
                MAX(source.generator_version), MAX(source.generation_seed),
                MIN(source.created_at), MAX(source.updated_at)
            FROM map_cells AS cell
            JOIN map_chunks AS source ON source.id = cell.map_chunk_id
            GROUP BY cell.map_space_id, cell.chunk_q, cell.chunk_r
            SQL);
        DB::statement(<<<'SQL'
            UPDATE map_cells AS cell
            SET map_chunk_id = target.id
            FROM map_chunks AS target
            WHERE target.map_space_id = cell.map_space_id
              AND target.chunk_q = cell.chunk_q
              AND target.chunk_r = cell.chunk_r
            SQL);
        DB::statement('DELETE FROM map_chunks WHERE chunk_q IS NULL OR chunk_r IS NULL');

        $this->updateRulesetSettings(
            ['initial_x_min', 'initial_x_max', 'initial_y_min', 'initial_y_max'],
            ['initial_q_min' => -30, 'initial_q_max' => 29, 'initial_r_min' => -30, 'initial_r_max' => 29],
        );

        Schema::table('map_spaces', function (Blueprint $table): void {
            $table->integer('min_q')->nullable(false)->change();
            $table->integer('max_q')->nullable(false)->change();
            $table->integer('min_r')->nullable(false)->change();
            $table->integer('max_r')->nullable(false)->change();
            $table->dropColumn(['min_x', 'max_x', 'min_y', 'max_y']);
        });
        Schema::table('map_chunks', function (Blueprint $table): void {
            $table->integer('chunk_q')->nullable(false)->change();
            $table->integer('chunk_r')->nullable(false)->change();
            $table->dropColumn(['chunk_x', 'chunk_y']);
            $table->unique(['map_space_id', 'chunk_q', 'chunk_r']);
        });
        Schema::table('map_cells', function (Blueprint $table): void {
            $table->integer('q')->nullable(false)->change();
            $table->integer('r')->nullable(false)->change();
            $table->integer('chunk_q')->nullable(false)->change();
            $table->integer('chunk_r')->nullable(false)->change();
            $table->unsignedSmallInteger('local_q')->nullable(false)->change();
            $table->unsignedSmallInteger('local_r')->nullable(false)->change();
            $table->dropColumn(['x', 'y', 'chunk_x', 'chunk_y', 'local_x', 'local_y']);
            $table->unique(['map_space_id', 'q', 'r']);
            $table->index(['map_space_id', 'chunk_q', 'chunk_r']);
        });
        Schema::table('nation_capitals', function (Blueprint $table): void {
            $table->integer('q')->nullable(false)->change();
            $table->integer('r')->nullable(false)->change();
            $table->dropColumn(['x', 'y']);
        });
        Schema::table('nation_creation_requests', function (Blueprint $table): void {
            $table->dropColumn(['reserved_x', 'reserved_y']);
        });
        Schema::table('nation_command_queue_items', function (Blueprint $table): void {
            $table->integer('target_q')->nullable(false)->change();
            $table->integer('target_r')->nullable(false)->change();
            $table->dropColumn(['target_x', 'target_y']);
        });
    }

    /**
     * @param  list<string>  $remove
     * @param  array<string, int>  $add
     */
    private function updateRulesetSettings(array $remove, array $add): void
    {
        DB::table('ruleset_versions')->orderBy('id')->each(function (object $ruleset) use ($remove, $add): void {
            $settings = json_decode((string) $ruleset->settings, true, 512, JSON_THROW_ON_ERROR);
            foreach ($remove as $key) {
                unset($settings[$key]);
            }
            foreach ($add as $key => $value) {
                $settings[$key] = $value;
            }
            DB::table('ruleset_versions')->where('id', $ruleset->id)->update([
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            ]);
        });
    }
};
