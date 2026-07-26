# ADR-0003 六角座標方式

- 状態: 採用
- 日付: 2026-07-26
- 対象: 地上map_space、将来の六角形map_space、DB、API、ゲームルール、UI投影

## 文脈

共有世界は初期生成範囲の外へ拡張し、負座標を許す。領土、ミサイル、災害、怪獣、登録地点探索、範囲効果が同じ隣接・距離規則を使う必要がある。

offset座標を正本にすると、列の偶奇が隣接計算へ入り込み、負数の剰余差もPHP、TypeScript、SQLへ分散する。表示上の矩形配置と、ゲームルール上の座標を分離する。

## Decision

DB、API、ゲームルールの正本には、符号付き整数のaxial q、rを使用する。地上はpointy-top hexとする。

q、r、chunk_q、chunk_rには負数を許可する。PostgreSQL等でunsigned型を使用しない。セルをx、y、row、column、offset座標として保存しない。

6方向の隣接ベクトルは次の固定順とする。

1. (+1, 0)
2. (+1, -1)
3. (0, -1)
4. (-1, 0)
5. (-1, +1)
6. (0, +1)

方向名を追加する場合も、この順序とベクトルの対応を1か所で定義する。

2点間の差をdq、dr、ds = -(dq + dr)とすると、距離は次の標準式を使う。

distance = (abs(dq) + abs(dr) + abs(ds)) / 2

同値なmax(abs(dq), abs(dr), abs(dq + dr))を最適化に使ってもよいが、共通座標ライブラリ以外へ式を複製しない。

## UI Projection

Vue等で矩形状に描画するときだけ、pointy-top odd-q vertical offsetを使う。奇数列を下へ半セルずらす。offsetのcolumn、rowは表示投影であり、API request、command payload、DBには保存しない。

parityは言語の剰余演算へ直接依存せず、floorMod(q, 2)で求める。

axialからodd-q表示への変換:

- column = q
- row = r + (q - floorMod(q, 2)) / 2

odd-q表示からaxialへの逆変換:

- q = column
- r = row - (column - floorMod(column, 2)) / 2

この式は標準的なodd-q変換と一致する。floorModを使うことで、負の奇数列でもparityは1となる。

### 変換テスト例

| axial q | axial r | column | row | 逆変換後q | 逆変換後r |
|---:|---:|---:|---:|---:|---:|
| 0 | 0 | 0 | 0 | 0 | 0 |
| 1 | 0 | 1 | 0 | 1 | 0 |
| 2 | 0 | 2 | 1 | 2 | 0 |
| -1 | 0 | -1 | -1 | -1 | 0 |
| -2 | 0 | -2 | -1 | -2 | 0 |
| -1 | 1 | -1 | 0 | -1 | 1 |
| 3 | -2 | 3 | -1 | 3 | -2 |

PHP側とTypeScript側は同じ表をcontract testとして共有する。画面上でx、yという表示名を使う場合でも、それがaxial q、rの別名かpixel座標かをUI文言で明示し、offset座標をx、yと呼ばない。

## Chunking

qとrを独立に数学的floor divisionし、チャンク内座標はfloor moduloで求める。

- chunk_size = 16
- floorDiv(value, size) = floor(value / size)
- floorMod(value, size) = value - floorDiv(value, size) * size
- chunk_q = floorDiv(q, chunk_size)
- chunk_r = floorDiv(r, chunk_size)
- local_q = floorMod(q, chunk_size)
- local_r = floorMod(r, chunk_size)

MVPの地上map_spaceは`size = 16`を採用する。これはDB、API、cache key、座標変換、既存Worldの互換性に関わる内部仕様であり、通常のruleset balance値として変更しない。ゼロ方向への丸めや、負数で負値を返す剰余をそのまま使わない。

必須境界例:

| qまたはr | chunk座標 | local座標 |
|---:|---:|---:|
| 0 | 0 | 0 |
| 15 | 0 | 15 |
| 16 | 1 | 0 |
| -1 | -1 | 15 |
| -16 | -1 | 0 |
| -17 | -2 | 15 |

常に value = chunk * size + local、かつ 0 <= local < sizeを満たす。

## 初期生成範囲

初期60×60はq=-30..29、r=-30..29を採用する。q=0..59、r=0..59案は原点が初期範囲の隅となり、登録探索、運用表示、四方向拡張の説明に偏りが出るため採用しない。

60は偶数なので完全な点対称にはならず、範囲の中心は(-0.5, -0.5)である。それでも原点を含み、正負方向をほぼ均等に持つ。登録地点探索は原点固定ではなく、現行生成境界、既存首都距離、候補scoreを使うため不利益はない。UIは絶対q、rをodd-qへ投影するため、負の初期境界を特別扱いしない。

論理上の地上map_spaceには固定座標上限を設けない。初期生成範囲と、座標型の論理範囲は別概念である。

## HexCoordinateの責務

将来のHexCoordinate値オブジェクトは次だけを正本実装とする。

- q、r
- neighbor(direction)
- neighbors()
- distanceTo()
- add()
- subtract()
- toDisplayOffset()
- fromDisplayOffset()
- chunkCoordinate(chunkSize)
- localCoordinate(chunkSize)

領土、ミサイル、災害、怪獣、登録、UIが独自の隣接・距離・odd-q・チャンク式を持つことを禁止する。PHPとTypeScriptに同等実装を持つ場合も、同じfixtureとproperty testで一致を検証する。

## Rejected

offset座標をゲームルールとDBの正本にする案を採用しない。

理由:

- 負座標の偶奇処理が通常の剰余演算へ依存しやすい。
- 隣接計算が偶数列・奇数列の分岐として各機能へ分散しやすい。
- 距離、範囲、ring、line処理がaxialより複雑になる。
- PHPとTypeScriptで除算・剰余の差を吸収する箇所が増える。
- ミサイル、怪獣、領土処理の保守性と決定性を損なう。
- 表示の都合をDB schemaとゲームルールへ固定してしまう。

cube q、r、sを3列で保存する案も採用しない。s=-q-rで導出でき、三列の整合性制約が増えるためである。必要な計算時だけcube成分を導出する。

## 地上世界と保存単位

論理上、共有地上世界は1つである。ただし、地上全体を1つのJSONまたは1ファイルへ保存しない。地上map_spaceを複数チャンクに分け、セルの正本はmap_cellsの各行に一度だけ保持する。

map_chunksのversion、checksum、更新turnはキャッシュ無効化、変更通知、同時更新検出の補助情報であり、別のマップ正本ではない。地下や宇宙は別map_spaceとし、地上と同じworldへ属しても座標境界・生成・可視性を独立させる。

## 影響

- DBとAPIの座標名はq、r、chunk_q、chunk_r、local_q、local_rになる。
- UIは受信したq、rをodd-qへ投影し、表示投影をserverへ送らない。
- 初期生成・登録・攻撃・災害・領土はHexCoordinateを共有する。
- 負座標とチャンク境界のcontract testが実装前提になる。
- 既存設計文書のx、y正本案は本ADRで置き換える。

## 未決定事項

- 宇宙map_spaceもhex axialにするか、別トポロジーにするか。
- 利用者画面で座標をq、rと表示するか、日本語の別ラベルを付けるか。
- 低zoom集約tileの座標契約。
