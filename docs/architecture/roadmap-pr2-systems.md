# Roadmap PR2: command・施設state・staggered map

## 範囲

本変更は`roadmap-pr2-v1` ruleset、7種の国内command definition、国家別command queue、施設・地形の型付きcell state、生産definition、資源別売却方針、viewer依存cell表現、32px正方形tile rendererを追加する。command実行、turn runner、実労働者割当、生産物加算、自動売却、森林成長、ミサイル発射は含めない。

PR #4以降、DB・API・command targetの現行正本座標はstaggered square-tile `x/y`である。PR2当初の座標契約はPR #4でbreaking migrationされた。

## 旧作から確認した値

`_references/hakoniwa-2plus/extracted`は調査だけに使用し、コードや画像を新作へコピーしていない。

| 対象 | 確認値 | 根拠 |
|---|---:|---|
| command queue上限 | 20 | `value.c:24,123-124`、`config.cgi:49-50` |
| 整地 / 地ならし費用 | 5 / 100 | `command.c`の`costTable` |
| 埋め立て / 掘削費用 | 150 / 200 | 同上 |
| 農場 / 工場 / 採掘場建設費用 | 20 / 100 / 300 | 同上 |
| 森 | 初期500本、+100本/turn、最大20,000本 | `new_island.c:136-150`、`map.c:382-387`。保存param 1は100本 |
| 農場scale | 10、+2、最大50 | `command.c:438-459,626-667` |
| 工場scale | 30、+10、最大100 | 同上 |
| 採掘場scale | 5、+5、最大200 | `command.c:484-507` |
| 施設scale表示単位 | scale 1 = 1,000人 | `hakow.js`の`param + "0" + "00人"` |
| 基地経験値 | 初期0、最大200 | `new_island.c`、`map.c:563-579,610-615` |
| 基地LV閾値 | 20、60、120、200 | `Land::getLevel`, `map.c:1377-1397` |
| LV別発射可能数 | LV1..5で1..5発 | command/UI sourceの基地level利用 |
| 他国向け基地 | 森、param 0として出力 | `Land::jsOut`。通常森も他国には数量を出さない |

農場は10,000人、工場は30,000人、採掘場は5,000人規模で開始する。規模levelだけを`map_cells.facility_scale`へ保存し、人数は`scale_unit_people=1000`から算出する。level値と人数を二重保存しない。工場・採掘場を新資源へ対応させる`production_per_scale=1`と販売価格の数値は、旧作の直接資金化を新資源へ分離した暫定値であり、ruleset metadataに暫定であることを記録する。

## Cell stateとreset規則

`population`は村・首都等の居住人口だけに使う。`terrain_quantity`は森林の本数、`facility_scale`は農場等の規模level、`facility_experience`は基地経験値、`facility_operational_state`は将来の停止・被害境界である。

- forest以外へ遷移すると`terrain_quantity=null`。
- quantityを持つterrainへ遷移するとdefinitionの初期値を設定する。
- facility撤去時はscale、experience、operational stateをすべてnullにする。
- scale施設ではexperienceをnull、基地ではscaleをnullにする。
- buildable terrainでなくなったfacilityは撤去resetする。
- facility規模は0以上maximum以下だけをdomain serviceが受理する。

初期島の3森林へ500本、基地へ経験値0を設定する。基地LVと発射可能数はexperienceから算出し、保存しない。将来の`expand_farm`、`expand_factory`、`expand_mine`は`build_command_key`、`scale_increment`、`maximum_scale`とcommand metadataから追加できるが今回は未実装である。

## Command definitionとqueue

採用keyは`land_clear`、`land_level`、`reclaim`、`excavate`、`build_farm`、`build_factory`、`build_mine`である。整地は費用5でturnを消費する旧作`Prepare`、地ならしは費用100でturn非消費・地震判定を伴う旧作`Prepare2`として分離した。地ならし地震、整地埋蔵金、海底油田探索はturn runnerへ延期しmetadataで示す。埋め立ては海→浅瀬、浅瀬→荒地、掘削は対象地形依存なので単一の結果terrainをdefinitionへ偽装しない。

queueは国家ごとに位置1始まり、上限20、headerのversionでoptimistic concurrencyを行う。`request_key`は同一queue内で一意、cancelled itemは位置をnullにして残し、queued itemを左詰めする。並べ替えは現在の全queued ID集合との一致をtransaction内で検査する。queue時はmembership、world/map space、bounds、cell存在、definition、terrain/facilityの基本条件だけを検査する。資金・資源の予約や減算、terrain・facility・数量の変更はしない。turn runnerは実行直前に所有・費用・対象を再検証する別境界を持つ。

数量指定・繰返しは今回確定せず`parameters`へversioned payloadを追加できる境界だけ残す。queue追加・並べ替え・取消はaudit eventを残す。

## 生産と売却方針

`production_definitions`はruleset、facility、output resource、scale当たり生産、必要workforce、稼働条件、price referenceを関連付ける。農場→小麦、工場→工業品、採掘場→鉱物である。actual workforceや生産加算は行わない。

売却方針は国家×tradable resourceごとに`sell_all`、`stockpile`、`keep_amount`を保存する。`keep_amount`以外の数量指定と負数を拒否し、row versionで競合を検出する。価格はresource rowへ数値を固定せずrulesetの`sale.*`参照を使う。自動売却はturn runnerへ延期する。

## Viewer依存API

cell presenterがDB modelからviewer用表現を作り、Vueは本物のfacilityを受信してから隠す処理を行わない。所有者は基地、experience、算出LV、発射可能数を受け取る。非所有者と未所属Userはterrain=forest、facility=null、森のasset、空detailsを受け取る。通常の他国森林も木の本数を返さない選択肢Aを採用したため、偽装基地とfield構成、asset URL形式、公開version規則が一致する。

chunk responseは`private, no-store`かつ`Vary: Cookie`で、owner responseを共有cacheへ載せない。chunk versionはviewerへ実際に返す表現のhashであり、非所有者cellの秘密stateだけが変化しても公開cell versionは変えない。

## Staggered square renderer

原画像は32×32px正方形で、旧作`hakow.js`は32px幅と16px spacerを使う。PR #4ではabsolute x/yをそのまま次へ投影する。

```text
screenX = x * 32 + (yが偶数なら16、奇数なら0)
screenY = y * 32
```

tile width/heightとvertical stepは32、half offsetは16で一元管理する。画像を`clip-path`で六角形へ切らず、6方向neighborとkeyboard操作はeven/odd y規則を共有する。rendererはpan/zoom後の画面矩形から外れるcellのDOMを生成しない。

## Migrationとrollback

`2026_07_26_010000_add_roadmap_pr2_systems.php`は既存users、identity、nation、world、3,600 cells、capitalを再生成せず列・catalog・queue/policy tableを追加する。既存worldは旧`mvp-v1`を上書きせず新しい`roadmap-pr2-v1`へ付け替える。既存forestへ500本、基地へ経験値0、既存scale施設へ各definitionの初期scaleをnull行だけbackfillする。追加resource残高とdefault policyは不足行だけ作成する。

既存OCIへ適用するときはDB backup後、明示的なtest DBで先に検証し、アプリ更新時に`php artisan migrate --force`を一度実行する。world init、volume削除、`down -v`は不要である。rollbackは追加table・列を落とすため、データ保持が必要な運用環境ではbackup復元または前進migrationを優先する。
