# 地底DB・transaction境界の再設計比較

基準: `Mamiki765/hakoniwa-world@2e93ec918774ad64d787aba99fc59a7bc3e54d16`、application 5.8.3。調査専用。source/製品設定/実DBの変更、DB起動、追加試験、CI、本番/OCI、secret閲覧は行っていない。本分冊は既存 `battle.md` の局所改善候補から範囲を広げ、保存単位・テーブル統合/分割・戦闘CPUのtransaction外実行を比較する。以下の期待効果は静的な構造・問い合わせ回数・保存回数の推定であり、実測の速度保証ではない。

## 判断の要点

**まず同じDB構造のままcritical sectionを短くする案が有利。次に、実際に同じprofileの待ち時間が支配すると確認できた場合、同期HTTPのまま短いsnapshot取得と最終CAS確定に分ける案を比較する。** 一括テーブル統合や全event sourcingが必要だという証拠は現時点でない。

地底の現行DBは、常に過剰に正規化されているわけではない。profileは一人の可変資産と進行をまとめ、装備は一個一行、戦闘は一確定一行、貸出は少人数の参加一行、詳細logと恒久統計は寿命を分けている。既に実装済みの地上/地底lock分離、借用元snapshotの短い先行transaction、数値projection cache、永久/詳細snapshotの縮約、verified prefixによるrollup/purgeを新規提案として数えない。

最も注意を要するのは、単に `fight()` をtransaction外へ移すことではなく、**採番・受付時刻・結果確定順・資産付与を一つの整合した境界に残すこと**である。特にreceipt IDを先行準備で採番すると、現行rollupのprefix契約を壊し得る。

## 現在の保存grainとauthority

DDLは `product/database/schema/pgsql-schema.sql` のbaselineと現行forward migrationを合わせて読む。本番への適用状態を推定していない。5.5.0の `is_listed` / `source_market_listing_id` / market tablesは `product/database/migrations/2026_10_18_000000_add_market_synthesis_and_warehouse_expansion.php:37` 以降にあり、baselineだけでは現行構造にならない。

| 保存対象 | 現在のgrain・正本 | 寿命/他表への依存 | 設計上の評価 |
|---|---|---|---|
| `underground_profiles` | Secretaryごと1行。Lv/XP、G/銀行、HP/覚醒、次戦時刻、STP、skill/AI選択、鍵/石、rental HP、家/導入進行等 | Secretary所有。Nation放棄で移転・削除しない | ゲーム状態の直列化点として分かりやすい。一方、無関係に見える操作も同じrow lockで待つ |
| `underground_skill_allocations` | profile×nodeの獲得/active slot | combat入力とrespec。profile lockと併用 | catalog定義と個人の配分を区別。全てをprofile JSONへ集約しても同時競合点は減らない |
| `underground_owned_equipment` | 所持instanceごと1行。owner/slot/protected/polish/listedは可変、generated payload/identity/出所は取得したinstanceの事実 | grant/source unique。marketでownerは移る。source IDsは削除可能receiptへのlive FKと分離 | 一個の資産を一個としてlockし、譲渡・売却・研磨を同じinstanceへ適用できるのは適切 |
| `underground_profiles.rental_party` | borrowerごと最大3人の現在HP/最大HP/覚醒 | borrowed sourceの現在HPとは別。宿・編成更新・戦闘で更新 | lenderの生HPを参照する設計へ統合しない。borrowerが持ち越す進行が正本 |
| `secretary_lending_build_snapshots` | lender Secretaryごと1行。source fingerprint、source snapshot、最大32個のnumeric projection map等 | canonicalな装備/技能から再構築可能なcache。behaviorとnumericを分離 | gameplay正本ではない。変更単位よりJSONが大きい可能性はあるが、cache分割の優位は未測定 |
| `underground_parties` | 現行runtimeでは一戦のPT rootを作成。leader/content/人数/平均報酬のsnapshot | battleのnullable party FKはUNIQUE（schema`:5517`）かつRESTRICT。1 partyを参照するbattleは最大1件。partyにbattleが必ず存在する逆方向の必須性は強制しない | 編成テンプレートの再利用表ではない。確定戦闘へroot情報を寄せる余地はある |
| `underground_party_members` | 上記PTのplayer側memberごと1行。1〜4人、借用は最大3人 | participation/ticket ledgerがmember IDを参照。NPC companionを含む | 少人数JSONだけへ押し込むとFK/unique/個別報酬の境界を失う。member grain維持に理由がある |
| `underground_battles` | profile/requestごと一つの確定戦闘result。seed、結果/前後残高、compact snapshot、統計 | terminal結果のみ。raw receiptは保持/verified purge境界あり | 一戦の結果として適切。初回INSERT後にdrop結果をsnapshotへUPDATEするwriteがある |
| `underground_battle_logs` | battleごと期限付きactions/presentation | battle FK CASCADE。detail保持とraw receipt保持は別 | 永久receiptとの分割は既改善。大きな詳細を通常history取得で読まない利点がある |
| image reference/lease | 発行済みsnapshotが参照する画像の予約/保持 | 秘書画像差替え/削除と直列化。期限後に整理 | 単なる表示cacheとは扱えない。CPUをlock外へ出す場合もleaseを失わない |
| `underground_skip_settlements` / `underground_skip_batches` | 一skip request / 一bulk requestごと一確定 | private seed又はexecution別派生、まとめたreward snapshot。資産はprofileへ確定済み | 1,000実行を1,000個のbattleへ展開する設計は現行の良さを失う |
| `underground_intro_requests` / market receipts | 各mutation requestの再送照合と結果 | 24h受付と既結果再読を区別。各種receiptに保持契約 | 全操作を永久に追記する新registryは不要。統合するなら既存receiptを置換する設計が必要 |
| clear/trial progress / lifetime/rollup | 初回/進行/恒久集約。receiptから復元し直す運用に依存しない | purgeのdurability blockerと連動 | raw logや消せるreceiptへ統合しない |
| lending participation / ticket balance/day/ledger | 一参加事実、User残高、日次/累計、券の増減 | owner順→残高→日次row lock。participation ID等のunique | 参加の事実、現在残高、監査を一つの可変JSONへまとめると二重付与防止が弱くなる |

主な根拠: schema `:2441` cache、`:2857` battle、`:3064` equipment、`:3116` party、`:3156` member、`:3196` profile、`:3292` rollup。party参照uniqueは`:5517`、equipment uniqueは `:5540-5574`、battle source reward uniqueは `:6409`。currentの生成/保存は `UndergroundEquipmentDropService.php:292-332`、member保存は `UndergroundLendingRewardService.php:37-97`。

### 再現性のために保存されているものと、保存されていないもの

現行 `UndergroundBattleStorage::compactSnapshot():41` はAI/full stats/equipment/skill配分/initial state等を永久snapshotから意図的に落とす。`compactPartyMemberSnapshot():78` も表示/出所/レベル中心に縮約する。従って「seedが保存されているから、30日後でも全過去戦闘をseed一つだけで再計算できる」とは言えない。

今回守るのは、同じコード版・初期状態・seedを与えれば再現できる性質と診断可能性である。全入力の永久保存を新しい義務として増やさない。CAS/job用にimmutable input bundleを作るなら、release SHA、実際に解決したcontent/combat identity、必要な数値/AI/HP/敵編成、private seed、capture時刻/boostを実行単位に凍結する。これは実行入力/診断情報であり、新しいRuleset catalogや別の資産正本にしない。保管を増やすならpending/失敗診断/通常成功の期間を明示し、現行compaction/receipt保持を無断で無期限へ延長しない。

## 現行transactionの時間的な境界

soloは `UndergroundRuntimeService.php:271` のDB transaction内で `:284` profile lockを取得し、重複/入場/試練/受付確認→seed/encounter決定→`:1906`装備/definition→`:1933`純戦闘計算→`:1990`HP/覚醒/cooldown/資産→`:2010`以降receipt/log/drop/画像/clear/day questを確定する。leader profileはこの全区間でlockされる。

PTのborrowed sourcesは既に分離されている。`:3292` leader level/装備ILの短い採取、`:3351` source profileをID順lockして借用snapshotと画像予約を作り、解放した後にleader transactionへ入る。`:3327` はleader level/装備ILが変わった場合の再準備を判定する。borrowed profileを本戦闘中にlockしているという前提で改善を提案してはならない。

PT本体では `:2489` party/member作成の後、`:2503`でCPU戦闘を行う。`:2690`前後のdrop/log、`:2709`貸出報酬も同じroot transactionへ含まれる。貸出ownerのticket/day row lockは戦闘後に取得するので、純戦闘CPUの全時間をlender残高lockが占有しているわけでもない。

静的なlock保持時間の分解は次の通り。

`T_profile = 入場/再送SQL + catalog/装備構築CPU + fight CPU + 投影/統計CPU + 資産/receipt/log/image/clear/day決算SQL`

row lockを維持したまま最初のCPU3項を減らす局所案と、CPUをlock外へ出す案は別である。CASは競合の少ない時のCPU自体を速くせず、lock内区間を短くする。衝突時には破棄計算/再読取が増える。前分冊のmicrobenchはPHPの局所時間であり、DBを含む `T_profile` や本番待ち割合は未測定である。

## 案の比較

| 案 | 変更grain/境界 | 静的に期待できる効果 | 複雑さ/競合・意味上のrisk | 現時点の扱い |
|---|---|---|---|---|
| D0 同schemaの局所短縮 | 一requestのcatalog/装備共有、AI走査再利用、貸出二段SELECT削減 | 反復CPU/DB往復を減らし、その分profile lockも短縮 | 小。既存整合性の境界を保てる | 最初に比較・実装候補。`battle.md` BU-01〜04 |
| D1 確定結果を一度で保存・member batch化 | pure result/drop planを先に構築し、同じroot transactionで最終result/資産へ確定 | battleのINSERT+snapshot UPDATEをINSERT中心へ、member最大4件のORM往復をまとめる | 中。persisted victoryを要求するguard/ID/grant順を明示して分離する必要 | 大改造前の段階案。SQL/WAL bytesを測って判断 |
| D2 同期snapshot→lock外CPU→CAS確定 | profile関連revisionを導入、pure immutable input/resultをmemory保持、final lockで検証して一括確定 | profileのlock/transactionからfight/catalog/投影CPUを除外可能。他操作の待ちを減らす | 大。全writerのrevision、時刻、receipt採番、grant、画像、再送を横断 | lock待ち支配の実測が得られた場合の有力な再設計案 |
| D3 hot/cold状態と装備factの分割 | profileの非戦闘表示/進行を分ける。装備immutable payloadを1:1側へ分ける候補 | hot UPDATEのtuple/WAL/TOAST量を減らす可能性。無関係な操作の競合分離 | 中〜大。tableを分けても同じprofile lockを取れば競合は減らない。joinも増える | tuple/TOAST/WAL/競合内訳測定後。列数だけでは採用しない |
| D4 party root/receiptの再編 | battleにparty root情報を統合、member grainは維持。receipt共通header+typed payloadへの置換を比較 | party rootの1 INSERT/lookupや複数receipt存在照会を減らせる可能性 | 大。ID/FK/旧party統計/rollup cursor/replay互換の移行が広い | 正規化数を減らす目的だけでは採らない |
| D5 lending cache粒度変更 | source1行+projection keyごとのcache行等へ分割 | 一projection追加で最大32map全体をupsertするwrite量を減らす可能性 | 中。index/row数/roundtrip/GC/冷cache競合が増える | JSON sizeとmiss/WALの実測待ち。cacheは再構築可のまま |
| D6 実行reservation/worker | 一操作のpending→compute→terminalを明示、同profile FIFO又はbusy制御 | Web/DB transactionの滞在を切り、CPU負荷を平準化できる可能性 | 非常に大。API待機/timeout/順序、crash復旧、release跨ぎ、24h期限、運用queueが増える | 機能/運用上の利点が必要。高速化だけで先行しない |
| D7 全event sourcing/資産store統合 | profile/equipmentをevent再生のprojectionへ全面置換 | 書込直列化やread model最適化の設計自由度 | 最大。既存に完全な過去入力/eventがない。状態再生や補填が別authorityになりやすい | 根拠不足。現データから歴史を捏造せず、採用前提としない |

## D1: 小さなwrite再設計

現在のsoloは `UndergroundRuntimeService.php:2010` 以降でdrop=pendingのbattleを作成し、`:2087`前後で実dropをsnapshotへ入れ直してsaveする。PTも`:2590`以降→`:2697`付近で同型。実際の中間pendingは未commitのため利用者には見えず、結果行の二段writeを一つへ寄せる意味保存の余地がある。

ただし `UndergroundEquipmentDropService::settleVictory():35` / `settleTrialVictory():103` は **persist済みのowned勝利**を要求する。guardを削除して任意のdrop planをgrantできるようにするのは不可。分離するなら、(a) pureな抽選/生成plan、(b) canonicalな勝利/resultと同一transactionでのみ行うgrant、を明示する。抽選sourceは既にground/trial/request IDを使い（同`:48-52`, `:114-118`）、生成identityもseedとsourceを含む（`UndergroundRuntimeEquipmentGenerator.php:219`）。新しい抽選を追加しない。

battle IDが必要なら **final profile lock内**で採番/作成を行う。既存に無い実装前提を置かず、次の二案を測定比較する。

- result本体は従来どおり一度INSERTし、drop結果を狭いtyped列/child factへ1 INSERTする。大きなsnapshotのUPDATEを減らせるが、read時の結合が増える。dropの正本をsnapshotとchild両方へ残さない。
- IDを同じtransaction内で確保してpure planを最終snapshotへまとめ、resultを一度INSERTし、既存uniqueを保つgrantを行う。guard/障害rollbackを含むまとまった変更になる。sequence gap自体は失敗時にあり得るが、per-profile順序を失ってはいけない。

party memberの1件ずつcreate/その後loadも最大4人のbounded処理。bulk INSERT RETURNING等の適切な一括取得でIDを保持できれば往復削減候補だが、一戦のCPUに対する寄与は未知。IDを新しく振り直したり、participation ledgerのmember identityを失ったりしてまで削減しない。

## D2: lock外CPU + optimistic/CASの成立条件

### 一つの採用可能な構成案

1. **短い採取transaction**: profileをlockし、既存の受付/入場/再送/active trial条件を確認。profile・equipment・skill・trial/progress・必要なinventory等のrevisionと、実際に解決したimmutable戦闘入力/seed/content/boostを採取する。PT借用元は現行の先行snapshot境界を利用する。状態・資産の消費をcommitしない。画像は必要なleaseを保持する。
2. **DB transaction外のpure compute**: frozen inputのみからfight、投影、統計、pure reward/drop planを生成する。mutable DB読取や資産writeを入れない。未確定結果をclientへ返さない。
3. **短い最終transaction**: 同じprofile lockで既存receiptを先に確認し、成功済みならそれを返す。期待revisionと必要なread setが一致した場合だけ、入力に対応する結果をcanonicalな残高/HP/覚醒/試練/日課/貸出券/装備/画像/receiptへ一括確定する。receipt IDはここで採番する。不一致なら結果を破棄し、同一requestの再試行をboundedに行う。再計算する場合もコード/内容versionを黙って変えない。

この構成は同期HTTP内でも可能であり、まずjob/statusテーブルを増やす必要はない。反面、同profileの複数requestが同じrevisionで計算してCPUを浪費する。global player数が増えても一人ずつ別profileなら現行profile lock同士は直接競合しないため、CASの効果は総QPSだけでは判断できない。

既存の採取helperをそのまま短いtransactionで呼べばreadonlyになる、という設計ではない。`UndergroundProfileService.php:11-25` はfirstOrCreate/lockを行い、Runtime`:3448` からの `UndergroundStarterEquipmentService::reconcile():16-56` はstarter新規付与/未装備weapon補完を書き込める。trial content reconciliationにも書込がある。元のroot transactionなら後続失敗時に戻る補完を、prepareだけで先にcommitしてはならない。初期のCAS対象を既に整合したopen状態へ限定し、補完が必要な状態は従来の一括lock経路へfallbackする案、又は既存補完をpure planとfinal applyへ分ける案を比較する。調査範囲からその状態を除外する意味ではなく、実装を段階化する境界である。

### 現行に不足するversionとread set

`underground_profiles` にCAS用の単調revision列はない。`updated_at`はschema `:3196` のtimestamp(0)で、同秒更新/値が元に戻るABA/子rowだけの更新を検出できず、代用できない。新しいrevisionを使う場合は、単なるEloquent save observerへの依存では不十分。query-builder更新、marketの譲渡、starter補完、装備/研磨/売却、技能/AI/respec、rental HP、trial進行、鍵/石/残高/解放状態等、意味上のread setに関係する全writerが同じlock下でrevisionを進める必要がある。profile.saveがdirtyでなければ更新しない経路も考慮する。

一つの総合revisionは実装しやすいが、背景変更等でもCPU再計算してしまう。複数revision（combat/build/inventory/progress等）は余計な衝突を減らす反面、見落としや整合性の複雑さを増やす。最初の設計は粗いrevisionで安全性を証明し、測定したfalse conflictを根拠に分離する比較が適切。設定/解決済みcontentもrevisionとは別に同じidentity/hashを要求する。

### 保存/時刻/競合の具体的な落とし穴

- **receipt採番**: `UndergroundReceiptRollupService.php:80-83` は全supported writerがprofile lock取得後にIDを割り当てることを前提にしている。`:347-358` は昇順・noncycling・CACHE 1を要求する。prepareで低IDを予約→別requestの高IDが先にcommit/rollup→低IDが遅れてcommit、となると `verified_through_id` / `deleted_through_id` の前へreceiptが後着する。CAS準備用にbattle/skip IDを割り当てない。必要なら入力bundleは独立UUIDで識別し、確定receiptはfinal lock内で生成する。
- **時刻**: current cooldownは `:1990`付近で `finishedAt + cooldown`。prepare時刻に前倒しするとプレイ頻度を変える。日次の券・quest、24h admission、boost捕捉時点、trial締切/イベント条件も時刻を含む入力/決算として整理する。current admissionはprofile lock後、fightより前に判定する。CPU中に期限を跨いだだけでfinal拒否へ変えるのも、逆にcrash後の期限切れ新規実行を許すのも意味変更になる。
- **borrowed sourceの再検証**: currentは準備時点の借用元を凍結してlockを解放する。finalでlenderの最新装備/公開可否を再読して結果を棄却する条件を追加すると現行契約を変える。recheckするのは自分のsnapshot選択に必要なleader同期入力と確定対象状態であり、borrowed source全体を再び現在値へ読み替えない。
- **inventory/容量**: compute中に同じprofileへ装備購入/market受取/売却が入ると、勝利dropのgrant/vault_fullが変わる。inventory revisionを含めてretryするか、どこまで最終状態に対して可換に決算できるかを証明する。適当に最新容量だけ使うと、元の直列順で起こらない結果になり得る。
- **画像**: profile lockとは別にsource共有lock/画像leaseがある。lock外へ出したinputが参照する画像を先に消させない。leaseの予約/昇格/期限整理と、失败時の解放は同じ実行identityで扱う。
- **二重送信**: 同じUUIDが並行computeしても、finalで同じprofile lockとrequest uniqueを通り、勝者のreceiptを返す。二度目はplanの資産効果を再適用しない。別HTTP operationとのUUID意味も既存fingerprint/admission contractに合わせる。
- **不確かなcommit**: timeout/接続断でcommit成否不明なら同一requestの保存済resultを確認する。新request/seedを作って再grantしない。code更新後の古いpendingを自動で新コードへ読み替えない。

旧乱数消費順の完全一致をCAS採用条件へ加えない。random engineを更新する候補では将来結果の変化を許容できる。一方、retryごとに勝ちやすいseedを引き直す、clientへ未確定結果を見せて選ばせる、確率/報酬を変えることはこの許可に含まれない。

## D3〜D5: テーブルの統合/分割をするなら

### profileのhot/cold分割

home背景/一部既読と、戦闘のHP/XP/残高を別rowにする案は比較できる。ただしvilla/倉庫拡張/石購入/解禁フラグには費用・容量・入場条件があり、全部をcosmetic扱いしてlockを外せない。profile IDはSecretaryに対応するcanonical identityとして維持する。

分割で熱いrowが小さくなればWAL/tuple更新量を減らす可能性があるが、実際のtuple/TOASTサイズ・変更列・index条件を測っていない。PostgreSQLの物理挙動を「50列だから遅い」と推測しない。読み取りで二表を結合し、全writerが元profileをlockし続ければlock競合は変わらない。独立操作を同時に通すなら、可換性を証明しlock/revisionを分ける設計が本体になる。

### equipmentのimmutable factと可変binding

現在のgenerated payloadをcoldな1:1 fact表へ、owner/slot/protect/polish/listedをinstance表へ分離する候補はある。payloadは元抽選の事実で、研磨はそこへ決定論的に適用する。既存のinstance identity、grant key、source IDs、qualityを移行時に保存する。seedから再生成して既存装備を置換しない。

generated instance identityはseed/sourceを含みほぼinstanceごとに固有（Generator`:219-238`）。「同じ見た目の装備だからpayloadをcontent-addressで一つにする」とは直結しない。固定装備は既にcatalog keyを持ち、全定義payloadを各rowへ複製していない。payload分割が大きく効くか、単にjoinが増えるかはpayload sizeと更新頻度で測る。

equipped slotを `(profile,slot)→equipment` のbinding表へ移す案も、現在のUNIQUE(profile,equipped_slot)が既に担保している関係を置き換えるだけになり得る。複合FK等で「そのprofileが持つitem」を保護し、slot/category検証、market出品不可、HP再計算を維持する必要がある。readを速くするだけならまず一操作内snapshot共有で十分な可能性がある。

### party rootとreceipt統合

一戦しか使わないparty root情報をbattleへ寄せるなら、member IDは保持し、参加/ledgerの関係をbattle participantへ移す比較になる。現在のparty FKの有無は旧統計のsolo/PT判別にも使われる（`UndergroundLifetimeStatistics.php:129`, `UndergroundReceiptRollupService.php:315`）。null化/削除で「本人damageが不明な旧PT」をsoloのdamageへ読み替えてはならない。

既存UNIQUE(party_id)により、参照済みpartyを複数battleから一つへまとめるcardinality問題はない。一方、逆方向の必須参照はないため、移行前に未参照partyとそのmember/participation関係を確認する必要がある。未参照というだけで不要と決めて削除したり、架空のbattleを作って埋めたりしない。実データは今回確認していない。memberのUNIQUE(party,combatant_id)/UNIQUE(party,secretary_id)、participationからbattle/memberへのRESTRICT FKとledgerのmember参照は、root統合でも別途維持する。

battle/skip/bulk/intro/marketの共通receipt headerは、request/fingerprint/operation/result参照の重複を減らし、admissionの最大5表exists（`UndergroundRequestAdmission.php:122-128`）を一表にできる可能性がある。しかし小改修なら一つのSQLの複数EXISTS/UNION等で往復だけを減らす比較も可能。schema統合しないと高速化できないわけではない。

全面統合する場合は、成功済みresultのcanonical identityを一つにし、typedなbattle/skip payload/統計のgrainは保つ。すべての状態を一つのnullable/JSON列群へ入れて、terminalだけのbattleにpendingまで混ぜる設計は避ける。旧streamごとに同じ数値IDがあり得るため、original `(stream,id)` 又は対応表を保ち、source/grant/ledger/rollup境界を機械的に新global IDへ読み替えない。全UUIDの永久registryを新設せず、24h受付・既結果再読・30日raw/verified purgeと現行pinを維持する。

### cacheの行粒度

`BorrowedSecretarySnapshotFactory::persistCache():404-436` はsource/最大32projection mapを一つのupsertへまとめる。sourceが熱く更新されmapが大きい場合、source1行・projection1key1行・slot cache1key1行に分ける余地はある。ただしcold miss時の数INSERT、複数query、削除/容量制御が増える。currentはlender profile lockでsource採取し、同Secretary upsertを使って冷cache競合を処理する。cacheをgameplay正本や別build catalogにしない。失効可能なprojectionなので、移行はcopyか空再構築のいずれも選べるが、再構築による一時CPU増も見積もる。

## D6〜D7: 抜本案の比較と止めどころ

worker化はCPUを別processへ移すだけではゲーム処理の高速化を証明しない。requestをpendingとして受付けた後の取消/timeout/再送、同profileのFIFO、取得時点のHP/装備、24h期限、日次/boost、実行中release、crash後の再開、画像lease掃除などを一つの実行状態機械として扱う必要がある。pending実行をcanonicalなcommand recordにするなら、resultはその終端のimmutable factとし、別のjob ledgerから資産を二重決算させない。最終決算は引き続き単一DB transactionと一意keyにする。

profile内のpendingフラグで次の操作を拒否する案は、今の「lock待ち後に実行」と利用者の挙動を変える。同期HTTPを保ってworker完了を待つ構成もあり得るが、queue待ち・timeout・運用を増やす。これらは実装前にOwnerが選ぶBehavior/API境界であり、この調査から採用済みとはしない。

全event sourcingへ移すには、既存のcompact receiptだけでは過去の全入力・全状態変更を再構成できない。現在の資産/進行を信頼できる初期snapshotとして移す案と、過去全event再生の主張は別。既存snapshotから新たなgrant/装備抽選を起こして移行する案は不可。現canonical stateを保存したまま将来eventのみ採用する設計なら比較可能だが、現在の性能候補に対して変更範囲が最大で、速度/整合性の利益を裏付ける測定はない。

Ruleset上は、D2/D6で処理順・受付時点・transaction・retryの意味を変える部分はAGENTS §5のBehaviorに該当し得る。「確率表を変えないからversion検討不要」とは扱わない。逆にD0や保存形式だけで全実行意味が不変な変更に、自動で新Rulesetを要求しない。実装時にはOwner確認済みproduction baseline Nとrelease境界を確認し、必要なら同じN+1 draftへまとめる。今回の調査からsnapshot変更/世代更新を実行していない。公式Turnのlifecycleを別jobへ出すT-02 gateと、ここで比較した地底戦闘のworker化も区別する。

## 既存データ移行・rollbackの比較

いずれも将来の移行計画であり、今回DDL/DB applyはしていない。production適用baselineはOwner確認と運用証拠から確定する。既存migrationの変更/統合、schema dumpだけによる本番状態推定は行わない。

| 案 | 安全な移行の最小要件 | rollback/運用の限界 |
|---|---|---|
| D0 | 通常のcode差分。保存形式とidentityを維持 | 新コードを戻すだけで足りる範囲が多い。failed実行を別版で黙って再開しない |
| D1 | old result/gearを再書込/再抽選しない。新規決算のwrite構成だけ変更。unique/FK/guardを保持 | old readerが新保存形式を読めるか事前検証。child fact新設なら互換reader期間が必要 |
| D2 | revision追加はforward migration。全writerがrevisionを進める版へ先に揃え、その後CASを有効化。旧writerとの混在を許さない | 初期は従来lock方式へ戻せる形を保つ。pendingが無い同期memory案は戻しやすい。pending永続化した後はdrain/停止/明示復旧が必要 |
| D3 | 1:1 identityを維持して既存値をcopy/照合し、片方だけを正本へ切替。移行中のderived copyは同transactionで追従し、独立writerを作らない | 新側だけ更新後に古いcopyへ戻せない。rollbackは現在値の逆copy/互換viewなどが必要。古いbackupへ資産を巻き戻さない |
| D4 | party/member/receipt original ID・streamを保持。FKとgrant/source来歴を照合。旧PT incomplete、初回事実、rollup markerを保存 | IDや保存grainが変わるとdownだけで戻せない。旧新両readerによる同値照合と停止を伴う切替が現実的。rawを重複永久保管する案にしない |
| D5 | cacheをcanonical sourceから再構築可能にする。projection identityを変えて旧cacheを誤再利用しない | cache破棄→再構築で戻せるが、stampede/CPU増の運用が要る。gameplay資産をcacheから復元しない |
| D6/D7 | current資産を起点に一回だけ採用し、未解決実行は版を固定して扱う。再送/二重grant/部分commit/再開手順を先に定義 | queue導入後の撤去は単純なcode rollbackでない。未完実行のdrainと一度だけ確定を証明してから切替 |

## 採否を決める測定と最小検証

この追加調査ではDB起動/追加probeを行っていない。将来の隔離検証で、次を**同じfixtureと固定SHA**で比較する。

1. profile lockの取得待ちと保持時間、catalog/fight/projection/SQL各区間、DB connectionのtransaction滞在。異なるprofileと同一profileの競合を分ける。CASが狙うのは特に同一profile待ちである。
2. table/index/TOASTサイズ、UPDATEごとのWAL/JSON bytes、equipment payloadとprojection mapサイズ、cache miss率、現行INSERT/UPDATE/SELECT数。D1/D3/D5の効果を列数/テーブル数だけで判断しない。
3. D2ではrevision衝突率・捨てたCPU・p95応答とlock保持。equip/研磨/売却/market受取/respec/rental/試練/skipがcompute中に入り、古いplanが確定しないことを代表で検証する。
4. 二重request、最終commit直前/直後のcrash、期限/日次境界、release違い、画像差替え、vault空きの競合を重点化する。全組合せの無意味な反復ではなく、独立した故障境界の代表を使う。
5. receipt採番後のprofile lock解放順を検証し、aggregate/purgeが未確定低IDを飛び越さないことを確認する。prefix照合、初回事実/装備provenance/累計券/統計のknown/unknownを同時に検証する。
6. 移行では既存instance identity/owner/quality/polish/slot/protection/listing/grant/source、profile残高/HP/XP/配分、rental HP、trial/初回事実、ledger残高、rollup markersが保存されることを照合する。現在データをseedから再抽選しない。

現段階の推奨着手順は **D0 → D1の局所write案を測定 → 必要ならD2を設計/prototype → 実測が示す箇所だけD3/D5**。D4は保存体系を整理する別の便益がある場合に比較し、D6/D7は運用・APIの方向が採用された時点で再評価する。全案を一括採用する提案ではない。
