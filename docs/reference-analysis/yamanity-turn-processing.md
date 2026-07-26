# やまにてぃ ターン処理

## 入口と起動

実行入口は ExecuteTurn::handle、Artisan signature execute:turn である（app/app/Console/Commands/ExecuteTurn.php:29-56）。次回予定時刻はturn生成時に現在時刻へTURN_UPDATE_MINUTESを加えて記録するだけで、予定時刻到来をCommand自身は検証しない（同:60-64）。

infra/app/cronは毎分schedule:runを記すがConsole Kernelのscheduleは空で、local imageへのcron登録・起動も確認できない。Cloud Buildは同一imageをCloud Run Jobへ配備するが、Jobを何が定期起動するかはリポジトリ外である。

## 確定処理順

| 順 | フェイズ | 根拠 |
| ---: | --- | --- |
| 1 | 最新Turn取得、turn+1作成 | ExecuteTurn.php:58-64 |
| 2 | 全有効島と現turnのStatus/Terrain/Plan、Achievement取得 | 66-104 |
| 3 | 全島の船・基地維持人口を所属国別に集計 | 106-110 |
| 4 | 島ごとに状態集計、生産・消費 | 112-128、Status.php:86-153 |
| 5 | 島ごとに計画キューの先頭基本行動を実行 | 129-141、Plans.php:84-113 |
| 6 | 他島対象計画を登録順に実行 | 143-186 |
| 7 | 島ごとに12種の災害を固定順で判定 | 188-203、Disaster.php:13-38 |
| 8 | 島ごとに全225セルのpassTurn | 204-208、Terrain.php:224-248 |
| 9 | 湖判定、状態再集計 | 210-215 |
| 10 | 人口0なら発展点半減・村を1つ生成 | 216-222、Terrain.php:404-424 |
| 11 | 連続資金繰りが設定値以上なら放棄印 | 224-231 |
| 12 | セル由来の他島イベントを実行 | 238-272 |
| 13 | 100ターンごとのターン賞判定 | 274-290 |
| 14 | 上限適用、状態賞、次turn snapshot・ログ・実績保存 | 292-357 |
| 15 | 放棄島を物理削除 | 359-364 |
| 16 | transaction commit後にprune:logs | 381-384 |

災害がセル処理より先なのは実装者コメント上の意図順と逆で、隕石後の湖判定都合による暫定順である（ExecuteTurn.php:199-206）。

## 収支

Status::executeTurnは最初に現在Terrainを集計し、人口から他島派遣を含む維持人口を引いた労働人口を、農業・工業・資源の設備容量比で配分する（Status.php:86-109）。

- 食料: 農場は環境係数0.3/0.6/1.2、ドームは常に1.2。人口×0.1を消費（111-146）。
- 資源: 実働資源容量×0.02を生産（129-131）。
- 資金: 資源で稼働可能な工業人口を制約し、×0.002を資金化、工業人口×0.02の資源を消費（133-137）。
- 発展点: 毎ターン round(人口/200) を加算（150、281-284）。
- turn末上限: 資金99,999、食料/資源9,999,999（166-179）。

施設容量や人口成長は各Cell classが保持し、セルpassTurnで変化する。怪獣はmonster_action_probablyと乱数の比較で行動をskipし得る（Terrain.php:224-245）。

## 計画、ミサイル、怪獣、災害

計画キューは30件。先頭を取り出しCashFlowを末尾へ補充する。turnを消費しない失敗計画等は同じturnに次を続行し、数量が残る計画は先頭へ戻す（Plans.php:77-112）。

他島への輸送、ミサイル、派遣は各島の自島phaseでTargetedToForeignIslandPlanへ変換し、全島の基本計画後に実行する。これにより先に処理された島だけが他島効果を即時受ける構造ではない。

災害順は怪獣出現、海賊、リヴァイノス、火災、地震、津波、噴火、台風、流星群、巨大隕石、暴動、地盤沈下で固定される（Disaster.php:13-26）。同一turn内の前災害結果を後災害が見る。

## 原子性と失敗

最新Turn作成から全snapshot・ログ・実績・放棄削除まで同一DB transactionである。例外なら全DB更新がrollbackされ、例外は再throwされる（ExecuteTurn.php:56-378）。成功commit後のprune失敗は既に進んだturnを戻さない。

例外通知はcatch内でnotification_webhook_urlへ同期HTTP POSTする。通知応答が300以上でもログのみだが、network例外が元例外を覆う可能性は静的には否定できない（366-378）。外部通知障害をturnから完全分離していない。

## 二重実行・再実行

明示的なmutex、advisory lock、Turn行のlockForUpdate、idempotency key、実行状態はない。turn列uniqueにより同じturn番号の二重commitは防げるが、並行処理は競合まで全計算を行い、どちらが成功するかに依存する。予定時刻の条件判定もない。

乱数はmt_rand、random_int、Collection::randomを混用し、seedとdraw結果を保存しない（Rand.php:5-10ほか）。rollback後の再実行は同じ結果を保証しない。

## 性能

- 全島をget()し、各島の225セルJSONを全復元する。
- status、terrain、plan、logs、achievementを島数分メモリ保持する。
- islandAchievementsはeager load後に島ごとに再queryし、N+1候補（ExecuteTurn.php:66-77,89-101）。
- 1 transactionが全島処理時間中DB connection/locksを保持する。
- batch、chunkById、queue、並列phaseはない。
- UIの島数対象一覧もIsland::get()で全件取得する（Web PlansController.php:73-75）。

## 新作で改善する要件

phaseを明示し、TurnExecutionを一意にlockする。入力snapshotと保存seed/ruleset versionを固定し、同じexecution keyの再実行をno-opまたは同一結果にする。世界拡大時はactive chunk・予約・イベント索引を使い、全世界全セルscanを前提にしない。外部通知はoutbox commit後の別workerへ送る。
