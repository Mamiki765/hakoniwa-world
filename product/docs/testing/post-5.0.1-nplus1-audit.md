# 5.1以降のDB N+1静的調査

更新追跡: releaseは`35c96749c078a20f855837544078372303ba6911`へ進み、PR262/267が取り込まれた。[追加台帳](post-5.0.1-nplus1-latest.md)で追記時点の課題状態と追加7変更経路、NQ-03の隔離PG実測(2→1、11assert成功)を確認できる。後続の採否・適用は[最終記録](post-5.0.1-nplus1.md)を参照する。以下は当初の固定SHAに対する再現可能な調査記録として保持する。

調査日: 2026-10-14。テスト固定期待値の棚卸しとは別の調査であり、テスト追加・修正もアプリ本体の編集も行っていない。SQL実行回数は呼出経路からの静的見積りで、DB実測・実行計画・所要時間の測定ではない。

## 範囲と証拠の固定

| 対象 | 固定SHA | 確認範囲 |
|---|---|---|
| 5.0.1基準 | `5affee61f180d6766ff7e59ce275c304c54002ad` | 親調査が特定した基準。既存処理との増減の比較に使用 |
| release/5.2.0 | `e5c02d7169de61d8bf6fd3ea4607aeaaf3f072af` | 基準以降に変更されたproduct/appの全23ファイル。commit履歴の変更集合も23件で、net差分集合と一致 |
| PR260 | `54f2dac5179f40e17a18c407609b0677fd838a09` | releaseとのmerge-base以降のproduct/app全8ファイル |
| PR262 | `6e64f55b196b09faae066b0db4ed0f47356671e4` | 同17ファイル |
| PR263 | `0489aa544f367e6ca9e91e63144b001f350dd133` | 同2ファイル |
| PR264 | `5448a7311e46f2592ebd4e18d45cffc2d3ccc54b` | product/app差分0件 |

重複を除く調査対象は35ファイル。対象の変更hunkと、そのhunkを呼ぶloop、関連のeager loading、守るWorld/Nation/Shipのscopeとlockを確認した。関連呼出元としてMapChunkService、SurfaceVisibilityService、QueuedCommandDefinitionResolver、MonsterBehaviorResolver、CommandDefinitionモデル等を必要部分だけ読んだ。PR261の目的地機能はreleaseの`32ba8ad`に取り込まれた経路として確認した。PR188、PR265、未公開作業、変更されていない他機能の全体性能監査はこの台帳の範囲に含めない。PRの後続push・統合時は該当行を再確認する必要がある。

`AGENTS.md`、`docs/README.md`、`product/docs/handoffs/current-status.md`、関連する`docs/open-questions.md`のgateを確認した。World Turn transaction/advisory lock、mutable balance、権限・可視性を変える最適化は採用しない。worktreeに他担当のテスト差分が増えていることを確認し、そのファイルには触れていない。

## 具体的な候補

### NQ-01: PR262の工作船付きqueue予測・Turnに同じRulesetの遅延ロード

分類: 新規の遅延ロードN+1。修正候補。未実装・未測定。

PR262の[UnderseaConstructionPolicy:37](https://github.com/Mamiki765/hakoniwa-world/blob/6e64f55b196b09faae066b0db4ed0f47356671e4/product/app/Domain/Command/UnderseaConstructionPolicy.php#L37)は、工作船指定があると`$command->rulesetVersion->settings`を読む。`queueFor()`は[items.definitionまでしかloadしない](https://github.com/Mamiki765/hakoniwa-world/blob/6e64f55b196b09faae066b0db4ed0f47356671e4/product/app/Application/CommandQueueService.php#L1152)。同じtargetの前方queueを走査する`projectCellStateBeforePosition()` → `projectedTargetMatches()` → `costMultiplier()`がこのpropertyへ到達し、未ロードのCommandDefinitionモデルごとにRuleset SELECTが発生する。

- queue予測: 該当アイテム数をq、工作船指定を持つdistinct CommandDefinitionモデル数をdとすると、新規Ruleset SELECTは+d。EloquentのbelongsTo eager loadingは同じdefinition IDに同じモデルを割り当てるため、単純に+qと断定しない。異なるdefinitionは同じruleset IDでも別モデルで、ロード結果は共有されない。
- Turn: [item取得はdefinitionとrequestRulesetVersionをload](https://github.com/Mamiki765/hakoniwa-world/blob/6e64f55b196b09faae066b0db4ed0f47356671e4/product/app/Application/DomesticCommandExecutor.php#L133)するが、definition.rulesetVersionは別relation。[validationFailure:395](https://github.com/Mamiki765/hakoniwa-world/blob/6e64f55b196b09faae066b0db4ed0f47356671e4/product/app/Application/DomesticCommandExecutor.php#L395)へ到達した工作船付きsアイテムでは、各アイテム取得で新モデルとなり、新規Ruleset SELECTは+s。同じアイテム内の費用計算ではpropertyが既にロードされている。
- catalog一覧の直接definitionsはcontrollerでWorldのrulesetを`setRelation()`している。この部分には新しいRuleset N+1はない。
- 旧基準・release HEADには工作船指定のこの読取自体がなく、新規経路である。

最小案: queue予測を行う既存queueのdefinitionだけをnested eager loadする、または既に読んだ正しいRuleset snapshotをdefinitionのruleset ID照合後に供給する。Turnではcurrent-ruleset照合済みのcontext snapshotを使うか、既存requestRulesetVersionのIDを照合して供給する。歴史的definitionに無条件でcurrent World snapshotを割り当てる案は不可。global cache、別リクエスト・Turnを跨ぐcache、全World全definitionsの先読みは不要。

権限・秘匿: Rulesetは指定commandのprovenanceを維持する。Ship owner/world/active/location/HPの検証と、mutation側のlockを省略しない。これはDB往復の候補であり、Ruleset世代・仕様変更の提案ではない。

### NQ-02: PR262で同じ工作船を一覧・予測・費用検証ごとに再SELECT

分類: 新規の件数比例重複READ。修正候補。未実装・未測定。

[controllerのdefinitions map](https://github.com/Mamiki765/hakoniwa-world/blob/6e64f55b196b09faae066b0db4ed0f47356671e4/product/app/Http/Controllers/Api/CommandQueueController.php#L198)は、同じconstruction_ship_idを各該当commandへ渡す。[validateTarget:1548](https://github.com/Mamiki765/hakoniwa-world/blob/6e64f55b196b09faae066b0db4ed0f47356671e4/product/app/Application/CommandQueueService.php#L1548)と[費用表示:295](https://github.com/Mamiki765/hakoniwa-world/blob/6e64f55b196b09faae066b0db4ed0f47356671e4/product/app/Http/Controllers/Api/CommandQueueController.php#L295)がそれぞれcostMultiplierを呼び、失敗時は[projectedTargetMatches:1252](https://github.com/Mamiki765/hakoniwa-world/blob/6e64f55b196b09faae066b0db4ed0f47356671e4/product/app/Application/CommandQueueService.php#L1252)でも再び呼ぶ。costMultiplierは毎回`Ship::activeForNation(...)->whereKey(...)->where(map_cell_id...)->where(current_hp>0)->first()`を実行する。

- 同じ船・targetに対するm個の該当定義で、妥当な入力・validation成功なら2m回のShip SELECT。validation失敗でprojection fallbackにも到達する場合は最大3m回。早期のterrain/metadata不適合ではShip SELECT前に止まるため、すべての失敗を3m回としない。
- 固定PRのv37 authored domainは海底基地・海底都市・海底消防署の3定義へ工作船parameterを付ける。該当3定義を通る通常の対象選択では6回、上記fallbackがすべて通ると9回となる。同一target前方queueの工作船指定qアイテムにはさらにq回のShip SELECTが加わる。
- Turn成功処理は[validationFailure:395](https://github.com/Mamiki765/hakoniwa-world/blob/6e64f55b196b09faae066b0db4ed0f47356671e4/product/app/Application/DomesticCommandExecutor.php#L395)と[executionCost:844](https://github.com/Mamiki765/hakoniwa-world/blob/6e64f55b196b09faae066b0db4ed0f47356671e4/product/app/Application/DomesticCommandExecutor.php#L844)で同じ船へ2回のlock SELECT。成功s件なら2s回。途中に船の移動・損傷・HP更新はない。失敗アイテムは1回で終了する。

最小案: controller内のvalidate・警告・費用表示へ一度得た検証結果/倍率を引き渡す。同じreadonly projection内に限定するなら、Nation/World/Ship ID・target・ruleset provenanceを含むmemoで船factを共有し、command個別のeligibilityは各定義で評価する。現在の一覧読取はWorld lock/transactionを持たず、同時mutationが挟まる観測順が変わる可能性はある。requestを超えるcacheには拡張せず、mutationでは必ずfreshなlock検証を保持する。Turnは一つのcommand内で検証済み倍率を費用計算へ渡し、次のcommandでは再検証する。単に全船を先読みする必要はない。

このPRは別担当が実装中なので、既存branchを編集せず担当へ差分案を渡す。統合時にはPR260の引数/memo変更との衝突も確認する。

### NQ-03: 目的地解除で停止した軍艦が同じNationを二度lock SELECT

分類: 5.1以降に追加された件数比例重複READ。小さい局所修正候補。未実装・未測定。

releaseの[SurfaceShipTurnService:184](https://github.com/Mamiki765/hakoniwa-world/blob/e5c02d7169de61d8bf6fd3ea4607aeaaf3f072af/product/app/Application/SurfaceShipTurnService.php#L184)は、移動候補がなく、目的地が`terrain_changed`/`blocked`であるときNationをlockしてfuelを検証する。同じ軍艦が[warship branch:202](https://github.com/Mamiki765/hakoniwa-world/blob/e5c02d7169de61d8bf6fd3ea4607aeaaf3f072af/product/app/Application/SurfaceShipTurnService.php#L202)へ進むと、同じactive Nationを再度lock SELECTする。二つの間はShip destination解除、Ship save、event記録であり、Nationのstate/moneyは変更していない。

- この条件を満たす軍艦b隻に2b Nation SELECT。旧基準は軍艦攻撃前の1bのみ。新しい+ bは同一processCell内の重複である。fuelのfirstOrCreate/lock読取は別にあり、ここでは削減対象に含めない。
- 最小案: 一つのprocessCell呼出内で、destination検証時に得た成功lock済みNationモデルを軍艦攻撃へ渡す。最初の照会を実施したかを区別し、null結果を再照会しない方針を選ぶ場合はその扱いも明示する。到着条件はfuel照会を行わないので、軍艦攻撃用の既存照会を残す。
- 別船へ同じNationモデルを持ち越すことは不可。先行船の攻撃・収益・crime等で資産やstateが変わる。World Turn transactionと攻撃の現行orderingは維持する。

### NQ-04: queue圧縮の行ごとのUPDATEは変更前から残るwrite amplification

分類: 既存の反復WRITE。read N+1とは分ける。最小bulk更新案は可能だが、この監査では保留。

新しい[CommandQueueCompactor:14](https://github.com/Mamiki765/hakoniwa-world/blob/e5c02d7169de61d8bf6fd3ea4607aeaaf3f072af/product/app/Application/CommandQueueCompactor.php#L14)は1回のlocked SELECT、1回の全対象position=NULL UPDATE、残るnアイテムへのn回のposition UPDATEで、空でなければn+2 SQLを実行する。baselineのCommandQueueService/DomesticCommandExecutorに同じ実装があり、共通化commit `24d8bac`で移動した。新規悪化とは扱わない。

Turnで複数の失敗・非消費commandを取り除くと、各除去後にcompactするため、SQL数は残数n_iごとのΣ(n_i+2)、多数除去では二次増加となり得る。最後の空queueはSELECTのみ。callbackやmodelイベントのための行ごとのupdateではなく、Eloquent query builder updateである。

最小案: unique position衝突を避けるNULL化は維持し、同じlocked n件だけをID→positionのCASE/VALUES UPDATE一回で書く。これなら空でない1圧縮あたり3 SQLとなる。status/queue scope、ID bind、updated_atの現在のEloquent挙動、unique/check制約、transaction/queue lock、最終order、version/audit責任のcaller側保持を確認する。NULL化を省く一括UPDATE、queue全体を跨ぐcache、圧縮timing変更はこの案に含めない。

### NQ-05: releaseの陸地近傍検証の重複はPR260で既に解消する

分類: 新規の追加READ、指定PRに局所改善が既にある。別修正を重ねない。

releaseの[UnderseaConstructionPolicy:19](https://github.com/Mamiki765/hakoniwa-world/blob/e5c02d7169de61d8bf6fd3ea4607aeaaf3f072af/product/app/Domain/Command/UnderseaConstructionPolicy.php#L19)は、同じNation/target/radius=3を、各海底建設commandで繰り返し`get()->with(terrain)`する。セルが存在すると1 cell SELECT+1 terrain SELECTで、r回評価なら最大2r SQL。baselineの同条件は`hasOwnedCellWithin()->exists()`の1 SQLで、後から陸地条件の保証が追加されたことに伴う増分。catalog一覧の3定義で条件を通る場合は6 SQL、validationとprojectionの両方が通る場合は最大12 SQL。terrainのモデル単位lazy N+1ではない。

PR260は[OwnedLandProximity:25](https://github.com/Mamiki765/hakoniwa-world/blob/54f2dac5179f40e17a18c407609b0677fd838a09/product/app/Domain/Map/OwnedLandProximity.php#L25)でland条件をexistsのSQLへ入れ、[hasCurrentOwnedLandWithin:2068](https://github.com/Mamiki765/hakoniwa-world/blob/54f2dac5179f40e17a18c407609b0677fd838a09/product/app/Application/CommandQueueService.php#L2068)がrequest-local memoへNation/space/target/radius/includeCenterで結果を記録する。controllerはdistanceReasonCode・validateTarget・projectionへ同じmemoを渡す。同一radius=3なら1 SQLで共有する。falseもmemo hitとなる。reclaimはradius=1/includeCenter=falseの別keyである。

複数targetを走査するbulk reclaimは各targetごとexistsで、target数に比例する。これは変更前からあるtargetごとの検証であり、全World土地の先読みへ広げない。PR260統合後の実測で必要性を判定できる。

### NQ-06: 探索船の宝箱候補再読取は既存・共有の意味が変わるため保留

分類: 変更hunkに隣接する既存の反復READ。新規悪化ではない。設計/実測待ち。

[explorationDestination:324](https://github.com/Mamiki765/hakoniwa-world/blob/e5c02d7169de61d8bf6fd3ea4607aeaaf3f072af/product/app/Application/SurfaceShipTurnService.php#L324)は自由探索の各探索船に対してactive BuriedTreasure全体とcellをeager読取する。候補が非空なら2 SQL × e呼出。可視座標計算のSQLはこの2eと別で、今回総量を実測していない。baselineにも同じ処理があり、目的地追加commitは最接近候補sortをhelperに移しただけ。

先行船の移動で視界が変わり、宝箱取得でactive状態も変わる。同じNationだからというcacheやTurn開始時全件snapshotへの固定は探索結果を変え得る。宝箱readに限定したsnapshotと取得時の除去同期・視界再計算の設計まで要するため、性能実測なしで採用しない。related pathとして記録し、他の探索/地下機能へ監査を拡大しない。

## 全変更経路の確認台帳

ここで「増分なし」は差分hunkが件数比例の追加SQLを導入しないという静的判断であり、ファイル全体の性能を保証する文言ではない。`—`は今回の差分にSQL追加がない。件数比例の必要なlock/writeを、すべて無駄なN+1とは判定しない。

### release/5.2.0: 基準以降の全23ファイル

| product/app以下の箇所 | 主なcommit | 変更経路・loop確認 | 分類/増分・判断 | 未確認 |
|---|---|---|---|---|
| Application/CommandQuantitySemantics.php | 92a3767 | repeated build key判定・quantity表示 | —。メモリ/metadata判定 | runtime測定なし |
| Application/CommandQueueCompactor.php | 24d8bac | add/cancel/Turn除去→compact→全残item | NQ-04。n行UPDATEは既存移動 | bulk案未実装、測定なし |
| Application/CommandQueueService.php | 700ccdb,24d8bac,b4ff3f0,7295261,161a87b | catalog validation・queue予測・bulk候補・圧縮 | NQ-05。旧legacy修復SELECT/UPDATEは撤去。items/definitionは既存eager | 後続PR統合で再確認 |
| Application/CompleteTurnEngine.php | 6e36274 | finalizeTurn→monsterCounts.record。processCells→船を追跡 | snapshot1回/Turn。loop内追加照会なし。船はNQ-03/06 | 大World時間未測定 |
| Application/CurrentDatabaseBaseline.php | f833601,6e36274,60ccf17 | structure metadata一括SQL→hash loop、migration marker取得 | marker SELECT +1固定。各table correlated subqueryは単一SQL内でN+1往復ではない | PG plan未測定 |
| Application/DomesticCommandExecutor.php | 700ccdb,92a3767,24d8bac,b4ff3f0 | Nation→消費/失敗queue loop→undersea検証・compact | NQ-04/05。旧legacy処理撤去、共有semantic helperはSQLなし | 後続PR統合で再確認 |
| Application/LegacyCommandQueueOrder.php | 700ccdb | 削除されたlegacy discard/project | 削除。現HEAD呼出なし | — |
| Application/PlayerIslandEventService.php | 36052da,32ba8ad | owner/public pageで名称読取→events map。private destination event表示 | ruleset propertyはpage準備で最大1 SELECT、event数比例なし。destination visibilityはconfidential維持 | 複数rulesetメモリ量未測定 |
| Application/PowerEconomyUpgrade.php | f833601 | 旧upgrade service撤去 | 削除。現HEAD呼出なし | — |
| Application/PublicWorldService.php | 6e36274 | summary/ランキングNation map→forWorld/forNation | 追加snapshot読取はarrayのみ0 SQL/Nation。既存公開aggregateは一括 | endpoint時間未測定 |
| Application/RulesetPublisher.php | f833601 | historical publisher撤去、残るsnapshot検証/create | 削除だけ。definition比較3一括SELECT、createの各行writeは既存永続化 | publish時間未測定 |
| Application/SurfaceShipCourseService.php | 32ba8ad | 単船destination更新→target可視性検証 | 単requestの固定照会。loop内追加なし。World/Nation/Ship lock維持 | request時間未測定 |
| Application/SurfaceShipTurnService.php | 32ba8ad | randomized cells→各船process→目的地/攻撃/探索 | NQ-03新規重複Nation、NQ-06既存宝箱再読取。地形/近接候補は事前loaded cell map | 時間・SQL実測なし |
| Application/TradingPostTurnService.php | e54e552,f48bf52 | listing settlement→resource overflow→sale policy | value(policy)がfirst(policy,keep_amount)へ。元と1 locked policy SELECT/溢れdelivery。sale plannerメモリ、write/eventは必要settlement | listing多数時の時間未測定 |
| Application/WorldMonsterCountSnapshot.php | 6e36274 | alive monsters loop→definition/occupancy/cell/terrain/facility | 関連eager、behavior resolver純粋。最大7 SELECT+1 World UPDATE/Turnでmonster数比例のSELECTなし | メモリ/PG plan未測定 |
| Console/Commands/CheckDatabaseBaseline.php | f833601 | baseline CLIの結果文言 | —。メッセージ変更のみ | — |
| Domain/Command/UnderseaConstructionPolicy.php | b4ff3f0 | validation/project→nearby land | NQ-05。1回最大2 SELECT。landの意味を守る | PR260統合後未測定 |
| Domain/Ruleset/CurrentRulesetAuthoringInspector.php | f833601 | domain fileループ→require/分類 | —。filesystem読取のみ | I/O時間未測定 |
| Http/Controllers/Api/CommandQueueController.php | 700ccdb | queue表示のlegacy依存除去。definitions map関連を追跡 | SQL増分なし。loopのnearby landはNQ-05 | 後続PR統合で再確認 |
| Http/Controllers/Api/SurfaceShipController.php | 32ba8ad | owner船一覧→船map、destination単船update | cell/ruleset eager、course純粋。追加property出力は0 SQL/船。owner membership維持 | endpoint時間未測定 |
| Models/Ship.php | 32ba8ad | course()とdestination casts | —。attributesのみ、relationアクセスなし | — |
| Models/World.php | 6e36274 | monster_count_snapshot casts | —。attributesのみ | — |
| Services/MapCellPresenter.php | 32ba8ad | MapChunk cells map→ship course | destination property追加はSQLなし。MapChunkServiceでship.nation/ship.rulesetVersion eager。非owner course秘匿 | 他の未変更caller全面監査なし |

### PR260: 全8ファイル

| product/app以下の箇所 | 変更経路・loop確認 | 分類/判断 | 未確認 |
|---|---|---|---|
| Application/CommandQueueService.php | currentLandDistanceReasonCode/validateTarget/projectで同じmemo使用 | NQ-05解消。radiusごとexists。bulk各target検証は維持 | 統合後実測なし |
| Application/DomesticCommandExecutor.php | reclaim/excavate/undersea→OwnedLandProximity | terrain eager追加の代わりにwhereLand exists。current土地保証維持 | 統合後実測なし |
| Domain/Command/CapitalDestructionPolicy.php | 首都破壊拒否 | —。純粋key判定 | — |
| Domain/Command/SurfaceCommandProjectionMemo.php | currentLandDistance key/result格納 | —。request内array、false hitも保持 | 統合後再確認 |
| Domain/Command/UnderseaConstructionPolicy.php | OwnedLandProximityへ委譲 | NQ-05。独自terrain全取得を撤去 | 統合後実測なし |
| Domain/Map/NationLandAreaCalculator.php | whereLand追加 | SQL builderのwhereHas。独立SELECTを発行しない | PG plan未測定 |
| Domain/Map/OwnedLandProximity.php | radius座標→所有者/space/land exists | 1 SELECT/未memo key。中心が所有陸地なら0 | 多target性能未測定 |
| Http/Controllers/Api/CommandQueueController.php | definition map→distance/validation/projection | 同じmemoへ渡し重複距離readを共有 | 統合後再確認 |

### PR262: 全17ファイル

| product/app以下の箇所 | 変更経路・loop確認 | 分類/判断 | 未確認 |
|---|---|---|---|
| Application/CommandQueueService.php | add/validate/project→costMultiplier、queued target loop | NQ-01/02。Ship scopeはWorld/Nation/target/active/HPを維持。addはlock=true | 担当への案、統合時再確認 |
| Application/CurrentDatabaseBaseline.php | 工作船migration構造variant適用 | —。既存marker結果のarray置換 | migration未実行 |
| Application/DomesticCommandExecutor.php | validationFailure/executionCost→costMultiplier | NQ-01/02。新しいRuleset lazyと2回Ship lock SELECT | 担当への案、統合時再確認 |
| Application/MissileImpactResolver.php | impact loop→船種のcrime設定 | 新コードはcontext.settingsの純粋catalog走査。追加SQLなし | CPU量未測定 |
| Application/RulesetPublisher.php | assertPublishedSnapshotへ切出し | 既存snapshot/definition照会数を維持 | publish未実行 |
| Application/SurfaceShipBuildService.php | failure/buildの既存capacity locked read | capacity per definition純粋判定。2回の既存ship count検証自体は変更なし | broader建造最適化は対象外 |
| Application/SurfaceShipForcedDisplacementService.php | displacement失敗→船種crime | context.settingsの純粋catalog。追加SQLなし | CPU量未測定 |
| Domain/Command/UnderseaConstructionPolicy.php | construction Ship validation→catalog倍率 | NQ-01/02。property lazy Ruleset、毎回first Ship | 担当への案、統合時再確認 |
| Domain/Ruleset/CurrentRulesetAuthoringInspector.php | 3 domainのversion別require | —。filesystemのみ | I/O時間未測定 |
| Domain/Ruleset/RulesetAuthoringValidator.php | 船設定field loop | —。array型/範囲検証 | — |
| Domain/Secretary/SecretaryDemographicPolicy.php | accepted Ruleset key追加 | —。純粋判定 | — |
| Domain/Secretary/SecretaryItemGameplayContract.php | accepted Ruleset key追加 | —。metadata/catalog純粋検証 | — |
| Domain/Ship/SurfaceShipCatalog.php | 船定義fields/definition(settings,key)追加 | definition()はメモリ走査。既存settings(command)のrelation呼出とは区別。今回NQ-01はUndersea側property | CPU量未測定 |
| Domain/Ship/SurfaceShipDefinition.php | readonly船設定追加 | —。データ保持のみ | — |
| Http/Controllers/Api/CommandQueueController.php | 同一construction_ship_idで各定義validation・preview・cost | NQ-02。直接definition RulesetはsetRelation済みなのでそこはNQ-01なし | 担当への案、統合時再確認 |
| Http/Controllers/Api/SurfaceShipController.php | ship_typesのdefinition別capacity | 既存ships collectionをメモリcount。DB追加なし | endpoint時間未測定 |
| Services/MapCellPresenter.php | 可視性限定船の秘匿 | DB追加なし。owner/rulesetはMapChunk eager。可視性判定で非owner船を隠す保証を維持 | 未変更全caller全面監査なし |

### PR263 / PR264

| 対象・箇所 | 変更経路 | 分類/判断 | 未確認 |
|---|---|---|---|
| PR263 Domain/Secretary/SecretaryDemographicPolicy.php | accepted Ruleset key追加 | —。array/key判定 | — |
| PR263 Domain/Secretary/SecretaryItemGameplayContract.php | accepted Ruleset key追加 | —。array/catalog検証 | — |
| PR264 product/app差分0件 | frontendのみ | backend経路の追加・変更なし。DB調査範囲へfrontend描画を拡張しない | 将来backend差分が入れば再確認 |

## 検証と残件

- 実施: 固定commitのGit差分、変更集合とhistory集合の一致、全35ファイル/変更50行(23+8+17+2)の静的経路確認、関連eager loading・World/Nation/Ship scope・lock・可視性の確認。
- 未実施: 隔離開発DBのSQLログ/クエリ実測、PG実行計画、Wall time/メモリ、性能修正・テスト・CI。DB接続も本番/実データ操作も行っていない。環境修理はしていない。
- 判断/実装候補: NQ-01/02をPR262担当へ共有し、同branchを並行編集しない。NQ-03は単processCell内の成功lockモデル再利用。NQ-04は既存圧縮のbulk update案として別差分で検討。NQ-05はPR260の改善を取り込んだ後の確認。NQ-06は可視性/取得順を維持する設計と実測なしにcacheを入れない。
- 性能修正時の必要な確認: 既存functional保証を利用し、owner/foreign/HP/移動済み船、private course、Turn再試行/状態更新順、queue unique position/order/version/auditが保持されることを確認する。SQL計測は少数の代表fixtureで対象queryの増分を比較し、全経路へN+1 assertを大量追加しない。
- 結論の限界: これは指定された変更経路の静的監査である。「N+1なし」や「性能問題なし」という全repository保証ではなく、DB実測まで行った完成性能評価とも扱わない。

## 担当へ渡す最小差分案と実測filter

### PR262: nested Ruleset readだけを減らす案

queue予測の案は、既存のqueue item/definition集合への一段追加で足りる。

```diff
 // CommandQueueService::queueFor() — current itemsだけを対象にする
-'items.definition',
+'items.definition.rulesetVersion',
```

これでdistinct definitionごとの+d lazy SELECTを1回のRuleset eager SELECTへ置換する。工作船指定のないqueueFor利用にも固定+1がかかるため、採用時はprojectionを行うcallだけで対象collectionに`loadMissing('definition.rulesetVersion')`する方が狭い。全queue/全Worldの先読みにはしない。

Turn側はitemごとの取得loopなので、`with('definition.rulesetVersion')`だけを追加しても+sを1にできない。既存context snapshotを渡す案の核は次である。

```diff
 // UnderseaConstructionPolicy: import App\\Models\\RulesetVersion;
 public function costMultiplier(..., bool $lock = false,
+    ?RulesetVersion $ruleset = null,
 ): int {
     if (! array_key_exists('construction_ship_id', $parameters)) {
         return 1;
     }
-    $settings = $command->rulesetVersion->settings;
+    $settings = ($ruleset !== null && $ruleset->id === $command->ruleset_version_id
+        ? $ruleset : $command->rulesetVersion)->settings;
```

`DomesticCommandExecutor::validationFailure()`から既存`$context->ruleset`を渡す。`executionCost()`の呼出にも同じsnapshotを渡せるよう局所引数を追加する。ID不一致時のfallbackを残すのでprovenanceを置き換えない。正しいcontextでの+s読取を0へできる。これは実装可能な差分の核であり、`...`を含む上記snippetをそのままapplyするpatchではない。

Ship lookup削減はNQ-02のscope内で行う。controllerの個別validationを消す案、foreign/位置/HP判定を省く案、Turnの次commandにも検証結果を持ち越す案にはしない。一つのcommandの検証結果/倍率を費用計算へ渡す構造への変更と、同時mutationの読取意味の確認が必要である。

### blocked warship: 別patch候補

workspaceの`blocked-warship-nation-reuse.patch`はNQ-03だけの候補である。repo本体には適用していない。destination fuel検証で成功したlock済みNationだけを、同じprocessCell内の攻撃へ再利用する。最初の照会がnullなら従来の後続照会を維持するので、null結果の観測順を変えない。目的地arrived時、目的地なし、別船/次Turnの読取には影響しない。

`finishDestination()`→TurnEventRecorderの経路も確認した。この区間はShip property、chunk変更、audit insertを行い、Nation state/moneyを書き換えない。reuse直後のwarshipAttackが従来どおりNation moneyを更新する。

推奨の最小functional実行対象は既存`SurfaceShipDestinationTest::test_blocked_last_step_clears_without_arrival_and_warship_still_attacks`である。このcaseは停止・目的地解除・fuel不消費・攻撃damage/支出・owner event・public秘匿を既に確認する。focusedコマンド例は隔離開発DBを指した環境内で`composer test:surface -- --filter test_blocked_last_step_clears_without_arrival_and_warship_still_attacks`。全体suiteをこの計測のために繰返さない。

SQL計測はfixture作成/context snapshot読取後、`move()`の実行直前から直後を窓にする。既存test helperはprocessCellを2回呼ぶが、同じ船の処理済みguardにより2回目は目的地/攻撃経路へ再到達しない。

| 対象 | SQL形の目安 | 必要最小filter | 期待するbefore/after |
|---|---|---|---|
| NQ-03 active Nation lock | `select * from "nations" where "nations"."id" = ? and "state" = ? limit 1 for update` | SELECT、from nations、state predicate、for update、bindingsに対象Nation IDとactive。fixture/後続assertは窓外 | 対象b=1のcaseで2→1。total SQL数は固定しない |
| NQ-01 Ruleset lazy | `select * from "ruleset_versions" where "ruleset_versions"."id" = ? limit 1` | SELECT、from ruleset_versions、対象command ruleset ID。一覧初期World loadと区別するためprojection/Turn評価だけを窓にする | projection d→1(狭いeager案)、Turn s→0(context供給案) |
| NQ-02 工作船lookup | `select * from "ships" where "world_id" = ? and "nation_id" = ? and "state" = ? and "ships"."id" = ? and "map_cell_id" = ? and "current_hp" > ? limit 1 [for update]` | SELECT、from ships、対象world/nation/ship/cell ID、active、HP>0。owner一覧の全船eager読取を除外 | 3定義の成功一覧6→1程度の船fact共有、単Turn成功command2→1 |

SQL文字列の空白・column並び・完全一致は測定contractにしない。上記shapeとbindingsで対象照会だけを分類し、実測ログで実際のdriver表記を確認する。測定対象fixtureはproduction DBへ接続しない。PR262のrefが更新された場合はSQL経路とfixed SHAを更新してから測定する。
