# 画面・読み込み・描画・asset補完調査

対象: main `2e93ec918774ad64d787aba99fc59a7bc3e54d16`（5.8.3）。製品sourceに変更なし。ビルド出力・試作・測定は本調査ディレクトリ内だけ。APIのサーバー側詳細は[API/DB分冊](api-db.md)、計画送信は[コマンド分冊](command.md)、戦闘の計算は[地底分冊](battle.md)。画面名やsourceの長さ自体を遅さの根拠にしていない。

## UI-01: 地底画面を必要時に読み込む（推奨: 中、初期表示向け）

`product/resources/js/app.ts:2` がAppを直接importし、`App.vue:6–37` が管理画面・地底画面までstatic importする。`UndergroundPanel.vue:2–30` はstory4件・AI・装備・市場・戦闘表示などをさらにstatic importする。現行の本番用ビルドはentry JS一つに結合され、地底を開かない公開ロビーもそのJSを取得・解析する。

**今回の隔離ビルド比較**（Node24.19.0 / 配置済みVite8.1.5、同じnode_modules、依存更新なし）:

| ビルド | entry raw bytes | entry gzip bytes（Python gzip level9） | 遅延JS |
|---|---:|---:|---:|
| 現行source | 644,278 | 193,934 | なし |
| 隔離試作: UndergroundPanel一つだけdynamic import | 395,504 | 123,824 | 252,385 raw / 72,315 gzip bytes |

初期entryのgzip入力は70,110bytes（36.2%）減った。地底まで全て開いた時の合計gzipは196,139bytesで、現行より2,205bytes増える。最初の地底遷移では新しいchunk取得が発生する。**初回表示時間・CPU解析時間・LCP・モバイル速度は測っていない**。chunk warningが消えたこと自体を性能保証にはしない。build成功は読み込み挙動の全面同値証明ではない。

変更範囲はAppのimportとasync component指定だけの隔離試作（`frontend-prototype.diff`）。実装を採用する場合、direct `/underground`、auth未取得時の遷移、load失敗/再試行、CSS/非AI背景/credit、初回story、フォームの状態保持を既存AppUnderground代表と遅延chunk条件で検証する。ロード中に操作できる形へ勝手に変えない。親のstory直接利用分はなおentry側にあるため、全storyを一括移動した見積りではない。管理画面の追加splitは測定しておらず、この改善率へ足さない。

証拠: `frontend-build.log`、`frontend-split-build.log`、`frontend-bundle-results.json`。最初の試作build呼出はcwdを一階層上にしたためentry解決前に失敗した。正しい隔離rootで実行した最終buildが上表であり、製品には一度もpatchしていない。

## UI-02: 地図の画面内検索・pointerイベント処理（推奨: 低、実ブラウザ計測後）

`HexMap.vue:85–110` は全loaded cellをpixel化し、pan/zoomごとに全N個をfilterする。`updatePan:378–409` は各pointermoveでpan更新とrange emit、pinchも同様。現在は表示外DOMを作らない点、`mapState.ts:101–109` のloaded/in-flight dedup、世代管理が既にある。これを未実装のvirtualizationや重複networkとして再提案しない。

純粋なscalar filterだけを再現した短い補助測定では、9,216セル×120panで1,105,920predicate、13.08/14.03/13.28ms（3回）。Vue proxy・DOM・paintを含まず、1event平均は約0.11msであり、このループだけを主因と認定する証拠はない。60×60では432,000predicate/120event、中央値5.08ms。`frontend-map-probe.json`。

画面が重い実測が出た場合に、行/座標indexからviewportに入るセルだけを選び、最終paddingと元の列挙順を維持する候補がある。gridToPixelのcapital原点はmapの各cellで再算出する必要がなく、computedの外側で一度求められる。pointerのrequestAnimationFrame集約はpinch anchor、最後のpointer位置、pointer capture/drag後click抑止、keyboard/focus、range取得の終端保証が必要。全世界fitでは意図的にN個を表示するため、ズームによる集約表示へ変更するのは別の設計gate（C-04）に当たる。本調査から勝手に追加しない。

## UI-03: 独立した初期readを重ねる（推奨: 低〜中、RTT依存）

`App.vue:408–410` は公開ロビーの2段read（world/announcement、次にsummary/rank/news/events）が終わってから本人`/me`を開始する。本人readをロビーと並行開始し、現在の401/部分失敗表示・busy解放・direct地底遷移判定を保ったまま合流させれば待ち時間の重複を減らせる。SQL件数は減らず、RTTの実測も未実施。daily-login等のmutationを無差別に並列化するとPd表示の新旧が逆転し得るので、この候補は独立したread開始だけに限定する。

`openOwnIsland:1130–1160` は地図表示後に配布倉庫GET→development-opened POST→daily-quests GETを逐次実行する。後2つの依存順は維持し、配布倉庫readだけを重ねる余地。busy、通知順、世代/ページ切替、エラー表示が同じ意味になることを検証する。既存のPromise.all/Promise.allSettledやmap chunk並列取得を改めて新提案として数えない。

## UI-04: assetの派生mapと画像検査（推奨: 低、一部保留）

`AssetManifestResolver.php:346–366` はsceneManifest JSON自体をinstance内cache済みだが、sceneAsset呼出ごとに全登録assetの検証mapを作り直す。`sceneAsset:374`→`sceneAssets()`、`UndergroundScenePresenter:64–91`の複数scene/actor投影で反復する。既に固定したsceneManifestから作る検証済みmapだけをinstance内で共有する案はO(A×参照回数)をO(A+参照回数)にできる。config、AI閲覧設定、creditの最終判定までglobal cacheしない。実際のscene応答時間は未測定。

`validatedPath:266–303` は同じpathでもgetimagesizeを毎回実行し、secretary fallbackは:313でさらに再検査する。256回同じ1px GIFを解決した補助測定は、中央値1.508ms/画像検査256回、単純memo wrapperは0.0106ms/1回、返却hash一致だった（`asset-probe.json`）。PHPのstat cacheやOS file cacheがあるため、stat関数呼出数をそのままdisk I/O数とは呼ばない。

**単純な全結果memoは保留**。既存 `TileAssetTest:59–60,84–94,217–220` は同じresolverでallowed_extensions変更やfile追加を直ちに観測する。`pathForFilename`は実配信前にpath/拡張子/MIME/寸法を検証する安全境界でもある。測定の約142倍をそのまま製品の改善率にせず、config/file変更を観測する現行意味、fallback、テーマ、失敗log、URL版の更新と、配信時のfresh validationを保持できる局所投影contextが定まってから検討する。画像検証を丸ごと削る提案はない。

## 確認したその他の画面処理

- `api/client.ts`の受付発行→mutationは意図的な二段。未知/期限切れrequestを新requestとして自動再実行しない、エラー後のtoken保持とreload要求を維持する。速さのための検証省略は候補にしない。
- `ownBoardUnread.ts`は1分timerが一本、board mounted時はcomponentのreportを使い、hidden時はnetworkを送らない。既読は表示済み分だけ。二重pollと誤認しない。summaryのfallback pollもturn未変更ならmap全reloadしない。
- `boosts.ts`は時刻/画面条件で取得、既存server boundaryに従う。TTL変更による古いboost表示は意味変更なので提案しない。
- 日時/通貨/名前/船指示・story markup・STP draft・AI editorの計算は入力に対して線形または小さな固定catalog。重い追加IOを確認しなかった。ランキングsortは一覧変更/検索条件時だけcomputed再計算。実Nation数と端末traceは未取得。
- 戦闘structuredログはround/actionをcomputedで投影し、再生timerは1actionずつ進む。詳細表示の自動省略やlog精度削減はしない。simple旧形式のround×actions filterだけはBU分冊の低順位候補に接続する。
- 倉庫・露店・会話・管理フォームは必要画面で取得する。server側の全件取得やN+1はAP分冊へ。preview harness/test helperは通常product入口ではなく、機械目録で区別した。
- 公開APIは30秒+stale-while-revalidate、private APIはCookie vary/no-store。private応答をshared cacheする案は除外。secretary画像のimmutable cacheと問い合わせ画像のprivate no-storeを維持する。HTTP圧縮・Apache worker/opcacheの本番有効性は本番操作禁止のため未確認。Dockerfileだけから未設定/未有効と断定しない。

全80 frontend sourceの処理群と確認/測定の区別は `frontend-coverage.csv`、asset/infraは `supplement-coverage.csv`。本分冊は全画面のbrowser trace、実HTTP latency、実asset IO、Android/iOS描画を検証した成果ではない。
