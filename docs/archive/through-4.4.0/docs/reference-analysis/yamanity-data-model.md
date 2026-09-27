# やまにてぃ データモデル

## 結論

永続化の中心は「不変に近い島レコード」と「ターンごとの状態・地形・計画スナップショット」である。島マップは島単位JSON、検索やランキングに使う状態は通常カラムである。migrationにはID列とindexはあるが、外部キー制約は宣言されていない。

## migrationと最終テーブル

| テーブル | 主な列・制約 | 役割 |
| --- | --- | --- |
| users | id、timestamps | 外部認証利用者。後続migrationでname/email/remember_tokenを削除 |
| user_authentications | user_id、identifier、provider | Google/Yahoo/debug識別子 |
| islands | user_id unique、name unique、owner_name unique、soft delete列 | 1ユーザー1島 |
| turns | turn unique、next_turn_scheduled_at、soft delete列 | ターン世代 |
| island_statuses | turn_id+island_id unique、集計値、abandoned_turn | 検索・ランキング可能な島状態 |
| island_terrains | turn_id+island_id unique、terrain JSON | 15×15全セル |
| island_plans | turn_id+island_id unique、plan JSON | 最大30計画 |
| island_logs | turn_id、island_id、log JSON、visibility | 生成済み表示ログ |
| island_achievements | island_id、turn_id、type、extra JSON | 実績 |
| island_histories | island_id、user_id、旧name/owner_name、deleted_at | 改名・放棄履歴 |
| island_comments | island_id、comment、soft delete | 島紹介コメントの履歴 |
| island_bbs | 対象島、投稿User/島、turn、comment、visibility、soft delete | 掲示板 |
| sessions | Laravel DB session | Web session |
| personal_access_tokens | Sanctum token | token schema。実利用routeは確認できない |

根拠は app/database/migrations。中心schemaは create_islands:16-25、create_island_statuses:16-33、create_island_terrains:16-24、create_island_plans:16-25、create_island_logs:16-24、create_turns:16-21である。

## 関係

IslandはStatus、Terrain、Plan、Log、Comment、History、AchievementをhasManyし、UserへbelongsToする（app/app/Models/Island.php:21-59）。UserはIslandをhasOneとする（User.php:50-60）。各ターンスナップショットはisland_idとturn_idで関連付く。

外部キーに相当するunsignedBigIntegerはあるが、foreign()やconstrained()はmigrationにない。したがって参照整合性とcascadeはDBでは保証されず、PruneLogsが関連行を明示削除する（PruneLogs.php:68-76）。

## ModelとEntity

| Model | Entity変換 | 保存 |
| --- | --- | --- |
| IslandTerrain | Terrain::fromJson(terrain) | Terrain::toJson(true) |
| IslandPlan | Plans::fromJson(plan) | Plans::toJson() |
| IslandStatus | Status::create()->fromModel(model) | getterを個別カラムへ代入 |
| IslandAchievement | Achievements::fromModel | 未保存AchievementのModelをsave |
| IslandLog | 変換なし | LogRow::generate()済みJSON文字列 |

根拠: IslandTerrain.php:21-24、IslandPlan.php:30-33、IslandStatus.php:46-49、ExecuteTurn.php:312-356。

Eloquent castはIslandStatusの数値列とTurnの日付・番号に限られる（IslandStatus.php:18-29、Turn.php:17-22）。terrain、plan、log、achievement.extraにはarray/json castがなく、生JSON文字列をEntityが解釈する。

## SQL検索対象とJSON内限定値

通常列に置かれる検索・排他対象は、島/ユーザーID、ターン、島名、ランキング用development_points、人口、資源、産業規模、環境、面積、ログvisibility、実績typeである。

JSON内だけにある主な値は次の通り。

- セルtype、座標、セル人口、森量、施設生産能力、基地経験値、怪獣HP、船の所属・損傷・帰還ターン。
- 計画key、対象座標、数量、対象島。
- ログの表示断片text/link/style。
- 実績の可変extra。

セル単位の所有者はない。島内陸地は暗黙にその島のもの、他島所属が必要な船だけaffiliation_id/nameをJSONに持つ。

## トランザクションと同時更新

- ターン更新本体は1トランザクション（ExecuteTurn.php:56-365）。
- 登録はロックを含む1トランザクション（Register IndexController.php:47-115）。
- 改名、コメント更新、掲示板投稿もController内でtransactionを使う。
- 計画PUTはtransactionもrow lockも使わず、最新turnの行を上書きする（Api PlansController.php:36-49）。ターン更新との競合時のlost updateや、旧turn行更新の可能性が残る。
- turn番号はuniqueだが、execute:turnは最新TurnをlockForUpdateせず、二重実行防止はunique違反による片方rollbackに依存する可能性がある。

## バックアップ・復旧

ファイルバックアップやDB dump処理はない。backup_logs_intervalという名称は、古いターンのうち一定間隔のスナップショットを残すPruneLogsの保持規則を意味する（PruneLogs.php:35-76）。既定値1ではvalidationが失敗してprune自体が無効になる（config/app.php:236-237）。復旧command、管理UI、整合性検査は存在しない。

## 問題候補

- user_authentications migrationのdownは user_auths をdropし、作成名と一致しない（2023_04_30_231943_create_user_authentications_table.php:14-33）。
- Island schemaはsoftDeletesを持つがIsland ModelはSoftDeletes traitを使わず、手動deleted_atとforceDeleteに依存する。
- User Modelのfillable/hidden/castsに、最終schemaから削除済みのname/email/password等が残る（User.php:23-48）。
- DB外部キーがなく、異常終了や手動操作で孤児行が生じ得る。
