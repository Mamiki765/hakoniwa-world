# API・画面データ・DB・管理/集計処理の性能調査

対象: `Mamiki765/hakoniwa-world` main **`2e93ec918774ad64d787aba99fc59a7bc3e54d16`**、application 5.8.3。2026-10-31。製品コード・設定・schema・実データ・本番・OCIは変更していない。以下のリンクの行はこの固定SHAのもの。処理意味・確率・効果を維持する調査であり、仕様変更や保持データ削除を高速化策として混ぜない。

入口の全API controller **31ファイル**、Console command **23ファイル**、関連service/resourceを含む**135ファイル**を静的triageし、public method **393行**の台帳とcanonical schema+現行migrationの**96 table**の索引台帳を別添した。この担当はcommand/公式Turn/戦闘本体を他担当へ明示的に引き渡している。台帳のowner列がその境界で、別担当部分を本担当の詳細検証済みとは扱わない。静的確認は実運用で速いという証明ではない。

- [処理台帳](api-db-coverage.csv): 入口・行・担当・確認状態・候補・実測の限界。
- [DB索引台帳](api-db-indexes.csv): 全tableとPK/unique/secondary indexの所在。migration追加tableも含む。
- [source manifest](evidence/api-db-source-manifest.json): 読取対象、行数、SHA-256、query/loop site数。site数は機械的検索であり実行SQL数ではない。
- [再生成script](scripts/api_inventory.py.txt): archive・.env・認証情報・本番データを読まず、固定sourceから生成。

AGENTS.md、docs/README.md、current-status、関連gateと既存N+1調査/最終記録/5.8.2/refactoring surveyを確認した。tracked treeと `/workspace/.agents` に関連SKILL.mdはなかった。archive本文を読んでいない。既修正の停止軍艦Nation重複lock(NQ-03)、Ruleset JSON model cache(5.8.2)、陸地距離memo、queue共通化等を新規提案として重複させていない。既存履歴にある「乱数消費順維持」は今回のOwner条件を上書きしない。本担当候補は基本的に乱数非依存で、新Rulesetは不要と推定する。

## 優先候補

優先は障害severityではなく、期待利益・利用頻度・変更riskの順。A=全active露店件数、H=本人の全出品履歴、S=本人active船数、C=chunk内cell数(通常最大256)、D=休眠首都数、B=purge候補戦闘数(最大1000)、T=そのうち試練件数、E=ログevent数。

### AP-01 高: 地底露店のページ切出しをDBへ移す

到達: `GET /api/v1/me/underground/market` → `UndergroundMarketController::index` → [UndergroundMarketService:30](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundMarketService.php#L30)。[37](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundMarketService.php#L37)で全active rowsを`get()`し、全snapshotをdecode、map/filter、price/IL sortした後、[79](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundMarketService.php#L79)で初めてpage分をsliceする。出品は無期限なので、各人の同時上限だけではWorld全体Aの増加を止めない。

- 根拠: 新着はPHP O(A)、price/IL sortはO(A log A)、memory O(A)、返すのはpage_size件。SQLは1回でも取得行・JSON転送が多い。これはSQL N+1ではない。
- 最小案: snapshot JSONの同じcategory/weapon_style/IL/quality/default判定をSQL WHEREへ移し、既存tie-break `id DESC`を含むORDER、OFFSET/LIMIT(size+1)でhas_moreを計算。DB絞込後の行だけblocked_reasonsを作る。全件総数は返していないのでCOUNT不要。
- 期待: PHP hydration/decodeをA→page_size+1、transferを同様に縮小。DB側はfilter/index次第でscan/sortが残るため、APIが必ず同率高速化するとは断定しない。
- 範囲/risk: 当service browseと必要ならappend-only index migration。JSONの数値型、未存在weapon_style/qualityのnull/0、purchasable_only、sort順、ページ境界を完全一致させる。profile lock、admission、残高/容量/購入IL上限、purchaseのfresh再検証は保持する。
- 検証: 現行 `UndergroundMarketTest` に準じ、同一fixtureで全filter/sortを代表境界ごとに旧projectionと比較。取得row数・response一致・has_moreを測る。生成/固定/共鳴、同額/同IL/欠損optional fieldを含む。実データ/本番計画は未取得、候補SQLの実測も未実施。

### AP-02 中〜高: ブースト管理一覧のseries N+1と捨てる公開projection

到達: admin boosts GET → [BoostScheduleService::adminStatus:56](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/BoostScheduleService.php#L56)。最大500 occurrencesをmapし、[present:35](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/BoostScheduleService.php#L35)が各rowごとに同じseriesをSELECTする。さらに[61](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/BoostScheduleService.php#L61)のpublicStatusで全future rowsを読み、kind先頭のprojectionを作った後、そのoccurrences keyはadmin側で上書きされる。

- 根拠: admin行mならseries SELECT m回、公開側は表示kind k回（通常最大2）。同一series反復でもmemoなし。admin rows queryと公開future queryは別。上限500でN+1はboundedだが大きい。
- 実測: 合成fixture **1series/3occurrences、現行コード1呼出**でseries SELECT **4回**（公開用1+管理用3）、計**7 SQL**を確認。**1 test / 4 assertions成功**。projection単回5.448msは参考値で、統計的な時間保証ではない。 [SQL evidence](evidence/api-boost-series-queries.json) / [log](evidence/api-boost-probe.log)。candidate patchを実行した結果ではない。
- 最小案: 管理行のdistinct seriesを一括で取得してpresentへ渡す。同じseries scheduleのdecodeもrequest内で1回にする。adminStatusでは公開occurrencesを生成せずserver_now/timezoneをそのまま生成する。
- 期待: series往復m+k→1（追加joinで0へできるがrow重複に注意）、捨てるfuture row hydrationと公開projection削減。管理者限定なので頻度は一般APIより低い。
- risk/検証: 返却するid/order/status/title、cancelled/ended、hunting/turn compensationのschedule解釈、server_now/timezoneは同じにする。参照欠損時throwを維持する。authとreserve/cancelのWorld lockは触らない。BoostScheduleTestのfinite recurrence/end-exclusive/cancel代表と管理payload比較、SQL count確認。500件全列挙の長期試験は不要。

### AP-03 高（地図規模依存）: chunk毎の全船視界と全休眠首都を近傍へ絞る

[SurfaceVisibilityService:31](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/SurfaceVisibilityService.php#L31)はchunk boundsを土地の視界queryには使うが、[46-68](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/SurfaceVisibilityService.php#L46)の船は本人全active船をcell込みで取得し、chunkから遠い船も全radius座標を作る。[MapChunkService:121](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/MapChunkService.php#L121)は全World休眠首都を読み、[129](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/MapChunkService.php#L129)でC×D距離判定する。

- 根拠: q chunksを開くと船hydrate/vision展開O(q*S*r²)、休眠theme判定O(q*C*D)。見えるchunk内座標だけが実際に利用される。query回数が固定でも対象sizeに比例する。
- 最小案: settingsから現在版の最大船visibility radiusを一度求め、保守的なoffset-hex boundsでsource船cellを絞る。その後既存hex判定を使う。休眠首都もradiusを広げたchunk boundsへ絞るか、保護範囲座標setを作りC回membershipで判定。MapChunkで取得済World/RulesetとSurfaceVisibilityのloadMissingを同一request内で正しく共有する小改善も可能（現行はWorldモデルをmapSpace relationへsetしておらず二度ロードする）。
- 期待: 遠い船/首都が多いWorldほどPHP/転送を減らす。chunk付近に全船が集まる場合は効果小。radiusの最大値を固定ハードコードしない。
- risk/検証: 負座標、奇偶row、radius0/最大、境界船、海底施設の秘匿、public viewer、別World/nation、現行Rulesetでの船視界を守る。矩形だけで視界を確定しない。既存PublicLobbyApiTestとsurface visibility testsで返却cell配列/hash完全一致を確認。未実測。

### AP-04 中〜高（World規模依存）: ランキングの全所有cellモデルを集計rowへ縮める

[NationBasicStatusProjection::forWorld:104](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/NationBasicStatusProjection.php#L104)は全rank対象NationのMapCellを`get()`、facility eager、owner別group、各cellで合計/施設capacityを算出する。人口/領土/農工鉱capacityにしか使わないがcell全属性をhydrateする。

- 根拠: 所有cell数NのO(N) PHP memory/hydration。さらに [110](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/NationBasicStatusProjection.php#L110)のlandArea->forWorldは [NationLandAreaCalculator::surfaceCells:95](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Domain/Map/NationLandAreaCalculator.php#L95)で全World所有cell+terrainをもう一度hydrateする。農工鉱の意味は [project:161](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/NationBasicStatusProjection.php#L161)の1cell capacity加算。forNationはすでにfacility+scale GROUP BYで縮約する別実装がある。
- 最小案: population/countをowner別aggregate、capacityは(owner,facility,scale) GROUP BY countを取得し同じcapacity resolver結果×count。landAreaは既存 [forNationIds:33](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Domain/Map/NationLandAreaCalculator.php#L33)のSQL aggregateを再利用して全cell再hydrateを避ける。food/地下capacityの既存batch経路は維持する。まずselect列縮小だけでも有効。
- 期待: hydration N→distinct groups G、通常G≪N。SQLは現行より増える場合があるのでDB/transfer/PHP別測定が必要。
- risk/検証: malformed facility_scaleが同じ例外になること、null facility、integer丸め、地下bonus、rank tie ID順、公開moneyの秘匿を維持。PublicLobbyApiTest fixtureで全rank/集計値比較、対象rowsとpeak memory計測。未実測。

### AP-05 中: receipt purgeのrow毎pin/progress照会をbatch事実へ

管理purge status → [AdminOperationsService:130](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/AdminOperationsService.php#L130)は全P profiles×4 streamsを同期走査。CLIも同じ。`UndergroundReceiptPurgeService`はbatch候補を得た後、[durabilityBlocker:167](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundReceiptPurgeService.php#L167)で各戦闘のlog pinとimage pinをexists、試練はprogressとsnapshotをさらに読む。

- 根拠: pinなしで先へ進めるB件に2B SQL、うち試練T件でさらに2T SQL。最悪B=T=1000なら**最大4000の追加SELECT**（他の固定query・transaction・checkpoint queryを除く）。early blockerやOR short-circuit時は少なくなる。batch上限とprefix停止順は既存で適切。
- 最小案: 同じprofile lock/transactionの候補IDsでpin factsを一括取得、trial progressをdistinct keyで取得、必要snapshot fieldsは候補queryへ。iterationと最初のblockerはそのまま。別profile/streamで共通のsequence定義照合も短命cache候補だが、schema不変の検証境界を先に決める。
- 期待: row毎network roundtripを定数個へ。P全scanの時間/lock保持を縮める。statusを非同期・部分返却にする変更は現API意味が変わるため本案には含めない。
- risk/検証: 最重要は早すぎる削除を起こさないこと。active trial/story、初回fact、30日、retention pin延長、verified/checkpoint、same prefix境界を維持する。write時fresh lockを省略せず、pin writersのserializationを確認する。UndergroundReceiptRollupTest/AdminOperationsTestの代表をbefore/after比較。未実測、削除処理の優先は他候補より後。

### AP-06 中: 図鑑バッジに全詳細生成、claimAllに同じ登録数再読込

SecretaryController::show [63](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Http/Controllers/Api/SecretaryController.php#L63) → [SecretaryCollectionService::unclaimedCount:117](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/SecretaryCollectionService.php#L117)はindex全体を作り、戻り値のunclaimed整数だけ使う。全monster/item図鑑行、effect_text、各ラベル、Pd残高まで生成/取得する。またclaimAllで登録数を求めた後、各bonusのclaimBonus [183](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/SecretaryCollectionService.php#L183)がregisteredCountを再実行する。

- 根拠: badgeにはentries/bonus flags+catalog membershipが必要だが説明用配列/効果文/Pd balance SELECTは不要。claimAllの到達milestone数Mに対して登録row SELECTは1+M、各rowのcatalogHasがmonsterCatalog/manual projectionを再生成する。
- 最小案: canonical catalog key setと取得済entriesからsummaryだけ算出してindexとbadgeで共有。claimAll内では既存資産lock取得後の登録factを一度計算してbonus検証へ渡す。request/transactionを跨ぐcacheは使わない。
- 期待: secretary画面readのCPUと固定1SELECT削減。claimAllは登録fact読込M回削減。catalogは有限なので件数的利益はbounded。
- risk/検証: 未発見名称/効果の秘匿、catalogから外れたhistory row除外、bonus到達条件、pay-once、Pd ledger entry identityとcredit順は守る。SecretaryCollectionTestのclaim/claim-all再送、badge対index summary一致を代表検証。未実測。

### AP-07 低〜中: 固定個数の重複readを同一呼出内で再利用

- [DailyQuestService::todayStatuses:91](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/DailyQuestService.php#L91)は3questにcurrentStatusを呼び、progress SELECT3+同じPd SELECT3=6。dayを一度決めprogress集合+Pd1回なら2 query。responseの各balance/dayが並行更新時に別値を返す現在の観測がcontractか先に判断する。mutation/ledger lockは省略しない。
- [PublicWorldService::nation:103](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/PublicWorldService.php#L103)は直前のnationWithPublicAggregatesがwith(capital)済だがcapital()->first()で再SELECT。loaded capital relationの利用で固定1往復削減。World/Rulesetも複数query helperへ既読を渡せる。
- 自島 [NationResource::toArray:30](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Http/Resources/NationResource.php#L30) の非空balance経路では、safeLand用World/Ruleset2、basicStatus用2+landArea用World1、capacity resolver用2、forecast用2+currentSkillLevelsのWorld照合1、currentTurn用1、計**少なくとも11のWorld/Ruleset SELECT**を呼出構造から確認できる（他SQLを除く静的値）。同じrequestの読取snapshotを明示的に渡し、ID一致検証を残せば重複する往復/別モデルJSON decodeを減らせる。5.8.2 model cacheは別々に取得したモデル間では共有されない。古いNationへcurrent Worldを無条件代入せず、readとwriteのcache境界を分ける。
- AnnouncementAdminAuthorizer::allowsが未ロードauthIdentitiesをrelation builder getで取得するがrelationへsetしない。MeResource::providers等の後続lazy accessで再SELECTの余地。loadMissingを使うscopeはsame requestに限定する。

小さい局所改善のまとめ。上限が固定なのでN+1大規模問題とは扱わない。API/既存代表payload同値、fresh mutation再検証、日境界と並行観測の保持が条件。未実測。

### AP-08 中: 露店の補助read/write増幅

[UndergroundMarketService::mine:102](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundMarketService.php#L102)は本人全history H（cancelledも含む）を読み、返すのはactive/sold/expiredだけ。WHERE status IN 3種で除外済terminal rowsのhydrate/decodeを省ける。sold/expired履歴自体にpage/保持上限を勝手に設ける案ではない。[attention:267](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundMarketService.php#L267)は同じsold scopeのcountとsumを別SELECTするため1aggregateへ、expired countまでconditional aggregateでまとめる余地がある。[claim:234](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundMarketService.php#L234)はlock済rowを1件ずつUPDATE（n件）。

- 期待: mineの返却対象外row transfer削減、attentionのaggregate3→1、claim UPDATE n→1。
- 範囲/risk: same profile lock、owner/status/null条件を維持。claim bulk案は各row `now()`/updated_at差・Eloquent event・将来observerを確認し、timestampsの完全一致が必要なら保留する。balance overflow/ledger/receipt/replayは同じ。read改善から先に実装すればriskは小さい。
- 検証: UndergroundMarketTest sold snapshot/expired seen/pay-once代表、cancelled履歴多めfixtureでbefore/after返却一致・取得行数、SQL count。未実測。

### AP-09 中: ログAPIでimmutable metadataを一度だけ解読

owner/public log取得 → [publicDestinationNationNames:703](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/PlayerIslandEventService.php#L703)ですべてのraw metadataを解読。public monster eventは[filter:677](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/PlayerIslandEventService.php#L677)で再解読、最終map[317/548](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/PlayerIslandEventService.php#L548)でも解読する。metadata()はarrayをそのまま返すfast pathがすでにある。

- 根拠: 通常2E、該当public monsterは3E相当のjson_decode、同じbytes。SQL数不変。
- 最小案: SQL取得後row.metadataを一度array化し、その同じrowの検証・projectionへ渡す。row-localで保ち、destination snapshot/current-name fallback、null/malformed JSONでの既存throw、private/public filtering、group/orderを維持する。
- **実測**: 既存classの正確なprivate metadata decoderをReflectionで呼ぶ純CPU試験。1000events×1626bytes、public monsterの3段を模したbounded 3回測定、PHP8.5.8。中央値 **25.348ms→8.655ms**、json_decode **3000→1000**、checksum一致。 [JSON](evidence/api-metadata-microbench.json) / [script](scripts/api-metadata-microbench.php.txt)。DB/HTTP/他のprojectionを含めず、APIが約3倍速いという結論ではない。Reflection呼出は両側でほぼ共通だが候補側の初回decode1回は追加callであり、absolute値は実APIに移植できない。
- 検証: 現行PublicLobbyApiTest、PlayerIslandEvent系の可視性/host attribution/legacy fallback代表の配列比較。production data不要。

### AP-10 低（retention batch規模依存）: 画像pruneの同じ参照判定を集合化

[SecretaryImageRetentionService::pruneExpired:224](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/SecretaryImageRetentionService.php#L224)は既にpath重複排除しconditional deleteを行うが、残った各pathにisRetained/Secretary.main_image_path/SecretaryImage.pathの最大3existsを呼ぶ（[deleteIfUnreferenced:442](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/SecretaryImageRetentionService.php#L442)）。P distinct pathsで最大3P SELECT。main_image_path/pathの有無を索引台帳と照合し、本番planは未確認。

最小案は一つのpathに対するOR EXISTS 1statement、または候補path群の参照factをbatchで得て削除直前に必要な再検証。raceにより参照中画像を消さないことが利益より優先される。lease延長predicate、current image参照、filesystem failure時診断を保持。global/path永久cacheは不可。index新設はplanとwrite負担計測後。未実測、後順。

### AP-11 低（登録時の海怪獣退避）: 全海cell sortから最小候補選択

[NationCreationService::relocateIslandMonster:371](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/NationCreationService.php#L371)は全occupancy IDをpluckし、退避可能海cell全件をlock/get/terrain hydrateして、hex距離+IDでsortしてfirstだけ取る。W候補ならO(WlogW)のPHP比較、比較ごとにGridCoordinateを生成する。

最小案は取得/lock集合と候補順を維持したままlinear min-reduction（O(W)）とorigin計算のloop外化。さらにSQL NOT EXISTS/ORDER/LIMIT化は候補全体lock集合を変えるので別段階のrisk判断が必要。same seedや退避先の仕様（距離最小、同距離ID最小）、元島plan、rollback、船/怪獣occupancy、chunk versionは同じ。NationCreationTestのdisplaceable monster登録代表で退避先/失敗rollbackを比較。通常登録では該当海怪獣がいなければ実行0回なので後順。未実測。

### AP-12 低（手動監査compact）: 同じeligible event集合の二重aggregate

[RoutineAuditCompactor::eligibleEventAggregate:210](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/RoutineAuditCompactor.php#L210)は同scopeを総数/bytes/min/maxとevent_type別count/quantityで二度scanする。group別にbytes/min/maxも取得してPHPで足し合わせれば2→1 SELECT/summary、scan半減の余地がある。source set・exact delete count・overflow/cast・JSON numeric判定・summary lock/transactionを保持する。predicateとsame source identityを別authorityへ複製しない。既存compaction代表で全routine metadata/bytes/count/delete一致を比較。運用manual batchなので後順。未実測。

### AP-13 低: Maria実績同期のidentity lookupを集合化

[SyncMariaAchievements:27](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Console/Commands/SyncMariaAchievements.php#L27)は最大25未送信receiptを取得し、[32](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Console/Commands/SyncMariaAchievements.php#L32)でuser毎Discord IDをSELECTする。receipt数nに対しidentity lookup n回。同userが複数receiptを持っていても再読する。unique(user_id,provider)があるため、joinまたはdistinct user集合のlookup1回で同じIDを供給できる。

送信そのものは今回一切実行していない。HTTP逐次送信・成功応答でのみsent更新・失敗時次回再送・connect2秒/timeout5秒・ログを維持し、parallel送信や成功前sent更新を高速化案にしない。DB往復だけn→1で、外部待ちが支配的なら時間効果は小さい。同期の代表テストをHttp::fakeした既存fixtureでbefore/after比較し、identity query数と同じ送信順を検証する。未実測。

### AP-14 低（明示的な履歴補填/図鑑backfill）: 全履歴readと反復lookup

[OilDiscoveryExperienceBackfill::preview:37](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/OilDiscoveryExperienceBackfill.php#L37)は境界以前の発見event全件をgetし、event毎にTurnRun+ruleset、Nation、owner membership、Secretary、oil skillを読む（正常な旧版eventで**6 SELECT/event**、abandoned owner復元では追加2）。同じrun/Nation/Userが反復しても再読する。固定boundary履歴の明示的な一回補填なので通常APIより低優先。snapshot ID/World/turn/production-completed条件を保ったbatch取得、same preview内のimmutable facts memoで往復を減らせる。applyはWorld lock取得後の再previewとskip拒否を維持し、判明しないOwnerを推測しない。未実測。

[CollectionBackfill::candidates:152](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CollectionBackfill.php#L152)はgachaの全result_snapshotをDB::selectで配列取得し、その後PHPで各drawを読む。他sourceはSQL GROUP BY縮約済みだが、draw/取引の未集約入力はhistoryに比例する。ID keysetの小batchで同じmin日時/登録factへmergeすれば、一度に持つ生snapshotを減らせる。最終候補一覧自体は現行previewの契約なので削らない。PDO cursorのみで全memoryが一定と断言しない。全source/Owner帰属/売買後日時/一度だけ登録を保ち、既存SecretaryCollectionTestのbackfill代表で出力一致を比較。過去データを実際に変更する操作は実施していない。

## 問題なしとした範囲、測定待ち、採用しなかった案

「具体的追加候補なし」は当該静的経路で明確な重複/全件増幅を見つけなかった意味で、性能SLAの認定ではない。

| 分類 | 確認した性質 | 判定/限界 |
|---|---|---|
| OAuth認証/link、Visitor code | identity unique+advisory/row lock、visitor fast path、衝突bounded retry | lock削除や認証結果cacheを提案しない。外部OAuthレイテンシは未測定 |
| Nation作成・休止・破棄・profile、船course/name | World/Nation/ownerのfresh検証、unresolved guard、単transaction、no-op保存回避 | 読取重複に見えるprecheckとlock後再検証をまとめて消さない。登録の具体案はAP-11 |
| 公開ランキング/島詳細、map chunk | relationships eager、図鑑/monster/current version適用、population/territory集計 | AP-03/04/07。monster countは既存snapshotで全怪獣再countを避けている |
| 掲示板 | timeline上限、作者/target eager、target/sender timeline index、cooldown index | query数はmessage数に比例しない。投稿auditと保持の意味は不変 |
| お知らせ・問い合わせ | latest5/page10、created_at+id順索引、問い合わせuser/nation eager | Markdown再renderは最大20KB×10件。更新版キーcache余地はあるが実測優先度低く具体候補へ昇格しない |
| 秘書profile/image/title/装備/合成/チケット/倉庫 | finite slot/catalog、prepared eager、pay-once receipt、asset lock順/overflow | public装備再読込はbattle担当BU2へ統合。全材料lockや付与/ledgerを高速化理由で省略しない |
| lending候補 | after-ID keyset、20+1上限、skills/images/equipped/trial eager | page全体のSQL N+1は見つからず。snapshot付与はbattle担当 |
| 地上交易 | active listing eager、resource balances eager、viewer bids一括、asset escrowとlock | 全active返却はAPI契約。pagination/履歴短縮は今回提案しない。軍艦停止二重lockは既修正 |
| 日次報酬/Pd/補填 | idempotency ledger/receipt、atomic credit、固定asset種、補填履歴100件 | AP-07のreadのみ候補。creditを合算してledgerを省略する案は意味が変わる |
| 地底journal/receipt aggregate | durable statisticsとrollup UNION、ID prefix/checkpoint、source sequence検証 | 戦闘全JSON再走査は回避済。purge AP-05、image AP-10、ログAP-09 |
| 統計report | battle lazyById500、lending cursor、statistics列利用 | memoryはsecretary別aggregate数には比例する。全battle snapshotへの逆戻りや無目的長期試験はしない |
| battle history compact | preview snapshot chunk25、apply battle単位、batch/soft time budget | 既存bounded処理を再提案しない。preview→apply再読は正当性再検証なので省略不可 |
| World初期化/拡張 | cells500件batch、chunk集合、coverage cursor検証、World transaction | per-cell INSERTではない。全coverage確認を除いて速くする案は不可 |
| baseline/install/catalog/validate/preflight | schema contract検証・append-only migration・fresh/upgrade境界 | cold CLI/起動costの実測なし。検証を省略する最適化は不可 |
| cron/jobs/cache | routes/console、全23 commands、docker/cron wrapper、query consumers、cache config確認 | 本番cron頻度/actual job数は未取得。sync mutationに新queueを挿入する仕様変更はしない |

PublicApiResponseは既に`public, max-age=30, stale-while-revalidate=60`、PrivateApiResponseは`private, no-store`を設定する。認証済みprivateレスポンスの共有cache化やTTL拡大は意味/秘匿が変わるため候補外。アプリcodeに一般目的のCache facadeによるgame state cacheはなく、RNG/assetのglobal cache導入を推奨していない。

索引はbaselineのCREATE INDEXだけでなくPRIMARY/UNIQUE constraintと全現行migrationを照合した。主な既存経路はmap `(space,chunk_x,chunk_y)` / `(owner,space)` / space座標unique、ship owner/state、audit world/turn/visibility/subject、message target/sender timeline、receipt `(profile,id)` cursorとrequest unique、market seller/statusとactive equipment unique、boost window、collections user/kind/key uniqueに対応する。queryを書くたびに索引を足す提案はしない。例えばmajor-newsのevent_type filter、recentBattlesの日付range、market JSON filter、image pruneのretained_until単独、synthesis recipe historyなどは実plan/selectivity/rowsを測ってから判断する。table数/索引の存在だけでquery planが良いとは断定していない。

## 実測と未確認の境界

隔離環境PHP **8.5.8**（source Dockerfileの8.5.12とは相違）、PG18.4。DBは親が作成したinternal network、tmpfs、合成fixture専用 `hakoniwa_test`。`.env`をコピー/閲覧せず、既設prod credentialを使わず、測定用の既知値だけを利用した。canonical sourceは固定SHAのまま、測定用PHP/Pythonはこの調査ディレクトリにのみ置いた。

実測はAP-09 decoder microbenchmarkとAP-02の現行代表SQL countのみ。他候補は静的な呼出/SQL/対象size/計算量の根拠で、実行時間や本番改善率を作っていない。DB query plan、実ユーザー数、World/船/ログ/market size、disk/WAL、lock wait、HTTP転送/圧縮/ブラウザ時間、同時負荷、PHP8.5.12は未確認。全suite/CI/長期試験/本番操作は実行していない。親の公式Turn測定は別担当証拠として統合する。

推奨順は AP-09/02（局所・根拠が強い）、AP-01（拡大前の主要API）、AP-03/04（地図/Worldサイズをfixtureで増やして確認）、AP-06/07/08のread改善、最後にAP-05/10/11/12/13/14。複数改善を一度に混ぜず、現行返却/資産/進行/lock/replay同値を守りSQL数・取得行・CPU・memoryをそれぞれ計測する。

Repository閲覧用のコピーでは測定器2本へのリンクを `.txt` にした。実行時は元の拡張子へ戻す。本文・測定器の完全原本は同じdirectoryの証拠ZIPに保存している。
