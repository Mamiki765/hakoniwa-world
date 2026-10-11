# Public living monster counts

`WorldMonsterCountSnapshot` records the latest surface monster counts once at the end of
an official World Turn. `CompleteTurnEngine::finalizeTurn()` calls it after Nation lifecycle
and karma finalization, so recovery removals and automatic abandonment are included. The
snapshot, World turn advancement and completed TurnRun commit in the existing TurnRunner
transaction. Failed attempts roll back the snapshot; retries replace the final values.
Dry runs do not update it.

## Counting and visibility

Only `alive` MonsterInstances with a current occupancy in that World's surface MapSpace
count. Actor definitions and occupied cells are loaded in bulk. Sea monsters use the
existing `MonsterBehaviorResolver::WATER_NEUTRALIZING` movement category; currently this
is `aoi_inora`. Other behaviors belong to the ordinary category, including `whale`.
`land_alive_count` names that ordinary category, rather than a terrain test.

The World total includes both categories and ordinary monsters on neutral land:
`total_alive_count = land_alive_count + sea_alive_count`. It is not computed from island
totals. Sea monsters never contribute to an island total. When an aoi inora digs into an
island, its occupied cell becomes neutral sea through the existing movement path; no
nearby island or spawning target is inferred as its host.

For ordinary monsters, island attribution uses the occupied cell's public owner from
`MapCellPresenter::visibleState($cell, null)`. This preserves existing disguised-ownership
policy. Every active, dormant or recovery Nation at recording time receives an entry,
including known zero. Abandoned Nations are absent.

## Saved state and API

`worlds.monster_count_snapshot` is nullable JSONB containing the four World fields below
and an internal `nation_counts` map keyed by Nation ID. It holds only the latest snapshot.
Existing and newly initialized Worlds start with null; there is no historical backfill or
request-time recount. The next successful official Turn produces the first snapshot.

`GET /api/v1/public/worlds/{world}/summary` adds:

```json
{
  "data": {
    "current_turn": 501,
    "monster_counts": {
      "counted_turn": 501,
      "total_alive_count": 8,
      "land_alive_count": 6,
      "sea_alive_count": 2
    }
  }
}
```

`monster_counts: null` means no recorded snapshot. Numeric zero means a recorded zero.
The internal Nation map is not returned in summary.

Each entry in `GET /api/v1/public/worlds/{world}/rankings` and the existing public Nation
detail adds these fields through the shared public Nation presenter:

```json
{
  "id": 17,
  "alive_monster_count": 2,
  "monster_counted_turn": 501
}
```

Without a snapshot both fields are null. A Nation registered after the latest snapshot
has a null count and the snapshot's recorded turn until the next successful Turn. Its
missing entry is not presented as zero. Cumulative kill achievements retain their existing
meaning. These additions introduce no monster or occupancy queries on public GET requests.

Turn-external registration, abandonment or administrative map changes may leave counts at
their earlier recorded values until the next successful Turn. Consumers use `counted_turn`
and `monster_counted_turn` to identify that boundary. Separate summary and rankings requests
can straddle a commit; consumers may re-fetch if their recorded turns differ. UI placement,
labels and zero presentation are frontend decisions.

## Migration boundary

The forward-only `2026_10_11_030000_add_world_monster_count_snapshot` migration runs after
the fixed 5.0.1 install/adoption marker. It adds one nullable column without rewriting rows,
the fixed schema dump or the 5.0.1 manifests. Fresh installation follows dump, baseline
validation/marker, then this migration; supported existing databases follow baseline
adoption, then this migration.

`CurrentDatabaseBaseline` continues to match complete recorded structures. Only when both
markers exist does it substitute the measured `table:worlds` definition from
`database/baselines/monster_count_structure.json`. Every other object stays exact, and
changing the snapshot column's type still fails the gate. See
[installation and baseline adoption](install-upgrade-rebaseline.md).
