# DB再設計の比較補遺（2026-10-31 追加依頼）

対象SHAは先行調査と同じ `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。既存DBを前提にした局所案だけに限定せず、テーブル/保存粒度/読み取りモデル/競合境界を比較した。**調査と提案のみ**で、schema・製品・実DB・本番/OCIは変更していない。追加DB起動・広範な試験は行っていない。以下の方式を採用済みとは扱わない。追加比較は静的であり、新案の性能実測は0件。

## 判断の出発点

現行の1Nation・3600cell成熟fixtureは3355 SQL/3977ms、そのうちprocess_cellsは3143 SQL/3096ms（SELECT18、UPDATE2012、INSERT1113）。従って、まず大きいのはcellごとの永続化とevent書込、ORM処理/DB往復である。96tableという数や正規化そのものを原因とは断定できない。一方、queueのQ30は104 SQL、ランキング/露店は1queryでも全件hydrateするため、呼出回数と取得量の両方に問題がある。

重要な既存の分離は既に実装されている。地上の`secretary_surface_states`と地底`underground_profiles`、battle恒久結果と期限detail、receiptとdurable rollup、借用元の短いsnapshot準備transactionである。これらを「分割不足」と再提案したり、一つの巨大Secretary JSONへ戻す根拠はない。

## 比較一覧

各行の「期待」は静的根拠からの見積り方向で、候補の速度測定値ではない。小=関数/SQL単位、中=bounded subsystem、高=横断的なwriter/移行/運用の変更。

| ID | 小改修 | 段階的再設計 / 抜本案 | 期待・複雑さ・現時点の判定 |
|---|---|---|---|
| DR-01 cell永続化 | 同じcellの2saveを統合、dirty列別bulk、event recordMany | Turn内にtyped state/write-setを置き、read-your-writesと因果順barrierを明示するUnitOfWork | 最大の実測SQL群へ直接効く。中〜高。**有力な段階案**。物理schemaを先に変える必要はない |
| DR-02 cellの列分割 | 必要列だけSELECT、index/row幅/HOT率を測る | 座標/identityとmutable stateを1:1分割、更新頻度別にpopulation/stateを分離 | 狭い更新rowとindex負担削減の可能性。中〜高。join/trigger増加が勝つなら不要。23列typed rowが既に小さいなら反証 |
| DR-03 chunk保存 | chunk表示用projection/cache | canonical stateをchunk JSONB/packed arrayへ統合、またはWorld世代snapshot+pointer切替 | SQL本数の上限低下は大きいが、書換えbytes/row lock/FK/集計負担増。**抜本比較対象、現時点で採用根拠不足**。derived chunkから試す |
| DR-04 status/ranking/visibility | SQL GROUP BY、同requestのfacts共有 | current revisionに結び付く`nation_status_read`/`surface_chunk_read`/公開feed projection | 全cell hydrateを少数rowへ。中。**有力**だが同transaction更新/正確なinvalidateが必要。owner privateのstale許容は追加しない |
| DR-05 event/log | metadata一度decode、隣接event batch INSERT、適切なpartial/covering index | identity/秘匿/再送を持つsmall coreと大payloadを分離、player feedをderived化 | 大payloadの転送と読み取りindexのworking set削減。中〜高。新たな二重INSERTは増えるので現在のTurn INSERT問題を自動解消しない |
| DR-06 履歴partition | 現index/SELECT列/retention batch改善 | payload/detailだけtime/ID range partition、global identity/receipt directoryは別core | 大きい履歴のmaintenance/scanに価値。高。現件数/plan未取得。単純な全table月分割はunique/FKとpurge prefixで反証 |
| DR-07 市場/装備snapshot | 同じJSON条件をSQLへ押し込みpage化 | immutable snapshotからcategory/style/IL/quality等のquery列を生成/保存、価格/種類に応じた索引 | A件→page+1のhydrateへ。中。**有力な局所schema案**。原本snapshotを残しprojectionは再構築可能にする |
| DR-08 queue保存 | target batch、位置UPDATE batch | active itemとhistory分離、order vector/sparse key、queue全体JSONB | 上限30のためstorage変更の利益は限定。中〜高。JSON化だけではtarget90SELECTを直さない。現行rowのまま優先 |
| DR-09 queue/Turn競合 | transaction内の重複read短縮 | 純queue編集のWorld共有fenceとNation/queue排他、World本体mutationの排他fenceを分ける | 異Nation編集の待ち行列を減らし得る。中〜高。即時実行を混在させない全writer検証が必要。単なるlock削除ではない |
| DR-10 combat境界 | catalog共有、戦闘結果を最終INSERT、lock内投影削減 | immutable入力採取→lock外compute→revision再検証→一括決算(CAS) | SQLよりprofile lock保持を短縮する方向。高。**競合実測が必要**。全依存revision/時間/lease/receipt ID採番を揃えなければ不採用 |
| DR-11 asset/receipt統合 | balance/receipt集合readとdirect unique lock | typed operation header＋detailの共通化、wallet/ledger統合 | 共通index/再送入口の簡素化の余地。高。所有scope/retention/ledger identityが違うため全資産の汎用JSON wallet化は反証。数table削減だけの利益なし |
| DR-12 全event sourcing/分散/並列Turn | sourceとdiagnosticを明確化 | event-only canonical state、別DB分割、phase並列、epoch publication | 将来の別SLOなら比較対象。非常に高。現行auditは完全な再生event streamでなくcompactもある。現在の性能改善として**推奨根拠なし** |

## DR-01: 大改造をするなら、まず永続化境界を明示する

根拠: `CompleteTurnEngine:1830–1875`の人口/stageの2save、`:1923`のsaveChangedCell、`TurnEventRecorder:24`の逐次INSERT。現行にはcoordinates/occupancy/TurnStateのin-memory indexとrecordManyが既にある。新規generic ORMを作るより、既存runtimeへ「取得済みtyped state」「dirty-column mask」「順序付きeffect」を導入する範囲を比較する。

この案は単なる終了時一括saveではない。船・怪獣・災害・中央施設・残高は同じTurn内で前の効果を読む。DBを読む側がwrite-setを経由しないなら、その直前に同じ順序でflushする。`map_cells`のship共存triggerは特定列のUPDATE時に動くため、batchのために全列をSETするだけでも発火条件が変わる（schema:6612）。異なるdirty列maskは分け、Eloquent hook/constraint/affected row/version数を照合する。auditの順序を跨いでまとめない。

期待はSQL往復とORM model保存回数の削減で、WAL/trigger/各rowの仕事が同率でなくなるわけではない。新しいengineと現在engineの二つを恒久authorityにせず、隔離shadow比較後にcanonical pathを一つにする。移行はまずschema不要、既存row ID/全dataは維持。fallbackは同じコード版内で通常writerへ戻し、未flush stateはattempt失敗時に破棄する。部分commitはしない。

## DR-02/03: 行・chunk・世代の三つを混同しない

`map_cells`は23 typed columns（schema:1174）、座標unique、terrain/facility/ownerへのFK。逆にships、monster_occupancies、nation_capitals、buried_treasures等がcell IDを参照する（schema:6715,6987,7019,7395）。物理行を消してJSONのoffsetに置換すると、参照先とDB制約を新しく設計し直す必要がある。

**1:1列分割**はstable cell identityを残せる。座標/indexを持つidentityと、population/version/数量等のstateを分ければ更新rowを狭められる可能性がある。ただしowner/facilityもmutableかつ検索されるため「不変topology」に全部押し込めない。API・trigger・bulk集計のjoinが増える。PostgreSQLの更新は新しいrow versionを作り、HOTはindex列非変更とpage空間等が条件なので、列分割・索引削減が役立つかはHOT率/row幅/WAL/plan次第である。[PG18 HOT](https://www.postgresql.org/docs/18/storage-hot.html)

**chunk JSON canonical化**は例えば多数のcell saveを数十chunk updateへ変えられるが、JSON内の1cell更新でもchunk行全体のlockを取る。JSONBだからcell粒度で同時更新できるわけではない。[PG18 JSON設計](https://www.postgresql.org/docs/18/datatype-json.html#JSON-DESIGN) また、読み取り/更新/所有者集計のために結局小さいindex rowを全cell分残すなら、二重保存と同期が増える。現行は全World lockもあるので、row lockの集約だけで同時実行性は増えない。

**派生chunk表示model**ならcanonical FKを保ち、API用payloadだけを `(space,chunk,world_revision,projection_version)`で持てる。地図取得が支配する実測が出た場合はこちらを先に試す。owner/視界/偽装/天候・船・命名の変化をrevisionへ含め、private responseを他viewerへ流さない。

**世代snapshot+pointer**はfrozen World入力から次世代を構築し最後に公開pointerを切り替える抜本案。読み取り一貫性とpublishの短縮は魅力があるが、途中のCommand/Secretary資産・履歴・外部FKを同じ世代へ揃える必要がある。現行の中間effect相互観測と全失敗rollbackを満たす証明が必要。準備中に別writerを通すとTurnの入力集合が変わる。現行の受付拒否/待機を維持するならWorld fenceは残り、期待した同時実行利益が消える場合もある。

## DR-04/07: 読み取りに必要な形を別に作る価値

`NationBasicStatusProjection::forWorld:104`と`NationLandAreaCalculator`は大きいcell集合をhydrateする。`UndergroundMarketService::browse:37–79`は全active出品を読んでからpage化する。これらはtableを減らすより「答えに必要なrowだけ返す」再設計に対応する。

- status/rankingはまずSQL aggregate/既存forNationIdsを再利用。まだ重ければNation単位のtyped summaryを同じtransactionで更新する。Turn終端だけでは、登録/放棄/建設/船収入/補填/装備等の中間writer後に古い値が出るため、全依存writerか正確なrevision invalidationが必要。
- 市場のcategory/style/IL/quality等はsnapshot由来の不変query projectionに適する。generated column/式indexと通常typed列を比較する。欠損値/型/quality既定値の意味をcanonical projectorに揃え、snapshotは歴史/再送用に保持する。purchasable/blocked reasonは現在profileの動的条件なので同じように永続cacheしない。
- cacheはrequest内parsed catalog、commit revisionに結び付くpublic read model、private responseを区別する。TTL延長でownerへの古い残高/進行を許す案にしない。非同期projectionは現行APIが許す鮮度を超えた時点で意味変更になる。

read modelはcanonical stateから再構築できる一方向の派生であり、二つ目のRuleset/資産authorityではない。backfill中は元sourceだけを正本とし、新modelは影で比較。切替後も不一致ならsourceへfallbackできる形から始める。read modelの更新負担がTurnのwrite削減を相殺するなら採らない。

## DR-05/06/11: event/receiptのcoreは表示専用ではない

`audit_events`はmetadata/jsonbとtimeline列を持つ（schema:698）。adminのrequest_idにはglobal unique部分indexもある（:6038）。PlayerIslandEventServiceはevent IDで順序を作り、AdminOperationsServiceは監査の保存済結果から再送する。従って「古いログだから別DBへ」「月ごとに同じidを許す」は安全な置換でない。

段階案はstable ID/owner/World/visibility/subject/再送identity等のsmall coreを残し、大きいpayloadや期限detailだけを別tableへ分ける。player timelineはそのcoreからの派生view/modelとして作る。既存SELECTで不要なJSONを取らない小改修も先に比較する。追加join/2row INSERTが逆に遅いなら物理分割はしない。現在のbattle結果/detail分離とdurable rollupは既にあるので、再設計の起点にする。

partitionはscan・retention運用に役立つ場合があるが、PG18ではpartitioned tableのunique/PKにはpartition key全列が必要で、既存global id/requestの一意性をそのまま日付partitionへ移せない。親indexのCONCURRENTLY作成にも制約がある。[PG18 partition制約](https://www.postgresql.org/docs/18/ddl-partitioning.html#DDL-PARTITIONING-DECLARATIVE-LIMITATIONS) 全96tableを分割するのでなく、規模/WHERE/保持条件が合うpayloadから検討する。partition自動削除で未検証receipt、初回事実、pin、moderation記録を消さない。

**地底receiptのID順が特に重要**。`UndergroundReceiptRollupService:80–83`はprofile lockを取得してからwriterがID採番する前提でverified_through_idを進め、`:347–358`は昇順/CACHE1/noncycle sequenceを要求する。lock外prepareでbattle IDを先取りし、後続の高IDが先commitされた後に低IDがcommitするとprefix集計/削除から漏れ得る。CASやtable統合でも最終決算のprofile lock内採番を保つ。prepareのidentityが必要なら別UUIDで、永久に全request IDを積む台帳へ広げない。

wallet/ledgerを一律統合しても、UserのPd/券、Secretary地上item、NationのWorld資源、地底profileのG、escrowにはownerとlock/保持の違いが残る。typed operation headerの共通化は比較できるが、polymorphic JSON財布へ押し込めてFK/残高check/ledger provenanceを弱める案は不採用。table数の削減は採用理由にならない。

## DR-08/09: queueをdocument化する前に、orderとfenceを分ける

queue itemにはstable ID/request UUID/fingerprint/request Ruleset/target_context、数量、位置の穴、取消/実行履歴がある（schema:1680）。上限30のactive orderのために全履歴をJSONへまとめる利益は薄い。rowのままtarget batch/位置bulkを行う小案、active/historyの分離、active ID order vectorを親queueへ置く段階案、queue document canonical化を比較する。後2者はDBの位置unique/target/provenanceの保証とpreview hashを再現しなければならず、migration/rollbackは重い。

`CommandQueueService::lockWorldForQueue:1903–1911`はWorld FOR UPDATE。TurnもWorld排他を持つため、異Nationの純queue編集まで直列化する。純粋な編集だけWorld FOR SHAREにし、Nation/membership/queueは排他、Turn/登録/放棄等のWorld本体mutationは排他に残すreader/writer fenceは比較価値がある。PGのFOR SHAREは相互には共存するがUPDATE/NO KEY UPDATEと競合する。[PG18 row lock](https://www.postgresql.org/docs/18/explicit-locking.html#LOCKING-ROWS)

現行addはquantity0を拒否し、Pdによる即時ゲーム実行はadd内にない。一方、日課達成ではPd balance/ledgerへcreditするため、単なるqueue行だけの処理とは呼べない。bulk previewもqueue初回作成と浅瀬cell lockを持つ。最初はreorder/update/cancel等の狭い境界を比較し、addの日課/資産lockとbulk候補lockは別に検証する。途中lock upgradeはdeadlockの新原因になる。各入口とWorld本体mutationを区分し、World rowを更新する全writer/順序/guardとの競合を列挙して証明できるまで採用しない。FOR KEY SHAREは通常の非key更新を防がないので同等の代用品とはしない。詳しい反証と移行条件はcommand設計分冊へ。

## DR-10: 戦闘のlock外計算は、計算結果だけ移せば済む案ではない

借用元profileのlockは既に準備transactionで解放している。残る本人profileはcatalog/戦闘/保存/報酬の期間保持する。現行profileにmonotonic revisionはなく、timestamp(0)のupdated_atをCAS tokenに代用できない。

段階案はimmutable combat resultを最後の一度のINSERTに近づけ、同じtransaction内のcatalog/装備重複/応答投影を削る。既存のprepare/ensure helperにはstarter補完やtrial reconcile等のwriteが含まれるため、read-onlyと誤認して先行commitへ移さない。大きいCAS案では、入力を採取してlockを解放し、pure CPU計算後に全依存revisionを照合して決算する。装備/skill/AI/HP/MP/party/借用snapshot/boost/時間/画像lease/倉庫/初回clear/券等のfresh条件を含む。cooldown/finishedAt、受付24h境界や勝利時だけ消費する資源の時点を勝手に動かさない。receipt IDは上記のとおり最終lock内で採番する。

同requestを二つ実行した場合は一方だけがgrant/決算し、他方は保存済結果を返す。不一致時に新seedを引き直して都合のよい結果を選んだり、二重にcooldownを付けない。retryで採用した初期state/seed/コード版を診断できることが必要。CPU時間が短い・競合が少ないならrevision導入/prepare状態管理がかえって高価なので反証する。profile transactionを細かい複数commitに分割する案や、報酬だけ後続jobにする案は現行atomic操作の意味を変える。

## 抜本案を採らない理由を区別する

- **全event sourcing**: auditは完全なstate再構築journalではなくroutine compactやdetail期限がある。新たに全event/初期snapshot/全code版を永続化して再構築できる方式へ変えると書込/保持/運用が増える。障害再現用seedの目的だけなら必須でない。現行の初期state＋同版seed再現を先に維持する。
- **phase並列/別transaction/別サービス**: ship/monster再行動、途中残高/占領/災害/資産の相互観測がある。同一phase内でも読取集合が独立とは限らない。確率と最終stateがたまたま同じfixtureだけでは同値証明にならない。World単位独立shardは将来複数World設計のgateと別判断。
- **read replica/非同期cacheで全read**: replication lag、本人操作直後のread-your-writes、受付/再送/未解決Turn判定には現行のfresh正本が必要。公開readの許容鮮度に限定した比較なら可能。
- **すべて正規化/すべてJSON化/すべてにindex**: immutable snapshotの意味とquery効率は両立させる。正規化で多段join、JSONで全decode/row競合、indexでwrite増を招き得るため、操作別に選ぶ。

## 既存dataの安全な移行とrollback

これは実施手順の提案であり、実移行の承認ではない。既存migrationはappend-only、使用済Rulesetは不変。

1. 対象の稼働schema/data baselineとsource版を別に確認する。repoのdumpだけで本番適用済みとは推定しない。no unresolved Turn、supported upgrade、identity/FK/unique/ledger/receipt/retentionの契約を採否条件にする。
2. 新列/table/indexを追加するexpand段階では旧canonical sourceを残す。derived read modelは元へ一方向に作る。新旧二つの独立writerを別transactionで更新する構成は採らない。
3. keyset/小batchで既存IDとsource revisionを保ってbackfill。並行mutationでrevisionが進んだrowを古いsnapshotで上書きしない。同transactionでの新旧投影更新か、version guardと再取得で収束させる。generated列なら旧JSONの型/欠損/異常を先に分類し、黙って補正しない。
4. shadow readで全資産/owner/FK/順序/version/整数丸め/公開秘匿/replay結果と統計prefixを照合。大きい列のhashだけで不足する意味は項目でも比較。sequenceを独自に先取り/並び替え/再採番しない。
5. cutoverは必要なら短い停止下でwriter版とschemaを揃えて行う。旧新runtime混在を当然視しない。不一致があれば切り替えない。新しいsourceへwriterを移す場合、切替点を一つにして正本を明記する。
6. 切替前のrollbackは旧read/writeへ戻し派生物を無効化できる。切替後は旧表が全新mutationを正しく反映しており旧runtimeが理解できる場合だけ戻せる。対応しない新状態が保存された後はroll-forwardを選ぶ。schema downや古いbackup restoreだけで稼働中の資産変更を消すrollbackはしない。old列/tableのcontract段階は検証/保持条件と別承認の後。

Read Committedでは同transactionの別SELECTが異なるcommit時点を見るため、backfill/統計の複数queryを「同じtransactionだから同じsnapshot」と誤認しない。既存のrollup+rawを一つのSQL snapshotで読む契約を維持する。[PG18 isolation](https://www.postgresql.org/docs/18/transaction-iso.html#XACT-READ-COMMITTED)

## 採否を決める測定

今回追加の新案測定は行っていない。実装前の次の比較は隔離合成dataで、source/seed/state/依存版を固定して行う。

| 比較軸 | 代表入力 | 必須の観測 |
|---|---|---|
| cell/writer | 成熟人口、森中心、疎なWorld、ship/monster/disaster混在 | phase wall/CPU、SQL/rows、affected列mask、trigger回数、WAL/dirty buffers、全state/event/version同値 |
| view/index | 同cell数でNation/船/market/history数を独立変更 | EXPLAIN/scan rows/transfer bytes、PHP hydration/memory、API payload/可視性、query列とsnapshot同値 |
| queue fence | 異Nation編集2件とTurn/放棄、同Nation二重更新、Pd即時実行 | 待ち時間/順序/guard/conflict/rollback。共有から排他へのupgradeがないか |
| combat CAS | 同request二重、装備/AI変更、borrowed更新、期限跨ぎ、画像削除、rollup同時 | lock保持と再計算率、exactly-once grant、同じsnapshot/seed診断、receipt ID prefixとpin/lease |
| migration | fresh/supported upgrade、中断backfill、cutover前後、fallback/rollback | 原本保持、FK/unique/ID、owner/資産/進行/集計、旧runtime互換、失敗時無変更 |

小改修後に効果が十分なら大改造を採らない。一方、query数を減らしてもrow量/lock/WALが支配すると確認された場合は、DR-01/04/07/09等の段階案へ進む。chunk canonical化、CAS、partition、event-only化はそれぞれの測定利益が複雑さ/運用負担を上回る時だけ検討する。採用方式は先取りしていない。

分担根拠: [Turn/map](db-design-turn.md)、[API/log/read model](db-design-api.md)、[combat/assets](db-design-battle.md)、[queue/fence](db-design-command.md)。この補遺は横断判断をrootで統合し、重複効果を加算していない。
