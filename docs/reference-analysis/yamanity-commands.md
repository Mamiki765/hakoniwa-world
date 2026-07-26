# やまにてぃ コマンド（計画）処理

## 定義方式

ユーザー操作はPlanと呼ばれる。PlanConst::PLAN_LISTが文字列keyから29個の具象classへmappingし、各classの定数が表示名、費用、入力項目、解放発展点を持つ（app/app/Entity/Plan/PlanConst.php:35-77、Plan.php:39-64）。巨大switchではないが、PHP classと中央registryによるコード駆動である。

## 一覧

| key | 表示名 | 費用（億円単位） | 主な入力 |
| --- | --- | ---: | --- |
| grading | 整地 | 5 | 座標 |
| ground_leveling | 高速整地 | 100 | 座標 |
| landfill | 埋め立て | 150 | 座標 |
| excavation | 掘削 | 200 | 座標 |
| afforestation | 植林 | 50 | 座標 |
| deforestation | 伐採 | 0 | 座標 |
| construct_farm | 農場整備 | 20 | 座標 |
| construct_farm_dome | 農場ドーム化 | 2000 | 座標 |
| construct_factory | 工場建設 | 20 | 座標 |
| construct_large_factory | 工場拡張 | 1000 | 座標 |
| construct_mine | 採掘場整備 | 300 | 座標 |
| construct_oilfield | 油田発掘 | 200 | 座標 |
| construct_park | 公園整備 | 3000 | 座標 |
| construct_missile_base | ミサイル基地建設 | 300 | 座標 |
| construct_seabed_base | 海底基地建設 | 2000 | 座標 |
| construct_transport_ship | 輸送船建造 | 50 | 数量 |
| construct_battleship | 戦艦建造 | 500 | 数量 |
| construct_submarine | 潜水艦建造 | 2000 | 数量 |
| removal_facility | 施設の撤去 | 0 | 座標 |
| firing_missile | ミサイル発射 | 20/発 | 座標、数量、対象島 |
| firing_high_accuracy_missile | 高精度ミサイル発射 | 50/発 | 座標、数量、対象島 |
| foods_transportation | 食料輸送 | 数量依存 | 数量、対象島 |
| funds_transportation | 送金 | 数量依存 | 数量、対象島 |
| resources_transportation | 資源輸送 | 数量依存 | 数量、対象島 |
| reinforce_battleship | 戦艦派遣 | 船数依存 | 数量、対象島 |
| reinforce_submarine | 潜水艦派遣 | 船数依存 | 数量、対象島 |
| cash_flow | 資金繰り | -10（資金が10増加） | なし |
| attract_activities | 誘致活動 | 1000 | 数量 |
| abandonment | 島の放棄 | 0 | なし |

根拠は各 app/app/Entity/Plan/OwnIsland/*Plan.php のKEY/NAME/PRICE定数、一覧順はPlanConst.php:37-67。

## 解放条件

既定は発展点0。埋め立て5,000、掘削10,000、高速整地100,000、海底基地400,000、潜水艦建造/派遣1,000,000、大工場1,200,000、農場ドーム4,500,000で解放される（DevelopmentPointsConst.php:7-20と各Plan::EXECUTABLE_DEVELOPMENT_POINT）。

PlanService::getExecutablePlansはこの条件でUI候補を絞る（PlanService.php:14-40）。しかしAPIのPUTはPlans::fromJson成功だけを見ており、送信されたplan keyが現発展点で解放済みかを再検証しない。実行class側の個別検査有無に依存する。

## キュー

Plans::initはCashFlowを30個作る。1turnに原則1件をshiftし、末尾へCashFlowを補充する（Plans.php:26-35,77-90）。無効地形や資源不足等でisTurnSpending=falseなら次の計画を同turnに試す。数量指定は実行後amountが1以上なら先頭に戻り、末尾を1件落とす（103-110）。

UIはPlanController/PlanListで配列を並べ替え・削除し、PiniaのputPlanが配列全体をJSON文字列にしてPUTする（MainStore.ts:132-155）。APIは現在turnのisland_plans行を全置換する（Api PlansController.php:36-51）。

## 失敗とログ

Plan具象classは資金不足、資源不足、不正セル、不正島、基地/船不足等をAbort*Logとして返す。多くの失敗はturn非消費のため同turn内に次へ進む。validation errorはWebApiの400、所有島違反は403、存在しない島は404である。

## 同時更新上の問題候補

計画PUTはlatest Turnを取得してから行を更新するだけで、transaction/lock/version条件がない。execute:turnが同じplan rowを読んだ直後にPUTが来ると、次turnへ反映されない、または旧turn行だけ更新される競合があり得る。新作ではplan_queueにversionまたはeffective_turnを持たせ、compare-and-swapまたはrow lockを使うべきである。

## 新Plan追加の変更点

1. Plan派生classとexecuteを追加。
2. PlanConst::PLAN_LISTへ登録。
3. 必要ならDevelopmentPointsConst、Cell type、LogRowを追加。
4. 入力flag/表示文字列を定数化し、PlanService経由でUIへ公開。
5. API validation、domain test、UI表示を確認。

メタデータは一部定義駆動だが、効果は任意PHPである。新作ではkey、価格、入力schema、解放条件、効果handler ID、ruleset versionを安全なregistryへ分ける。
