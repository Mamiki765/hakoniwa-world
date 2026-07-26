# Command API

## Scope

Roadmap PR2 で command definition と queue 操作だけを提供する。queue item は turn engine が将来実行する予約であり、本 scope では地形、施設、資金、資源を変更しない。

## Coordinate contract

command target は canonical x/y である。

```json
{
  "command_key": "build_farm",
  "target_x": 12,
  "target_y": 8,
  "request_key": "00000000-0000-4000-8000-000000000000",
  "expected_version": 1,
  "parameters": {}
}
```

queue response の各 item も `target_x` / `target_y` を返す。command definition preview query は `?target_x=12&target_y=8` を使う。旧 payload は長期互換しない。x/y がない request は422 validation errorになる。

## Safety retained

- user / nation / world / map space authorization
- target bounds と cell existence
- terrain / facility / ownership validation
- queue 上限20
- leader version による optimistic concurrency
- request key による idempotency
- stale refresh generation token と AbortController
- add / reorder / cancel audit

audit の新規 coordinate metadata は x/y とする。

## Non-scope

command execution、cost deduction、terrain/facility mutation、production、workforce、food、population change、auto sale、disaster、monster、missile、combat、scheduler は実装しない。
