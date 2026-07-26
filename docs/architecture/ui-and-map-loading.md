# UI and map loading

## API boundary

API prefix は `/api/v1`、map response は可読な JSON、取得単位は16×16 chunk とする。

- `GET /api/v1/worlds/{world}/map-spaces`
- `GET /api/v1/map-spaces/{mapSpace}/chunks/{chunkX}/{chunkY}`
- `GET /api/v1/nations/{nation}/map-spaces/{mapSpace}/command-definitions?target_x=&target_y=`
- command queue request/response は `target_x` / `target_y`

cell JSON、capital JSON、selected cell と aria label は x/y だけを使う。旧 payload と新 Backend が混在した場合、必須 x/y validation または type mismatch で失敗し、別座標として黙って扱わない。この変更は breaking change である。

## State and loading

Vue map state key は `${x}:${y}`。Capital の chunk を中心に3×3 chunksを並列取得し、generation token と AbortController により stale refresh を破棄する。空 chunk、部分失敗、loading を独立表示する。

command queue の optimistic version と request key による冪等性を維持する。queue 登録は実行ではなく、turn engine は本 scope に含めない。

## Projection

```ts
export function gridToPixel({ x, y }) {
  return {
    x: x * 32 + (floorMod(y, 2) === 0 ? 16 : 0),
    y: y * 32,
  };
}
```

projection は absolute x/y に適用する。Capital 周辺へ pan するときは `gridToPixel(cell) - gridToPixel(capital)` を使う。相対 y の parity を使ってはならない。

全世界 footprint:

- 60 rows
- 各 row 60 cells
- row width 1,920px
- even y の left 16px、odd y の left 0px
- right edge は1,904pxと1,888px
- row が増えても horizontal drift なし

DOM/CSS renderer は32px正方形 image を使い、六角形へ clip しない。pan/zoom 後の viewport 外 cell は DOM に生成しない。

## Keyboard directions

direction は Backend と共通で、0 east、1 north-east、2 north-west、3 west、4 south-west、5 south-east。ArrowRight、PageUp、ArrowUp、ArrowLeft、PageDown、ArrowDown をこの順に対応させる。移動先が未取得または bounds 外なら選択を維持する。

## Viewer safety

server presenter が owner 別表現を作る。秘匿 missile base は非 owner に通常の forest と同じ field shape、asset、details で返す。response は `Cache-Control: private, no-store` と `Vary: Cookie` を持つ。

## Verification

projection、row parity、60×60 footprint、同一 row 幅、no drift、六方向移動、command target、stale refresh を Vitest で検証する。Browser QA では正方形 tile、左右端の交互の半マス差、自然な初期島、console error なしを確認する。
