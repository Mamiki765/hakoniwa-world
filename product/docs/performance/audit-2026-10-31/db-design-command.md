# 開発計画DBの再設計比較（提案のみ）

対象は `Mamiki765/hakoniwa-world` **`2e93ec918774ad64d787aba99fc59a7bc3e54d16`**（5.8.3）。既存の[コマンド性能調査](command.md)へ、本人が許可した大胆なDB再設計の比較を追加した。**採用・実装の承認を意味しない。** この追加調査では静的読取だけを行い、製品source・設定・schema・実DBを変更せず、DB/コンテナの起動、負荷試験、本番・OCI・secret操作をしていない。先行調査の合成fixture測定は参照したが再実行していない。

判断の要点は、**保存形式の変更、SQL往復削減、World内の競合分離を別の軸で比較すること**。現行の主要なGET増幅はqueueの行モデル自体ではなく、行ごとのtarget cell/terrain/facility再取得にある。JSONBへ丸ごと移せば解決するとは言えない。一方、異Nationのqueue編集まで同じWorld行で排他にする現在の境界は、reader/writer fenceへ分ける研究価値がある。

## 1. 固定コードで確認した現行契約

以下のソース参照はすべて上記SHA。SQL schema dumpだけでcurrent triggerや本番適用状態を判断せず、後続migrationも照合した。

| 項目 | 現行の保存・実行契約 | 根拠 |
|---|---|---|
| Queue identity | Nationごとqueue1行、map space FK、bigint version。itemは独立したbigint IDを持ち、API・audit subjectが参照する | [schema:1737](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/schema/pgsql-schema.sql#L1737)、同5013、[Controller:676](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Http/Controllers/Api/CommandQueueController.php#L676) |
| 順序 | `(queue_id, queue_position)` UNIQUEはNOT DEFERRABLE。positionはnullable。status=queued/position順で取得する複合indexあり。APIの番号付き枠と、穴を残すplacementsを持つ | schema:4989,6108、[Service:559](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CommandQueueService.php#L559) |
| 範囲 | 現行queue上限30はRuleset由来。quantityは1〜99のDB CHECK。positionはschema上nullableで、1〜30 CHECKがあるわけではない。旧staged positionの修復経路も残る | schema:1680–1710、`DevelopmentPlanQuantity:15`、Service:309–324、`HotfixCommandQueueCorruptionTest:38` |
| Item型/FK | surfaceはdefinition ID+x/y、地下は限定command key+layer/slotのCHECK。definition/requestRuleset/membershipへFK、queue削除はitemとbulk ledgerへcascade | schema:1710,7034–7083 |
| Request再送 | `(queue_id, request_key)` UNIQUE。request fingerprintはnullableの64桁hex。元requestのRuleset/schema/selector/位置等で比較し、同UUIDの意味変更を拒否する | schema:1709,4997、[Service:136–228](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CommandQueueService.php#L136)、同511–557 |
| 過去版と現行版 | request Rulesetは再送同一性の根拠。実際のゲーム実行・projectionはcurrent World Rulesetの同じcommand keyを解決する。保存definitionを常にcurrent IDへ書換える設計ではない | [Resolver:22](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/QueuedCommandDefinitionResolver.php#L22)、[10/17 trigger更新:13](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/migrations/2026_10_17_010000_resolve_queued_commands_in_current_world_ruleset.php#L13) |
| Bulk確認 | actor/Nation/map/action/position/version/request UUID/期限1hと、全候補・active item attributesのhashをCrypt tokenへ結ぶ。apply時に同じ経路で候補を再計算し照合。両policyの取消集合、候補総数、2計画組を保持 | [Service:807–944](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CommandQueueService.php#L807)、[API契約:46](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/docs/architecture/bulk-plans-and-vault-api.md#L46) |
| Bulk再送 | 完了ledger `(queue_id,request_key)` UNIQUE。token+policy fingerprintを保持し、期限後も完了再読取、方針変更はconflict | schema:4973、Service:825–847,1117–1138 |
| Queue version | mutationはlocked current versionとexpected_versionを比較し、成功単位でincrement。Turnは同Nationの複数失敗/0T処理を終えた後、queueMutatedなら1回increment | Service:2395、[Executor:294](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/DomesticCommandExecutor.php#L294) |
| Turn消費 | Nation→queue→先頭itemを排他。失敗ならfailed履歴へ残して次、0Tなら成功後も次、通常はTurn消費まで進む。数量反復は同じIDを先頭に残しquantity decrement。削除相当はstatusとpositionを変え、行を残す | [Executor:120–250](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/DomesticCommandExecutor.php#L120)、同2250–2299 |
| 放棄 | World→Nation→membership等の境界でqueue/itemを排他後、queueを物理削除する現在の明示cleanupがある。永久不変のhistory保持とは混同しない | [AbandonmentService:52](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/NationAbandonmentService.php#L52)、[Operation:143](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/NationAbandonmentOperation.php#L143) |

Queue番号の穴を詰めて相対rankへ変えることは表示/API/previewの変更になる。一方、番号の穴ごとに自動資金繰りを数Turn挟む仕様を新しく推測してはいけない。現行Executorはposition最小のqueued itemを選ぶ。保存の再設計でもこの実行経路を変えない。

## 2. 既存測定と、保存形式ごとの期待値

記号はQ=active行数（現行上限30）、H=当該queueに蓄積した終了履歴、S=map全浅瀬、R=距離検証へ進む浅瀬、K=同Worldで同時に操作する異Nation数。

先行調査の同一首都をtargetとするfinance Q1/Q30では、各1GETで全SQL **17/104**、target MapCell/terrain/facility各 **1/30**を観測した（[生記録](evidence/command-serialization-queries.json)）。このうちactive item+definitionの初期読取は固定個数で、targetの3Qが増分の中心。queue documentだけに移してもtarget factsをcanonical mapから読む限り3Qは残る。まずtargetの集合取得で3Q→最大3 SELECTとする案が比較基準となる。

書込では現行compactorは1 SELECT+位置null化1 UPDATE+Q UPDATE。SQL往復数はQ+2、更新されるtuple数は概ね2Q。二段の集合UPDATEならSQL往復数は約3（select含む）だがtuple数は概ね2Qのまま。deferrable UNIQUEを使って直接最終位置へ集合更新できればtuple数も概ねQへ減る可能性がある。これらはSQL/計算量の静的モデルで、WAL/latency/IOPS改善の実測ではない。

| 案 | 期待する主な削減 | 残る費用・競合 | 制約/変更範囲 | 比較上の扱い |
|---|---|---|---|---|
| D0 行モデルのまま集合化 | target3Q SELECT→最大3、位置復帰Q UPDATE→1 | tuple更新O(Q)、World排他、S/R候補探索 | 既存ID/FK/receipt保持。狭いcode変更 | 最初の比較基準 |
| D1 deferrable位置UNIQUE＋集合更新 | null段階を省ければ位置write約2Q→Q | 同queue競合・World排他は残る | forward migration、constraint timingと一括SQL変更 | D0でwrite/WALが残れば比較 |
| D2 sparse order key | 挿入/隣接移動のrank更新を局所化 | API番号付き穴を別表現すると別途O(Q) mapping、消費時compaction | 新rank型/比較規則/再均衡が必要 | 今の枠契約では単独採用の利点弱い |
| D3 active ordinal別table | 幅広いitem行のposition/index更新を小さい行へ移す | O(Q) ordinal更新、join、World排他 | item statusとの跨table整合性とFK追加 | 履歴肥大やWALが根拠となる場合 |
| D4 queue全体JSONB document | active queue読取/順序保存を少ないSQLへ | document O(Q)、履歴内包ならO(Q+H)、whole-row競合、map3Q | 既存FK/CHECK/ID/replay/履歴の広い移植 | 大改造比較候補。現測定だけで優位とは言えない |
| D5 item/receipt行＋active順序document | 順序保存1 document UPDATE、履歴/identityは行で維持 | rowとdocumentの整合、Q document書換、map3Q | orderの唯一のauthorityを選ぶ必要 | 純documentより意味を保持しやすいがD0より広い |
| D6 read model・preflight snapshot | 同じ投影の再計算・lock内CPUを減らす | dependency invalidation、再検証/fallback、追加保存 | canonical sourceを置換しない派生物 | 保存形式と独立に比較 |
| D7 World shared fence＋Nation/queue排他 | 異Nation queue編集のWorld row待ちを分離 | Turnとは引続き排他、same user/queue/cell競合 | 全経路のlock upgrade確認、並行保証 | 条件付きで有望。global helper置換不可 |
| D8 Turn中も別inboxへ確定受付 | Turn待ちを表面上なくす | acceptance cutoff/再検証/失敗応答/二重履歴を新設 | 現行同期受付とTurnの観測境界を変える | 設計・仕様変更の候補。意味不変案として採用しない |

## 3. 保存形式を変える場合の反証

### D0: 行モデルを残し、集合で読む/書く

既に測ったtarget SELECT増幅へ直接効く。ID・request UUID・status履歴・provenance・既存のrow CHECK/FKを維持できる。位置更新は現在のqueue lock下で最終mappingを作り、全対象をnullへ→`UPDATE FROM VALUES`等で最終位置へ戻す。UNIQUEが非deferrableなので、単に一文CASEへ置き換えて途中重複を無視する案は成立しない。

Eloquent builderの一括updateが現行も各行で使われる箇所と、model updateによりtimestamp/イベントが関係する箇所を区別する。取消・実行のauditや時刻、失敗metadata、request列を位置更新へ巻き込まない。DB storageの世代を変えないため、異常時に旧binaryへ戻す難しさは最小。ただし新規deploy/実行は今回未承認。

### D1: UNIQUEの検査時点を変更する

`(queue_id,position)`をDEFERRABLEへ変更し、transaction内だけ対象constraintを遅延させるなら、最終状態の一意性を保持してswap/compactionを一度の集合UPDATEで表現できる余地がある。現行constraintは非deferrableなので `SET CONSTRAINTS` だけでは変更できない。PG18の[制約検査時点](https://www.postgresql.org/docs/18/sql-set-constraints.html)を確認した。

「先にstatus/completedへ更新して後でpositionを移す」など現行の中間順序がDB triggerやeventで観測されることを点検し、対象外constraintまで遅延させない。migrationのDDL lock時間、fresh/upgrade、rollback時の重複検査が必要。old binaryのnull二段updateも新constraintで動く可能性は高いが、混在運転を許可した証拠にはならない。最大30行の改善のためにconstraint変更を負う価値があるか、D0後のWAL/CPUを見て判断する。

### D2: sparse rankだけでは現在の位置契約を表せない

例: placementsでitem Aを1番、Bを30番に置ける。rankがA<Bしか保存しない場合、元の1/30を復元できない。APIの番号、挿入位置、bulk suffix切捨て、取消from、beforePositionの投影が変わる。rankを足す一方でvisible slotも保持すれば両者のauthorityが競合し、位置集合更新を消せない。

rankを検討するなら、番号付きslotを正本に残しrankは派生とする、またはcurrent contractを変える判断が必要。後者は本依頼の意味保持条件から外れる。float midpointは精度/比較/再均衡を新しい故障源にするため採らず、仮に整数・可変長rankでもschema、index collation、全writerの並びを規定する。Q上限30で現行単純性を上回る証拠はない。

### D3: active slotsを狭い別tableへ

例として `queue_slots(queue_id, position, item_id)` を唯一の現在順序とし、itemはrequest/execution履歴を持つ。slotに `(queue_id,position)` UNIQUE と item一意性を置き、itemのqueue帰属もcomposite FK等で守る。単にitem_idのFKだけでは「別Nationのitemをslotへ参照する」破綻を防げない。

active itemのstatusとslot存在は跨tableの契約になる。片方の更新だけがcommitすると、実行済item再実行/計画消失になるので、同一transaction・同queue lock・deferred constraint trigger等で検証する必要がある。数量反復は同ID/slotを残す。終了時は履歴rowを保存してslotを削除、放棄時は既存と同じcleanup scopeへつなぐ。現在の幅広いitem行の位置/複合indexを触らずに済むが、ordinal行のO(Q)更新は残り、追加join/制約も増える。active数が小さいため、既存複合indexのEXPLAINとWAL量なしに優位と言わない。

### D4/D5: JSONBは小さなactive queueとhistoryを分けて比較

full documentへID、request、status、失敗履歴まで入れる案では、終了履歴Hが際限なく増え、1件数量変更でも大きな行を更新する。PG18はJSON documentの更新も行全体のrow lockになると説明する。[JSON document設計](https://www.postgresql.org/docs/18/datatype-json.html#JSON-DOC-DESIGN)参照。配列の順序を扱う一方、object key順・重複keyの保持に依存しない。

JSONの各要素へ現行のdefinition/membership/requestRuleset FKやsurface/地下のCHECKが自動で移るわけではない。DB trigger/制約関数と全入口検証を再設計し、request UUID一意性やitem IDの診断・audit参照を残す必要がある。arrayを順番に検索して再送を判定すればO(Q+H)になり、今のUNIQUE lookupを悪化させる。

比較するならD5が狭い。既存item/receipt IDとimmutable request identityを行に残し、active順序だけを `[{position,item_id}]` のような小documentへ持つ。documentかordinal tableか**正本は一つ**にし、もう片方を派生物とする。queue rowを `WHERE id=? AND version=?` でCASし、request receipt INSERT・document変更・item状態・daily quest/Pd付与を同じtransactionへ入れる。JSON形式にしても「2タブが同じversionを保存」する競合が消えるわけではない。

request fingerprintを現在のquantityやcurrent Rulesetから作り直してはいけない。quantityは後から減る/編集され、過去版requestとの再送等価性を失う。nullableな旧fingerprintは旧コードと同じ拒否/判定を維持し、移行で推測補完しない。現在と同じitem IDを発行・保持し、API `item_id`、cancel path、bulk取消集合、audit subject、失敗診断を継続する。

## 4. D6: read modelと危険効果の事前snapshot

### 成立する狭い案

1. 不変Ruleset由来の定義部分を、Ruleset ID/hash＋projection code版でcacheする。dynamic価格/残高/target・可視性を混ぜない。
2. 一request内のtarget/ship/Nation factsを一括取得して再利用する（CQ-01/CQ-03）。永続read modelよりinvalidation範囲が狭い。
3. 危険な上書きのbefore/after計算を、同じ取得済facts・**別々の投影memo**で行う。現行は同queue objectへitemsを差し替えるため同じmemoの共有は誤り。
4. より大きい案では、lock前にsnapshotから候補を計算し、commit transaction内でWorld fence・owner/membership・queue versionと**全依存version**を再検証する。合わなければ現在のcanonical経路で再計算し、古い危険効果/取消集合を確定しない。

### 破綻するcache key

`(queue_version, world.current_turn)` だけでは不十分。同Turn中でも別Nationの登録/放棄/状態変更、船情報、秘書/地底profileの階層解放、日課/Pd等が変わり得る。projectionの各項目が本当に依存するmap cell/chunk、Nation state、ship、entitlement、Ruleset等を列挙するか、全該当writerが確実に更新するgame-state revisionが必要。単一global revisionを各書込で更新すれば、別のhot rowを作る可能性も比較する。

UI read modelは、毎requestの認可とviewer/owner秘匿判定を迂回しない。async更新ならoutboxをcommit後に反映し、旧版の遅着で新しいprojectionを上書きしない。cache miss/失効時はsourceへfallbackし、canonical mutationをcacheの生存に依存させない。DB内read modelは元stateと同じtransactionでrollbackし、外部cacheへ未commit結果を先行公開しない。Turnの再試行でattemptの新stateを作る現在の診断契約も維持する。

### Preview tokenの移行は別の互換境界

現行hashはactive itemの `getAttributes()` から作る。新しいcolumn追加やJSON document移行で同じ意味の状態のhashが変わると、有効期限内の旧tokenが突然409になる。format1を読む間は旧snapshotのcanonical field表現を再現する、または最大1hの既存tokenとinflightを正規にdrainした切替を設計する。いずれも採用時の具体的手順が必要で、storage変更を理由に確認済み取消集合を読み替えたり、新snapshotへ勝手に署名し直したりしない。

## 5. D7: Worldをreader/writer fenceにする条件付き案

### 現在の競合

queue mutationは [CommandQueueService:1905](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CommandQueueService.php#L1905) でWorld行 `FOR UPDATE` を取得し、Nation→owner membership→queue/itemへ進む。queue側は `WorldMutationLock` のadvisoryを取得していない。Turnは[advisory取得:47](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/TurnRunner.php#L47)＋[attempt冒頭World FOR UPDATE:332](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/TurnRunner.php#L332)を持ち、全phaseを同transactionで処理する。

したがって現在はNation Aの短い数量変更も、Nation Bの重いbulk previewも、同World行で直列化される。K別Nationのqueue編集を同時に受け付ける負荷ではこの待ちが支配し得る。ただし実運用のK、lock待ち時間・P95は未測定。

### 候補の境界

queue編集だけWorldをtransaction終了まで **`FOR SHARE`**、その後のNation/membership/queue排他は維持する。Turn・放棄・地図/Ruleset変更側はWorld `FOR UPDATE` を維持する。World share同士は共存し、World updateとは競合するため、**同Worldの別queue編集を並行化しつつ、公式Turnとの境界を保つ**候補になる。[PG18 row lock表](https://www.postgresql.org/docs/18/explicit-locking.html#LOCKING-ROWS)で確認した。`FOR KEY SHARE`はnon-key updateと共存する別の強さなので代用しない。

同Nation/同queueのversionは依然排他で1本化する。World lock待ちの後には現在と同じfresh Nation stateとmembershipの再認可が必要。既存 [PostgresCommandQueueAbandonmentLockTest:57](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/tests/Feature/PostgresCommandQueueAbandonmentLockTest.php#L57) は放棄待ち後の403/無書込を守る代表で、shareへ変えてもこの性質を残す。

### 「queueだけ」の中にもあるside effect

| 経路 | 今回確認した実コードの書込/lock | shared fence候補に含める条件 |
|---|---|---|
| reorder/reposition、quantity edit、cancel/cancelFrom | queue/item/status/version/audit。現在surface危険effect再検証・地下slot sequence検証もある | 最初の限定対象。World読みの後でWorldを書き直す経路がないこと、own queueのlockとfresh再認可を保持 |
| add | queue/item/auditのほか、成功後 `DailyQuestService` による日課進捗/活動INSERT、達成時Pd残高/ledger credit。工作船/自沈のown Ship排他もある | 日課/Pdの既存冪等性とlockを維持。plain queue editと同じ小境界とは扱わず後段で検証 |
| bulk apply | generated item、取消、request ledger、日課/Pd付与。浅瀬等をy/x順に `FOR UPDATE` で取得 | 候補cell lockのscopeを別に検討。同じ浅瀬を別Nationがlockするので、World share化だけで並行化できるとは限らない |
| bulk preview | ゲーム効果/日課付与はしないが、queue `firstOrCreate` とWorld/Nation/queue、候補MapCellの排他を取る | read-only APIのつもりでlockを落とさない。snapshot整合、初回queue作成競合、map全浅瀬lockを扱う |
| 地下施設受付 | entitlementのSecretary→UndergroundProfile/trialProgressの**読取**、Nation所有slot読取と予約検証。施設create/delete本体はTurn executor | RPG profile排他へqueueを統合しない。解放状態のfreshnessは保持し、施設即時建設へ変更しない |

addのquantity0は [DevelopmentPlanQuantity:15–28](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Domain/Command/DevelopmentPlanQuantity.php#L15) が拒否する。`consumes_turn=false` やPd費用を持つcommandもstoreで即実行していない。資産のdeduct/applyは [Executor:205–215](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/DomesticCommandExecutor.php#L205)・900以降。例外は受付に伴う**日課達成Pdのcredit**で、[Service:505](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CommandQueueService.php#L505)→[DailyQuest:105–160](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/DailyQuestService.php#L105)→[Paradox:103–151](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/ParadoxBalanceService.php#L103)を辿った。

### 反証したい競合と、まだ採用できない理由

- **World lock upgrade**: 2 transactionがWorld SHAREを保持してから両方UPDATEへ昇格するとcycle/deadlockになり得る。対象queue経路と今回追ったdependencyではWorld update/touchを見つけなかったが、一般のWorld mutation helperを共有lockへ置換する根拠にはならない。将来呼出の追加も含め、read fenceからwriteを行わないAPI境界が必要。
- **逆順**: profile/Pd/日課/Shipを先に排他にした別経路が後からWorld UPDATEへ来ると、queueのWorld SHARE→そのrowとの循環が成立し得る。日課/Paradox内部は今回Worldを再取得しないことを確認したが、全writerの逆向き到達性の証明は未完。具体的なlock順台帳と既存surface/RPG並行代表での確認が採用条件。
- **同じtargetを選ぶ別Nation**: normal addはmapの効果を変えず予約を別queueへ保存する。実行は後のTurnでfreshに再検証するので、予約だけでtargetを占有しない現在の契約を維持できる。対してbulkの全浅瀬FOR UPDATEは同じcellへ集まり、異Nationでも待つ。ここを先に細分化しないと想定K並行の効果が出ない。
- **同Userの別操作**: 受付に伴う日課/PdはUser所有のrowで直列化される。Worldの共有化を理由にこれらのlockやentry_key一意性を外すと二重付与する。地底RPGのprofileロックをNation queueのロックへ置換する案も所有境界に反する。
- **Turnを追い越す受付**: Share fenceはTurn待ちを残す案。Turn中でもqueue保存をcommitさせるには別の受付世代/cutoff設計が必要で、それはD8として分離する。

期待効果は異Nationのqueue編集間の待ち短縮であり、1requestのSQL数削減ではない。same queue、同User日課、同cell、Turn/放棄の待ちは残る。新しい長いSHARE transactionを増やせばTurn待ちを長くする可能性もあり、lock保持時間とtransaction全体のP95を測る必要がある。**提案条件付き。lockを取り替える承認・安全証明として使わない。**

## 6. D8: inbox/世代分離でTurnと同時受付する案

「受理した次世代queue」を別tableへ積み、Turn開始時の世代だけ実行すれば、Turn中の受付を保存できる。しかし、現行ではWorld row待ち後の新stateからowner・current Ruleset・queue version・危険効果・token snapshotを確認して応答する。古いmapから暫定受付を成功扱いして後で失敗へ変えるとAPI契約が変わる。Turnがqueueを既に消費したのに古いexpected_versionを成功させるとlost updateになる。

意味を保つなら「保存だけprovisional、最終の成功/失敗応答はTurn終了後に同じcanonical再検証」という形が必要だが、利用者の応答待ちは消えず、receipt/outbox/再実行の複雑性だけ増え得る。World全体のMVCC世代と全dependency revision、Turnのqueue cutoff、retry・rollback・診断snapshotまで設計する案は比較対象として残すが、今回の局所高速化案へ混ぜない。自動retryやlifecycleの分離、chunkごとcommitをついでに採用しない。

## 7. 移行・dual write・撤回の比較

| 案 | 既存dataの扱い | Dual writeで起こる故障 | 撤回/rollbackの条件 |
|---|---|---|---|
| D0 | schema/ID/provenance保持。通常はdata変換なし | 二つのstorage authorityを増やさない | transaction中の最終mappingが既存形式なら旧runtimeへ戻す難度が低い |
| D1 | 位置UNIQUEをforward migrationで変更。既存重複/旧staged位置の扱いを確認 | schema世代とruntimeの検査時点が不一致 | nondeferrableへ戻す前に最終一意性を検査。旧binaryの動作検証とDDL lock時間を確認 |
| D3 | active slotsのみ既存ID/positionでbackfill。終了履歴rowは移動不要 | statusのみcompleted/slot残存、slotのみ消失、別queue item参照 | 同queue/versionで逆変換できる間だけ旧positionへ戻す。新旧slot順が分岐したら旧列を信用しない |
| D4 | historyを含む全row変換ならHが大きい。旧fingerprint/null・時間・IDをそのまま保持 | rowとJSONに異なるquantity/order/status、receipt片側欠落 | 新規書込を止めて正本documentから行を完全再構築できることが必要。単なる古いbackup復元は新しい資産/Turnを消すので不可 |
| D5 | item/receipt行を保持しactive順序だけ変換 | ordinal二正本、CAS versionの片側increment、消費時片側更新 | authorityを明示し、同じversionの逆projectionが一致すること。旧列が派生なら常に同期保証が必要 |
| D6 | 既存dataを変えず派生read modelを作る | DB rollback済みstateを外部cacheへ公開、遅着workerが新結果を上書き | source fallbackがあればcacheを停止/再構築できる。canonical mutationは継続可能にする |
| D7 | storage変更不要 | old/new runtimeのlock順が混在し、upgradeや逆順が顕在化 | 対象queue入口だけWorld FOR UPDATEへ戻せる。ただし実行中transactionを安全にdrainし、行ロックを途中変更しない |

初期採用で高価な常時dual writeを自動的に選ばない。まず承認済みmaintenance境界でmutation/Turnをdrainし、対象queue単位の版・全ID・位置・quantity・status・request provenance/receiptを検査して短く切り替える案と、online移行の追加実装/運用費を比較する。旧writerが新storageを知らず書ける混在を防ぐ。

online移行を選ぶ場合も、**両書込を同じDB transaction・同じqueue lock・同じversion更新に含める**。別jobで片側を追いつかせた状態をmutationの正本にはしない。read-shadow比較は非正本として行い、差があればcutoverを止める。未対応状態を黙って前詰め/切捨て/指紋再計算で直さない。schema追加は既存migrationを編集せずforward migrationとし、fresh/supported upgrade・CurrentDatabaseBaselineの新構造認識を合わせる。本番baseline/適用済み状態はsourceだけから推定しない。

## 8. 採用判断に必要な限定測定

今回は下記を新実行していない。必要となった候補だけ、既存代表を最小限拡張した隔離fixtureで測る。

| 測定 | 比較する値 | 既存保証・反証するfailure |
|---|---|---|
| D0/D1: Q=1/30、先頭挿入/並替/取消/0T連続消費 | 対象SQL round trip、更新tuple/WAL、lock保持時間、P95、結果全列 | unique swap、穴ありplacements、head数量残存、失敗→次、versionをNationあたり1回、audit/時刻保持。`CommandQueueAndSalePolicyTest`、`HotfixCommandQueueCorruptionTest`、Turn代表 |
| D3/D5: active Qとhistory Hを別に増加 | active read plan、index hit/heap/TOAST/WAL、joinとdecode、移行件数/停止時間 | 同じID/再送、終了履歴の再実行なし、abandon cascade、current key解決とoriginal request契約 |
| D4: 同等document/行fixture | 1件数量変更のwrite bytes、同queue競合、request UUID lookup、最大document | 「1 SQLだから速い」の反証。H混入、JSON不正要素、FK相当制約欠落の検出 |
| D6: queue不変でmap/ship/Nation/entitlementだけ変化 | cache hit時の正しさ、invalidation/fallback回数、locked CPU | version不足、他人への情報露出、preview後に取消集合変更、失効token自動再署名禁止 |
| D7: 同queue2人 / 別Nation2人 / bulk＋normal / queue＋Turn/放棄 / 日課完了＋他Pd操作 | lock取得順・待ち、deadlock SQLSTATE、二重付与なし、成功/409/403、Turnの待ち | shared→exclusive upgradeの有無、放棄後owner再認可、異Nationが同targetを予約しても現行どおりTurn再検証。既存Postgres queue/abandonment代表とsurface/profile代表 |
| 移行/撤回 | 旧ID/hash/token再送、rollback後checksum、fresh/upgrade、inflight drain | 1h以内旧token、nullable旧fingerprint、cancelled/completed/failed行、quantity変更後replay、schema/binary混在拒否 |

## 比較としての推奨順

1. **D0**を基準に、測定済み3Q SELECTと一括候補S/Rを集合化する。保存形式の変更なしで効く分を先に分離する。
2. **D7の限定queue編集fence**を、全writer/lock upgradeの台帳が揃う条件で比較する。addの日課/Pd・own Shipとbulkのcandidate lockは後段へ分ける。
3. write/WALが依然支配的なら **D1**、履歴とactive index/行幅が根拠なら **D3/D5**を比較する。
4. 再計算が支配的なら **D6**。不完全なversion keyで永続cacheを先行導入しない。
5. **D4 full JSONB/D2 rank/D8 inbox**は同じ出力・履歴・受付境界を維持する設計費まで含めて評価する。現時点で採用優位は証明していない。

これらはarchitecture選択の材料であり、親が横断統合・Owner判断を保持する。純storage/SQL変更で意味を保つ場合と、受付時点/順序/実行経路が変わるBehavior候補を分けてRuleset authoringと照合する。必要なら確認済みproduction baseline Nに対する同じN+1の提案へ整理するが、現行immutable snapshotやRulesetは一切更新していない。旧コードとの乱数消費順一致は要求せず、同じコード版・初期state・seedの再現性と診断記録を守る。
