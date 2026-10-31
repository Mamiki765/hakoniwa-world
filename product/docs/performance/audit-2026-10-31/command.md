# コマンド送信・開発計画の性能調査

調査基準: `Mamiki765/hakoniwa-world` main **`2e93ec918774ad64d787aba99fc59a7bc3e54d16`**（application 5.8.3）。2026-10-31。製品コード、テスト本文、設定、DBを変更していない。以下のSQL件数は明記したものを除き**静的推定**で、実測時間の改善率ではない。

`AGENTS.md`、`docs/README.md`、current-status/current-contracts の案内、関連設計・既存N+1調査を確認した。tracked tree とローカルの `.agents` を確認したが関連 `SKILL.md` は存在しなかった。archive は検索・読取していない。本番・OCI・実データ・secretへ接続していない。

## 調査単位と範囲

`routes/web.php:249–257` の計画API **9入口全件**を、Controller **9 public action全件**、`CommandQueueService` の登録/読取/並替/取消/数量/preview/bulk、および首都地下施設の投影とcurrent definition解決まで追跡した。フロントは `CommandQueuePanel.vue` の取得、通常登録、一括登録、編集、取消、順序変更、refresh/abort/世代管理を確認した。意味のある処理群へ集約した `command-coverage.csv` が確認範囲の台帳。Turn内のゲーム実行本体は別担当の台帳へ接続する。依存先である一般認証・Middleware全体、通信ネットワーク実測、DB実行計画の全件確認をこの担当だけで完了したとは主張しない。

記号: `Q`=有効計画数（現行Ruleset上限30）、`Qs`=固定セルを読むsurface計画数、`Qf`=うちfacility非nullの行数、`Qt`=対象Nation IDを持つ行数、`Qm`=記念碑selector行数、`S`=map spaceの全浅瀬数、`R`=reclaim検証が陸地EXISTSまで進む浅瀬数、`C`=一括の生成候補行数、`N`=選択可能なactive他Nation数、`M`=工作船指定を受けるcommand定義数。

## 優先候補

### CQ-01 / 優先A: 返却queueのtarget取得をまとめる

入口はGET queueと、通常登録・並替・数量更新・取消・bulkの全成功応答。`CommandQueueController::serializeQueue` は [656](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Http/Controllers/Api/CommandQueueController.php#L656) で**行ごと**にMapCellを読み、terrain/facilityもeager loadする。674で対象Nation名も行ごとに読む。さらに695→`CommandQuantitySemantics::label:162` は記念碑selector行ごとにMonumentDefinitionを `find(quantity)` する。同じ座標/同じNation/同じ記念碑selectorの重複でも再SELECTする。

- 対象部分だけで `2Qs + Qf + Qt + Qm` SELECT。30行すべて施設ありなら90 SELECT、さらに全行target IDがあれば30 SELECT。条件を満たす仮想fixtureでの静的上界であり、常時120 SQLという観測ではない。記念碑selectorの名称lookupは別に最大Qm SELECT。projection/認可/基礎取得SQLは別に加わる。
- **隔離PG実測**: PHP8.5.8/PG18.4、Debug32x32の合成World・1自国・同じ首都facilityをtargetとするfinance計画をQ=1→30に増やし各1GETした。対象MapCell SELECT **1→30**、terrain **1→30**、facility **1→30**、全SQL **17→104**（29行増で87SQL増）を確認。1test/9assert成功。`evidence/command-serialization-queries.json` とJUnitへ生query/fixture条件を保存。未最適化現行版の測定であり、差分適用後の時間改善の実測ではない。
- 最小案: queueに現れるdistinct `(map_space_id,x,y)` だけを一括取得しterrain/facilityをeager load、distinct targetNation IDのnameも一括取得。対象cell部分は最大3 SELECT、対象Nation名称は最大1 SELECTへできる。記念碑名称もdistinct selector IDを一括読取すれば最大1 SELECT。同じshape、null、表示用計算、現行順序を保持する。全map先読みは不要。
- 変更境界: Controllerのserializerと局所lookup。通常定義のRuleset供給、工作船に追従する計画を固定座標へ投影しない契約を維持。
- 意味保存リスク: 読取APIはtransaction外なので、同時Turnが挟まると現状も複数時点を混ぜ得る。一括化は読取時点を変える。整合した一回の読取として返す方針を明示し、他国の秘匿や不存在cellのnullを維持する。mutationのlockを削る案ではない。
- 検証: 空queue、同座標30行、distinct座標30行、monument対象名、工作船、地下枠を代表に既存応答比較と対象query数を測定する。`CommandQueueAndSalePolicyTest` のlist/add/reorder/cancel、scuttle、hidden overbuild代表を利用。

関連: 危険な上書き効果のbefore/after検証も `CommandQueueService:1533–1564` で危険効果M行に対し各passでtargetCell取得。World lock下で基礎cell factsだけ共有する余地がある。ただしcurrent/proposed queueは同じオブジェクトへitemsを差し替えるため、**同じprojection memoを両passで共有すると誤ったcache hitになる**。投影memoは別々にし、読み取りfactのみ共通化する。この派生部分はserializerとは別の回帰検証が必要で後順。

### CQ-02 / 優先A: 一括埋立てのmap全浅瀬走査とSQL往復・不要配列を減らす

`bulkPreview→bulkInsert(previewOnly)` とapplyは同じ処理。`CommandQueueService:[884–906](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CommandQueueService.php#L884)` はreclaim系でmap spaceの**全浅瀬S行**を `FOR UPDATE` + terrain/facility取得し、全行validateTargetへ渡す。自国/中立かつ施設等の先行検証を通ったR行は、1719→2269→`OwnedLandProximity:24–34`で各1 EXISTS、半径1の6座標条件を組み立てる。表示previewでもWorld→Nation→queue→全浅瀬のlockを取る。previewとapply両方で再実行するので通常操作1回で最大2R回の距離SELECTとなる。

- 最小案: 現行land定義とGridCoordinateを再利用し、自国陸地の隣接候補を集合で作り、浅瀬を所有者/対象条件で絞る。モデルhydrate/lock対象を実際の検証対象へ狭める。あるいは候補座標集合に対する一回のSQLで同じland EXISTS判定を行う。候補選定は**現在のmap**を根拠にし、将来queueの埋立て連鎖を勝手に使わない。
- 期待効果: 半径検証SQL `R→1～少数batch`、遠隔・他国浅瀬のhydrate/lock除去。World拡大時にも計画上限30とは独立して増える項目なので優先する。実際のS/R分布とPG planは未測定。
- 追加のCPU/メモリ削減: 917–918で両方針のbulkPlanを生成する。`bulkPlan:1064` は入り切らないgenerated候補も `dropped` へ1wrapper配列ずつ作る。**両方針で約2C個**のwrapperを持ち、後続1001–1009は既存行取消だけに利用し、1018/1027はcountだけ使う。生成済みdropはskipped_countに集約し、既存取消行だけ保持すれば、候補総数・結果を保ちながら不要な配列を削れる。snapshot用の全候補リスト自体は引き続き必要。隔離PHP8.5.8の現行bulkPlanを空queue/C=10,000で各方針1回呼ぶと、捨て候補wrapper **19,940個**、両plan保持による追加メモリ **8,055,488 bytes（約7.68MiB）**、17.41msを観測した（`evidence/command-bulk-plan-microbench.json`）。candidateのdefinitionはこのmethod内で使わないplaceholder。SQL/ORM/snapshot/HTTPを含まず、最適化後の比較や全requestの時間改善を示さない。
- 意味保存リスク: `(y,x)`順、1cell=埋立て→整地の2行組、候補数、fill/overflowの取消確定集合、token snapshot、期限/再送/409、地形/所有者/施設判定、World lockとapply時の再検証を維持する。**先頭30件で打ち切る案は候補数・snapshot・overflow契約を変えるので不可**。previewのlockを単純削除する案も本候補へ含めない。
- 検証: `CommandQueueAndSalePolicyTest:40,136,180,243,292,347,400` の既存代表で候補、両方針、zero候補再送、危険効果、ペア分割拒否、preview後変更を比較。隔離合成mapの浅瀬Sと自国沿岸Rを独立に増やし、SQL数・hydrated行数・peak memory・lock保持時間を一回ずつ測る。

### CQ-03 / 優先B: 工作船コマンド一覧で同じ船の再読取を共有する（既報NQ-02残存）

`CommandQueuePanel.vue:517–522` は工作船のあるセルで通常一覧と工作船一覧を2 requestで取得する。工作船一覧request内では、`CommandQueueController` のvalidateTarget(229)、undersea向けprojectedTargetMatches(265–277)、cost表示(330)が、各々 `UnderseaConstructionPolicy::costMultiplier→constructionShip:68` の同じShip SELECTへ到達する。

- 現行は妥当な入力で**3M回**、validation失敗catchのfallback(253–263)も通れば最大**4M回**。M=3の3 undersea定義なら9回/最大12回の静的見積り。旧10/14資料の2M/3Mは現行call graphと異なるため再利用しない。commandごとのeligibilityをShip SELECT前に拒否する場合はこの上界へ達しない。
- 最小案: request内に限り検証済み船factを渡す、または同じowner/world/ship/target/Ruleset IDをkeyにした局所contextを使う。commandごとのmetadata・種別・倍率判定は各々維持する。通常一覧と船一覧を一つの応答へまとめる案はAPI変更範囲が増すので後順。
- 期待効果: 同一requestの成功3定義で対象Ship SELECT9→1程度。総時間はDB往復が支配する環境ほど改善。requestを跨ぐcacheではない。
- リスク: 読取途中で船が移動/沈没する同時mutationの観測時点を変える。登録/Turn実行のfresh `FOR UPDATE`、HP/所有者/world/active/位置判定を省略しない。
- 検証: `UnderseaConstructionShipTest:42,95,276`、ShipUpgrade代表と、frontendの工作船一覧代表を利用。SQL listenerでowner一覧の一括取得を除き、対象shipのlookupだけを計測する。

### CQ-04 / 優先B: 記念碑対象の首都海域検査をNationごとの3SELECTから集合取得へ

`CommandQueueController:310–311` → `NationCommandTargetService::monumentFlightOptions:48–61` → `hasCompleteCapitalChunk:83–109`。active他Nation N件を読み、各々capital cell、MapSpace、chunk COUNTを読む。完全な通常chunkを持つN件では**1+3N SELECT**、不完全な対象は途中終了する。

- 最小案: 対象capital+MapSpaceを一括取得しbounds検査、候補chunkのcell COUNTをgroupして取得。共通map spaceを何度も再読取しない。対象国集合の3程度のSQLへ縮小する余地。
- 影響/リスク: 一覧のowner/world/state範囲、chunk256セル確認、端の不完全chunk拒否を保持。単なるbounds判定に置換すると、欠落cellのあるchunkを許してしまう。
- 検証: `CommandQueueAndSalePolicyTest:654,821` と完全/端/欠落chunkの合成代表。N増加時のSQL slopeを1回比較。readonly同時変更の時点差もCQ-01同様に扱う。

### CQ-05 / 優先C: queue位置の行別UPDATE（既報NQ-04）

`CommandQueueCompactor:14–30` は1 SELECT+全件位置null化1 UPDATE+残Q行のQ UPDATE。呼出は取消、取消from、破損修復、Turnのconsumeで、後者はTurn台帳へ接続。`CommandQueueService:449–455,699–704,974–1009` でもshift/reorder/bulkの位置を行ごとに戻す。

- 最小案: 現行のqueue lockと位置null化段階を維持し、`UPDATE ... CASE` / `UPDATE FROM VALUES` 等で復帰を一括化。Q行の位置復帰SQLを1にする。既存hook/updated_atの差を確認する。
- リスク: 非deferrableなunique位置制約を守るため**null化段階を無条件に削除しない**。順序、穴を残すrepositionと前詰めcancelの違い、cancel audit行、request provenance、version incrementを保持。ゲームstate mutationなので優先Aより後。
- Q上限30で効果はbounded、S/Nで伸びる候補より優先を下げる。`HotfixCommandQueueCorruptionTest` とqueue順序/version/abandonment lock代表で実保存後の比較が必要。静的のみで時間改善未測定。

### CQ-06 / 優先C: 一requestの既読World/Rulesetと不要な前検証queue投影の再読取

- definitionsは64でqueueFor(World+Ruleset読取)、65でWorld+Ruleset再読取、70のqueueLimitもWorld+Rulesetを再読取。各missile定義のvalidateTarget1692もWorld+Rulesetを毎回読む。現行4 missile定義が検証に到達すれば**その部分だけ8 SELECT**。同一requestの既読Ruleset/currentWorldを渡せる。
- reorder/cancel/updateのController:502,601,624は `queueFor(mutationPreflight:true)` を呼び、items+definitionまでloadして返却値を使わず捨てる。mutation本体は別のlocked readを行い、返却時にもloadQueueする。前検証でprojectionを要しないならitems/definitionsの最大2 SELECTを省ける。
- 地下施設のsequence検証は `UndergroundFacilityService:92–121` でslotごとcurrentFacilityKeyを1回、各itemの `QueuedCommandDefinitionResolver:31–34` はrequestRulesetVersion未ロードならprovenance確認SELECT。現在のRuleset/施設集合を投影contextへ供給する余地。provenance欠落のfail-closedを削る案ではない。
- 検証/リスク: exception順、認可、freeze/reset guard、map space、World→Nation→membership→queueのlock順を維持。事前読取snapshotでmutationのfresh readを置換しない。current codeと異なるhistorical Rulesetを無条件供給しない。まずserializer/definitionsのreadonly範囲に限定して測る。

## 既修正として除外したもの

- 5.8.2 `RulesetVersion::settings` JSON decode cacheは実装済み。一般的な「settingsをcacheする」再提案はしない。
- 旧NQ-01のqueue予測Ruleset lazyは現行 `CommandQueueService:1573–1575` がcurrent定義へRulesetをsetRelationする。`projectQueuedCellResult:1240–1242` は船追従commandを固定セル予測から除外。旧調査のd件SELECTを現在のqueue投影へそのまま主張しない。
- `serializeQueue:646–651` の工作船はdistinct ID一括取得済み。これを未修正として数えない。
- 同一target陸地距離は `SurfaceCommandProjectionMemo` + OwnedLandProximityのexistsへ改善済み。buildLandProjectionもrequested radiusとqueue依存座標だけを取得し、全map先読みではない。ここを旧NQ-05のまま再提案しない。
- 中央施設上限は `centralFacilityLimit->reachedMaximum` を登録側372で共有済み。10/18 refactoring surveyの「2コピー」案を現行未修正として戻さない。
- フロントのposition-only取得、Promise.all、AbortController、generation判定、mutation中refresh延期、成功queue応答反映は実装済み。通常成功のあとに毎回queue再GETするという指摘は誤り。初期表示だけqueueを先に読み、末尾positionを確定する逐次2段がある。位置不明のまま並列化すると重複取得/予告ずれを作るので単純な修正候補にしない。

## 実施した隔離確認と限界

環境: Node v24.19.0、Vitest v4.1.10。当初のhostにはPHP CLI/vendorがなかったが、親が後からPHP8.5.8 + PG18.4の隔離runtimeを用意した。下記PHP method測定を追加した。GET queueのSQL件数を1case内のQ=1/Q=30で測定し、現行の3Q SELECTを確認した。PG EXPLAINとbefore/after性能比較は未実施。過去の別長期試験は再実行していない。

`npx vitest run --project=surface resources/js/components/CommandAndSalePanels.surface.test.ts -t 'defers refresh during a committed add|ignores a successful stale refresh|asks before a bulk insertion overflows|uses the next insertion position' --reporter=verbose`

結果: **3 passed / 41 skipped**, 1 file passed、Duration **2.42s**（tests342ms）。filter最後の語に一致するtestはなく、実際に実行したのは前3ケース。既存のmutation中refresh延期、古い成功応答の排除、preview→選択方針apply→取消確認の機能保証を一度確認した。これは**API性能・network時間・高速化倍率を測った結果ではない**。再実行していない。前後の `git status --short` は空で製品tree不変。

追加PHP/PG確認: `docker exec perf-audit-php-20261031 php vendor/bin/phpunit /audit/scripts/CommandSerializationSqlProbeTest.php --log-junit /audit/evidence/command-serialization-junit.xml` は **1test / 9 assertions成功**、2.693s/69.50MB。GET窓のelapsedはQ1 **70.65ms** / Q30 **132.76ms**だったが、1回ずつのcold/warm差、Docker/隔離PGの影響を含むためP95や高速化率へ換算しない。反復負荷試験はしていない。1件の合成fixtureの同target効果を示し、工作船、Nation名、記念碑lookup、distinct30座標や本番分布の時間は未測定。bulkPlanのCPU/メモリ確認は現行private純粋methodをreflectionで呼ぶもので、アプリ起動/DBや全bulk requestのprofileではない。

推奨着手順: CQ-01のserializer一括取得 → CQ-02のbulk距離SELECT/不要配列 → CQ-03のrequest内船fact共有 → CQ-04記念碑の集合照会 → CQ-06の重複snapshot → CQ-05のwrite削減。各々、既存代表fixtureへ一時SQL listenerを足し、対象query/件数だけを記録してから最小差分を検討する。

乱数: この送信/投影群は抽選を担わない。別担当のTurn最適化と統合する場合、旧版と乱数消費順を一致させることは要件としない。同じコード版・初期state・seedからの再現性、当該版/Ruleset/seedの診断記録は維持する。今回Ruleset更新はしていない。
