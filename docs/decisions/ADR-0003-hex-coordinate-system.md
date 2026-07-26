# ADR-0003 staggered square-tile x/y 座標

- Status: 採用
- Date: 2026-07-26
- Updated: 2026-07-26 / PR #4
- Scope: 地上 map space、DB、API、ゲームルール、command target、Vue

## Context

初期実装は pointy-top axial 座標の直積を保存し、表示時だけ staggered row へ変換していた。この方式では各 row の表示開始位置が累積して、60×60 の世界全体が平行四辺形になる。

求める表示は旧式箱庭諸島に近い32px正方形 tile である。各 row は同数のセルを持ち、偶数 row だけ右へ16pxずれ、ゲーム上は6近傍として扱う。

## Decision

地上 map space の正本座標を integer `x` / `y` とする。

- `x`: row 内の左から右へのセル番号
- `y`: 上から下への row 番号
- 初期範囲: `x = 0..59`、`y = 0..59`
- 初期セル数: 3,600
- `coordinate_system`: `staggered_square_offset`

DB、Model、API JSON、route parameter 名、command payload、capital、audit の新規 metadata、Vue type と UI label は x/y 用語だけを使う。過去 migration の backfill と rollback 以外では旧座標名を現行 interface に残さない。

## Projection

```text
TILE_SIZE = 32
HALF_TILE = 16
VERTICAL_STEP = 32

screenX = x * 32 + (floorMod(y, 2) == 0 ? 16 : 0)
screenY = y * 32
```

projection は absolute x/y へ適用する。首都周辺を中央へ置く場合は、セルと首都をそれぞれ absolute 座標から pixel 化して差を取る。首都相対 y の偶奇で offset を決めてはならない。

全 row の表示幅は1,920pxで同じである。左端は0pxと16px、右端は1,888pxと1,904pxを交互に取り、row が進んでも横方向へ drift しない。

## Directions and neighbors

direction 番号は Backend と Frontend で次に固定する。

| number | direction |
|---:|---|
| 0 | east |
| 1 | north-east |
| 2 | north-west |
| 3 | west |
| 4 | south-west |
| 5 | south-east |

偶数 y は右へ16pxずれ、近傍は west `(x-1,y)`、east `(x+1,y)`、north-west `(x,y-1)`、north-east `(x+1,y-1)`、south-west `(x,y+1)`、south-east `(x+1,y+1)` である。

奇数 y の north-west / south-west は `x-1`、north-east / south-east は `x` を使う。map bounds 外の座標は存在しない。

## Distance

公開 interface と保存層は x/y のままにする。距離実装の private な数学処理だけ、偶数 row 右ずれ offset を一時的な cube 成分へ変換してよい。

```text
first = x - floorDiv(y + 1, 2)
second = y
third = -first - second
distance = max(abs(deltaFirst), abs(deltaSecond), abs(deltaThird))
```

負数を扱う migration rollback と単体テストのため、除算は数学的 floor を使う。

## Chunking

`chunk_size = 16` とする。

```text
chunk_x = floorDiv(x, 16)
chunk_y = floorDiv(y, 16)
local_x = floorMod(x, 16)
local_y = floorMod(y, 16)
```

初期世界は `chunk_x = 0..3`、`chunk_y = 0..3` の16 chunksで、右端と下端は12×16、16×12、12×12の部分 chunk を含む。local 座標は常に0..15である。

## Migration and reset

forward migration は新列追加、既存表示と同じ変換による backfill、chunk row 再構成、unique/index 移行、旧列削除の順に行う。migration だけで world や nation は削除しない。rollback は逆変換と chunk 再構成を行う。

既存世界の外形は誤ったまま保存されるため、migration 後に `hakoniwa:world:reset` で正しい0..59の世界を再生成する。reset は users と auth identities を常に保持する。

## Consequences

- 座標変更は API breaking change である。旧 command payload は必須 x/y validation を満たさず422になる。
- 初期島、首都間距離、領土 radius、command target、keyboard 移動は同じ6近傍規則を共有する。
- turn runner、command execution、生産、災害、戦闘、scheduler はこの決定の実装範囲外である。
