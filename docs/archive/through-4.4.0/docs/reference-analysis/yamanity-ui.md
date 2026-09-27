# やまにてぃ UIとマップ描画

## 構成

Vue 3.3.4、Pinia 2.0.36、Axios、Vite 4.3.8、Tailwind CSS 3.3.2を使う（app/package.json:8-31）。Bladeがサーバー取得データを @js でVue page componentへ渡し、componentがPiniaへ格納する。SPA routerはなく、画面遷移はLaravel routeである。

主要画面はランキングtop、観光用島詳細、所有島の計画、登録、設定、help/release/privacy。専用管理画面はない。

## マップ取得単位

自島または観光島の初期表示はControllerが最新turnのisland_terrainsを1件取得し、全225セルをBlade propsへ埋め込む（Web DetailController.php:27-38,70-119、PlansController.php:31-40,76-139）。

計画画面で他島を選ぶ場合、PiniaのgetIslandTerrainがGET /api/islands/{id}を呼び、その島の全225セルを取得する。一度取得した島はtargetIslands要素のterrainsへcacheし、再取得しない（MainStore.ts:156-179）。HTTP cache header、ETag、turn/versionによるcache invalidationは確認できない。

## 描画

IslandViewerはheight行×width列を二重v-forし、各セルをimg要素で描く（IslandViewer.vue:1-22）。奇数/偶数行の左右に半セルpaddingを入れたoffset六角配置で、CSS gridは15列を直接記述する（同:113-149）。

セル検索は描画のたびにterrains.filterして座標一致を探す（67-71）。225セルなら許容され得るが、Nセル描画に対し線形検索を繰り返すため大規模mapでは二乗的負荷になる。

画像URLとtooltip文はPHP EntityがwithStatic=true時に各セルdataへ含める（Cell.php:58-70）。ブラウザはdata.image_pathをsrcに使う（IslandViewer.vue:12-18）。

## 操作とレスポンシブ

- hoverで画像・infoのtooltipを表示（IslandViewer.vue:23-33,72-97）。
- clickでPinia selectedPointを更新し、計画入力と連携（98-101）。
- 画面幅1024px未満をmobileとし、tooltip位置を補正（52-54,77-89,102-106）。
- map幅は最大496px、セルはaspect-square。
- zoom、map内部scroll、drag、viewport仮想化はない。
- 選択セルの強調はIslandViewer自体にはなく、計画component側の状態参照に依存する。
- PC/モバイルでlayout調整はあるが、mapデータ量は同じ。

## ログと島情報

StatusTable、RankingViewer、AchievementIcons、IslandBbs、LogViewer等が島状態を表示する。LogViewerはJSON断片をparseし、textはVue補間、link/styleはattribute bindingする（LogViewer.vue:15-35）。Markdown componentはsanitizerを使うが、ログ描画とは別である。

## 共通世界への転用評価

現行のpage/component分割、Pinia、クリック/tooltip、座標interfaceは参考にできる。しかしIslandViewerは15×15固定CSS、全件props、img DOM、線形セル検索を前提とし、60×60以上の共通世界へそのまま転用できない。

必要な再設計:

- viewportとprefetch marginからchunk keyを求める。
- terrainを Map<coordinate, cell> と chunk cacheでO(1)参照する。
- pan/scroll/zoomを持つCanvas/WebGLまたは仮想化DOM rendererを比較する。
- request取消、重複排除、turn/version別cache、LRUを持つ。
- 首都座標を初期camera中心にする。
- owner/nation style、国境線、layer、未取得領域、loading/errorを表示する。
- 画像pathをサーバーの各セルpayloadへ重複させず、definition catalogを別cacheする。

60×60は3,600セルでDOM imgでも初期検証は可能だが、動的拡張・animation・overlayを考えると全世界DOM常駐を設計前提にしない。
