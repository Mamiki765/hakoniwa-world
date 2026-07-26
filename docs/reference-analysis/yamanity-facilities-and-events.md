# やまにてぃ 地形・施設・災害・怪獣

## 定義方式

DB masterやSeederではなく、PHP具象class、中央match/list、属性配列を組み合わせる。画像URL、上限、生産、災害耐性、turn処理はコード定数/メソッドである。管理画面から変更できない。

## 地形・施設

| 分類 | type例 | 主な値・効果 |
| --- | --- | --- |
| 基本地形 | sea、shallow、lake、wasteland、plain、mountain、volcano | elevation、陸海、地形遷移 |
| 人口 | village、town、city、metropolis | 人口上限3,000/10,000/20,000/30,000を基礎とし発展点で増加 |
| 食料 | farm、farm_dome | foodsProductionCapacity。環境影響、ドームは最高環境扱い |
| 資金 | factory、large_factory | fundsProductionCapacity、資源消費 |
| 資源 | mine、oilfield | resourcesProductionCapacity |
| 自然 | forest | woods、資源化、防火等 |
| 軍事 | missile_base、seabed_base | experience、維持人口20,000、発射能力 |
| 公園/碑 | park、6種のmonument | 発展点産出、防災等 |
| 船 | transport_ship、battleship、submarine、pirate、敵艦 | 所属、経験、損傷、帰還turn |
| その他 | egg、out_of_region | 孵化、仮想範囲外 |

CellConst::getClassByTypeには45 typeが列挙される（app/app/Entity/Cell/CellConst.php:76-125）。各typeはIMAGE_PATHを持ち、主に /img/hakoniwa/hakogif 配下を参照する。Cell::ATTRIBUTEはland、monster、shipと、火災/津波/地震/台風/隕石/暴動/怪獣等の破壊可否、防火/防風/防波を表す（Cell.php:15-31）。

## 地形遷移

- 初期島は海から森4、荒地14、火山1、村2、平地7、浅瀬10を正規分布中心付近へ生成し、囲まれた浅瀬を湖へ変換する（Terrain::init、Terrain.php:103-131）。
- CellConst::getDefaultCellはelevation 1/0/-1/-2を山/荒地/浅瀬/海へ変換する（CellConst.php:128-135）。
- 災害・ミサイル・怪獣は属性とelevationに基づき荒地、浅瀬、海等へ変更する。
- replaceShallowToLakeは外周と接続しない水域を湖にする（Terrain.php:329-378）。

## 災害

| 災害 | 発生 | 対象・結果 |
| --- | --- | --- |
| 怪獣出現 | 人口帯別候補、都市セルごと0.002を既存怪獣数で半減 | 海岸に接する人口地形を怪獣化 |
| 海賊 | 島ごと0.01 | 人口8万ごと最大5隻、海/浅瀬へ |
| リヴァイノス | 現在0（TODOは0.002） | 高人口時、既存なしで海へ出現 |
| 火災 | 対象セルごと0.01 | 隣接防火セルがなければ荒地 |
| 地震 | 島ごと0.005 | 対象セルごと0.25で荒地 |
| 津波 | 島ごと0.015 | 周囲の防波セル数に応じ0.40から0へ |
| 噴火 | 島ごと0.01 | 既存火山/鉱山を山化、新火山と隣接被害 |
| 台風 | 島ごと0.02 | 周囲防風セル数に応じ0.50から0へ |
| 流星群 | 島ごと0.015、0.5で連続 | random座標、elevationを1下げる |
| 巨大隕石 | 島ごと0.005 | 中心海化、1hex降下、2hex対象破壊 |
| 暴動 | 食料0 | 対象セルごと0.25 |
| 地盤沈下 | 面積10,000超で0.03 | 浅瀬は海、平地高度は0.20で浅瀬 |

根拠: Disaster::DISASTERS（Disaster.php:13-38）と各 app/app/Entity/Event/Disaster class。これらの確率は環境変数ではなくPHP定数である。

## 怪獣・ミサイル

怪獣はMonster派生classで、type、表示名、画像、DEFAULT_HIT_POINTS、人口帯、移動/硬化等を分散定義する。MonsterConstが出現候補を人口から選ぶ。怪獣HP例は1から10で、上限はDB schemaではなくclassロジックである。

ミサイルはFiringMissilePlanとFiringHighAccuracyMissilePlan。価格は1発20/50、座標・数量・対象島を使う。発射可能セルはIMissileFireable interface、被害対象はDESTRUCTIBLE_BY_MISSILE属性で判定する。命中・誤差・経験増加等はPlan/基地classへ分散し、管理設定ではない。

## 施設追加手順と硬結合

新施設には、Cell class、CellConst match、画像、建設Plan、PlanConst、必要なLogRow、災害属性、集計interface、TypeScript/UI確認が必要である。新災害にはIDisaster class、Disaster::DISASTERS登録、属性追加または対象判定、LogRow、testが必要になる。

良い点はclass分割と能力interfaceである。不足はruleset version、DB/API master、管理変更、schema validation、包括testである。新作ではCellDefinitionと実行handlerを分け、未知typeの読み込み方と既存snapshot migrationを先に定義する。
