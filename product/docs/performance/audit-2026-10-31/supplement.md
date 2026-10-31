# 起動・初期化・cold path・周辺処理の補完

固定SHA `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。主4分冊と基盤分冊のpath集合をsource-censusへ突き合わせ、台帳にfile名が現れなかった24 PHP sourceをrootで補完確認した。併せてbootstrap/public entry/view/style/config/DB/cron/build設定を構造確認した。目録数は全分岐を実行した数ではない。

## SUP-01: 初期構築の固定/有限集合への逐次writeと定義lookup（低優先、未実測）

`LegacyInspiredInitialIslandGenerator::apply:254–264` はplanに含まれるK changed cellsをid順でlock/get・prestate検証した後、K回Eloquent saveする。`NationResourceService::initialize:24–43` はR初期資源を各1 INSERT、tradable分はさらにsale policy INSERT。`RulesetPublisher::createDefinitions:133–145` はcommand/production/monsterのpayloadごとにINSERTし、`productionPayloads:288–289` はP生産定義ごとfacility/resource IDを2P SELECTする。publishだけでなくassertDefinitionsでもこの2P lookupを再実行する。通常TurnのguardはCurrentRulesetGuardのidentity確認であり、毎Turnこのpublisherを呼ぶと誤認しない。

範囲は登録・fresh install・明示的なschema/catalog検査で、毎クリック/毎cellに起きる仕事ではない。bulk INSERT/UPDATE、既読canonical ID mapの利用によりK/R/Pに比例するDB往復を減らせる余地がある。先に最小のID lookup共有から検討する。期待は2P→2程度のID SELECT、各payloadのINSERT→型別batch。K cellのUPDATEは既存trigger・version・変更集合・prestate例外・ship退避適用順を保てる場合だけまとめる。

意味保存上のriskはmodel observer/casts/created_at、PKを後続が必要とする順、constraint/triggerのrow/statement差、failed plan全rollback。新規登録で都合のよい島候補へ飛ばす/先にWorld拡張する最適化、prestate確認・advisory lock削除は不可。検証は既存NationCreationの候補棄却/船退避/rollback、fresh baseline/schema check、immutable definition一致で対象query数と全保存stateを比較する。未採用の旧migration整理や本番rebaselineへつなげない。期待時間は未測定で、通常read/Turnの改善より後。

## SUP-02: 初期島内の船退避候補sortをmin選択へ（低優先、未実測）

`LegacyInspiredInitialIslandGenerator::planShipRelocations:319–345` は船Sごとに予約cell RのoriginをfirstWhereで走査し、空き深海をfilter→距離/y/x/id順にsort→firstする。O(S×R logR)。cell ID indexでoriginをO(1)、候補集合の同じlexicographic keyのmin-reductionならO(S×R)。全Worldの海怪獣退避を扱うAP-11と同じ考え方だが、こちらは予約範囲内の船で、tie-breakはdistance/y/x/id。両者を一つの移動仕様へ統一しない。

変更範囲はplan内の選択だけ。先行船の退避によるoccupied集合の変化、NPCだけ除去できる分岐、退避不能時のplan棄却、同World/owner/terrain/HP/version検証、apply時fresh lockは残す。島予約範囲は有限で船がいなければ0回なので後順。検証は同距離複数候補、複数船連続退避、退避不能、NPC分岐の既存代表でplan配列/保存先の一致を確認する。

## 具体的な追加改善へ昇格させなかった処理

- `AdditionalTurnPlan` は31日/最大500受付の有限slotを作り、dueは時刻の同値判定。sortや毎分無限探索ではない。stored slot再解読の反復はAP-02のseries共有へ統合する。`TurnExecutionSchedule` はslot重複とdebt/奇数時条件の正当性を守る。nextRegular自体は既読World属性の算術でSQLなし。
- `TurnScheduleStatus` の最新完了Turnと未解決Turn readは状態の正本。private/Public summaryのTTLを勝手に伸ばして速くしない。boost API側のN+1はAP-02へ。
- `BuriedTreasureRevealResolver` は必要なcompleted runを1query、distinct treasure cell IDごと独立labelで抽選する。結果を別viewer/Turnへcacheする案は秘匿・抽選意味を変え得る。全chunk合流時のrun reuseは同一World/currentTurn/Ruleset/viewerのsnapshot境界を守ってから測る。既報T07/AP03と二重計上しない。
- `PublicMonsterKillProjection` は歴史参照とcurrent定義をbatch取得、同stable keyで集約してsort。Nationごと定義SELECTをしない。`NationActivityProjection`・DTO/interface/例外は属性/算術のみ。
- `SecretaryItemEffectContextResolver` はscopeをjoinで確定しsettingsを1回decodeする。毎item再decodeではない。公開/本人のowner境界とmetadata照合を省略しない。反復catalog構築はT01/FND02へ。
- `CurrentBaselineSeeds` は既に複数row INSERT一回。`CurrentDatabaseBaseline`/`RulesetPublisher`はimmutable/schema照合が目的で、検証をスキップする速さは提案しない。OilBackfill/CollectionBackfillの増幅はAP-14へ。
- `ManualIndexPresenter` は既存settings+有限catalogから表を作り、note用PHP配列をrequireする。notesをrequest内で一度渡す小改善は可能だが、出力は小さく未測定なので高優先候補にしない。ManualControllerのMarkdown・索引差込とheading処理は出力に比例する。新しい永続文書cacheはconfig/release更新の無効化設計が必要。
- `MapCellPresenter` は呼出側eager relationを使い、偽装用terrain/facilityはinstance memoあり。船catalog direct parseはFND01、assetはUI04へ。公開/所有者可視性・偽装・硬化・現在版の名前と古いinstance provenanceを維持する。`SeaAreaWeatherPresenter`はweather種類ごとのasset memo済み。`MoneyFormatter`は算術/number_formatのみ。
- `ProductionDestructiveDatabaseCommandGuard` は本番破壊commandを拒否する境界。sourceを読むだけで認証ファイルは開いていない。隔離runtimeは.envを持たない。guardをcache/省略して起動を速くする提案はない。
- Laravelのbootstrap、provider、public/index/htaccessは通常のrequest起動・routing・static file分岐。providerのsingleton/bindを一律singleton化するとmutable状態やretry寿命を変えるため、各catalogの局所cache案と分ける。
- 10 CSS source・12 Blade viewは実ビルド転送サイズと静的ループ構造を確認。全theme/global CSSは一つの約199.84KB(raw)/36.18KB(Vite gzip)に結合されるが、theme切替を維持したsplit/critical CSSの効果は未測定。parse/style/paintの実browser profileがないため「CSSが主因」と断定しない。
- Docker/Apache/nginx設定、18運用source、2workflow、5build設定は構造確認。cron wrapperは既存flock/application guardへ委譲し、新jobやbackground決算を導入しない。コンテナ起動/deploy/buildは運用頻度とcache hit次第で、本番worker・opcache・圧縮/IOの現状は未観測。CIのmatrixは正当性検証範囲を担い、今回はCIを起動していない。

全fileの出所・担当・静的/動的限界は `source-coverage.csv` と `supplement-coverage.csv`、全HTTP入口は `route-coverage.csv`。依存vendor/OS内部・archive本文・third-party reference・本番設定/実データ・全testsの性能は対象外。既存代表testsとpure probesは検証手段であり、全suiteの再実行は行っていない。
