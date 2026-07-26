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
  "position": 4,
  "request_key": "00000000-0000-4000-8000-000000000000",
  "expected_version": 1,
  "parameters": {}
}
```

queue response の各 item も `target_x` / `target_y` を返す。command definition preview query は `?target_x=12&target_y=8` を使う。旧 payload は長期互換しない。x/y がない request は422 validation errorになる。

PR5ではresponseへ常に20件の`plan`を追加する。DBへ保存するのは明示commandだけで、空きpositionは`kind: automatic_finance`の表示用placeholderとする。挿入位置、全明示itemのposition指定reorder、取消後の左詰め、generic JSON parametersの編集を提供する。掘削quantityは1–99、default 1、preset 1/5/10/25/50/99であり、turn executionは引き続き行わない。詳細は`docs/architecture/public-lobby-and-island-dashboard.md`を参照する。

## Safety retained

- user / nation / world / map space authorization
- target bounds と cell existence
- terrain / facility / ownership validation
- queue 上限20
- leader version による optimistic concurrency
- request key による idempotency
- stale refresh generation token と AbortController
- add / reorder / cancel audit
- parameter validation / edit audit

audit の新規 coordinate metadata は x/y とする。

## Non-scope

command execution、cost deduction、terrain/facility mutation、production、workforce、food、population change、auto sale、disaster、monster、missile、combat、scheduler は実装しない。
