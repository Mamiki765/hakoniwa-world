# 公式Turn・地図stateのDB再設計比較

調査固定点: `Mamiki765/hakoniwa-world` main **`2e93ec918774ad64d787aba99fc59a7bc3e54d16`**、5.8.3 / Ruleset v41。本人承認による追加の**設計比較**であり、採用・実装・本番適用を意味しない。この追加担当ではsource変更、DB起動、追加試験、実データ操作をしていない。既存の隔離測定JSONと現行source/schema/設計文書だけを参照した。

現時点で最も根拠が強い進め方は、**typedなcell行を当面維持し、同じセルの重複保存→明示的なwrite set→短い区間のbulk保存→typedなTurn内stateへ段階移行すること**。chunk JSONB、列分割、世代別snapshotも比較対象に含めたが、SQL本数が少なくなるだけでは現在の原因を解決した証拠にならない。

## 1. 現行測定が示す問題と、示していない問題

親による現行固定SHAの既存 `TurnRuntimePerformanceTest::test_production_mature_world_has_bounded_process_cell_queries` 1caseの隔離PG測定を根拠にする。PHP8.5.8、PG18.4、lockに対応する依存、合成60×60 fixture。1test/5assertions pass。詳細は `turn-current-mature.json` と `turn-current-mature-profile.log`。

| 対象 | 所要時間 | SQL | 形 |
|---|---:|---:|---|
| 全Turn | 3977.031ms | 3355 | 単一fixture・単回 |
| process_cells | 3096.320ms | 3143 | SELECT18 / UPDATE2012 / INSERT1113 |
| territory influence | 241.098ms | 7 | このfixtureでは変更0、SELECT7 |
| global_disasters | 190.273ms | 27 | このseedの災害・出現だけ |
| nation_economy | 112.951ms | 24 | Nation1の代表 |
| aggregate_nations | 90.052ms | 21 | SELECT5 / chunk UPDATE16 |
| finalize_turn | 69.523ms | 63 | SELECT50 / UPDATE5 / INSERT8 |

process_cellsでは、stage変化900件に対して `facility+population+version` UPDATE900件と、その後の `version` だけUPDATE900件がある。監査event単行INSERTも1112件ある。`CompleteTurnEngine:1830-1836,1871-1875` がこの二重保存を直接説明する。既存JSON解読cacheは採用済みなので、以前の29.8秒という旧測定を現在のbaselineへ混ぜない。

これは「現行は書込要求が多い」という根拠であり、**3.096秒すべてがDB待ち**というCPU profileではない。また、fixtureの900 stage変化は本番の毎Turnの変化頻度を示さない。定常状態でstage変化が少ないworldでは二重保存統合の利益も小さくなる。CPU、PDO往復、trigger、WAL、disk fsync、同時利用者のlock待ちの内訳はこのJSONだけでは分離できない。

参考となる計算上の規模:

- 同じcell内の二重保存900件とchunk16→1 UPDATEだけを消せるなら、他を固定したSQL数は **3355→2440**。これは未実装の静的差引きで、時間改善の実測ではない。
- process_cellsの1112 eventをすべて同じ安全な窓で500行ずつinsertできるなら1112→3 statementだが、実際は途中の読取・診断・順序境界でflushが必要になり得る。常に1109本削減とは見積もらない。
- process_cells以外は合計約880.7ms残る。process_cellsが仮に無料でも全Turnは0msにならない。非cell処理とcommand送信APIの待ちは別に評価する。

## 2. 保存粒度の比較

| 案 | DBの正本・grain | 利益の狙い | 代償・反証 | 現時点の位置付け |
|---|---|---|---|---|
| A0 現行rowの局所統合 | cell1行、typed列 | 900重複UPDATE、chunk逐次UPDATE等を直接削る | version増分/event順を失うと意味が変わる | 最初に測る |
| A1 row+bounded write set | cell1行、変更列maskごとbatch | 往復・Eloquent保存準備・複数保存を減らす | row trigger/WALは行数分残る。途中SQLの観測を保証する必要 | 強い段階案 |
| B1 Turn内typed state+UnitOfWork | DBは既存row、計算は明示state/indices | hydrate/refresh/反復SQLを減らし、全mutationを追跡 | 全consumerが同じ更新済みstateを読むよう移行する大改造 | A1の次。schema変更を必須にしない |
| B2 topology/stateの縦分割 | 不変座標row+可変state row | hot rowを小さくし、座標indexから更新を分離 | JOIN/追加PK/FK/tuple、lock対象変更、移行が必要 | WAL/ページ負荷の測定次第 |
| B3 更新頻度別の列分割 | 基本state+population/forest等のhot row | populationだけ更新する頻度が支配的ならhot範囲を狭める | 1cellの一貫性維持・2表更新・NULL状態規則が複雑 | B2より後。小さな行で十分か先に反証 |
| C1 chunk JSONBをcanonicalに | 16×16の1payload+cell identity索引 | 1chunk一括処理/転送、最大256cellを1更新 | 1cell変更でもchunk行競合、FK/typed検索/triggerの再構築、payload変換 | currentでは優先根拠不足 |
| C2 cell delta log+snapshot | typed identity+順序付きdelta+snapshot | 追跡/再構築とwrite appendの可能性 | read amplification、compaction、未確定deltaと正本境界、監査の第二authority | 診断価値も必要なら別設計で比較 |
| C3 世代別snapshot+atomic publish | immutable入力世代→新世代→active pointer | 計算と公開を分け、将来並列計算の足場 | 全ゲーム状態の世代整合、競合検出、容量増、rollback contract変更 | 抜本案。現行仕様を満たす設計証明が先 |

**chunkという読取単位、計算の単位、DB row lockの単位、永続化statementの単位は別に選べる。** 現行の16×16 APIを維持したまま、DBはcell row、計算は全Worldのindexed state、書込はdirty行500件、APIはchunk projectionという組合せが可能である。

### 2.1 現行rowのままで得られる改善

現行 `map_cells` は23のtyped列を持つ（`database/schema/pgsql-schema.sql:1174-1198`）。座標・所属chunk、地形/施設/owner、人口/木/施設状態、versionが同じ行にある。現行の主なindexは座標unique、chunk、owner、facility/scale、facility/experience（同schema:4777-4789,6175-6199）。

二重saveの統合は**実際のrow更新回数**を減らす。一方、1000行の単行UPDATEを2本のbatch UPDATEへ置換しても、論理的には1000行の更新であり、triggerやconstraintが1000行分なくなるわけではない。単純なSQL本数比較と物理的な更新量を分ける。

PostgreSQLのHOTはindex対象列の変更がなく、同じpageに空きがある場合に成立し得る。したがって、population/versionだけの更新が既にHOTで済むか、facility変化がindex/WALを支配するかを測ってから列分割・fillfactorを判断する。機械的なfillfactor変更は容量・cache効率との交換条件がある。[PostgreSQL 18 HOT](https://www.postgresql.org/docs/18/storage-hot.html)

### 2.2 immutable topology / mutable state分離

候補となる不変側は `id,map_space_id,map_chunk_id,x,y,chunk_x,chunk_y,local_x,local_y`。**terrainは不変側に入れない**。埋立、掘削、津波、噴火、海怪獣等で変わる。施設・owner・人口も可変で、generation metadataと現在状態は別である。

`map_cell_states(cell_id PK/FK, terrain_id, facility_id, owner_id, population, ... version)` を新設する場合:

1. existing cell IDと座標uniqueをidentity tableに保持し、ships、monster occupancy、capital、treasureの参照先を失わない。
2. 現行 `MapCell::lockForUpdate()` がidentity行だけをlockしてstateを保護しなくなる危険がある。state行のlock、欠損state作成、JOIN上のlock範囲を全writerで揃える。
3. `MapCell` のrelation/castとSQL groupByをnew stateへ接続する。自動updatable viewやINSTEAD OF triggerは移行adapter候補だが、書込経路を隠すと解析と性能を難しくする。
4. world拡張はidentity/stateを同じtransactionで増やす。`WorldExpansionService:147-153` と `OceanWorldGenerator:120-132` の生成経路があるため、将来の生成/明示reset/importを含めず「永遠に不変」とは断定しない。
5. topology cacheはmap-spaceの生成世代/範囲をkeyにする。world拡張後に古いstable列挙を再利用しない。

行を二つへ分けると、追加tuple・index・JOINのコストも発生する。単一world3600cellという今回fixtureだけでは、保存サイズ削減が利益になると立証できない。`pg_column_size`、HOT率、WAL bytes、shared-buffer reads、join plan、hydrate CPUを比べるまではB2の採用を決めない。

### 2.3 column split / sparse state

populationとforest quantityを別hot tableにする案は、施設種類に依存した疎なstateを表現できる。しかし村→町→都市、地形→森林/海、怪獣踏潰しなどで、行の作成/削除と施設本体を同時に切り替える必要がある。nullable typed列を消せる利点と、同じcellの複数表更新が増える欠点がある。

実装時は「施設を消したのにhot population行が残る」「海へ変わったcellをforest tableが参照する」といった不整合をDB/アプリのどちらで拒否するか決める。現行は `MapCellStateService` が複数stateをまとめて変える責務を持つ。型別表へ分散させる場合も同じcanonical mutationを入口にする。populationだけを細分化しても、owner/terrain変更を含む汎用cell rowの更新量を全部消せるわけではない。

### 2.4 chunk JSONB / 配列payload

JSONBの一部pathを更新しても、別cellを含む同じchunk row全体のrow lockを取得する。PostgreSQL公式文書も、JSON documentを競合しにくい適度な大きさにし、独立更新できないatomic datumを単位にすることを勧めている。chunk内cellは隣接するが、同じuser/用途で常に一体更新されるとは限らない。[PostgreSQL 18 JSON設計](https://www.postgresql.org/docs/18/datatype-json.html#JSON-DESIGN)

canonical chunk payloadにするなら、少なくとも次が追加設計となる:

- cell ID→chunk/local-indexのidentity参照、partial chunk、負座標とfloor division、拡張後の新cell。
- owner/facility/terrainによるSQL絞込・集計の代替。JSON展開、generated index、別索引表のどれを正本から派生させるか。別索引表を独立更新する第二authorityにしない。
- payload内の各FK、NULL、整数幅・値域、施設state整合、ship共存を、現行と同等に検証する経路。JSON schemaだけではforeign key検証の代替にならない。
- 同chunkの競合更新のlost update防止。read→modify→whole payload writeはversion/CASかlockが必要。chunk単位のversionをcell versionの代わりにすると、owner向けversionの変化を拡大してしまう。
- 256cellの1項目変更と全cell変更の両ケースで、payload bytes、encode/decode、WAL、TOAST、lock時間を測る。JSON path変更が固定長fieldだけの物理in-place更新になると仮定しない。
- public/owner/未視認のprojectionを混ぜた共有payloadをそのまま返さない。`MapCellPresenter:159-161` は非ownerへsecret stateのversion/updated_atを出さない。

先に採用しやすいのは**canonical rowsのまま、同一commitから作るchunk read cache**である。これも動的overlay、船視界、user別秘匿、owner変更を再projectし、DB stateと同じturn/generationを確認する必要がある。raw canonical cacheとviewer向け完成responseを分け、秘匿を落とす前のresponseを全userへ共有しない。

## 3. UnitOfWork / write setの境界

### A1: 現行Eloquent+明示flush

最小形はTurn attemptごとに `cell_id → pending changed fields, final values, final version, event sequence` を持つ。成長や森林などの限定メソッドだけ参加させ、他の保存経路は当面現行のままにする。flushは次の条件で行う:

- pending cellを読むSQL/refresh/DB aggregateの前。
- pending cellを参照するtrigger/FK/constraintが動くwriteの前。
- moduleがDBの最新状態を正本にする既存境界の前。
- bounded batchサイズ到達、対象phaseの終了、最後のcommit前。

**phase末だけflushすれば常に安全、ではない。** process_cells中はミサイルの後に `refresh()->load()` を行い（CompleteTurnEngine:635-641,654-657）、怪獣damageはhost cellを再lockする（MonsterDamageService:81前後）。同じphase内でDBを読み直すため、メモリだけ更新してDBへ未反映なら後続の挙動が変わる。

旧検討のDB `beforeExecuting` hookで未知SQLの前に全部flushする方式は保守的なfallbackとして比較できる。ただし、SQL文字列のprefixだけで「audit insertは無関係」「別cell updateは独立」と判断すると、trigger内の読取・新しいconsumer追加を漏らす。初期はunknown→flush、独立性を証明した狭いallowlistだけ遅延可能にし、長期的には明示persistence gatewayへ寄せる。処理途中の監査を省いて速度を得る案は含めない。

#### batch UPDATEの重要条件

- **dirty-column maskで分ける。** populationだけの更新へterrain/facility列を全列SETすると、同値代入でもUPDATE OF triggerが発火する。現行ship cell guardは `map_space_id,terrain_definition_id,facility_definition_id` に限定される（schema:6612）。PostgreSQLではSET対象列に現れることが列指定triggerの発火条件になる。[PostgreSQL 18 CREATE TRIGGER](https://www.postgresql.org/docs/18/sql-createtrigger.html#SQL-CREATETRIGGER-NOTES)
- 同じidが一つのVALUES batchへ複数回現れないようにする。最終値のlast-writeとversion増分を明示的に合成し、affected rowsを期待数と比較する。
- Eloquentのstale model全列から書き戻さない。既に別moduleが変えたowner/施設/HPを上書きする危険がある。identity mapへ同じモデル参照を集め、変更列だけ書く。
- model events/observers、row trigger、statement trigger、timestampsの差を棚卸しする。現行MapCell/MapChunk自体に特殊observerは見つからないが、全書込adapter/将来の追加経路まで自動保証しない。
- stage変化+人口増加では現行versionが2増える。UPDATE1回へ統合しても2増分とmetadata順を保つ。owner APIへcell version/updated_atが出るので、単なる内部値と見なさない。
- INSERT/DELETEをUPDATEと同じ最終値mapへ雑に統合しない。occupancy、treasure、asset identity、unique/FK、履歴rowは別の永続化規則がある。

### B1: canonicalなTurn内stateへ移す

次段階は、typedな `CellState`、`NationState`、`ResourceBalances`、`MonsterOccupancyIndex` 等へ計算を集約し、既存DBを全ての計算途中の読取APIとして使わなくする。全phaseと現在の逐次順は維持し、DBに保存する回数だけを減らす。

既に成立している小例は `TerritoryInfluenceService:161-253` である。owner変更をメモリ上で即反映し、後続cellへ見せ、land countを更新してから `:255` でbatch永続化し、後続treasure処理へ進む。これは同時解決への変更ではない。

必要な設計:

1. 全writerをcanonical mutationへ通し、before/afterからowner別人口、陸地、施設capacity等のdeltaを更新する。新しい第二ゲームengineをコピーして作らない。
2. DB refreshを単に削除せず、同じcausal pointの最新stateを読むAPIへ置換する。mutable stateとturn開始freeze stateを別の型で持ち、取り違えない。
3. DB adapter未移行のmoduleを呼ぶ前はflush、呼出後は影響範囲を再同期する。UOWへ全consumerを移し終えるまで段階adapterが必要。
4. row lockとWorld lockを維持した同じroot transaction内から始める。メモリcacheがあるから同時writerを無視できるとは判断しない。
5. attempt失敗時はwrite set、identity map、aggregate delta、RNG、監査bufferを全破棄して再構築する。singletonに持ち越さない。
6. state配列を複製し続けるとPHP memoryが増える。Eloquentを全部typed DTOへ移す利益と、二重保持による悪化を測る。structure-of-arrays/packed buffer/native実装はさらに別の大改造で、DB schema変更と同時導入しない。

最終flushのtable順は固定の `map_cells→ships→monsters` だけでは足りない。たとえばshipを沈めてからそのcellを陸へ変える現行操作では、active shipが残る間の地形変更をtriggerが拒否する。逆にshipを新cellへ置くときはdestinationの海/施設stateが先に正しくなっている必要がある。**因果上の依存区間を保存単位にする**。最終状態だけ正しいことは、中間制約を正しく満たした証拠にならない。

## 4. Economy / summary read model

### Turn内集計

現在は人口/陸地/施設をphaseごとにgroupByまたは一括取得する。`aggregate_nations`は既にまとめられているが、finalizeは人口を再集計し、lifecycleのcollapse判定は2値だけに `NationBasicStatusProjection::forNation():45-80` の全projectionを計算する。

UOWに所有者別 `population, land, farm/factory/mine capacity, food amounts` を持てば反復集計を減らせる。ただし、初期値を1回SUMした後、**全mutation**を追跡する必要がある。領土移転は人口を旧ownerから減らし新ownerへ増やし、都市破壊は人口/施設capacityを減らし、難民は受取側へ増やし、undersea維持・船・露店・overflowは食品/資金へ影響する。現在のphase終端に行っているlive再集計を無条件にstart snapshotへ置き換えない。

段階案は、まずlifecycleへ必要な最新2集計だけを渡すこと。その後、既存SUMとUOW deltaをshadow比較する期間を設け、差が出るmutation経路を特定する。未使用projectionが行っていたfacility状態の異常検証も、canonicalな別箇所で維持されるか確認する。

### 永続read model

`nation_turn_summaries(world_id, nation_id, counted_turn, source_revision, ... )` やchunk projectionは、**同じofficial Turn transactionでcanonical stateと一緒に確定**する案が第一候補。これならpublic ranking等は完成済みturnの結果を読め、元rowから再構築可能にできる。

用途を限定する:

- 完了Turnの履歴/表示には利用できるが、command送信時の残高/所有/設備条件、live装備/倉庫、入札の信用枠を古いsummaryで認可しない。
- phase途中のゲーム計算に「最終Turn read model」を使わない。必要なpointのTurn内集計を使う。
- post-commit非同期更新はstale windowを導入する。UIで許される範囲とfallbackを先に決める。状態変更の判定へeventual consistencyを混ぜると仕様変更になる。
- read modelがraw secretを持つならprivateな保存境界を保つ。viewerごとにrepresentationが違うmap responseをpublic共有cacheへ入れない。
- source turn/revisionの単調性、再生成、二重実行、途中失敗を検出できるkeyを持つ。再計算でentry identityやfirst discoveryを作り直さない。

## 5. transaction・競合・並列化の比較

### 維持しやすい案

現行 `TurnRunner:244-251,322-376` は全12phase、game state、audit、World current_turn、execution slot完了、TurnRun完了を同じtransactionで確定する。advisory lockを取ったPDO sessionを確認し、root rollbackが確認できた40P01/40001だけを最大3attemptで再試行する（:258-264）。queue送信側もWorld行のFOR UPDATEを取る（CommandQueueService:1903-1911）。

最初のA0/A1/B1はこの境界を維持する。**flushはcommitではない**。同じtransaction内で早めにbatch書込しても、後続phaseが失敗すれば全てrollbackできる。statementsをまとめるためにWorld lockを短くすることは必要条件ではない。

PostgreSQLのrow lockは通常の読取と、同じ行へのwriterの扱いが異なる。advisory lockも、それを取得する協調経路しか自動では止めない。全Writerが同じ鍵を使う証明なしに、World lockだけでSecretary/資産/FK側のlockを削除できるとは結論しない。[PostgreSQL 18 locks](https://www.postgresql.org/docs/18/explicit-locking.html)

Secretaryの地上状態・skills/items、独立した地底profile、借用準備は別lock境界を持つ。`product/docs/architecture/secretary-lock-boundaries.md` のID順取得と所有境界はそのまま必要で、Turn内の全部をWorld-only lockへ広げるのも、全部lockなしへ狭めるのも変更である。

### phase/chunkごとの別transaction

単に「economyをcommit→commandをcommit→災害をcommit」にすると、災害失敗後も生産・消費・攻撃の一部が残り、同じrun/seedでrootからretryできなくなる。Turn開始前の状態へ戻る現契約を破る。stateをDBへ小分けに保存するA1と、部分commitをする案は区別する。

補償transactionで戻す方式も、途中のAPIが状態を観測・利用できれば逆取引が必要となり、単純なSQL rollbackと同等ではない。checkpoint/sagaとして検討するなら、全side effectの冪等性、入力hash、確定状態、公開境界、再開可能なコード版、補償不能な処理を改めて設計する。現行A-07とD-02を置換する新しい判断が実装時に要る。

### lockをchunk単位へ分割・並列化

ゲーム処理を同じにするための反証例:

1. 領土感化: 前cellのowner変更が隣cellのsourceとして直後に使われる（TerritoryInfluenceService:191-227）。chunk開始snapshotを別workerへ渡すだけでは逐次因果が消える。
2. 怪獣: 未処理cellへ移動すると同Turn内で再行動し得る。monsterごとに1回だけ独立workerで動かすと行動数が変わる。
3. 船・誘致: 先の船収益で後のcellの誘致費用を払えるという明示経路がある（CompleteTurnEngineのsupplemental attraction付近）。Nationを分けても同Nationのcell処理を並列snapshotで解くと支払可否が変わる。
4. 防衛・災害: hex半径/haloがchunk境界を跨ぎ、先の攻撃による防衛破壊を次の攻撃が観測する。境界ghost cellを1回同期するだけでは不十分。
5. 共通資金/食品/Secretary: 別cellでも同じNationや同じuserの資産に依存する。地理的に離れていることはread/write setの独立性を意味しない。

乱数結果の変化は許容されても、これらの依存・確率・効果・state transitionを変えてよいことにはならない。並列化可能なのは、snapshotに対する設定解決、座標の不変計算、最終stateからの表示用projectionなど、read setがfreezeされwrite conflictがなく、適用順が結果へ影響しないことを証明した仕事である。CPU量が小さければworker起動/serializeの方が高くなるため、まず単processで測る。

### 抜本案: immutable世代とatomic publish

試案は、version付き入力snapshotから次stateを計算し、`world.active_generation` の切替と全関連状態を一度だけpublishする方式である。途中計算を未公開にできれば、部分commitの問題を回避する余地がある。

ただし、mapだけをgeneration N+1へ切り替えてNation資金/資源/queue/XPを旧世代に置くと、世代混在になる。次のいずれかが必要:

- 全ゲームstateを世代化し、一貫したgenerationを全read pathが参照する。
- 計算結果をprivate stagingへ置き、最後の短い**単一transactionでcanonical rowsへ全変更を適用**する。後者ならDB row identityとFKを維持しやすいが、最終適用のtrigger/競合検証は残る。

World row lockを計算前に解放するなら、queue締切、資産変更、装備、入札、登録、手動操作の全read setをversion/hashで検証し、変化していたら結果を捨てる必要がある。Read Committedでは同じtransaction内の後続commandが新しいcommitted stateを読む場合があるため、単に入力を一度取得しただけでは現在のfresh read契約と等価にならない。[PostgreSQL 18 isolation](https://www.postgresql.org/docs/18/transaction-iso.html#XACT-READ-COMMITTED)

同じWorld lockを維持したままstagingするなら既存の締切は守りやすいが、送信APIの待ち時間は必ずしも短くならない。計算outside-lock案は、競合時の再計算率・queue締切の意味・入力snapshotの診断記録まで含めて比較する。現在の約4秒1fixtureだけを根拠に、世代別永続stateの大規模化を採用する根拠はない。

## 6. trigger・FK・audit・versionを保つ条件

| 境界 | 現物根拠 | 再設計で守る条件 |
|---|---|---|
| cell identity | schema:4781,6871-6923、ships/monster/capital/treasureのmap_cell_id FK | id/座標/帰属を移し替えて歴史の意味を変えない。identity tableを維持するか全FK移行を設計 |
| shipと地形/施設の共存 | schema:392-435,6612、5_2_0_surface_ship_guards.sql | active shipを陸/不許可施設へ置かない。sink/move/cell変更の依存順を維持 |
| monster occupancy | schema:6521、occupancy unique/FK | 同cell二重占有を拒否し、move/deleteをmemory indexとDBで同期 |
| current定義とprovenance | CurrentMonsterDefinitionResolver、5.4関連migration | runtime definition cacheの都合でimmutable作成来歴を書換えない |
| cell/chunk version | CompleteTurnEngine:863-879、MapCellPresenter:159-161 | cellの既存増分とowner表現を維持。changed chunkを同transactionで正確に増加 |
| audit | TurnEventRecorder:24,46、PlayerIslandEventService:295-296 | 全eventのtype/visibility/metadata/draw/因果順を保持。row id順の表示も含む |
| root rollback/retry | TurnRunner:244-278,322-376 | failed attemptの全state/bufferを破棄。同run/target/ruleset/seed、新attempt state。commit成否不明は勝手にretryしない |

監査bufferへModel参照だけを入れ、最終flush時にmetadataを読む方式では、event発生時点のHP/owner/名称等が最終状態へ変わり得る。eventの値を発生時に捕まえ、後でDBへ運ぶ。eventのidが後から採番されても、同じpublic/privateイベント間の順序を保存する。最適化のための「監査削減」「後から現在stateで復元」は本依頼の候補にしない。

## 7. 既存dataの安全な移行と戻し方（将来実装の計画）

今回は実施しない。現行productionの適用状況は未確認で、sourceのbaselineやmigration履歴をproduction適用証拠にしない。

### schema変更なしのA0/A1/B1

まず同じrow schemaに対して新writerを隔離fixtureだけで比較できる。段階adapterには選択可能な旧writerを一時的に残せるが、永続的な第二計算authorityにしない。rollbackは旧writerへ戻し、同じDBをそのまま使える形がよい。新writerがversion/metadata意味を変えた後に旧コードで読み直すような互換破壊は避ける。

### topology/state・chunk canonicalへのschema移行

1. append-only migrationで新table/列/constraint/indexを追加する。既存migrationや使用済みRuleset snapshotを上書きしない。
2. sourceをauthorityにして既存cell ID、typed値、NULL、version、作成履歴を移す。negative/partial chunk、abandoned Nation、occupancy、monument、treasureを含むsupported stateを保持する。
3. 停止中の一括backfillか、短い区間のbackfill+同transaction内の同期を選ぶ。chunk単位に範囲/進捗を記録し、途中停止から二重適用せず続行できるようにする。**値の書換えではなくコピー**で開始し、source側を正本のままにする。
4. shadow読取でcell count/key/nullable fields/version/集計/FKを照合する。新旧engineを二重に実行して報酬/queue/eventを二度永続化しない。計算比較はsynthetic stateかside effectのない結果比較に限定する。
5. cutover前に未解決TurnRunがないこと、fresh installと確認済みbaselineからのsupported upgradeを確認する。旧runtimeと新schemaの不整合な混在を避けるrelease切替を設計する。
6. 新側をauthorityにした後も旧表をfallbackにしたいなら、**新しいgame progressを旧表へ反映し続ける経路**が必要。cutover前backupへ戻すだけでは、その後のplayer dataを失うため安全なrollbackではない。戻す場合は書込停止中に最新stateを逆変換・照合してから旧runtimeを起動する。
7. 再構築可能なread cacheだけなら破棄/再生成で戻せる。canonical新表はcacheと同じ扱いで削除しない。旧列削除/constraint撤去は観測期間と復帰手順を確認した別段階にする。

dual writeを採る場合も一つのtransaction、一つのcanonical mutation、明示された一方のauthorityから他方を派生させる。両方が独立して正当性を主張する状態、エラー時に片側だけ確定する状態は作らない。

## 8. 実装判断gateと、止めずに比較できる範囲

調査段階では全案を比較できる。以下は**将来実装時**の条件であり、今回の比較を停止する理由にしていない。

- 現行A-07は1World1Turnの単一transaction（docs/open-questions.md:177-183）。部分commit/checkpoint/sagaは代替ADRとOwner判断が要る。
- phase固有の逐次因果はA-06/turn-pipelineのcontract。simultaneous resolutionへの変更は本依頼の意味保存条件に合わない。
- lifecycleを独立scheduler/jobへ移す場合はT-02の実装gate。今回の速度案には含める理由がない。
- relational rows正本の採用は `docs/architecture/chunk-storage.md`。chunk JSONB canonicalへ移すならこの設計とschema/consumerの変更範囲を明記して承認を得る。
- DB保存粒度・cacheだけで結果の意味を変えない案は、Ruleset新世代が常に必要とは限らない。RNG algorithm/解決順/selectorを変えるならBehavior分類で、新しい世代案を検討する。本人の乱数結果変更許容を根拠に、確率/効果まで変えない。
- productionのimmutable snapshot/使用済みbaselineを上書きせず、既存のN→N+1上限とrelease境界を実装時に確認する。

## 9. 次に行う測定の順番（今回は実行しない）

目的は「設計が大きいほど速い」を立証することではなく、どのコストを何で減らしたかを分離すること。広範な全suiteや長期試験を繰返さず、変更の故障境界を踏む既存focused caseを使う。

1. **A0**: 同じ固定SHA/依存/PHP/PG/seed/初期fixtureで、2save統合とchunk version一括化を別々に測る。SQL・更新行数・全永続stateの差、version増分、metadata順を比較する。
2. **A1**: 成長中心のcase、ミサイル/怪獣/船で途中DB再読取を踏むcase、途中phase例外のrollback caseを代表にする。batchサイズとdirty-column group数、flush回数/理由を計測する。unknown SQLでflushするfallbackの有効性も確認する。
3. **B1/read model**: 同じ逐次処理のmemory stateと既存SQL集計を比較し、各mutation後/phase末の最小検証点で差を捕まえる。current snapshotsとlive stateを混同するcaseを重点化する。
4. **B2/B3/C1**: 60×60の定常成長、stage変化が多いcase、96×96の疎な変更という性質の異なる代表を用意し、row/JSONBのstatement数だけでなく、更新行数、WAL bytes、HOT/non-HOT、table/index/TOAST size、buffers、serialize CPU、peak RSSを記録する。SQL EXPLAINはsynthetic専用DBで実施する。
5. **競合**: queue送信、ship/cell変更、地上item/Secretary flushなどの既存2process代表で、lock待ち時間と正当な合算/拒否/秘匿を確認する。世界並列化・outside-lock計算を提案する場合のみ対象read/write setの競合を増やす。
6. **再現**: 同じコード版/初期state/seedの再実行一致、失敗attemptの未確定状態なし、commit成否不明時の停止、最終current_turn/TurnRunの一致を確認する。旧版と新算法の乱数列一致を不要な合格条件へ戻さない。

全案で記録する指標は、端から端までのTurn時間、phase時間、SQL形/数、実更新行数、Eloquent hydrate数、peak memory、WALとbuffer、lock保持/待ち、retry原因。意味保存の合否はtyped永続state、queue/資産、snapshot来歴、auditのevent順/metadata、public/private responseで評価する。数値目標を推測で新contractにせず、現在の実測との改善と運用上の価値で採用を判断する。

## 推奨比較順

**A0→A1→B1+Turn内集計→同commitのread model** を最初の実装候補として比較する。これで足りず、WAL/row幅/heap更新が実測で支配的ならB2/B3へ進む。chunk JSONB canonicalはcache用途との比較で利益を示してから検討し、世代別snapshot/outside-lock計算は、競合率・大規模world・公開一貫性の必要性が確認された場合の抜本案として残す。

現在の証拠からは「rowが悪い」「JSONBなら速い」「World lockを外せば意味を保って速い」とは結論できない。一方、現在のrow設計のままでも、同一cell二重保存と大量の単行event/write要求には具体的な削減根拠がある。
