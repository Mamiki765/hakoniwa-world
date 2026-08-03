# Initial ocean and island generation

## Ocean world

`OceanWorldGenerator` は transaction 内で共有 World、surface MapSpace、catalog と全面海の初期セルを冪等作成する。

- x = 0..59
- y = 0..59
- y 外側、x 内側の loop
- 3,600 cells
- 60 rows、各 row 60 cells
- terrain = sea
- owner / facility = null
- population = 0
- generator version = 3
- seed = `hakoniwa-staggered-xy-v3`

各 cell は同じ x/y から chunk_x/y と local_x/y を求める。完了 run があれば再実行しても増殖しない。旧座標から backfill された cell があるのに version 3 run がない場合はデータを混在させず、専用 reset command を要求する。

## Capital placement

候補は reservation radius 5 の91 cellsが全て海・未所有・施設なしで、既存 Capital から distance 12 以上の地点に限る。距離は ADR-0003 の x/y 方式を使う。候補探索 SQL の cube 成分は計算式内部だけに閉じる。

## Initial island

`LegacyInspiredInitialIslandGenerator` は deterministic seed から初期島を作る。

- reservation radius: 5
- growth radius: 4
- initial land radius: 2
- initial territory radius: 2（19 cells）
- minimum neutral shallow cells: 3
- Capital、village、forest 3、mountain 1、missile base 1

growth、random neighbor、bounds、territory、Capital 保存、creation request と audit metadata は x/y を使う。row の偶奇で6近傍が変わるが、距離と radius は同一 domain value object に集約する。

growth後に中立・施設なしの浅瀬が3未満なら、reservation内で陸地に隣接する海からdeterministicに不足分を浅瀬へ変える。既存浅瀬を所有化せず、施設を置かない。候補不足時に範囲外へ広げたり既存cellを破壊したりせず、生成できた範囲だけを保持する。値はWorldが参照する不変ruleset snapshotから読む。

## Failure behavior

World 初期化と Nation 作成はそれぞれ transaction で囲む。途中失敗時は cell、chunk、nation、capital、membership、resource、creation request、audit を部分的に残さない。

## Existing-world transition

historical ruleset Worldは既存地図と監査情報をread-onlyで表示できる。standalone initやgame-state mutationで暗黙にcurrent rulesetへ移行せず、`reset_required`で停止する。運用者が`hakoniwa:world:reset`を明示実行した場合だけ、対象Worldの開発game dataをcurrent rulesetで再作成し、usersとauth identitiesを保持する。
