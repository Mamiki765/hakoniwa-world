# やまにてぃ Laravel責務分離

## 実在する層

| 層 | 有無 | 実際の責務 |
| --- | --- | --- |
| Controller | あり | route入力、Validator、認可、query、transaction、response/view組立 |
| Form Request | なし | Controller内のValidatorで代用 |
| Model | あり | Eloquent relation、cast、Entityへの薄い変換 |
| Entity | あり | ゲームルールの大半 |
| Repository | なし | EloquentをController/Commandから直接利用 |
| Service | あり | 寸法・所有島、計画UIメタデータ、Yahoo client builder |
| Action | なし | なし |
| Job | なし | queue処理なし |
| Console Command | あり | turn更新、古いsnapshot整理 |
| Observer | なし | なし |
| Domain Event | なし | Event名前空間は災害を表し、Laravel Eventではない |
| Listener | なし | なし |
| Policy | なし | Controller内ID比較 |
| Middleware | あり | 認証基盤、maintenance、debug限定、CSRF |
| API Resource | なし | 配列を手作業でresponseへ |
| Seeder | あり | 初期Turn、開発用島 |
| Factory | あり | Model test data |
| DTO/Value Object | 一部 | Point、各Result、MaintenanceInfo |
| PHP Enum | なし | 文字列定数classで代用 |

## ゲームルール

主要ルールは app/app/Entity に置かれる。

- Status: 生産、消費、資源上限（Status.php:86-179）。
- Terrain: 15×15操作、近傍、集計、セルturn（Terrain.php:40-441）。
- Cell具象型: 成長、施設効果、怪獣/船行動。
- Plan具象型: 費用、条件、地形遷移、他島計画。
- Disaster具象型: 発生率と被害。
- Achievement、LogRow: 受賞条件と表示ログ。

この分離によりControllerが直接災害や生産を計算することはない。ただし登録Controllerは初期Terrain/Status/Plansの生成と各Modelへの保存を全て担当し、改名Controllerも資金、地形内船名、履歴更新を扱う（Register IndexController.php:47-115、Api DetailController.php:60-133）。

## 永続化

ModelはActive Recordとして使われ、RepositoryやUnit of Workはない。ExecuteTurnがquery、Entity変換、全ゲームフェイズ、次snapshotのModel生成、保存を386行の単一Commandに集約する。DB transactionはLaravel facadeを直接呼ぶ。

EntityはModelへ依存する箇所が多い。Plan::executeやCell::passTurnの引数にIslandとTurnのEloquent Modelが含まれ、純粋なドメイン層ではない（Plan.php:245、Cell.php:121-124）。Status::fromModelもIslandStatusに直接依存する（Status.php:68-84）。

## 定義の集中と分散

- typeからCell classへの復元はCellConstのmatchへ集中。
- plan keyからclassへの復元はPlanConst::PLAN_LISTへ集中。
- 災害順はDisaster::DISASTERSへ集中。
- 個々の費用、発生率、容量、画像、属性、処理は各classの定数・methodへ分散。
- UI用plan metadataは具象Plan定数をPlanServiceが抽出する。

巨大switchより局所変更しやすいが、新しいCellではclass作成に加えてCellConst、Plan、画像、必要な災害属性、TypeScript型/表示を横断する。DB masterやversioned rulesetではないので、運用中の設定変更にはdeployが必要である。

## Turn内の責務

ExecuteTurnは次を全て担う。

1. 世代作成と全島ロード。
2. Model→Entity変換。
3. 維持人口集計。
4. 生産・計画・他島効果・災害・セル・表彰。
5. Entity→Model変換とsave。
6. 例外通知とprune呼出し。

処理の見通しはコメントで保たれるが、phaseごとの独立transaction境界、再試行、計測、部分実行、テストdouble注入が難しい。

## 参考にしたい点

- ゲームルールをControllerからEntityへ出す。
- Cellの能力をinterfaceと属性フラグで問い合わせる。
- mutation結果をExecutePlanResult、PassTurnResult等でまとめる。
- APIへstatic metadataを付加し、DB payloadには動的値だけを残そうとする。

## 改善案

- Application層にTurnCoordinatorと明示phaseを置き、DomainはEloquentから切り離す。
- Repository interfaceでchunk、nation、plan、eventをまとめて取得・保存する。
- Form Request、Policy、API Resourceでvalidation/authorization/serializationを明確化する。
- ルール定義をversion付きregistryへ置き、任意コード実行のない安全な定義形式にする。
- 外部通知はdomain event + transactional outbox + Jobへ移す。
- phase入力snapshot、seed、結果eventを明示し、再実行可能にする。
