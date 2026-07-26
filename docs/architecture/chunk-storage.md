# チャンク保存設計

## 状態

PostgreSQL、`chunk_size = 16`、signed axial座標、関係行＋チャンクmetadataをMVP基盤として採用する。turn更新、event、outbox、snapshotは将来機能の境界であり、最初のMVP縦切りでは実装しない。

## 目的

動的に拡張する共有世界を、巨大な単一ファイルや単一JSONに依存せず保存する。チャンクは配信と変更検知の単位であり、所有者・座標・資源など検索やロックが必要な値は関係列として維持する。

## 比較した方式

| 方式 | 長所 | 問題 | 判定 |
|---|---|---|---|
| 世界全体を1 JSON | 読書きのコードが単純 | 競合、全量I/O、部分取得不能、破損影響が全世界 | 不採用 |
| 1チャンク1 JSON | 部分取得とスナップショットが容易 | 所有者検索、セル単位更新、制約、同時ロックが難しい | 読取キャッシュには可 |
| 1セル1行 | 検索、制約、差分更新、ロックに強い | 行数とAPI組立コストが増える | 正本の基礎として採用 |
| 関係行＋チャンクメタデータ | セル検索性とチャンク版管理を両立 | テーブルと更新規約が増える | 採用 |

## 暫定スキーマ

### map_chunks

- id
- map_space_id
- chunk_q、chunk_r
- version
- generated_at_turn
- last_changed_turn
- generation_seedまたは生成参照
- checksum（必要性は要検証）
- timestamps

map_space_id、chunk_q、chunk_rを一意にする。versionはAPIキャッシュの検証に使い、セルが1件でも変わるトランザクションで1回増やす。

### map_cells

- id
- map_space_id
- chunk_id
- q、r
- terrain_catalog_id
- owner_nation_id nullable
- facility_instance_id nullable、または施設側から参照
- population
- resource_amountなど頻繁に検索する値
- attributes JSONまたはJSONB
- created_turn、updated_turn
- lock_version（楽観ロックが必要な場合）

map_space_id、q、rを一意にする。q、r、chunk_q、chunk_r、local_q、local_rはsigned integerとし、unsigned型を使わない。chunk_idとq、rの対応はHexCoordinateのfloorDivとfloorModで求め、DB制約または保存前の値オブジェクトで保証する。

### facility_instances

施設に耐久、所有者、建設進捗、固有效果があるならセルの属性へ埋め込まず独立行にする。

- id、map_cell_id、facility_catalog_id
- owner_nation_id
- durability、level、construction_state
- attributes JSONまたはJSONB
- created_turn、updated_turn

1セル1施設に限定するか、地上設備と地下設備を重ねられるかは将来要件を確認して制約を決める。

## JSONへ入れるもの

JSON系カラムに適するのは、特定の地形・施設だけが持つ疎な属性、表示に必須でない追加情報、版付きの拡張属性である。次は通常列または関連表にする。

- q、r、map_space、chunk
- owner_nation_id
- terrain_catalog_id、facility_catalog_id
- 人口、耐久、資源残量のうち範囲検索・集計・ロック対象
- ターン番号、処理状態、外部キー

JSON属性にはschema_versionを持たせ、未知版を黙って無視しない。PostgreSQLのJSONBを使用できるが、頻繁に検索・制約・lockする値は通常列または関連tableへ置く。

セルの正本をmap_cellsとchunk JSONの両方へ二重化しない。map_chunksのversion、checksum、generated_at_turnは補助情報であり、地形、所有者、施設、人口の別正本ではない。

## 座標とチャンク境界

正本座標はADR-0003のsigned axial q、rである。UI用odd-q column、rowを保存しない。

- chunk_size = 16
- chunk_q = floorDiv(q, chunk_size)
- chunk_r = floorDiv(r, chunk_size)
- local_q = floorMod(q, chunk_size)
- local_r = floorMod(r, chunk_size)

定義は次の通り。

```text
floorDiv(value, size) = floor(value / size)
floorMod(value, size) = value - floorDiv(value, size) * size
```

floorDivは負の無限大方向へ丸め、floorModは0以上chunk_size未満を返す。PHP、TypeScript、SQLの除算・剰余演算へ直接依存しない。

次の境界testをq、rの両方へ適用する。

| 値 | chunk | local |
|---:|---:|---:|
| 0 | 0 | 0 |
| 15 | 0 | 15 |
| 16 | 1 | 0 |
| -1 | -1 | 15 |
| -16 | -1 | 0 |
| -17 | -2 | 15 |

初期生成範囲q=-30..29、r=-30..29は、各軸でchunk -2、-1、0、1に交差する。60×60を正確に初期生成するため、外周チャンクの範囲外セルを自動的に生成済みとみなさない。

## 書込み単位

MVPのWorld初期生成とNation登録はApplication serviceの明示的transactionで保存する。初期生成、必要時のWorld拡張、Nation・Capital・初期Territoryの作成は、全て確定するか全て失敗する。

次のcommand・turn用書込み規約は将来境界として維持するが、MVP縦切りではworker、event、outboxを先行実装しない。通常のプレイヤー操作は命令を登録するだけで、マップを即時更新せず、turn workerが変更集合だけを保存する。

保存時の推奨順序は次の通り。

1. 世界のturn lockを取得する。
2. 対象chunkを共通順序でロックする。
3. 対象セルの現版を確認する。
4. セル・施設・所有権・国家集計を更新する。
5. 対象chunkのversionを増やす。
6. domain eventとoutboxを同じtransactionへ保存する。

セルごとにEloquent saveを繰り返す実装は避け、検証済みのbulk updateまたはupsertを用いる。ただしmodel eventへルールを隠さない。

## 読取りとキャッシュ

`/api/v1`のチャンクAPIは、map_cellsと必要なcatalog・Nation表示情報を可読なJSON DTOへ投影する。chunk versionをETagへ変換し、変更がなければ本文を返さない。複数chunkのviewport応答ではchunkごとに版を返し、一部だけ更新できるようにする。compact array、binary、独自圧縮はMVP後に計測結果から判断する。

Redis等の導入は必須条件ではない。最初はDB索引とHTTPキャッシュで計測し、必要になったら投影済みチャンクをキャッシュする。キャッシュ削除失敗に依存せず、versionキーで古い値を到達不能にする。

## 履歴とバックアップ

やまにてぃのように全島225セルを毎ターンJSON複製する方式は、拡張世界では容量が急増する。正本履歴は次を組み合わせる案とする。

- turn_runとdomain_eventによる変更理由の監査。
- 重要な所有権・首都・国家資産の履歴表。
- 定期DBスナップショットと継続ログバックアップ。
- 必要なら一定間隔のチャンクスナップショット＋差分イベント。

domain eventだけから完全再構築するイベントソーシングは、初期採用しない。復旧時間、保持期間、過去地図表示の要件を測って追加する。

## 容量と索引

最低限の索引候補は以下である。

- map_cells(map_space_id, chunk_id)
- map_cells(map_space_id, q, r) unique
- map_cells(owner_nation_id, map_space_id)
- map_cells(terrain_catalog_id) ただし用途を確認
- facility_instances(owner_nation_id, facility_catalog_id)
- map_chunks(map_space_id, chunk_q, chunk_r) unique

すべての属性へ無条件に索引を作らない。国境セル抽出、首都周辺、資源探索、ターン対象抽出の実クエリで設計する。

## 破損検知と修復

定期検査で、座標重複、chunk対応不一致、存在しない所有国、不正な施設と地形の組合せ、境界外セル、首都参照切れを検出する。自動修復は監査なしに行わず、dry-run、対象件数、修復計画、管理者承認、復旧点を備える。

checksumはディスク破損検知よりも、投影キャッシュの同一性確認に有効である。DBの物理整合性はDBバックアップと基盤監視へ委ねる。

## 検証項目

- 負のq、rを含むfloorDiv、floorMod、chunk/local座標の境界テスト。
- 60×60初期生成時のセル数と一意性。
- map_cells以外にセル正本が作られていないこと。
- 同一セル更新競合、複数chunkのロック順。
- chunk versionとETagの一致。
- 1万、10万、100万セルでのviewport取得とターン対象抽出。
- バックアップから指定turnまでの復旧時間。

## MVP実装記録（2026-07-26）

`map_cells`をセル正本とし、signed axial `q`,`r`、`chunk_q`,`chunk_r`、`local_q`,`local_r`を通常列で保存する。`(map_space_id,q,r)`を一意、`(map_space_id,chunk_q,chunk_r)`をindex化した。chunk sizeは16で、負座標は数学的floor division/moduloを共通serviceで求める。

初期60×60範囲は16 chunksとなる。APIは指定chunkだけを最大256セル返し、map全体をJSON blobや1 responseへまとめない。`map_chunks.version`と各cellのversion/updated_atを後続のcache・競合検出境界とする。
