<?php

namespace App\Application;

use App\Domain\Hex\HexCoordinate;
use App\Models\MapSpace;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CapitalPlacementService
{
    /** @return list<HexCoordinate> */
    public function candidates(MapSpace $mapSpace, int $limit = 3): array
    {
        $radius = (int) config('hakoniwa.ruleset.initial_island_reservation_radius');
        $minimumDistance = (int) config('hakoniwa.ruleset.minimum_capital_distance');
        $requiredCells = 1 + 3 * $radius * ($radius + 1);
        $sql = <<<'SQL'
            SELECT candidate.q, candidate.r,
                COALESCE((
                    SELECT MIN(GREATEST(
                        ABS(capital.q - candidate.q),
                        ABS(capital.r - candidate.r),
                        ABS((capital.q + capital.r) - (candidate.q + candidate.r))
                    ))
                    FROM nation_capitals capital
                    JOIN nations n ON n.id = capital.nation_id
                    WHERE n.world_id = ?
                ), 2147483647) AS nearest_capital
            FROM map_cells candidate
            JOIN terrain_definitions candidate_terrain ON candidate_terrain.id = candidate.terrain_definition_id
            WHERE candidate.map_space_id = ?
              AND candidate.q BETWEEN ? AND ?
              AND candidate.r BETWEEN ? AND ?
              AND candidate_terrain.key = 'sea'
              AND candidate.owner_nation_id IS NULL
              AND candidate.facility_definition_id IS NULL
              AND (
                SELECT COUNT(*)
                FROM map_cells surrounding
                JOIN terrain_definitions surrounding_terrain ON surrounding_terrain.id = surrounding.terrain_definition_id
                WHERE surrounding.map_space_id = candidate.map_space_id
                  AND GREATEST(
                    ABS(surrounding.q - candidate.q),
                    ABS(surrounding.r - candidate.r),
                    ABS((surrounding.q + surrounding.r) - (candidate.q + candidate.r))
                  ) <= ?
                  AND surrounding_terrain.key = 'sea'
                  AND surrounding.owner_nation_id IS NULL
                  AND surrounding.facility_definition_id IS NULL
              ) = ?
              AND NOT EXISTS (
                SELECT 1
                FROM nation_capitals capital
                JOIN nations n ON n.id = capital.nation_id
                WHERE n.world_id = ?
                  AND GREATEST(
                    ABS(capital.q - candidate.q),
                    ABS(capital.r - candidate.r),
                    ABS((capital.q + capital.r) - (candidate.q + candidate.r))
                  ) < ?
              )
            ORDER BY nearest_capital DESC, candidate.q ASC, candidate.r ASC
            LIMIT ?
            SQL;

        $rows = DB::select($sql, [
            $mapSpace->world_id, $mapSpace->id,
            $mapSpace->min_q + $radius, $mapSpace->max_q - $radius,
            $mapSpace->min_r + $radius, $mapSpace->max_r - $radius,
            $radius, $requiredCells, $mapSpace->world_id, $minimumDistance, $limit,
        ]);

        return array_map(fn (object $row): HexCoordinate => new HexCoordinate((int) $row->q, (int) $row->r), $rows);
    }

    public function choose(MapSpace $mapSpace): HexCoordinate
    {
        return $this->candidates($mapSpace, 1)[0] ?? throw new DomainException('初期島を配置できる海域がありません。');
    }
}
