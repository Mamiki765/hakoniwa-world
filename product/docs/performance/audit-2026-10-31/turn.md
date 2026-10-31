# 公式Turn性能調査（担当分）

対象は `Mamiki765/hakoniwa-world`、固定 main SHA `2e93ec918774ad64d787aba99fc59a7bc3e54d16`（5.8.3 / Ruleset v41）。製品コードは変更していない。調査・測定ファイルは本ディレクトリにのみ作成した。production、OCI、実データ、認証情報にはアクセスしていない。

AGENTS.md、current-status/current-contracts、docs/README、open-questions、関連する現行設計・既存性能調査を確認した。checkoutと/workspaceの `.agents/skills` は存在せず、対象treeにも追加AGENTS/skillはなかった。archiveは閲覧していない。

## 判定の前提

`TurnPipeline::CANONICAL_PHASE_KEYS` の12 phaseを起点に、`CompleteTurnEngine` の全phase dispatch先と主要下位処理を確認した。`turn-coverage.csv` は12phase+下位経路の65行。「確認済み」はsourceの到達経路・主要loop/SQLを確認した意味であり、全分岐の動的試験済みを意味しない。「問題なし」は本調査の静的観点で追加の具体的改善を特定しなかった意味で、性能上限保証ではない。`turn-dependency-inventory.csv` はconstructor型から辿れる110 sourceファイルの構造棚卸しであり、実行call graphや全分岐の意味確認を主張しない。`new`/static/メソッド注入/動的dispatch、framework内部はその自動台帳だけでは辿らないため、phase台帳を正本とする。

記号: C=地上cell数、N=active/recovery Nation数、L=lifecycle Nation数、M=alive monster数、S=船数、O=生産する油田cell数、F=火災抽選成立後に海底消防の選択へ到達した海底都市数、B=弓を持つNation数、T=active埋蔵宝箱数、P=食料消費優先種類数、D=イベント数。

ユーザー承認に従い、旧版と将来ターンの乱数消費順の完全一致は必須にしない。一方、抽選確率・効果、同じ版/初期状態/seedの再現性、seed秘匿と障害診断は守る。以下の最小案の多くは乱数結果自体も変えずに実施可能である。

## 優先候補と根拠

### T01: immutable設定の検証済みitem effect catalogを再利用（優先: 高）

- 到達経路: `NationCapacityResolver::resolve():95` → `SecretaryItemEffectAggregator::capacityPercentages():27` → `SecretaryItemGameplayContract::validatedEffectCatalog():247-266`。毎回 `validate()` で全定義を検査し、42 itemのeffectを再展開する。これは5.8.2のJSON解読cacheとは別のCPU仕事で、現行にcacheはない。`SecretaryTurnService:42` も同じcatalogを開始時に構築している。
- 呼出数: food生産、栄養消費後overflow、resource_sales、enforce_capacitiesの各resolverから、food生産量が正の通常Nationで4回/N。電力施設を実際に処理するNationはPowerEconomyTurnService:101,118経由でさらに2回/N。これにその他の資産付与経路が加わる。食料生産0時はoverflow側を呼ばないため常に4Nとは言わない。
- **今回の純粋CPU実測**: 現行v41、PHP8.5.8 CLI、42 item、500 calls×3: 1119.24 / 1003.63 / 1001.56 ms。中央値2.007 ms/call。出力hashは3回同一。DBと全Turn時間はこの測定に含まない。結果 `turn-catalog-results.json`、再現script `turn-catalog-bench.php`。
- 期待: 同じimmutable payloadへの反復分を除き、約2ms×削減呼出数というCPU見積り。例えば通常10Nationの4calls/Nだけで約80msの処理があるが、実際の全Turn改善値ではない。
- 最小変更範囲: canonical validator/catalogに検証結果を保持し、同じRulesetモデル・同じ生JSONへ限定して利用する。設定代入/refreshで必ずinvalidateする。設定のauthorityやvalidatorを別系統へコピーしない。巨大settingsを毎回serialize/hashするkey方式は逆効果になり得る。
- 意味保存リスク: draftの同じidを編集して古いcatalogが残る、長寿命singletonで異なるpayloadを混同する、検証を省略する実装。既存モデルcacheと同じ生値の変更境界、異常設定のfail-closed、current/past payloadと再試行の新contextを確認する。変更内容が計算cacheのみならRuleset世代の更新は不要。

### T02: 海底消防候補を19hexセルから選ぶ（優先: 条件付き高、局所）

- `DisasterTurnService:630,678-719` は火災成立後、`$cellIndex->cells()` を毎回呼ぶ。`DisasterMutableCellIndex:45-51` は**全Cセル**をidでsortして返すため、半径2の消防候補選択だけで O(F×C log C)。現在の半径は `config/hakoniwa/rulesets/current/terrain-and-disasters.php:178-183` の2。
- 既存 `cellAt(x,y)` を使い、半径2の19セルを取得してidでsort後、現行owner/施設/距離を判定すれば O(F×19 log19)。同じ生きたMapCell参照なので、途中の施設破壊も再判定できる。
- **今回のCPUアルゴリズム実測**: 実コードのindexと座標計算を使用、9216 scalar stub cell、候補選択50回×3。現行全sort: 326.34 / 347.45 / 292.32ms。radius index案:1.372 / 1.386 / 1.161ms。id順候補 `[4561,4656,4658]` 一致。モデルstubなのでEloquent属性アクセス・SQL・支払・火災確率・保護判定は含まない。速度比を全Turnへ外挿しない。結果 `turn-undersea-index-results.json`。
- 期待はFとC依存。Fは全海底都市数ではなく火災成立・休眠保護/お守り不成立後の件数であり、F=0なら効果0。最小実装はこのprivateメソッドの候補集合だけ。
- 検証: 既存 `DisasterAndOilTurnTest::test_undersea_fire_uses_hex_range_and_one_affordable_payment`、foreign施設、地上不保護、重複消防の1回支払を使う。id昇順、同owner、hex半径、最新money、FOR UPDATE、charge/eventは維持する。既存 `test_fire_protection_source_scan_has_bounded_query_shape` のfixtureは工場/森林で、この海底経路は通らない。

### T03: 油田の同一残高再SELECTを除く（優先: 中、局所）

- `CompleteTurnEngine:1455,1470-1475` はNation lock、油残高のlock付きvalue取得の後、`creditInventory():1364-1367` が同じ残高を `firstOrCreate` で再SELECTしてからincrement。既存残高がある通常経路で油田1マスあたりNation1+残高2=3 SELECT、残高UPDATE1、event INSERT1。
- 最初の残高lockをモデル取得にし、同じメソッド内のincrementに渡せば、既存残高の O SELECTを省ける。before/afterの監査metadataを現行どおり保持し、欠損残高作成は現行と同じtransactionで行う。
- 静的SQL数の推定であり、今回PGの油田ケースは測定していない。Nation lockを外す案や全Turnで古い残高をcacheする案は含めない。`DisasterAndOilTurnTest` の生産・秘書bonus・枯渇・rollbackをfocusedで計測する。

### T04: 食料消費の残高取得をNation単位にまとめる（優先: 中）

- `CompleteTurnEngine::consumeFood():1382-1392` はP種類それぞれfirstOrCreate SELECTとFOR UPDATE SELECTを行うため、通常2P SELECT/N。その後の更新は消費した種類だけ。
- 現行の栄養計画と消費優先順を維持し、対象food残高を一度にlockしてmissingだけ作る案。資源が既存なら2P→1 SELECT/Nが静的な削減上限。`lockedOrCreatedBalance()` や既存salesのbatch取得を参考に、汎用の第二asset経路を作らない。
- PG未測定。複数残高のlock取得順、欠損行の競合、食品の整数栄養・端数/不足、電力用食品と栄養消費の順、rollbackを確認する。単にlockを削除してはいけない。

### T05: capacityの同一World・設定・関連sourceの反復読取（優先: 中、設計注意）

- 現行v41 `NationCapacityResolver:29,66,93,204` は1resolveにつきWorld SELECT、central/condenser SELECT、membership/skill/itemをjoinしたcapacitySources SELECTの3SELECT。T01のCPUに加え、通常4calls/Nなら12SELECT/N、電力処理Nationはさらに6SELECT/Nのこのresolver由来読取が発生する。
- まずcontextに既知のWorld+rulesetの整合検査結果を渡し、Worldの再読取だけを省く局所案。次にphaseごとのsourceをまとめる案。`CompleteTurnEngine:894-895,995-996` のescrow読取は既にWorld単位にまとめられているので再提案しない。
- **Turn全体のcapacity値cacheは不可**。developmentで中央施設が建つ/失われる、災害で施設が変わる、finalizeでsecretaryのskillが上がる。開始時snapshotへの置換はlive capacity契約を変える可能性がある。phaseのmutation境界ごとの再取得・invalidationsが必要。`FoodOverflowResolver:98` の生産時capacity同一チェックも維持する。PG計測と同時更新の既存focused試験が必要。

### T06: 成長cell書込・逐次audit INSERT（既知の未採用候補、優先: 中〜高）

- `CompleteTurnEngine:1830-1844,1873-1875,1923-1926` はstage変化時のsaveの後、同じcellのpopulation成長保存をもう一度行う。森も変更cellごと1UPDATE。fire/famine/disaster等はeventごと1INSERTで、`TurnEventRecorder:24` は逐次INSERT、recordMany:46-67は既に存在。
- 同一cellの最終書込への統合、成長のbounded batch書込、隣接したauditのrecordMany化はSQL削減余地がある。食糧飢饉は `CompleteTurnEngine:1653,1660` の2event/被害cellもあり、D INSERT→ceil(D/batch)が形式上の上限。
- **新発見ではない**。`peridot-notes/lapis/2026-10-23-turn-runtime.md` の2/3が既調査。そこには同じseedの旧isolated結果の一致検証と、UPDATE2654→260、process_cells3.0→1.2sの歴史的結果があるが、現行SHAの測定ではない。5.8.2はJSON解読cacheだけ採用し#338を含めないと明記。現行本体にDeferredMapCellWriterはない。
- **親による現行SHAの隔離PG測定が再支持**: 既存60×60成熟world 1case、1test/5assertions pass。全Turn3977.031ms/3355SQL、process_cells3096.32ms/3143SQL（INSERT1113/SELECT18/UPDATE2012）、stage_transition900。facility+population+version UPDATE900件とversionだけUPDATE900件が同居する。出力 `turn-current-mature.json` と `turn-current-mature-profile.log`。単一fixture/単回であり本番速度を保証しないが、N+1読取より書込件数が支配的な現行query形を示す。
- 一般的なdeferred writerを先行導入するより、まず同じcellの2saveを1saveにする局所案を評価する。DBの途中SELECT/refresh、trigger、monster/ship/施設破壊の相互作用、version増分、監査の挿入id順を保持する。PlayerIslandEventService:295-296等はevent id順を使うため、別種類の途中eventを跨ぐまとめ方では表示順が変わる。診断用metadataを削る・auditを黙って省略する案は除外。

### T07: 探索船の宝箱/視界の反復（既報残件、優先: 中〜低）

- `SurfaceShipTurnService::explorationDestination():317-349` は探索船ごと全cell collectionのbounds算出、visibleCoordinates、全active treasure関連を取得しfilter/sortする。`SurfaceVisibilityService:31-73` はbounds4回走査、land SELECT、ships SELECT+cell eagerと定義解決。探索船E隻なら O(E×(C+T logT)) と関連SELECTが増える。
- NQ-06として `product/docs/testing/post-5.0.1-nplus1.md` に既報。全宝箱のsortから最小距離+idを選ぶだけならO(T)にできる。視界/treasure indexは同じNationでも先行船の移動、宝箱取得/破壊、領土変化を観測するため、単純なTurn cacheは意味が変わる。最新状態に追従できるindexを設計するまで横断cacheを採用しない。既存探索船の候補・visibility・複数船連続動作のfocused計測が必要。

### T08: 秘書弓の全monster×全Nation候補走査（優先: 低、規模依存）

- `SecretaryBowAttackService:122-163` はB NationそれぞれM occupancy全件を走査し、リボン時はそのNationの防衛施設も判定する。O(B×M×防衛数)。同所有地monsterと世界アオイの候補index、半径2防衛maskで対象を絞れる。
- ただし前のNationの命中でHPが変わり、kill時は `:219-226` で集合から除く。safe target/finisher判定は各弓直前に行い、共有monster参照を更新する。候補の一様抽選、未所有worldアオイの重複除去、硬化/不死への安全判定を保つ必要がある。PG/CPU未測定。M/Bが少なければ無視できるので先行最適化しない。

### T09: 地盤沈下snapshotのNation別再走査（優先: 低、稀な多発時）

- `DisasterTurnService:334-357` は沈下が成立したK Nationごと全C snapshotを再走査してownerを絞る。事前にowner別のid順snapshotを作ればO(K×C)→O(C+対象領土合計)にできる。
- 全World snapshotは海岸判定・同時の不可逆地形変化をfreezeするためのものであり、削除してはいけない。隣接6方向は現行snapshotから読む、affected Nation処理順とmetadataを保つ。K=0では全World loadを避ける既存最適化済み。今回は静的候補のみ。

### T10: RNG representation/native engine（優先: 低、測定済み）

- 現行 `DeterministicRandomStream:67-112` は8wordのHMAC blockを4byteずつsubstr/unpackする。1blockを一括unpackしてoffsetを持つ案なら同じbit列を維持できる。
- **今回実測**:100000draw×3、現行41.89/42.15/43.66ms、一括unpack19.89/20.75/21.14ms、native Xoshiro+Randomizer8.82/9.00/9.26ms。現行と一括unpackのchecksum一致、full signed range/単値/高rejection領域を含む10000drawの完全一致。nativeはchecksumが異なるが同seedの反復3回は一致。factoryのcache済みstream呼出100000回19.05ms。`turn-rng-results.json`。
- 一括unpackは今回のunsigned word/bounds/shuffleとcounter overflow例外を保持して追加確認する。10000drawあたり約2.1ms削減という規模であり、全Turnの優先事項ではない。native engineなら約3.3ms/10000drawだが、明示algorithm version・新しいRuleset候補が必要。private256bit master seed→label分離を維持、unbiased bounded integer、platform対応と再現vector、固定コード版のretryを確認する。PHP/global randや予測可能な公開seedへの置換は提案しない。

### T11: lifecycleのcollapse判定で未使用の全statusを計算（優先: 中、Nation数依存）

- `NationLifecycleService:262-263` はtotal_populationとfood_total_tonsの2値だけを読むが、`NationBasicStatusProjection::forNation():45-80` はWorld+Ruleset取得2SELECT、food1、人口1、territory count1、陸地1、facility definition1、facility group1の少なくとも8SELECT/N、さらに地下farm/factory別にsnapshotを取得する。地下施設0でも各1SELECTで、施設ありならeager relation読取が加わる。
- collapseに必要な最新2集計だけを渡す局所案、または既存forWorld/batch projectorの利用が候補。先行Nationのlifecycle処理が次Nationの判断inputへ影響する場合、まとめた古いsnapshotへ置換しない。現行statusは未使用facilityまでvalidateするため、既存のfail-closed検証を別のcanonical phaseが保証するかを確認し、黙って異常検出を消さない。
- 親の成熟1Nation測定ではfinalize69.523ms、同phaseでWorld4SELECT、地下facility種別4SELECTの反復も存在した。この数値はfinalize全体でありT11単独の時間ではない。複数NationでSELECT数増分を測るのが次の検証。collapse、idle休眠、recovery、abandon/escrow保護の既存focused試験を使う。

### T12: changed chunkのversion更新をまとめる（優先: 中、局所）

- `CompleteTurnEngine:863-879` はchanged chunkを順序付きFOR UPDATEで取得・存在確認した後、chunkごとにincrementする。親の現行成熟測定で16 UPDATEを確認した。
- 取得/lock順と欠損例外を維持した後、対象idへの `version = version + 1` 一括UPDATEで16→1（このfixture）の余地。affected行数を確認し、重複idを二度増加させない。MapChunkモデルobserverやrow/statement triggerで副作用の差がないこと、version全件+1とretry rollbackを確認する。大量cell batch writerより変更境界が小さい。15SQL削減の期待で、時間差は未測定。

## 再提案しない既修正・問題なしの根拠

- 5.8.2 `RulesetVersion:25-61` は生JSON単位でdecodeを再利用済み。旧29.8→4.6sは歴史的報告であって、このSHAの追加改善見積りに数えない。
- 5.8.3 `SecretaryTurnService::flushCharmCharges` はlifecycle全Nationを対象にし、消費をまとめて保存する。1被害ごとのDB読取/更新へ戻さない。
- TerritoryInfluenceはcellをbatch load、coordinate index、World集計、mutation batch UPDATE（:282-307）、event recordMany済み。新たなcell SELECT N+1は特定しなかった。
- MonsterTurnBatch / SurfaceShipTurnBatch / DisasterMutableCellIndexはO(1) occupancy/coordinate lookup。missile coveringDefenses:1231-1244はradius2のmemory探索を既に使用し、shotごとの防衛SQL再取得を防止済み。
- 海域天候は1回draw、chunk bulk read+upsert、後続phaseはstate cache。global weather災害はarea+haloを一括取得し、全World再hydrateを避ける。natural monster spawnもeligible Nation/必要settlementだけを取得する経路を持つ。
- 停止軍艦のNation二重lock SELECTは5.2で改善済み（`SurfaceShipTurnService:204` destinationNation再利用）。NQ-03を再提案しない。
- summary/人口/陸地はgroupByでまとめる。finalizeの後段はlifecycleでabandon等があり得るため、aggregate_nationsの前段結果でsummaryを全面置換する案は除外。
- process_cellsのprepass・本pass・normal monster passは3Cループだが、移動先が後順cellなら同monsterが再行動する仕様がある。最初のmonster集合だけを一回動かす置換、ship/monsterを独立jobに分離、phaseを並列化はゲーム処理の意味を変えるため提案しない。
- TurnRunnerのWorld advisory lock/row lock、same-session確認、全transaction rollback、限定retry、phase診断は必要な正当性コスト。高速化名目で外したりrelease跨ぎretryを追加しない。

## 測定環境と限界

新規の一時containerは既存local image `hakoniwa-world-hakoniwa-dev:latest`、image id `sha256:3ce451260a2a66cf1e49aa5170bc4cb09de8edfb69669bd701cf8a10e2ad8310`。`--network none --read-only --entrypoint php`、必要なsourceディレクトリだけreadonly bindした。PHPは**8.5.8**（productのDockerfile8.5.12とは異なる）、CLI opcache off、JITなし。3回はばらつき確認の最小限で、長期試験・全suite・CIを実行していない。vendorはpure probesで使用しない。

最初のRNG probeでは許容signed32範囲外の検証入力を入れたためexit255で測定なし。正当な高rejection範囲に修正して実行した。undersea probe初版の近傍station集合が空だったため、境界内3件/外1件を持つfixtureへ直して再測定した。最終JSONは修正後の結果。

DBのEXPLAIN、productionサイズ/アクセス比率、current PHP8.5.12のprofileはこの担当で測定していない。親が隔離PGで実行した既存60×60成熟world 1caseの計測を上記T06/T11/T12へ引用した。各候補の秒数を足し合わせて全Turnの改善率としない。動的未確認はCSVに明記した。現行sourceと旧履歴を照合したが、非公開の全未merge議論までは網羅していない。

推奨着手はT06の同cell2save統合（現行fixtureで900UPDATE削減余地）→T01（immutable catalog）→T03/T12（局所の重複SELECT/chunk UPDATE）→T04/T11（Nation増加への対策）→T02（実際に海底都市が多いworld）→T06の一般batch writer→T05 phase別source→T07/T08/T09→T10。最大効果の判断は親のcurrent全Turn測定を優先する。
