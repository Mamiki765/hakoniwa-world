# 箱庭全処理 高速化調査（2026-10-31）

固定対象: [Mamiki765/hakoniwa-world main `2e93ec918774ad64d787aba99fc59a7bc3e54d16`](https://github.com/Mamiki765/hakoniwa-world/tree/2e93ec918774ad64d787aba99fc59a7bc3e54d16) — application 5.8.3 / Ruleset v41。mainの解決結果と隔離環境のHEAD一致を確認して固定した。製品コード・設定・テスト・schema・Rulesetは変更していない。commit/push/PR/merge/deploy/CI、本番/OCI操作、実データ変更、認証情報の閲覧/変更は行っていない。

**最初に着手する候補は、計画返却の対象一括取得、人口成長の重複保存の局所統合、地上/地底catalog構築の共有。** その後に装備snapshot共有、露店のSQLページング、一括埋立ての距離照会を進める。全体の最大利益は本番件数・利用頻度・DB往復に依存するため、局所microbenchmarkの倍率を合計してアプリ全体の改善率にはしない。

本成果は、全入口と処理群の静的評価、既存改善の照合、意味のある代表の隔離実測を仕上げたもの。全入力・全分岐・全端末・本番構成を実行したという主張ではない。未実測や保留も台帳へ残した。

## 網羅の根拠と読み方

| 目録 | 全件数・確認内容 | 証拠 |
|---|---|---|
| アプリ独自HTTP入口 | **179**（182 route中、残3はframeworkのstorage/health） | [route一覧](route-coverage.csv)、Laravelで取得した[routes.json](routes.json) |
| Console command | **23**、全handleからサービスへ接続。inspire closureはroutes/consoleで別記 | [console台帳](console-coverage.csv) |
| 公式Turn | **12 phaseすべて**＋関連経路65行 | [Turn台帳](turn-coverage.csv)、[分冊](turn.md) |
| 計画送信・投影・bulk | **9 API入口すべて**、31処理群 | [command台帳](command-coverage.csv)、[分冊](command.md) |
| 地底戦闘・地下施設 | solo/PT探索、試練、案内人決闘、狂月賛歌、異世界、力試し、導入、skip、貸出、保存、公式Turn施設の**37処理群** | [地底台帳](battle-coverage.csv)、[分冊](battle.md) |
| API/管理/集計/DB | 31 API controller、23 command、関連135 source、393 public入口（他担当への75行を含む） | [API台帳](api-db-coverage.csv)、[分冊](api-db.md) |
| 基盤 | Domain155 / Models72 / Request42 / Middleware4 / Rules2 / Provider1、計**276 file** | [基盤台帳](foundation-coverage.csv)、[分冊](foundation.md) |
| 画面・起動・周辺 | JS/Vue80、CSS10、Blade12、bootstrap/public入口4、config66、DB33、運用18、CI2など | [画面台帳](frontend-coverage.csv)、[補完台帳](supplement-coverage.csv) |
| 全sourceの照合 | **758 file**、うちapp PHP526、名前付き関数/メソッドの機械索引**3,751** | [file照合](source-coverage.csv)、[hash目録](source-census.csv)、[関数索引](symbols.csv)、[集計](coverage-summary.json) |
| DB schema | baseline＋全現行migrationの**96 table**、PK/unique/secondary indexを照合 | [索引台帳](api-db-indexes.csv) |

[統合カバレッジ台帳](coverage-ledger.csv) は1,057行。粒度の異なる元台帳を結合しており、同じ処理への複数参照を含む。1,057を独立した処理数や実行分岐数には使わない。全758 sourceに担当台帳への参照があり、参照なし0件を機械確認した。全fileの構造確認と、重点経路の深い呼出/SQL/計算量の確認を区別し、**全3,751関数の全行を手で精読した／全分岐を動的検証したとは扱わない**。

判定は「確認済み（静的な到達・loop・SQL/relation構造）」「具体的追加候補なし／問題なし」「改善候補」「他担当へ接続」「未確認」を分けた。「問題なし」は、その経路で具体的な追加改善を裏付けなかった意味で、性能SLAではない。HTTP latency・PG plan・本番件数/負荷など動的未確認は各行のlimit列へ記録している。

入口の範囲は独自runtime、画面、運用command/cron、起動・build設定。依存vendor/OS内部、archive本文、third-party reference、実データ、本番worker/設定の観測、全testsや過去の長期試験の再実行は含めない。開発preview/test補助は目録で区別した。AGENTS.mdと関連入口/現行contract/gateを読み、tracked treeとworkspaceの.agentsには関連SKILL.mdがないことを確認した。

## 優先順位

以下のA/B/Cは障害severityではなく、着手順の目安。各IDの正確な到達経路、固定SHAの行、期待効果、変更範囲、意味保存risk、既存検証代表はリンク先分冊に記載した。

| 順 | 候補 | 根拠・期待効果 | 証拠と主な注意 |
|---:|---|---|---|
| 1 / A | **CQ-01 計画返却のtarget一括取得** | 同じ首都targetのQ1→Q30で全SQL **17→104**、cell/terrain/facilityが各1→30。対象読取は3Q→最大3程度へ縮小可能 | [実測](evidence/command-serialization-queries.json)、[Controller:656](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Http/Controllers/Api/CommandQueueController.php#L656)。null/秘匿/表示順、工作船追従を保持 |
| 2 / A | **T06 人口成長の2saveを局所統合** | 現行成熟fixtureでstage遷移900件に、施設/人口更新900＋versionだけ更新900。重複900SQLを減らせる余地 | [現行profile](turn-current-mature.json)、[Engine:1830](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CompleteTurnEngine.php#L1830)。**既知未採用案**。version増分・event順・trigger・途中観測を保持。汎用deferred writerは後段 |
| 3 / A | **T01/FND-02 地上item effect/catalogの反復構築を共有** | 現行42itemの検証済みcatalog構築が約**2.007ms/call**。通常の食料生産Nationで最大4回/N、電力等で増える | [CPU測定](turn-catalog-results.json)、[contract:247](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Domain/Secretary/SecretaryItemGameplayContract.php#L247)。設定代入/refresh/retryの無効化、fail-closed。FNDの下位効果を重複加算しない |
| 4 / A | **BU-01 地底catalog/manifest構築を共有** | 通常soloの一通過例でfoundation6read、bulk skipは4read/実行。最大1000実行なら4000read/decode、239MB相当の反復入力 | [地底分冊](battle.md)、[Catalog:1156](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundAlphaV1PlayerCatalog.php#L1156)。59,720B/52skills/10statuses。request内のcanonical catalogを共有、抽選/報酬を省略しない |
| 5 / A | **BU-02 装備rows・definition・combat aggregate共有** | 状態返却で同じequipped集合を3回、公開statusで2回SELECT。各々1回のsnapshotを渡す余地 | [地底分冊](battle.md)。装備mutationの前後/貸出元更新を跨がない。rating/品質/HP/公開項目を保持 |
| 6 / A | **AP-01 露店のSQLページング** | 全active A件のget→decode/filter/sort→slice。取得/投影をpage_size+1へ | [API分冊](api-db.md)、[Market:37](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundMarketService.php#L37)。JSON型/欠損/quality/購入条件/id tieとhas_more完全一致、plan未測定 |
| 7 / A | **CQ-02 bulk埋立ての沿岸集合判定と不要配列削減** | 全浅瀬lock/get＋適格RセルごとEXISTS。候補10000の現行bulkPlanは捨てwrapper19940個/追加**7.68MiB** | [bulk測定](evidence/command-bulk-plan-microbench.json)、[Service:884](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CommandQueueService.php#L884)。全候補数/snapshot/overflow/ペア順を維持、先頭30打切りは不可 |
| 8 / B | **CQ-03 工作船fact共有** | 現行は成功3M、catch fallback含む最大4M。同3定義ならShip SELECT9/最大12を1程度へ | [command分冊](command.md)。旧NQ02の更新確認。登録/Turnのfresh lock・owner/world/HP/位置は維持 |
| 9 / B | **AP-02 Boost管理のseries N+1** | 1series/3occurrencesで同series4 SELECT/全7 SQL。最大500管理行に比例 | [実測](evidence/api-boost-series-queries.json)、[Service:56](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/BoostScheduleService.php#L56)。distinct series batchと捨てるpublic projection除去。管理限定で頻度は低い |
| 10 / B | **AP-09 ログmetadataをrowで一度decode** | 1000×1626Bの限定CPUで3decode→1、中央値**25.348→8.655ms**、hash一致 | [測定](evidence/api-metadata-microbench.json)。SQL不変。公開/本人filter、destination名、malformed例外と順序を保持 |
| 11 / B | **UI-01 地底画面の遅延読込** | 隔離buildで初期entry gzip **193934→123824B（36.2%減）** | [測定](frontend-bundle-results.json)、[画面分冊](frontend.md)。初期JS量の測定でありLCP/FPSではない。初回地底取得、direct URL、失敗/読み込み状態を検証 |
| 12 / B 条件付き | **T02 海底消防候補を近傍19hexへ** | 全Cセルsortを火災成立F回。9216 scalar cell×50回:中央値326.34→1.372ms、候補id順一致 | [測定](turn-undersea-index-results.json)。現行radius2の場合。F=0なら利益0。owner/距離/最新資金/一回支払を維持 |

中順位の残り: **CQ-04** 記念碑対象の1+3N照会を集合化、**CQ-06** 既読World/Rulesetと不要前検証投影の共有、**T03** 油田の同残高再SELECT、**T04** 食料残高2P SELECT→集合lock、**T05** phaseに対応したcapacity source読取、**T11** lifecycleの未使用全status投影、**T12** chunk versionの16→1 UPDATE（このfixture）、**AP-03** chunk付近の船/休眠首都へ絞込、**AP-04** rankingの所有cell二重hydrateをaggregateへ、**AP-06** 図鑑badgeの全詳細生成削減、**AP-07/08** 固定重複read/露店補助集約、**BU-04** 貸出報酬の4→2 SELECT/借用人。いずれも正当性の再検証は残し、静的効果と実測を分ける。

低順位/条件付き: **CQ-05** queue位置UPDATE（上限30）、**T07** 探索船の最新視界/宝箱index、**T08/09** 弓/地盤沈下の集合走査、**T10** RNG表現/engine、**BU-03** AI選択再利用、**BU-05** stat/modifier再計算、**FND-01** 船catalog parse、**AP-05/10/12** purge/画像保持/監査compact、**AP-11/13/14** 登録退避/外部同期lookup/一回補填、**SUP-01/02** cold初期構築/島内船退避、**UI-02/03/04** 地図入力/独立read並行/asset派生map。処理内容や保持情報を変える短縮は候補へ入れていない。

## 今回の測定

| 対象 | 観測 | 何を証明しないか |
|---|---|---|
| 現行60×60成熟world、固定seedの公式Turn | **3977.031ms / 3355 SQL**。process_cells **3096.320ms / 3143 SQL**（SELECT18 / UPDATE2012 / INSERT1113）、SQL計1556.92ms | 人工の成長/施設遷移を多く含む1Nation fixture。本番時間/平均負荷ではない |
| phase profile | 全12phase、hydration/主要重複SQL/metricsを取得。[JSON](turn-current-mature.json) | CPU sampling profileではない。DB query.time以外の時間をすべて純CPUと呼ばない |
| 計画Q1/Q30、Boost管理 | 現行の件数増幅を各1caseで測定。各 **1test/9assert、1test/4assert成功** | candidate patch適用後の改善倍率ではない。cold/warm差でHTTP速度比較しない |
| 地底AI隔離試作 | 48戦で全結果hash/RNG呼出一致、AI select回数が約34〜37%減るfixture | 全boss/覚醒/free-action分岐の同値保証ではない。時間は単発・instrument入り |
| 地底catalog | 16回構築9.026ms→1回を16参照0.544msの短い例（別runも保存） | 全探索/HTTPが同倍率速いという意味ではない |
| Turn RNG | 100k draws×3:現行中央値42.15ms、一括unpack20.75ms、native9.00ms | 全Turnでは通常数ms規模。nativeは旧結果と異なる、version設計/検証が必要 |
| asset画像検査 | 同1px GIF256解決:1.508ms/256検査、単純memo0.0106ms/1検査、hash一致 | 単純memoはfile/config即時更新を壊す反証あり**保留**。約142倍を製品効果にしない |
| フロント既存代表 | command表示のrefresh/古い応答/bulk確認 **3tests成功**。現行・isolated splitの各build成功 | 全画面回帰/ブラウザ描画や通信測定ではない |

環境は配置済みlocal Docker image `3ce451260a2a…` の **PHP8.5.8**、**PG18.4**、Node24.19.0、Vite8.1.5。source DockerfileのPHP8.5.12とは異なる。PHP純粋probeは必要sourceのみreadonly、network none。DB用は新規internal network＋tmpfsの専用合成DB。現行composer.lockを外部の隔離runtimeへコピーし、`--no-scripts --no-plugins`でinstallした。元imageの古いvendor/lockを現行と同一とは扱っていない。source/notes/lock hashは[provenance](provenance.json)。

成熟Turnは最初の1回がassert確認のみになり、report環境変数を指定した2回目で必要なphase JSONを取得した（どちらも1test/5assert成功）。RNG/undersea probeの検証入力修正、autoload/cwd解決前の失敗も元分冊に記録した。無意味な反復・全suite・CI・拒否済みの別件長期試験は実行していない。

## 既存改善の照合と反証

| 既報/疑い | 現行判定 |
|---|---|
| 5.8.2 Ruleset JSON decode cache、旧29.8→4.6秒 | **実装済み、再提案しない**。旧改善率を今回へ加算しない |
| 10/23の人口二重save・deferred writer | **既知未採用**。現行の重複更新を新規profileでも確認。旧2654→260/3.0→1.2秒は過去の別測定で、現行candidate効果ではない |
| NQ-03 停止軍艦の二重Nation lock | **修正済み**。成功lock済モデルを再利用する現行codeを確認 |
| NQ-01 queueのRuleset lazy | current definitionへのsetRelation、船追従commandの固定セルprojection除外で旧指摘はそのまま成立しない |
| NQ-02 工作船lookup / NQ-04 queue圧縮 / NQ-06探索船 | 残存経路として再照合。工作船の現行call数は旧資料2M/3Mから3M/4Mへ更新 |
| 陸地距離memo、ship一覧batch、中央施設上限共通化、怪獣/船occupancy index、天候bulk、Territory batch、receipt rollup、借用projection cache、skip容量batch | **現行に存在**。追加案として重複計上しない |
| SurfaceShipControllerで船ごとcatalog parse / 地形変更ごと余分Facility SELECT | 前者はRuleset毎memo、後者は主要callerが先にfacilityをnull化。一般的N+1としては反証 |
| 画像resolveを丸ごとmemo / phase並列化 / monsterを一度ずつ動かす / private応答shared cache | file/config即時更新、処理順・再行動、秘匿等を変えるため不採用/保留 |

過去資料は本体の現役testing/release文書と、外部管理庫の非archive記録に限定。notesの固定SHAは `b78efc726646bc196534e8627755efc4b541e038`。過去の未merge/未修正表記を現在の根拠にしない。既存文書の「乱数消費順を維持」は今回の明示許容を上書きしない。

## 意味保存とRuleset

抽選確率・ゲーム効果・資産/進行・owner/Worldの権限・秘匿、transaction/lock、retry/冪等性、event/receiptの診断可能性を維持する。**最適化前後の将来の乱数結果は一致不要**。同じコード版・初期状態・seedから同じ結果を再現でき、当該コード版/Ruleset/seedを診断に結び付けられる性質は維持する。

canonical immutable catalog共有、SQL batch、同じ集合の計算量削減では基本的にRuleset更新は不要。native RNG engineの変更や抽選の表現を変える案はBehaviorとしてversionを明示する候補。HMAC labelのdomain分離、unbiased bounded integer、private seed、再現vector、platform依存、manual retry境界を検証する。必要な次世代draftにまとめ、使用済snapshotを書き換えず、第二の設定authorityや世代を増やす迂回をしない。今回は提案だけで実更新しない。旧版との乱数完全一致を理由に候補を棄却しない。

World全体snapshotを古いcapacityへ置換する、mutable残高をTurn全体cacheする、途中eventを跨いでaudit順を変える、検証/診断を削る、保持履歴を短縮する、mutationを非同期/別phaseへ動かす案は意味保存を満たす根拠がないため採らない。

## 推奨する実装・検証の順

1. CQ-01を単独の局所serializer改善にする。今回の同座標30件とdistinct/null/monument/船/地下の代表で返却同値と対象SQL削減を確認。
2. T06は人口成長の二重saveだけを局所化して確認。version/全保存state/audit metadata/順を比較してから、必要性が実測で残る場合にbounded writerへ進む。
3. T01/FND-02とBU-01を各canonical catalog内の短命共有として別々に扱う。設定差替え、retry、新旧payloadと異常設定を確認し、cache-hitのために毎回巨大payloadをhashしない。
4. BU-02、AP-01、CQ-02を独立した差分にする。資産/lock/replay、page境界、bulk snapshot/overflowを既存代表で確認し、SQL数だけでなく取得row数/CPU/peak memoryを測る。
5. AP-02/09、CQ-03/04、T03/12など局所案を続け、World/船/履歴の規模依存案は合成件数を独立に増やして一度比較する。purge/画像削除の最適化は後順。RNG交換は小さな局所改善を済ませてから判断。

最終的に現行PHP8.5.12、実際の件数/利用頻度、DB plan/lock待ち/WAL・IO、HTTPと端末traceが必要。これらは本番操作禁止と今回の代表測定範囲から**未検証**であり、未確認を問題なしへ読み替えない。測定した局所倍率や候補SQL削減を合算した全体速度予測は提示しない。

## 成果物・再現・残る制約

主分冊: [command](command.md) / [turn](turn.md) / [battle](battle.md) / [API・DB](api-db.md) / [foundation](foundation.md) / [frontend](frontend.md) / [supplement](supplement.md)。全体台帳は[coverage-ledger.csv](coverage-ledger.csv)、file単位照合は[source-coverage.csv](source-coverage.csv)。

`scripts/`、rootの`*-bench.php`/`battle-probe.php`、`evidence/`と各JSONに測定器・fixture・生SQL・assert結果を保存した。[再現案内](REPRODUCE.md)に安全な隔離手順と入力上限を記載。これらは調査専用で、製品へ適用した差分ではない。`frontend-prototype.diff`も隔離試作。

残る操作ブロッカー、拒否・承認待ち・実行結果不確定の操作は**なし**。本番、実データ、OCI、認証情報、長期負荷/全分岐、PG planと実端末の未確認は上記に記録した。製品worktreeの最終不変確認は`verification.json`。配布用ZIPはruntime/vendor/node_modules/build/generated画像を含まず、レポート・台帳・測定器・証拠だけを収録する。

## 追加：DBを作り直す案も含めた比較

12:47の本人指示を受け、上記の先行調査本文は保持し、大改造も比較した[DB再設計補遺](DB-REDESIGN.md)を追加した。固定SHAと意味/確率/同版再現の条件は同じ。**追加は静的な設計比較で、新案の性能実測は0件。製品・schema・実DBは変更していない。**

| 比較軸 | 局所案→段階案→抜本案 | 現時点の評価 |
|---|---|---|
| セル保存 | 重複save統合→dirty列別write-set→typed Turn state/UnitOfWork | 実測の大量UPDATE/INSERTへ直接対応する有力案。途中read/trigger/因果順barrierが必要 |
| テーブル粒度 | 必要列SELECT→座標identity/stateの1:1分割→hot列分割 | row幅/WAL/HOT率とjoin増分を測って判断。23列typed行を分けるだけの利益は未証明 |
| chunk/世代保存 | 表示用chunk projection→canonical chunk JSONB→世代snapshot/pointer | SQL本数は減り得るがchunk全行lock・payload書換・FK/集計再設計が重い。[PG18 JSON設計](https://www.postgresql.org/docs/18/datatype-json.html#JSON-DESIGN)。抜本比較対象で採用根拠不足 |
| 集計/read model | GROUP BY→同期Nation summary/公開feed/視界model | 有力。Turn外writerも含むrevision/invalidationとprivateのfreshnessを保持 |
| ログ/イベント | 一度decode/batch→small identity coreと大payload分離→履歴partition | 表示だけでなくadmin再送global uniqueも担う。単純月分割/古いログ削除は反証 |
| 市場 | SQLページング→snapshot由来query列/式index/generated列→active/history分離 | 有力なschema案。snapshot原本を残し、型/欠損/品質既定値と動的購入条件を区別 |
| queue保存 | row集合化→ordinal/history分離・order vector→queue JSONB | 上限30。JSON化だけでは対象3Q SELECTを直さない。ID/番号の穴/replay/preview互換の移行費が大きい |
| queue競合 | lock内短縮→限定操作のWorld共有fence＋Nation/queue排他 | 異Nation編集の待ちを減らす可能性。addは日課Pd付与、bulkは共通浅瀬lockがあり一律共有化不可。全writer検証待ち |
| 戦闘境界 | catalog/装備/投影短縮→結果単一write→immutable入力/lock外CPU/CAS決算 | 競合実測があれば比較価値。全依存revision、時間、boost、lease、最終receipt採番が必要 |
| asset/receipt | 集合read/unique直接lock→typed operation header/payload→wallet統合 | owner/World/ledger/保持が異なる。table削減目的の汎用JSON財布は反証 |
| 全event sourcing | 現診断の整理→delta/snapshot→event-only canonical | 現auditは完全再生journalでなくcompactもある。書込/保持が増え、性能策として現時点の推奨根拠なし |
| transaction/分散 | 現single transaction改善→fence/入力世代→phase/chunk並列・別DB | 途中の残高/占領/船/怪獣を相互観測するため、部分commit/並列化は同値証明が先 |

大改造で守る必要がある実コード上の要点:

- cell IDは船・怪獣・首都・宝のFK先。chunk JSONへ詰め直すならidentityと制約を残す設計が必要。
- auditには管理操作のrequest_id global uniqueと保存結果再送がある。battleもglobal ID/FKと(profile,request) uniqueを持ち、日付partitionへそのまま移せない。[PG18 partition制約](https://www.postgresql.org/docs/18/ddl-partitioning.html#DDL-PARTITIONING-DECLARATIVE-LIMITATIONS)
- 地底rollupは「profile lock後にID採番」と昇順/CACHE1 sequenceを前提にする。CAS準備でbattle IDを先取りすると、後着する低IDがverified prefixから漏れ得る。採番は最終決算lock内に残す。
- profileにCAS用のmonotonic revisionはなく、秒精度updated_atでは代用不可。既存prepare/ensureにはwriteもあり、そのまま先行commitへ移せない。
- 初期状態＋同コード版＋seedの再現性を守ることと、過去全入力/全eventを永久保存することは別。無限のrequest ID台帳や第二のRuleset authorityは足さない。

移行は、旧sourceを正本にしたexpand→ID/revisionを保つ小batch backfill→同transactionの派生更新/guard→shadow同値照合→必要なら停止下cutover→検証後contractの順で比較する。切替前は旧readへ戻せる。切替後は旧表が最新mutationを表現できる場合のみrollbackし、できなければroll-forward。古いbackupへ戻して新しい資産変化を消す方式は採らない。

実装方式は先取りしていない。まず局所改善を比較基準にし、それでもrow量/lock/WALが支配するときに段階案を測る。chunk canonical化/CAS/partition/event-only化は、それぞれの利益が複雑さと運用負担を上回る証拠が必要。詳細は[Turn/map](db-design-turn.md)、[API/log/read model](db-design-api.md)、[combat/assets](db-design-battle.md)、[queue/fence](db-design-command.md)。

## 納品時の未確認区分とLibrary状態

統合台帳1,057行の**確認列**は、静的確認済み1,055、別担当1、未確認1。**判定列の「未確認」は2行**で、うち1行はcommand担当からTurn担当への受渡し（Turn分冊で扱い済み）、残1行はDB実行計画/ネットワーク負荷の未確認。これらは全分岐動的実行の率ではない。limit列に「未」を含む行は機械集計で1,014行あり、PG plan、本番サイズ、同時負荷、全入力分岐等の不足を明記している。残43行が全面的な実証済みという意味でもない。[区分の正確な集計](unverified-counts.json)を添付した。

上の「残る操作ブロッカーなし」は先行調査完了時の状態。その後に依頼されたLibrary保存は、正規の複数ファイル用helperが`hosted apps tools/list`で **networkエラー**となり停止した。prepare/finalizeの結果やlibrary_file_idは取得できておらず、**Library保存未完・確認済みID 0件**。自動承認の拒否ではなく、ネットワーク詳細原因は未特定。順序付きの正規batchを一度だけ試し、別経路への迂回や自動再試行はしていない。接続の回復後に承認済み保存を完了する必要がある。[納品状態](delivery-status.json)。外部共有/公開/repo pushは行っていない。
