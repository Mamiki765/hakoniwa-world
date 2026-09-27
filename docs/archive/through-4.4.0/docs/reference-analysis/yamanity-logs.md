# やまにてぃ ログと履歴

## ログ種類

| 種類 | 保存先 | 内容 |
| --- | --- | --- |
| ゲームturn/島ログ | island_logs | LogRow::generate済みJSON断片とvisibility |
| ランキングtopログ | island_logsをglobal filter | 最近の全体出来事 |
| 島詳細ログ | island_logsをglobal/public filter | 当該島の公開出来事 |
| 所有島計画ログ | island_logs全visibility | 所有者向けprivateを含む |
| 島名/owner履歴 | island_histories | 旧値と削除時刻 |
| 島コメント履歴 | island_comments soft delete | 現在値を先頭取得 |
| 掲示板 | island_bbs soft delete | public/private |
| アプリ/エラー | Laravel logging | storage/logs、stderr |
| 管理監査ログ | 存在しない | 設定変更、管理操作なし |

## ゲームログ構造

LogRowはgenerate(): stringとvisibilityを定義する（app/app/Entity/Log/LogRow.php:5-11）。67個の派生classが発見、計画実行/中止、輸送、ミサイル、災害、破壊、怪獣、船、放棄等の文を生成する。

JSONはtype付きeventではなく、text、任意link、任意styleからなる表示断片配列である（IslandFoundLog.php:18-23）。DBはisland_id、turn_id、visibilityで索引できるが、「災害type」「actor」「座標」「重要度」で検索できない。

visibility:

- global: topを含む全体公開。
- public: 自島詳細に表示。
- private: 所有島計画画面だけ。

LogConst.php:15-20のコメントはpublicを「自島のみ」、privateを「自分のみ」と表現する。Web Detailはglobal/public、Plansはvisibility filterなし（DetailController.php:39-44、PlansController.php:43-47）。

## 生成と保存

Plan、Disaster、CellがLogs collectionへLogRowを追加し、ExecuteTurnが島ごとにmergeする。turn末に各LogRowをgenerateし、1行ずつIslandLogへsaveする（ExecuteTurn.php:342-350）。同じtransactionなので、状態rollback時にDB game logもrollbackする。

登録ログは登録transaction内、BBS/コメントは別tableで即時保存。アプリlogはtransaction外の開始/終了/例外記録である。

## 表示

Web Controllerがturn範囲でqueryし、turn番号別にgroup化してBlade propsへ渡す。LogViewer.ts側parserが各JSON文字列をparseし、Vueがtextをescapeして描画する（LogViewer.vue:15-35,113-119）。ページネーションはなく、設定turn範囲の全件を取得する。

## 保持・削除

PruneLogsは直近PRUNE_LOGS_MARGIN_TURNを残し、それより古いturnのうちturn番号がBACKUP_LOGS_INTERVALで割り切れる世代とturn 1を残す意図で、他のTurnに紐づくLog/Plan/Status/Terrainを物理削除しTurnをsoft deleteする（PruneLogs.php:35-76）。

既定interval=1はvalidator min:2を満たさずprune無効。保持「件数」ではなくturn世代である。BBS/コメント/履歴/実績はこのpruneの対象外。

## 外部通知

通知webhookはturn/prune失敗時だけ同期送信し、ゲーム内重要eventの配信基盤ではない（ExecuteTurn.php:366-378、PruneLogs.php:78-90）。delivery status、retry、deduplication、outboxはない。

## 新作向け改善

表示文ではなくturn_eventsにevent_type、importance、world/turn、actor/target/nation、coordinate、visibility、payload、dedup keyを保存する。表示文はlocale/templateで生成する。notification_outboxを同一transactionで作り、Discord/Mariachangは別workerがretryする。管理監査ログはgame eventと分け、append-onlyにする。
