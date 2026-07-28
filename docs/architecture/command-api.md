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
  "quantity": 5,
  "request_key": "00000000-0000-4000-8000-000000000000",
  "expected_version": 1,
  "parameters": {}
}
```

queue response の各 item も `target_x` / `target_y` を返す。command definition preview query は `?target_x=12&target_y=8` を使う。旧 payload は長期互換しない。x/y がない request は422 validation errorになる。

PR5ではresponseへ常に20件の`plan`を追加する。DBへ保存するのは明示commandだけで、空きpositionは`kind: automatic_finance`の表示用placeholderとする。挿入位置、全明示itemのposition指定reorder、取消後の左詰めを提供する。

PR6では全command共通の`quantity`をfirst-class column/API fieldへ移す。範囲は1–99、default 1、presetは1/5/10/25/50/99である。省略は1、明示的な`null`、0、100、負数、float、数値string、boolean、array、objectは422とする。quantity 99でもqueue itemは1件だけ保存し、cost multiplication、複数turnへのdecrement、command executionは行わない。automatic finance placeholderのquantityは`null`である。

definition endpointはcommandごとのquantity metadataを返さず、top-levelの`quantity_contract`と`commands`を返す。queue responseの明示itemは常に`quantity`を返し、quantity編集はtop-level fieldだけをPATCHする。詳細は`docs/architecture/public-lobby-and-island-dashboard.md`を参照する。

`parameters`は将来のcommand固有値だけに残し、`parameters.quantity`を受理しない。validatorはkeyの不存在と明示的`null`を区別する。required keyの不存在はschemaにdefaultがある場合だけdefaultを適用できる。required keyの明示的`null`は422、optional keyの`null`はschemaが`nullable: true`を明示した場合だけ許可する。

## Development plan / turn engine boundary

開発計画はcommand、target x/y、position、quantity、特殊command用の空のparameters拡張点を保持し、追加、並べ替え、取消、20枠表示、optimistic lock、auditだけを担う。quantityの意味を解釈しない。

将来のturn engine handlerだけが、quantityの意味、実行回数、費用、成功／失敗、decrement、itemを残すか削除するか、先頭へ戻すか、1turn内で一括処理するか、デザインや効果、特殊parameters、実行結果を判断する。

例として`build_farm`は複数turnの残数、`excavate`は同一turnの一括回数と費用・油田判定、将来の`monument`はデザイン番号としてquantityを使う可能性がある。meteor itemはquantityとは別にinventory item IDをparametersへ要求する可能性がある。これらは拡張例であり、PR6では仕様を確定せず実装もしない。

## Safety retained

- user / nation / world / map space authorization
- target bounds と cell existence
- terrain / facility / ownership validation
- queue 上限20
- leader version による optimistic concurrency
- request key による idempotency
- stale refresh generation token と AbortController
- add / reorder / cancel audit
- parameter validation / quantity edit audit

audit の新規 coordinate metadata は x/y とする。

## Non-scope

command execution、cost deduction、terrain/facility mutation、production、workforce、food、population change、auto sale、disaster、monster、missile、combat、scheduler は実装しない。
