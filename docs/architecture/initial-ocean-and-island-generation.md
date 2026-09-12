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

Ruleset v24の候補は、reservation radius 5 の91 cellsが全て未所有・施設なし・人口0で、地形が海・浅瀬・荒地・山のいずれかであり、既存 Capital から distance 12 以上の地点に限る。平地・森は候補へ広げない。Capital距離はNation stateで絞らず、地図に残る全Capitalを対象とする。距離は ADR-0003 の x/y 方式を使い、候補探索 SQL の cube 成分は計算式内部だけに閉じる。

候補に船がいても探索段階では除外しない。上位3候補を有限に評価し、初期島生成後も航行可能な空き海へ船を退避できない候補だけを棄却する。3候補とも使えない場合は既存の1回だけのWorld拡張を行い、無制限に候補探索や拡張を繰り返さない。

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

生成器は初期陸地、growth、初期施設、最低浅瀬として実際に変更するcellだけを上書きする。reservation内に既にある中立の自然地形も生成計算へ取り込むが、変更対象にならない外周の浅瀬・荒地・山はそのまま残す。初期領土は従来どおりdistance 2以内の生成陸地だけで、範囲外の中立地を初期資産へ加えない。

初期島の変更で最終地形が海でなくなるcellにactiveな船がいる場合だけ、reservation内の生成後も中立・無施設・人口0で、他の船・怪獣がいない深海へ移す。退避先は距離、y、x、cell id順で安定選択し、複数船へ同じcellを割り当てない。最終的に海のままの船は動かさず、強制退避に燃料、報酬、技能経験値、KARMA、通常航行eventを付けない。

## Failure behavior

World 初期化と Nation 作成はそれぞれ transaction で囲む。途中失敗時は cell、chunk、nation、capital、membership、resource、creation request、audit、船の移動を部分的に残さない。安全な船の退避先がない候補は島を適用せず、別の有限候補へ進む。

## Existing-world transition

historical ruleset Worldは既存地図と監査情報をread-onlyで表示できる。standalone initやgame-state mutationで暗黙にcurrent rulesetへ移行せず、`reset_required`で停止する。運用者が`hakoniwa:world:reset`を明示実行した場合だけ、対象Worldの開発game dataをcurrent rulesetで再作成し、usersとauth identitiesを保持する。
