<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE worlds IN SHARE ROW EXCLUSIVE MODE');
            DB::statement('LOCK TABLE turn_runs IN SHARE ROW EXCLUSIVE MODE');

            $zeroTurnWorlds = DB::table('worlds')
                ->where('current_turn', 0)
                ->orderBy('id')
                ->get(['id', 'key']);
            foreach ($zeroTurnWorlds as $world) {
                $lockKey = "hakoniwa.turn.world.{$world->id}";
                $lock = DB::selectOne(
                    'SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired',
                    [$lockKey],
                );
                if (! in_array($lock?->acquired, [true, 1, '1', 't'], true)) {
                    throw new RuntimeException(
                        "Refusing to migrate current_turn=0 world {$world->id} ({$world->key}) "
                        .'while a turn operation holds its advisory lock.',
                    );
                }
            }

            $blocked = DB::table('worlds')
                ->join('turn_runs', 'turn_runs.world_id', '=', 'worlds.id')
                ->where('worlds.current_turn', 0)
                ->where('turn_runs.is_dry_run', false)
                ->orderBy('worlds.id')
                ->orderBy('turn_runs.id')
                ->limit(20)
                ->get([
                    'worlds.id as world_id',
                    'worlds.key as world_key',
                    'turn_runs.id as turn_run_id',
                    'turn_runs.target_turn',
                    'turn_runs.status',
                ]);

            if ($blocked->isNotEmpty()) {
                $affected = $blocked->map(static fn (object $row): string => sprintf(
                    'world %d (%s), run %d, target_turn=%d, status=%s',
                    $row->world_id,
                    $row->world_key,
                    $row->turn_run_id,
                    $row->target_turn,
                    $row->status,
                ))->implode('; ');

                throw new RuntimeException(
                    'Refusing to renumber current_turn=0 Worlds with non-dry-run history: '.$affected.'. '
                    .'Resolve the recorded run explicitly before retrying this migration.',
                );
            }

            DB::table('worlds')
                ->where('current_turn', 0)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('turn_runs')
                        ->whereColumn('turn_runs.world_id', 'worlds.id')
                        ->where('turn_runs.is_dry_run', false);
                })
                ->update(['current_turn' => 1]);

            DB::statement('ALTER TABLE worlds ALTER COLUMN current_turn SET DEFAULT 1');
            DB::statement(
                'CREATE INDEX IF NOT EXISTS audit_events_player_world_turn_id_desc_idx '
                ."ON audit_events ((metadata->>'world_id'), ((metadata->>'target_turn')::bigint) DESC, id DESC) "
                ."WHERE jsonb_exists(metadata, 'target_turn')",
            );
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The one-based World turn migration is forward-only; restore from an explicit backup instead.',
        );
    }
};
