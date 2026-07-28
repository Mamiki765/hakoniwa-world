<?php

namespace App\Application;

use App\Domain\Map\GridCoordinate;
use App\Models\MapSpace;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CapitalPlacementService
{
    /** @return list<GridCoordinate> */
    public function candidates(MapSpace $mapSpace, int $limit = 3): array
    {
        $rules = $mapSpace->world()->firstOrFail()->rulesetVersion()->firstOrFail()->settings;
        $radius = (int) $rules['initial_island_reservation_radius'];
        $minimumDistance = (int) $rules['minimum_capital_distance'];
        $requiredCells = 1 + 3 * $radius * ($radius + 1);
        $sql = <<<'SQL'
            WITH candidates AS (
                SELECT candidate.*,
                    candidate.x - FLOOR((candidate.y + 1) / 2.0) AS cube_x,
                    candidate.y AS cube_y
                FROM map_cells candidate
            )
            SELECT candidate.x, candidate.y,
                COALESCE((
                    SELECT MIN(GREATEST(
                        ABS((capital.x - FLOOR((capital.y + 1) / 2.0)) - candidate.cube_x),
                        ABS(capital.y - candidate.cube_y),
                        ABS(
                            ((capital.x - FLOOR((capital.y + 1) / 2.0)) + capital.y)
                            - (candidate.cube_x + candidate.cube_y)
                        )
                    ))
                    FROM nation_capitals capital
                    JOIN nations n ON n.id = capital.nation_id
                    WHERE n.world_id = ?
                ), 2147483647) AS nearest_capital
            FROM candidates candidate
            JOIN terrain_definitions candidate_terrain ON candidate_terrain.id = candidate.terrain_definition_id
            WHERE candidate.map_space_id = ?
              AND candidate.x BETWEEN ? AND ?
              AND candidate.y BETWEEN ? AND ?
              AND candidate_terrain.key = 'sea'
              AND candidate.owner_nation_id IS NULL
              AND candidate.facility_definition_id IS NULL
              AND (
                SELECT COUNT(*)
                FROM map_cells surrounding
                JOIN terrain_definitions surrounding_terrain ON surrounding_terrain.id = surrounding.terrain_definition_id
                WHERE surrounding.map_space_id = candidate.map_space_id
                  AND GREATEST(
                    ABS(
                        (surrounding.x - FLOOR((surrounding.y + 1) / 2.0))
                        - candidate.cube_x
                    ),
                    ABS(surrounding.y - candidate.cube_y),
                    ABS(
                        ((surrounding.x - FLOOR((surrounding.y + 1) / 2.0)) + surrounding.y)
                        - (candidate.cube_x + candidate.cube_y)
                    )
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
                    ABS(
                        (capital.x - FLOOR((capital.y + 1) / 2.0))
                        - candidate.cube_x
                    ),
                    ABS(capital.y - candidate.cube_y),
                    ABS(
                        ((capital.x - FLOOR((capital.y + 1) / 2.0)) + capital.y)
                        - (candidate.cube_x + candidate.cube_y)
                    )
                  ) < ?
              )
            ORDER BY nearest_capital DESC, candidate.y ASC, candidate.x ASC
            LIMIT ?
            SQL;

        $rows = DB::select($sql, [
            $mapSpace->world_id, $mapSpace->id,
            $mapSpace->min_x + $radius, $mapSpace->max_x - $radius,
            $mapSpace->min_y + $radius, $mapSpace->max_y - $radius,
            $radius, $requiredCells, $mapSpace->world_id, $minimumDistance, $limit,
        ]);

        return array_map(
            fn (object $row): GridCoordinate => new GridCoordinate((int) $row->x, (int) $row->y),
            $rows,
        );
    }

    public function choose(MapSpace $mapSpace): GridCoordinate
    {
        return $this->candidates($mapSpace, 1)[0]
            ?? throw new DomainException('初期島を配置できる海域がありません。');
    }
}
