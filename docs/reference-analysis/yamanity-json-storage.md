# やまにてぃ JSON保存形式

## 保存単位

島地形は世界全体ではなく、島1つ・ターン1つを1 JSON documentとして island_terrains.terrain に保存する。15×15の225セルを平坦な配列にする（Terrain::toArray、app/app/Entity/Terrain/Terrain.php:63-77）。セル配列は内部では cells[y][x]、JSONでは行順に平坦化される。

復元時は15×15のnull配列を作り、各JSON要素のdata.pointを使って該当位置へCellを配置する（Terrain::fromJson、Terrain.php:80-100）。

## 地形JSON

永続化形の最小構造は次の疑似例である。

    [
      {"type":"sea","data":{"point":{"x":0,"y":0}}},
      {"type":"village","data":{"point":{"x":1,"y":0},"population":1000}},
      {"type":"forest","data":{"point":{"x":2,"y":0},"woods":100}}
    ]

Cell::toArrayがtypeとpointを定義し、具象クラスが可変属性を追加する（Cell.php:58-72、HasPopulation.php:55-61、Forest.php:57-63）。image_pathとinfoはwithStatic=trueのAPI/Blade出力だけに追加し、通常の永続化には含めない設計である。ただしExecuteTurnと登録は terrain->toJson(true) を呼び、第1引数はprivate表示制御でwithStaticではないため、DBにはstatic値を保存しない（ExecuteTurn.php:336-340、Register IndexController.php:74-79）。

## セルtypeと可変属性

typeは文字列IDで、CellConst::getClassByTypeのmatchへ列挙される（CellConst.php:76-125）。主な可変属性は次の通り。

| 分類 | 属性 |
| --- | --- |
| 共通 | point.x、point.y |
| 人口地形 | population |
| 農場/工場/採掘/油田 | foodsProductionCapacity、fundsProductionCapacity、resourcesProductionCapacity |
| 森 | woods |
| ミサイル基地/海底基地 | private時のみexperience、maintenanceNumberOfPeople |
| 怪獣 | hit_points、種類によりis_metalized、level、elevation |
| 船 | elevation、experience、damage、affiliation_id、affiliation_name、return_turn、maintenanceNumberOfPeople |

キーはcamelCaseとsnake_caseが混在する。constructorはPHP named argumentへ展開されるため、JSON key変更は互換性破壊になる（CellConst::getClassByType、CellConst.php:76-125）。

地形の所有者フィールドはない。各島JSONが島に属するため、島全体の所有を暗黙表現する。他島派遣船のみaffiliation_idを持つ。

## 計画JSON

island_plans.planは最大30要素の配列である。疑似例:

    [
      {"key":"grading","data":{"point":{"x":3,"y":4}}},
      {"key":"firing_missile","data":{"point":{"x":5,"y":6},"amount":2,"targetIsland":9}},
      {"key":"cash_flow"}
    ]

Plans::fromJsonはkeyをPlanConstへ渡し、point、amount、targetIslandを復元する（Plans.php:37-54）。toJsonはUI用static metadataを除き、各Plan::toArray(false)を保存する（Plans.php:57-64、Plan.php:203-232）。

## ログJSON

island_logs.logは「表示断片の配列」をJSON文字列で保持する。

    [
      {"text":"青島","link":"/islands/7","style":"font-weight: bold;"},
      {"text":"が発見されました！"}
    ]

LogRow::generateが文字列化し、Turn処理がそのままjson列へ保存する（IslandFoundLog.php:18-23、ExecuteTurn.php:342-349）。イベントtype、importance、actor、coordinateは正規化されておらず、後から機械検索しにくい。

## 上限と検証

- 225セルはHakoniwaServiceの15×15定数由来。JSON schema上の上限ではない。
- 計画30件はPlans::MAX_PLANSだが、fromJsonは件数を検証しない。
- 資金99,999、食料/資源9,999,999はStatusのPHP定数でturn末にtruncateする（Status.php:15-21,166-179）。
- セル個別値の上限は具象クラスへ分散する。
- json_decode失敗、未知type、重複座標、範囲外座標、欠落セルを明示検査しない。
- PlanService::isValidPlansは復元例外の有無だけを検証し、件数、座標範囲、development point、対象島、amount上限は確定しない（PlanService.php:43-51）。

## 新type追加時の変更箇所

少なくとも具象Cell class、CellConstのuseとmatch、画像、必要なPlan、災害ATTRIBUTE、フロント表示型を更新する。type別class復元は中央matchに集中する一方、効果・遷移・画像は複数クラスへ分散する。

## 新作向け評価

セル固有の疎な可変属性をJSON/JSONBへ置く考え方は参考になる。ただし共有世界では1島1巨大JSONを採用せず、world/layer/chunk/coordinate/owner等を通常列で索引・lock可能にし、metadataだけをversion付きJSON schemaへ置くべきである。未知key許容、schema version、migration、size limit、完全性検査が必要になる。
