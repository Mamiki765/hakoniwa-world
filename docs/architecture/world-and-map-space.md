# World and MapSpace

本書はstaggered x/yへの座標移行時点の地図・保存境界を起点にした記録である。
当時の実装scopeを現在の未実装一覧として扱わない。現行のWorld拡張は
[World expansion foundation](world-expansion-foundation.md)、公式Turnは
[Turn pipeline](turn-pipeline.md)を参照する。

## Ownership

World は不変の ruleset version と current turn を持ち、地上は `surface` MapSpace として分離する。MapSpace は座標方式と現在生成済み bounds を持つ。

## Canonical coordinates

地上の現行正本は staggered square-tile offset の x/y である。初期 bounds は0..59×0..59で、各 y に60 cellsを持つ。DB、API、ゲームルール、Capital、command target、chunk と UI label は同じ用語を使う。

6近傍、距離、projection の正本は ADR-0003 とする。距離内部の一時 cube 成分を persistence や public DTO へ出さない。

## Storage

- `map_spaces`: coordinate_system、min_x/max_x/min_y/max_y
- `map_chunks`: chunk_x/chunk_y、version と generation metadata
- `map_cells`: x/y、chunk_x/chunk_y、local_x/local_y、terrain、facility、owner、state
- `nation_capitals`: x/y と map_cell_id
- `nation_creation_requests`: reserved_x/reserved_y
- command queue item: target_x/target_y

同一 MapSpace 内の `(x,y)` は一意で、セルの座標は作成後に変更しない。

## Initial bounds and expansion

初期 world は論理的な長方形である。実装済みのWorld expansionは既存セルを移動せず、MapSpace bounds と chunk を追加する。座標移行時点で地下・宇宙・複数Worldを範囲外としていたことを、現在の首都地下施設や地底RPGが未実装という意味に読み替えない。現在の所有・実行境界は[文書入口の基本原則](../README.md#地上地下地底の基本原則)を参照する。

## API loading

API は world 全体ではなく chunk を返す。response は chunk_x/y、absolute cell x/y、公開 terrain/facility/owner、viewer-safe details と representation version を持つ。

## Turn boundary

座標移行自体はTurnRunnerを追加しなかった。現行のTurnRunner・phase transaction・retryは[Turn pipeline](turn-pipeline.md)、乱数は[Turn randomness](turn-randomness.md)を正本とする。初期の設計gateを現在も未決として再適用しない。
