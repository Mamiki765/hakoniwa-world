# process_cells MapCell保存の契約調査

調査日: 2026-10-31。基準は **main `2e93ec918774ad64d787aba99fc59a7bc3e54d16`**。以下の行番号は明記しない限りこのSHAの `product/` 以下。対照は既存PR #340の固定head **`8509bd5c3c6666db580c6fda2a9c7ce986316642`**。これはsourceの調査であり、本番適用状態の確認でも実測でもない。

結論: 通常人口増加とstage遷移の二重保存を局所的にまとめる余地は実在するが、**最終versionの2増加、同じ場所でのstage監査、共有Modelの更新を残す**必要がある。人口・森の保存をprocess_cellsの末尾まで無条件に遅延する案は、実在する途中DB読取と衝突する。#340はこの両候補を既に含み、DB実行前hookで読取前flushを行うため、新writerの重複実装は勧めない。

`updated_at`のflush時刻への集約はowner APIの出力差である。一方、現在の消費経路と現行bulk保存の反証から、これだけを到達可能なP2回帰とする根拠はない。保存要求時刻を各行へ捕捉する変更は、必要なら互換性・診断粒度を高める追加案として扱う。

## 1. 調査範囲と根拠

- 基準worktree `/workspace/hakoniwa-world` のHEADを再確認し、製品差分がないことを確認した。
- `AGENTS.md`、現handoff入口、`docs/README.md`、関連gate/current-contractsを確認。`archive/`本文は閲覧していない。
- MapCell model、TurnRunner、TurnState、CompleteTurnEngine、MapCellStateService、監査記録、関連する船・怪獣・ミサイル・難民・災害・秘書弓経路、表示経路を読取確認した。app/bootstrap/configのmodel event登録、dirty/change読取、MapCellのtimestamp/version消費を横断検索した。
- schema dump全体でMapCell trigger/FK/index定義を確認し、現migration中のMapCell/関連trigger更新も検索した。dump以後のmigrationにMapCell保存契約の追加・置換は見つからない。現migrationの関連関数変更はqueue rulesetとmonster provenanceで、このMapCell guardを置き換えていない。
- 既存lockfileに対応する隔離vendorのEloquent `Model::save/performUpdate/finishSave`、`HasTimestamps`も読取確認した。vendorコードを製品へ転載していない。
- #340のwriterとengineはGitHubの固定headで取得・読取。localにhead objectがなかったためcheckout/fetch/実行はしていない。既存非archive記録 `/workspace/peridot-notes/lapis/2026-10-23-turn-runtime.md` も確認した。そこに記載された性能・hash一致は**過去記録**であり、今回再測定した値ではない。
- 実DB、本番、runtime起動、長期試験、CI実行、製品変更、commit/pushは行っていない。

固定source入口: [main](https://github.com/Mamiki765/hakoniwa-world/tree/2e93ec918774ad64d787aba99fc59a7bc3e54d16)、[#340 writer](https://github.com/Mamiki765/hakoniwa-world/blob/8509bd5c3c6666db580c6fda2a9c7ce986316642/product/app/Application/DeferredMapCellWriter.php)、[#340 engine](https://github.com/Mamiki765/hakoniwa-world/blob/8509bd5c3c6666db580c6fda2a9c7ce986316642/product/app/Application/CompleteTurnEngine.php)。

## 2. 保存そのものが行う処理

| 項目 | 現mainの実態 | 保存統合での境界 |
| --- | --- | --- |
| Model | `app/Models/MapCell.php:42-106` は通常のEloquent Model。整数castとrelationを持つ。custom boot/observer/dispatchesEvents/touches/version CASはない | 将来の仮想observerを現状のP2としない。既存アプリのcallbackを失う根拠は見つからない |
| Eloquent save | locked vendor `Illuminate/Database/Eloquent/Model.php:1380-1420,1477-1523`。saving→updating→timestamp→dirty列UPDATE→syncChanges→updated→saved→syncOriginal | query builder/raw SQLはこのlifecycleを通らない。app内でMapCellのwasChanged/getChanges/getOriginalを読む到達経路は未発見。`isDirty`利用は島初期生成の別経路にある |
| UPDATE列 | Eloquentはdirty列を保存する。MapCell独自の全列書戻しではない | typed write setは「今回変更しない列」をSETへ足さない。guarded列/FK/lockが増えることを避ける |
| version | DB自動加算ではなく各callerの明示加算。`WHERE id = ? AND version = ?` の楽観更新ではない | 保存回数を減らしても明示加算回数は維持する。versionを保存回数から再計算しない |
| timestamp | `HasTimestamps.php:87-105,137-140` は保存ごとのPHP時計。schema `pgsql-schema.sql:1184-1185` はtimestamp(0)。modelに独自date formatなし | 時刻はseedで固定されない。flush共通時刻は従来の保存地点時刻とは異なり得るが、精度も秒単位 |
| chunk | engine `saveChangedCell:1923-1926` はsave後にchanged chunkを記録。`TurnState:535-549` は集合化。engine `863-879` が各changed chunkを1回increment | cell保存統合後も成功した変更のchunk集合を維持。各行・各saveごとにchunk.versionを増やさない |

app/bootstrap/configのobserver・eloquent listener・DB query callback登録を検索した結果、現在のAppServiceProviderのEvent登録はdestructive CLI guardとSocialite拡張 (`75-81`)。MapCell向けlistenerはない。これは現SHAについての負の確認であり、今後のschema/observer追加に自動追従する保証ではない。

## 3. PG triggerはどのUPDATEで動くか

schema上の**user-defined MapCell直接triggerは1本**。FKの内部trigger/制約は別に存在する。

| 操作・SET列 | user-defined trigger | 読取・副作用 | 根拠 |
| --- | --- | --- | --- |
| `UPDATE map_cells SET map_space_id=…` または `terrain_definition_id=…` または `facility_definition_id=…` | `surface_ship_cell_mutation_guard`、BEFORE/ROW | 当該cellのactive shipをSELECT FOR UPDATE。NEWのmap-space/terrain/facilityと定義を検証。違反なら例外 | schema `6612`、関数 `392-435` |
| `population`, `terrain_quantity`, `version`, `updated_at` のみUPDATE | 上記guardは発火しない | 型・NOT NULL等の通常制約は残る。これらの列にはMapCell FKなし | schema `1174-1198,6612` |
| `owner_nation_id`, `map_chunk_id`, monument系などのみUPDATE | 上記guardは発火しない | 該当FKの検査は別。owner等をpure growth batchへ混ぜない | schema `6874-6922` |
| `map_cells.id`等identity変更 | 今回対象外 | inbound FK・unique座標等の影響。growth最適化で変更しない | schema `4781,6715,6987,7019,7395` |

`UPDATE OF`は値の差ではなくSET対象列で決まる。同じ値をguarded列へ代入しても発火する。[PostgreSQL 18 CREATE TRIGGER](https://www.postgresql.org/docs/18/sql-createtrigger.html)で確認。したがって全列を固定SETする汎用writerは、実際のdirty列だけ保存する現行経路とtrigger/lockの性質を変え得る。

`validate_surface_ship_cell_mutation` は**他のMapCell行を読まない**。`ships WHERE map_cell_id=OLD.id AND state=active FOR UPDATE` と、NEWのIDに対応するmap_spaces/terrain_definitions/facility_definitionsを読む。pendingな別cellの人口/木の量は参照しない。この意味で#340の「別cellの通常saveはpending growthを読む必要がない」という限定前提は現schemaと一致する。ただしactive ship row lockは実在するため、guarded列更新まで並べ替える一般化は別問題。

関連tableからMapCellを読むtrigger:

| 到達する書込 | triggerがDB上で読むMapCell | 現行caller・順序 |
| --- | --- | --- |
| monster_occupancies INSERT/UPDATE全列 | cellをFOR UPDATEし、space/world/facilityを読む。capital禁止、船共存禁止 | schema `6521`, function `223-264`。MonsterTurnService `288-296,392-400` は先にcell terrain/facilityをsaveし、その後occupancyをmove/save |
| ships INSERT、またはUPDATE OF world_id,ruleset_version_id,nation_id,map_cell_id,ship_type_key,max_hp,state | activeならcellをFOR UPDATE、space/terrain/facilityを読む | schema `6619`, function `513-558`。船移動・沈没などから到達。cell変更とship状態変更の既存順序は維持 |
| buried_treasures INSERT/UPDATE全列 | cellのmap_space経由worldを読む | schema `6507`, function `119-143`。SurfaceShipCombatService `87-93`→BuriedTreasureServiceで作成可能 |

これらは単なるSELECT文字列の検索では見落とすDB側reader。ただし読取列はidentity/terrain/facilityであり、限定growthのpopulation/quantity/versionだけを遅延すると直ちに違反する、という根拠にはならない。対象列を広げる際の明確なbarrierである。

## 4. process_cells途中の実在readerと共有Model

engine `556-561` は対象MapCellsをid順でlockしてeager load。`563-575` のcellsById、cellsByCoordinate、災害indexは同じModelを保持する。処理順はfrozen `surfaceCellIds()` (`578,603,757`)。読み込んだid順をそのままゲーム処理順に置換しない。

| 経路 | 観測する状態 | deferred writeに必要な条件 |
| --- | --- | --- |
| 通常の隣接・海底消防・船/怪獣の候補判定 | shared cells/indexの現在値。例: engine `1618-1624`、ミサイル `1040-1048` | Modelの値とterrain/facility relationをその場で更新する。DBへだけ書きPHP indexを古くしない |
| ミサイルbase再検証 | `MissileImpactResolver:120-123` がbaseをDBで再取得 | 読取対象列を遅延する場合、その前にflush |
| ミサイルlaunch開始の人口snapshot | `MissileImpactResolver:511-513` がtarget NationのSUM(population) | **人口成長pendingを先にflush**。PHPのcellだけ更新してphase末保存ではsnapshotが変わる |
| 継続発射の人口同期 | `fireShot:274`→`synchronizeLaunchPopulation:560-573`、DB SUM(population) | **同上**。発射の途中にもbarrierがある |
| 海賊沈没→難民受入 | `SurfaceShipCombatService:94-108`→`NationRefugeeReceptionService:72-95`。DBから居住地を別Modelとしてlockし、現在人口との差で空きを計算しsave | **成長人口を先にflush**。受入後の共有ModelはSurfaceShipTurnService `532-535`でrefresh |
| ミサイル結果の反映 | engine `635-641,654-657` が共有Modelをrefresh/load | 未保存値をrefreshで消失させない。書込後の最新状態を再読取する既存位置を維持 |
| 怪獣へのdamage | MonsterDamageService `73-88` はnested transaction内でhost MapCellを再取得/lock | 対象rowにpendingがあればflush。damage・reward・versionの順序を混ぜない |
| 怪獣防衛施設自爆 | MonsterTurnService `460-479`→災害blast→radius cell refresh。DisasterTurnService `1695-1703` はradius DB load | blast/refreshの前に影響するpendingを反映 |
| 秘書弓 | engine `744-748`→SecretaryBowAttackService `92-96` のoccupancy.cell eager load、必要時 `109-113` のdefense query、damage service | 後段を含むprocess_cells全体を「DB読取なし」と扱わない。phase return前flushだけでは弓前の読取を覆えない |
| global_disasters | 次phaseがDBからradius/all cellsをload (`DisasterTurnService:1695-1714`) | process_cellsのpendingをphase終端までに確実にflush |
| aggregate_nations | engine `795-800`→`populationByNation:1272-1276`、施設query `813-814` | 集計前に全pendingを保存。chunk集合も完備 |
| ship報酬等の容量判定 | NationCapacityResolver `204-209` はMapCell central施設をDB読取 | pure population/quantityだけなら読取列は重ならない。facility/scale等へ範囲を広げたらbarrier |
| Attraction towel残高 | engine `1787-1797` は未支払の適格cellで現在moneyを条件付きdecrement。同Turnの船収益で後の支払が可能になる | MapCell writer最適化に併せて残高をsnapshot化したり前払いへ変えたりしない |

fallback queryを常時実行と誤認しない。engine隣接query `1620-1621` はcoordinate indexにキーがない場合だけ。ミサイル `1051-1053` もshared indexがない場合だけ。通常process_cellsはindexを渡すため、ここをN+1の恒常コストとして再計上しない。

その他のMapCell利用先（map/command preview、国状態/資源予測、視界、登録・初期生成、World拡張/reset、首都配置、船建造/針路、Nation lifecycle/abandonment、spawn、territory/turn order、land/capacity helpers等）も検索した。これらの存在だけからprocess_cells途中のreaderとは扱わない。API readerはcommit前の未確定状態を読む必要がなく、同Worldのゲームmutationは既存lock境界を前提とする。今回のtableはprocess_cellsから到達する実在経路を優先したもの。

## 5. 監査・id順・stageの最小境界

`TurnEventRecorder:14-30` はrecordごとに即時audit_events INSERT。`recordMany:45-67` は呼出位置内のlist順で既存batch INSERTを行う。`row:82-108` はsubjectのmorph/idと明示metadataのみを保存し、MapCell attributes・version・updated_at全体を自動展開しない。schemaにMapCell更新差分を自動監査するcustom triggerはない。

PlayerIslandEventService `294-301` はturn/id降順で読むため、同turn内audit挿入の相対順は実際に利用される。writer最適化を理由にauditをphase末へ移動、type別に並替え、個別イベントを削除・結合しない。audit INSERT時にMapCell pendingが残ること自体は、現行RecorderやschemaにMapCell DB再読取がないため、直ちに差を生まない。

通常成長の現状 (`CompleteTurnEngine:1830-1878`):

1. populationを更新。
2. stageが変わるならsetFacility（facility idだけでなくscale/experience/operational/monument状態とrelationも整理）、versionを1増加してsave。
3. `settlement.stage_transitioned` を同じ位置でINSERT（population/from/to/nation）。
4. increase>0ならversionをさらに1増加してsave、routine summaryへ加算。

最小の統合は**increase>0分のversion加算をstage判定前へ移し、stage保存が起きた場合は後続saveを省略**する形。stageあり増加ならV+2、stageなし増加ならV+1、増加なしstageならV+1、両方なしは保存なしを維持する。stageイベントの相対位置とmetadataは変わらない。guarded facility列UPDATEは残り、追加で消えるのは2回目のversion/timestamp保存。この形は#340のengineに既に存在する。

**同じ変形を全人口減少へ広げない。** famine (`1633-1671`) とover-cap decline (`1739-1764`) は最初の人口saveとpopulation audit、undersea最低人口判定、その後stage/discardの順序を持つ。最初は通常増加分だけに限定するのが小さい境界。

## 6. timestampと楽観versionの要求を分ける

確認できた要求:

- MapCellPresenter `159-161` はownerにcell.versionとupdated_atを返し、non-ownerにはversion=1/updated_at=nullを返す。秘密状態の変化を非ownerのversionへ漏らさない。
- `resources/js/types.ts:859-860` にMapCellの両fieldがある。
- MapCell.versionを入力にしたCASは見つからない。CommandQueueService `2395-2399` は**queue** version、SurfaceShipCourseService `90` は**ship** versionを比較する。これらをMapCell versionと混同しない。

timestampについての反証:

- JS全体検索でMapCell.updated_atは型とfixturesに現れるが、現在画面の表示・選択・sort・cache invalidation・ゲーム判定に使うconsumerは見つからない。App.vueのupdated_at表示はannouncementでありcellではない。
- appでMapCell timestampを比較・並替え・lock判定へ使うconsumerは見つからない。
- **現main TerritoryInfluenceService `282-306` は既にraw bulk UPDATEで `updated_at=CURRENT_TIMESTAMP` を共用し、model save eventsを通さない。**したがって「全MapCellは各モデルsaveのPHP時刻でなければならない」は現状の一貫した契約ではない。
- 秒を跨ぐ遅延で#340の時刻が従来より後になることはあり得るが、元の時刻もwall clock依存。same-code/same-state/same-seed replayはwall clockやphase durationのbit一致をもともと意味しない。

判定: **flush共通timestampは、API上の観測可能な差として明記すべきだが、これ単独で現player/operatorの具体的P2回帰と断定する証拠はない。仮想observerや未実装consumerを理由にP2にしない。** 各rowの保存要求地点で時刻を捕捉する案は、将来consumerのための架空要件ではなく、既存Eloquent保存地点に近い診断値を残す任意の互換性改善として比較可能。ただし、それ自体に速度改善の根拠はなく、捕捉コストとper-row timestamp bindingの追加がある。

## 7. 原子性・rollback・retry・同版replay

| 維持項目 | 根拠と必要条件 |
| --- | --- |
| Worldの1Turnを1 game transaction | TurnRunner `244-251`。cell flushをこの外へ移動しない。audit、資産、world進行、TurnRun完了と同じTXに置く |
| World lock session | `run:47-52,120-121`、`executeAttempt:329-338`。writerが別接続を使わない。lock取得PDOを再接続して迂回しない |
| 限定transient retry | `253-278`。40P01/40001、body failure、root全rollback、同session確認時だけ、最大3attempt。writer例外もこの既存境界へ伝播 |
| attempt間PHP状態の破棄 | `240-241` のMonsterRemoval reset、`340-348` の新Context/TurnState/RNG。writer pending、timestamp、connectionに保持したcallbackのactive状態も失敗/成功のfinallyで破棄 |
| run/target/ruleset/seed | transient retryは同じrun/seed、manual unresolved retryも既存run/random_seedを維持 (`191-214,273-278`)。新seed生成やrelease跨ぎ自動retryを追加しない |
| 成功時flush完了 | 全pending flush成功前にphase成功/resultを返さない。flush失敗の一部書込は同TX rollback。modelだけsyncOriginalしてDB未保存を成功扱いしない |
| completed二重適用防止 | `181-189,333-337`。batch保存の有無でこのguardを迂回しない |

#340のprocessCells wrapper (`530-542`) はbegin→process→flush→return、finally endの形。writerのendはpendingを破棄しactive=falseにする。現在のsingle default connectionとfinally境界について、pendingが次attemptへ残るという具体的反例はsourceでは見つからない。既存retry契約を通したruntime証明は今回行っていない。

## 8. #340との重複・採否の境界

#340 writerの現実装は以下。

- defer対象は通常人口増加/森成長。dirty列がpopulation/terrain_quantity/versionの範囲なら500行までbulk UPDATEし、その他は通常saveへfallback。
- DB ConnectionのbeforeExecutingで、audit INSERTとpendingでない別MapCell単一行UPDATE以外は先にflushする。
- したがって上表のSUM(population)、難民SELECT、refresh、triggerを動かす別table書込もhookの前flushに含まれる。これらのreaderの存在だけでは#340の未flush不具合を意味しない。
- pendingはModel参照であり、flush時点のdirty/値を読む。単一固定timestampを全batchへ使い、raw SQL後はupdated_at設定とsyncOriginalを行う。

現schemaで確認できたこと: pure growth columnsにはship guardなし。別MapCell通常UPDATEのguardはpendingな他MapCellを読まない。audit INSERTもMapCellを読まない。この限定条件は正しい。

残る複雑さ: SQL文字列形に依存する独立判定、Model参照のまま後続変更が合流すること、Eloquent dirty snapshotとcallback時点の前後関係、追加observer/schemaに対する将来の追従。これらを**今の到達可能な不具合が示せないままP2扱いしない**。明示typed row/timestampは可読性・互換性の候補であって、既存writerを別authorityで置き換える理由にはならない。

提案順:

1. stage二重save削減もgrowth batchも#340との共通範囲として扱い、同じ候補を新規発見・二重性能効果へ数えない。mainの後続修正と既存6patchを保持して、必要な差分だけ統合して確認する。
2. timestampの差を現時点で許容するなら、その限定をレポートへ明記して既存writerを評価する。新たなtimestamp-only実装を必須修正として作る根拠は現調査にない。
3. より厳密に保存要求時刻を保つ判断なら、既存#340への小さなper-row timestamp捕捉に限定して比較する。defer毎に最後の時刻を保持し、後続通常saveがある場合のtimestamp優先順位、Eloquentの秒へのserialize、成功後model original同期、failed attempt破棄を含める。保存時刻fieldの追加だけでaudit順/RNG/資産処理を変えない。
4. population/quantity以外のtyped write setやaudit全phase bufferへ、この確認を自動的に拡張しない。guarded列・DB reader・audit orderingの再評価が必要。

## 9. 実装時のfocused検証候補と未確認

今回は読取調査のみであり、以下は**実行結果ではなく選定根拠**。

- 通常stage昇格の既存代表 `TurnCellProcessingTest:1105` を拡張し、stageあり/なしの最終version、facility関連列とordered監査、chunk集合を確認。全列挙は不要。
- 既存 `test_warship_refugees_remain_in_turn_local_settlement_before_later_population_growth` (`TurnCellProcessingTest:949`) と、ミサイル途中人口同期を通る代表で、先行growth→DB reader→後続growthが元の意味を保つことを確認。
- `TurnRunnerTransientRetryTest:39`、`TurnRunnerTest:188,307` に対応する失敗後再試行で、pending/timestamp漏れなし、同run/seed/最終state/ordered監査を確認。新たな全suite/長期trialを必要条件にはしない。
- timestamp改善を採用するなら秒跨ぎをsleepせず制御clockで作り、別rowの保存要求時刻、同rowの後続更新、flush後refreshの値を比較。時計が動く通常run同士のtimestamp bit一致は要求しない。
- DB statements数・trigger lock・時間の実測、実PostgreSQL例外注入、exact-head CIは今回未実施。過去#340のmd5比較はID系metadata除外・timestamp除外を含む条件の記録で、全APIの厳密同値証明とはしない。
- 現productionのDB拡張trigger、observer注入、別接続構成、実worldの対象サイズ・イベント分布は未確認。本番/実DB/資格情報にはアクセスしていない。

## English summary

At main `2e93ec918774ad64d787aba99fc59a7bc3e54d16`, ordinary population growth plus a settlement stage change saves twice and increments the cell version twice. Folding those saves must retain the total version increment, related facility fields, shared model state, chunk invalidation, and the original audit insertion order. Existing PR #340 already implements this optimization and deferred population/forest writes; do not duplicate it.

There are real database-read barriers inside `process_cells`: missile population aggregates, refugee capacity calculations, model refreshes, and combat/disaster readers. PR #340's pre-query flush hook covers those readers. The direct custom MapCell trigger fires only when `map_space_id`, `terrain_definition_id`, or `facility_definition_id` appears in the UPDATE target list; pure population/quantity/version/timestamp writes do not fire it.

The owner API exposes `updated_at`, so a shared flush timestamp is an observable difference. However, current UI/gameplay does not consume that value, and main already performs other bulk MapCell updates with a shared timestamp. The evidence does not establish a concrete P2 regression or a mandatory per-save timestamp contract. Per-row timestamp capture is an optional compatibility/diagnostic refinement, not a demonstrated performance fix. All conclusions here are static source findings; no database, runtime, CI, or production actions were performed.
