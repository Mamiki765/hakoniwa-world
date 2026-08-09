<?php

namespace App\Application;

use App\Domain\Turn\TurnContext;
use App\Models\Nation;
use App\Models\NationMonsterCycleStat;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;

final class MonsterKillCycleService
{
    /** @return array{start: int, end: int} */
    public function intervalForTurn(int $targetTurn): array
    {
        if ($targetTurn < 1) {
            throw new DomainException('Monster award cycle requires a positive target turn.');
        }
        $start = intdiv($targetTurn - 1, 100) * 100 + 1;

        return ['start' => $start, 'end' => $start + 99];
    }

    /** @return array{previous: int, current: int} */
    public function increment(TurnContext $context, Nation $nation): array
    {
        $interval = $this->intervalForTurn($context->targetTurn);
        $row = DB::selectOne(<<<'SQL'
INSERT INTO nation_monster_cycle_stats (
    world_id, nation_id, cycle_start_turn, cycle_end_turn, kill_count,
    version, seeded_at, created_at, updated_at
) VALUES (?, ?, ?, ?, 1, 1, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (world_id, nation_id, cycle_start_turn) DO UPDATE SET
    kill_count = nation_monster_cycle_stats.kill_count + 1,
    version = nation_monster_cycle_stats.version + 1,
    updated_at = CURRENT_TIMESTAMP
WHERE nation_monster_cycle_stats.cycle_end_turn = EXCLUDED.cycle_end_turn
RETURNING kill_count
SQL, [$context->world->id, $nation->id, $interval['start'], $interval['end']]);
        if ($row === null) {
            throw new DomainException('Monster award cycle increment found inconsistent interval state.');
        }
        $current = (int) $row->kill_count;

        return ['previous' => $current - 1, 'current' => $current];
    }

    /**
     * @param  list<int>  $nationIds
     * @return array<int, int>
     */
    public function counts(World $world, int $targetTurn, array $nationIds): array
    {
        $interval = $this->intervalForTurn($targetTurn);
        $counts = array_fill_keys($nationIds, 0);
        if ($nationIds === []) {
            return $counts;
        }
        $stored = NationMonsterCycleStat::query()
            ->where('world_id', $world->id)
            ->where('cycle_start_turn', $interval['start'])
            ->where('cycle_end_turn', $interval['end'])
            ->whereIn('nation_id', $nationIds)
            ->pluck('kill_count', 'nation_id');
        foreach ($stored as $nationId => $count) {
            $counts[(int) $nationId] = (int) $count;
        }

        return $counts;
    }

    /** @param list<int> $nationIds */
    public function initializeNextInterval(World $world, int $completedTargetTurn, array $nationIds): int
    {
        $next = $this->intervalForTurn($completedTargetTurn + 1);
        $created = 0;
        foreach ($nationIds as $nationId) {
            $created += DB::table('nation_monster_cycle_stats')->insertOrIgnore([
                'world_id' => $world->id,
                'nation_id' => $nationId,
                'cycle_start_turn' => $next['start'],
                'cycle_end_turn' => $next['end'],
                'kill_count' => 0,
                'version' => 1,
                'seeded_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $created;
    }
}
