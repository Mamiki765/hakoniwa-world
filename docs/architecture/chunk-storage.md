# Chunk storage

本書は初期の地図・chunk保存設計を起点にした記録である。導入時点のscopeと、
現在のTurn・event・retryの実装状態を分ける。現行の実行境界は
[Turn pipeline](turn-pipeline.md)と[Turn randomness](turn-randomness.md)を参照する。

## 採用方式

PostgreSQL の relational rows を正本とし、`map_cells` を16×16の論理 chunk に分ける。map 全体を巨大 JSON として保存しない。turn、event、outbox、snapshotを後続scopeとしたのは、この初期chunk設計の実装範囲であり、現行システム全体の未実装宣言ではない。

## Schema

`map_spaces`:

- `coordinate_system = staggered_square_offset`
- `min_x` / `max_x`
- `min_y` / `max_y`

`map_chunks`:

- `map_space_id`
- `chunk_x` / `chunk_y`
- `version`
- generator ID、version、seed、generated timestamp
- unique `(map_space_id, chunk_x, chunk_y)`

`map_cells`:

- `map_space_id` / `map_chunk_id`
- `x` / `y`
- `chunk_x` / `chunk_y`
- `local_x` / `local_y`
- terrain、facility、owner、population と typed state
- unique `(map_space_id, x, y)`
- index `(map_space_id, chunk_x, chunk_y)`

## Calculation

```text
CHUNK_SIZE = 16
chunk_x = floorDiv(x, CHUNK_SIZE)
chunk_y = floorDiv(y, CHUNK_SIZE)
local_x = floorMod(x, CHUNK_SIZE)
local_y = floorMod(y, CHUNK_SIZE)
```

`floorDiv(-1,16) = -1`、`floorMod(-1,16) = 15` を満たす。通常の初期世界は非負座標だが、rollback と将来拡張の算術を壊さない。

初期60×60では chunk は4×4である。右端と下端は部分 chunk で、最大256セルという API 上限は維持する。

## API

```text
GET /api/v1/map-spaces/{mapSpace}/chunks/{chunkX}/{chunkY}
```

```json
{
  "data": {
    "chunk_x": 0,
    "chunk_y": 0,
    "chunk_size": 16,
    "state": "generated",
    "cells": [
      { "x": 0, "y": 0 }
    ]
  }
}
```

未生成 chunk は `state = empty` と空の cells を返す。viewer ごとの秘匿表現を含むため response は private/no-store とし、representation hash を version として使う。

## Update invariant

セル変更と対応 chunk version 更新は同じ transaction で行う。複数 chunk を lock するときは `(map_space_id, chunk_y, chunk_x)` の順に統一する。現行Turnのlock・transaction・retry境界は[Turn pipeline](turn-pipeline.md)を参照する。初期のturn engine設計gateを現在も未決として扱わない。
