# process_cells 監査write-setの静的可否調査

対象repository: `Mamiki765/hakoniwa-world`。基準mainは `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。既存PR #340は `8509bd5c3c6666db580c6fda2a9c7ce986316642` に固定して差分を確認した。以下の行番号は、明記した #340 以外は基準mainの `product/` 相対pathである。

調査日: 2026-10-31。source・schema・既存test・固定差分の読取のみ。製品変更、commit/push、application/DB起動、測定、CI、長期試験、本番・認証情報操作は実施していない。

## 判断

**`process_cells` だけを対象に、record時点で完成した監査行をTurnContext/TurnState内へ保持し、順序を保ってphase末または件数上限でINSERTする小境界は、現行source上は候補として成立する。実装採用・実測成功の判断ではない。**

同phase内で未flush監査をSELECT/UPDATEする、挿入した監査IDを後続処理へ渡す、または正常完了する経路でrecorder外の監査INSERTが割り込む、という反例は確認できなかった。見落とし防止に、全appの `audit_events` 出現箇所（29 files / 54 matching lines、コメントを含む）を列挙し、phaseの呼出経路と突き合わせた。これは全動的SQLを形式検証したという意味ではない。

一方、**Turn全体のbufferは不適切**。`Application/DomesticCommandExecutor.php:1351-1359` の `damagedNationIds()` は、開発コマンドの巨大隕石処理が直前に記録した被害監査を同transaction内で読み、被害国一覧を作る。phase限定ならこの経路は先行する `development_commands` で即INSERTのままになる。

監査IDについて保存できるのは、同Turn内のevent相対順と行数、重複なし、行snapshot、監査参照の整合性である。**全global IDの数値、別transactionとのsequence割込み順、失敗attemptが消費するsequence番号までの完全一致は、phase batchingでは保証できない。** これらまで必須と解釈する場合は本案を不採用とする。IDを予約するためrecordごとにnextvalを発行する案は往復削減を損ない、全worldのsequenceを直列化する案は別の大きな変更になるため勧めない。

## 既存 #340 との境界

#340の `Application/CompleteTurnEngine.php:531` 付近は `processCells()` をbegin / processSurfaceCells / flush / finally endで囲み、成長の二重UPDATE削除と `Application/DeferredMapCellWriter.php` を追加する。監査recorderの変更はない。

`DeferredMapCellWriter.php` の説明と `independentOfPendingCells()` は監査INSERTをpending map cellから独立したものとして扱う。今回の案で新たな `beforeExecuting` / SQL文字列判定hookを追加する必要はない。既存map writerを重複実装したり、そのSQL hookを監査flushへ転用したりしない。両writerを使う場合、phaseが正常returnする前に両方のflushを完了し、finallyでは失敗時のpendingを捨てる。

## phaseの呼出台帳

`Application/CompleteTurnEngine.php:529-784` が追跡入口。各行はphaseから到達する処理と、その監査に関する結果である。下記全経路について、監査SELECT/UPDATE、監査IDの返却・参照は未検出。

| 到達経路 | 主なsource根拠 | 監査書込みと確認結果 |
|---|---|---|
| weather・cell索引load | CompleteTurnEngine:566-594、SeaAreaWeatherService:68 | weatherはmap_chunks upsert。監査の直接操作なし |
| monster prepass / ordinary pass | CompleteTurnEngine:596-607,629-641,763-777、MonsterTurnService:321,423,468,491 | moved/trampled/stayed、自爆・爆風はrecorder。MonsterRemovalService:180もrecorder |
| monster damage / kill / reward | MonsterDamageService:73,120,172,279-327、MonsterKillCycleService:30 | nested TX内でgame state・kill ledger・dropを処理後recorder。監査IDでなくkill stat等のIDをmetadataに保存する |
| monster item drop | SecretaryMonsterDropService:46-80、SecretaryItemGrantService:140-177 | inventory-full分岐・入手通知はrecorder。recorder外INSERT候補の反証は次節 |
| ship move / income / fuel / pirate / warship | CompleteTurnEngine:620-627、SurfaceShipTurnService:219,288,307,478,529,543,553,666,699-725 | record、combat、monster damage、treasure、overflow/Ecopoへ分岐。監査直接操作なし |
| ship damage / sink / refugee / treasure | SurfaceShipCombatService:84-113、SurfaceShipRemovalService:85、NationRefugeeReceptionService:40 | 全eventはrecorder。人口/船/宝物snapshotを監査IDに依存させない |
| ship exploration treasure collection | SurfaceShipTurnService:307、BuriedTreasureService:149-208 | collection_failed / collectedはrecorder。item grantの直接監査候補は次節 |
| missile surface/underground launch、finalize | CompleteTurnEngine:642-671,745-747、MissileImpactResolver:336-377 | impact各分岐、missile launch/detail/aggregate、idle counterはrecorder |
| missile karma / recovery exit / refugee | MissileImpactResolver:655-684,731-752,1980-2043、NationRecoveryExitService:47 | recoveryは状態updateとrecorder。NationLifecycleService::exitRecoveryForCrime:147-155は同exit serviceへ委譲。manual監査は呼ばない |
| settlement / forest / oil / famine / riot | CompleteTurnEngine:676-743,1442,1476,1502,1597,1653,1660,1746,1772,1875,1908 | 直接engineのeventもrecorder。routine summaryのTurnState集計は監査DBを読まない |
| fire / defense blast / nuclear blast | DisasterTurnService:711,1451,1500,1538、MonsterTurnService:359-371,442-471 | charm、monster/ship removal、facility damage、public damageはrecorder。災害のglobal execute自体は後続phase |
| secretary bow | CompleteTurnEngine:749-757、SecretaryBowAttackService:201 | MonsterDamageServiceへ戻る。装備・確率・経験値は監査DB非依存 |
| secretary charm / XP / demographic XP | SecretaryDisasterCharmService:34、SecretaryExperienceAwardService:18-38、SecretaryDemographicExperienceService:14-15 | charm eventはrecorder。XPはTurnStateへ積み、監査ID非依存 |
| capacity / food overflow / Ecopo | FoodOverflowResolver:59,90,175、EcopoService:42-54、Domain/Economy/CapacityBoundedAssetService:21,167,202 | nested TX、asset mutation後recorder。Paradox exchangeはfinalize側でありphase対象外 |

補助policy/catalog/resolverも確認: MapCellStateService、SurfaceVisibilityService、NationProtectionPolicy、CurrentMonsterDefinitionResolver、MonsterHardening、MonsterBehaviorResolver、MonsterRewardPolicyResolver、SecretaryItemEffectAggregator、SecretaryItemTargetSafetyPolicy、SecretaryItemProbability、SecretaryNavyEvasionService、FacilityScaleDamageService、CentralFacilityDamageService、LaunchBaseExperienceService、CollectionRegistrar、UndergroundFacilityBenefits、KarmaTurnService::snapshotMissileBoundary、NationIdleCounterFinalizer。監査への別入口、監査ID返却、監査readbackはない。CollectionRegistrarのSQL対象はuser_collection_entries、MonsterKillCycleServiceはnation_monster_cycle_statsであり、監査表と混同しない。

SurfaceShipForcedDisplacementServiceも周辺確認したが主にdevelopment commandの経路であり、同serviceのsink/recovery/eventもrecorderを使う。WorldExpansion、registration、abandonment、admin、manual profile/ship command等をphase内から呼ぶ経路はない。

## recorder外INSERT候補の反証

### SecretaryItemGrantService::recordInventoryFull

`Application/SecretaryItemGrantService.php:230-258` は明確な直接INSERTである。これを「recorderが全書込みを支配している」と無視してはならない。ただし、同phaseの正常成功経路からは以下のguardで到達しない。

1. **Monster drop**: `SecretaryMonsterDropService:46-50` は `grantGenerated()` だけを呼ぶ。`SecretaryItemGrantService:146-158` はSecretary surface stateをlockしてduplicateを検査し、同lock内で `$used >= capacity` なら**即return**する。full監査はcallerの `monster.item_drop_inventory_full`（:56）であり、直接 `secretary.inventory_full` ではない。
2. fullでなければ `grantGenerated:160-170` がdrawだけのcallbackを呼び、同じ `$used` を `persistLockedGrant(..., $used)` のknown usageへ渡す。`persistLockedGrant:200-205` のdirect-full判定は同じusage/capacity。callbackは `SecretaryMonsterDropService::drawItem:115-157` で、inventoryやcapacityを変更しない。従って現行の正当な入力・同lockを守るmutationではdirect-full条件へ反転しない。
3. **Exploration ship → treasure**: `BuriedTreasureService:159-173` はsurface lockを取得し、全treasureのrequired合計を算出して `count + required > capacity` ならrecorderのcollection_failedで即returnする。以降はlock内でrequired件以下のgrantを順次実施する。grant内部も同surface lockを使うため、通常の成功経路で容量を超えない。
4. 仮にtreasure preflightとgrantがdivergeし直接fullへ到達しても、`BuriedTreasureService:189-191` がDomainExceptionを投げる。catchしてTurnを継続する処理はなく、whole TXがrollbackする。その失敗経路ではglobal sequence消費位置は変わり得るが、逆順の監査行がcommitする例にはならない。

これはDB直書きや新しいcallback実装までの永久保証ではない。現行guardを変更する将来差分では再監査が必要。「direct writerが存在する」という理由だけで正常phase bufferを不可能とするのも、guardを読まずに安全とするのも不十分である。最小実装ではgrantの全callerへcontextを配線する変更は必須ではない。

### NationLifecycleService::recordManualEvent

`Application/NationLifecycleService.php:532` はdirect INSERT。しかし `enterDormant:432-436` はcontext有ならrecorder、context無のmanualだけdirect。process_cellsの到達口 `exitRecoveryForCrime:147-155` はNationRecoveryExitServiceへの委譲であり、ここからmanual分岐へ入らない。

### その他の直接writer

AdminOperationsService、AuthIdentityService、BoostScheduleService、CommandQueueService、MessageBoardAuditRecorder、ModerationRecordService、NationAbandonmentOperation、NationCreationService、NationProfileService、SalePolicyService、SecretaryEquipmentService、SecretaryItemSaleService、SecretaryNamingService、SecretaryProfileService、SurfaceShipCourseService、SurfaceShipNameService、TradingPostService、UndergroundLendingRewardService、WorldExpansionServiceにはdirect INSERTがあるが、上記phase graphから呼ばれない。TradingPostTurnServiceはenforce_capacities側で、TurnEventRecorderを使う。これら全体の一括置換は今回の小境界に含めない。

## readback・ID・制約・transaction

| 条件 | 確認結果 / 根拠 | 意味 |
|---|---|---|
| phase途中の監査SELECT/UPDATE | 到達graphに未検出。app全体のreaderはDomesticCommandExecutor、AdminOperationsService、PlayerIslandEventService、CollectionBackfill、OilDiscoveryExperienceBackfill、RoutineAuditCompactor、ExpandWorld、ResetWorld | phase限定の間は監査をDBへ見せる必要があるconsumerを確認できない。全Turnへ拡張しない |
| recorder戻り値 | TurnEventRecorder::record:14-34 / recordMany:45-64はvoid | INSERTした監査IDを返していない |
| auditへのFK | schema/pgsql-schema.sqlの `REFERENCES public.audit_events` は0件。subject_type/idはmorph情報で、監査から対象へのFKもない | 監査IDを子rowの親IDとして即利用する制約なし |
| auditからのFK | schema:6683 actor_user_id→users SET NULL、6691 nation_id→nations SET NULL、6699 world_id→worlds CASCADE | actorはTurn recorderでnull。phase中はworld/nation削除を呼ばず、turn/world lock・既存nation lockを保持。遅延FK検査の一般的同値性まで保証するものではない |
| unique / check / trigger | schema:6038 admin request_id uniqueはadmin.operation_completedのみ。visibility/severity checkは:716-717。audit table triggerなし、migration内にもaudit追加変更なし | 行数削減や重複排除は不可。JSON・defaultsはrecord時点に確定。制約errorはflushで発生し得る |
| row snapshot | TurnEventRecorder::row:80-115はsubject morph/key、nation、coordinates、JSON、defaultsと時刻を確定する | Modelや未解決event descriptorを後へ持ち越すと意味が変わる。完成rowを保持する |
| 同Turn内event順 | PlayerIslandEventService:295-298,461-465,531-534はturn/id順。:2352では派生表示IDにもaudit ID使用 | 挿入row順を元のrecord呼出順に保つ。type別・nation別に並べ替えない。record後のsubject mutationを反映させない |
| global sequence | schema:4002はshared audit_events_id_seq。WorldMutationLock:116はworldごとのkey。AuthIdentityService:18-21,73、SecretaryProfileService:36,281等は別lockからINSERT | 他worldやprofile/auth等とのglobal ID割込み順・数値同値は保証不可。公開表示はworld/turn filterだがglobal ID自体は外へ出る |
| nested TX / catch | MonsterDamageService:73、grantGenerated:146、grant:100、FoodOverflowResolver:59,90、EcopoService:42。到達graphにcatchしてrollback後継続する経路なし | pending行がrollback済みsavepoint由来なのに後でcommitされる反例は未検出。将来catch継続を足すなら別対策が必要 |
| whole-TXとretry | TurnRunner:241-249でexecuteAttempt全体をDB::transaction。:350-357でattemptごとnew TurnContext/new TurnState。:259-266は完全root rollback確認後の限定retry | stateはattempt局所で保持。失敗時flushせず捨てる。retryで古いbufferを再利用しない。afterCommit flushは禁止 |
| 外部reader / compactor | 外部connectionからは未commit監査が現状も見えない。RoutineAuditCompactor:151-160はcompleted TurnRunを条件にする | phase内のINSERTを遅らせても外部に部分Turnを新たに見せない。既存保持整理やadmin replayを削らない |

既存 `tests/Feature/CompleteTurnIntegrationTest.php:632-650` のretry比較は監査をidで並べ、event_type/subject/metadataを比較し、audit自身の数値IDは比較対象にしていない。同test:638-640はPostgreSQL sequenceがrollbackしないことも明記している。これはOwnerのID要件を勝手に狭める根拠ではなく、現在の検証範囲を示す証拠である。

## 成立し得る最小設計（未実装）

1. `TurnState` にphase-local、attempt-localなtyped write-setを持たせる。型は監査16 columnsの完成row、またはprivate readonly DTOとそのordered list。contextのModel参照、callable、任意SQL、closure、あとで評価するmetadataを溜めない。
2. `TurnEventRecorder::record()` は従来のrow生成と `now()`、JSON_THROW_ON_ERROR、subject morph/key、visibility/severity確定を**呼出時点**に行う。activeな `process_cells` の `TurnContext` のみappendし、それ以外のTurnContextとWorldEventContextは従来どおり即INSERTする。定義正本や新たなイベントauthorityを作らない。
3. active scopeは `CompleteTurnEngine::processCells()` で明示的に開始・終了。正常return直前にordered chunksを同じconnection/TXでINSERT。たとえば既存recordManyと同じ1,000 rows上限なら16,000 bindings/statement。任意のSQL文字列hookは不要。
4. 件数上限で中間flushしてもrecord順を保つ。これはmemory O(total events)を避ける手段で、第三者INSERTの順序問題を自動解決する機構ではない。scope外へpendingを残さず、flush中の例外は握り潰さない。finallyでdiscardする。DB失敗を個別INSERTへ自動retryするfallbackは加えない。
5. `recordMany()` を対応させる場合、既存の「一回のcallの全rowは同timestamp」を保存する。現process_cellsからのrecordManyはないが、通常recordと順序を混ぜるAPIにするなら一度生成したrowを同listへappendする。既存resource_salesのbehaviorを変えない。
6. direct `SecretaryItemGrantService::recordInventoryFull` をSQL hookで検知してflushする実装はしない。正常到達性guardを文書化し、もし実装時に実際の成功反例が見つかれば、小境界を再評価または見送る。

## 期待効果と反証

親の既存隔離測定ではmature 60×60の `process_cells` に **1,113 audit INSERT** がある。今回自身は新しい測定をしていない。この全件が本境界で捕捉されるfixtureなら、1,000 rows/chunkでINSERT statementは理論上 **1,113→2**。行数、index entry、FK検査、WAL量そのものは減らない。JSON解読ではなく書込み往復・statement構築/実行回数の削減候補である。

局所PGでroundtripが小さい場合、監査以外のUPDATE、RNG、PHP、index/WAL処理が支配すればwall time改善は小さい。memoryコピーと巨大statementの時間が増える可能性もある。#340による成長UPDATE削減後の残余時間で評価する。1,113 rows全件を落としたりsummaryへ集約したりして得る効果は意味保存の範囲外である。

## 採用前に必要な最小検証

- 既存mature fixture一回のbefore/afterで、phase SQL数・event行数・event_type/subject/metadata/visibility/severity/message/coordinates・ID昇順のevent相対順・phase metrics・世界状態を比較する。独立した同一初期sequence・競合なしの通常fixtureでは**audit ID数値も含めて**比較する。clockを固定してrow timestampsも比較する。sourceとseedを固定し、新たな長期・全suite・CIをこの調査から起動しない。
- 既存のmonster full inventory代表 `tests/Feature/SecretaryItemEffectsTest.php:1089` とtreasure collection容量境界の代表を優先し、full時のevent種別・件数・非再抽選・receipt/asset不変を照合する。未検出のdirect INSERTが成功Turnへ入るなら不採用または境界再設計とする。
- 既存monster/ship/missile/fire/refugee代表から、相互にeventが交錯する一つのfixtureを使う。全部を新規の組合せtestへ展開しない。直接serviceを呼ぶ既存testではbufferが非activeで従来どおり即書込みとなることを守る。
- row snapshotをrecord後のsubject mutationで変えないこと、二つのrecordのtimestampをflush時刻へ潰さないこと、chunk境界で順序と行数を保つことは既存代表の最小拡張で確認する。
- 既存wholeTurn failure/retry代表に接続し、flush前/flush失敗のいずれも世界状態・監査rowがrollbackし、retryへpendingを持ち越さないことを確認する。DBエラーの発生地点は変わり得るため、failure phaseをprocess_cellsのまま診断できるか確認する。
- global ID数値・別TXの割込み順・失敗sequence gapは同値主張から明確に外す。それまで保存する必要があると判断された場合は追加mechanismを積まず、本候補を見送る。

## 限界・未解決

本調査は静的に到達性を整理したもの。任意の将来config mutation、DB trigger追加、外部listener、非正規DB書込み、runtime instrumentationの全挙動を保証しない。source内Model boot/providerを確認し、現在のSecretary::created callbackはsurfaceState作成のみ（Models/Secretary.php:48-53）で監査へ書かないことも確認した。

未確認の重要点は、実装したbufferでのevent完全比較、batch時のID順のPG実行確認、memoryとwall time、#340統合時のfocused回帰である。実装branch/PRをこの調査では作成していない。最初の `git show 8509bd5` は未取得objectのため失敗したが、親の共通repo fetch後に固定full SHAで読取を完了しており、未解消の操作ブロッカーはない。

## 独立レビューへの引継ぎ台帳

この調査は未実装案に対して「P2なし」を宣言しない。次のcapture点を実装diffと突き合わせるための台帳として渡す。

| capture点 | 実装で確認すべき故障 | 固定sourceの根拠 |
|---|---|---|
| engineのstage / famine / oil / riot / appearance | engine注入recorderだけ別sinkにして他service eventを取りこぼさない。stage subjectを後で再評価しない | CompleteTurnEngine:1442-1908、TurnEventRecorder::row:96-115 |
| missile / ship / monsterの各注入recorder | recorder instanceが別でも同一context.stateへappendする。type/subject別にgroupして順を変えない | MissileImpactResolver:78、SurfaceShipTurnService:45、MonsterTurnService:31、MonsterDamageService:32 |
| helperのEcopo / food overflow / charm / drop / refugee / recovery | helperの新しいrecorderもphase activeを拾う。outer service側だけのevent queueでは不足 | 各serviceの上記call graph、同じTurnContextの引数伝播 |
| row生成とclock | subject Model・metadataの後続変化、flush時の一括nowで過去eventを上書きしない | TurnEventRecorder:23-33,80-115 |
| threshold / tail / empty | 1,000到達時とphase末のtailで欠落・二重insert・順序逆転を生じさせない。empty時query不要 | recordMany既存chunk実装:45-64を参考、実装後PGで確認 |
| 非active context / WorldEventContext | 直接service利用、development command readback、manual world mutationの即監査を変えない | recordのunion type:15、DomesticCommandExecutor:1353 |
| nested transaction / failure / retry | failed savepoint由来pendingをcommitしない。phase exception時のfinally discard、attemptごとのnew stateを維持 | TurnRunner:241-266,350-357、現到達graphはcatch継続なし |
| #340との組合せ | phase正常終了前にmap writerとauditの両flush完了。SQLhookによる監査検知を足さない | #340 CompleteTurnEngine::processCells wrapper、DeferredMapCellWriter |

親の最新設計案で示されたnullable buffer、1,000 rowsでの明示flush、active時のrecordMany共通sink、他context即INSERT、finally discardは、この台帳の静的境界に合致する。実装後の不具合有無は固定head差分とfocused証拠で別途判断する。

## English summary

A narrowly scoped, attempt-local audit write-set for `process_cells` is statically viable as a candidate, not yet implemented or benchmarked. Build complete rows at record time, preserve row order, timestamps, metadata and defaults, and insert ordered chunks before leaving the phase within the existing whole-turn transaction. No arbitrary SQL inspection hook is needed.

No audit readback, update, returned audit-ID dependency, or successfully committed direct audit insert was found in the reachable phase graph. The apparent direct inventory-full insert is guarded: monster drops return before generation when full, and ship treasure collection preflights all required slots under the same surface-state lock. A treasure preflight divergence throws and aborts the whole turn. Development commands do read newly written audit rows, so whole-turn buffering is unsuitable.

The approach can preserve same-turn relative event order and committed row content/count, but cannot preserve exact global numeric IDs, cross-transaction sequence interleaving, or sequence gaps consumed by failed attempts. Reject the candidate if those stronger guarantees are required. The parent's existing fixture has 1,113 audit INSERT statements; 1,000-row chunks predict two statements while keeping all 1,113 rows. Index/WAL work remains. Runtime equivalence, ordered IDs, rollback/retry, memory and wall-time checks remain necessary; this investigation ran no application, database test, CI or production operation.
