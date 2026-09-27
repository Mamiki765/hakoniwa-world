# やまにてぃ 未解決事項

## 状態区分

- 要実動確認: static sourceだけではDB/Frameworkの挙動を確定できない。
- 問題候補: source間に不整合または競合余地がある。
- 要権利確認: repository内に根拠がない。

## 問題候補

### 1. execute:turnの二重起動

最新Turnをlockせず、unique turnだけが衝突を防ぐ。並行transactionのisolation、失敗側の副作用、例外通知を隔離DBで確認する必要がある（ExecuteTurn.php:56-64）。

### 2. 計画PUTとturn読込の競合

PUTにtransaction/version/lockがなく、どのturnから反映されるか境界競合をtestする必要がある（Api PlansController.php:36-49）。

### 3. 登録lockの0件時/gap

Island::lockForUpdate()->count()がMySQLで最大島数超過を厳密に直列化するか、isolationとquery planを確認する。unique user/nameは別途存在する（Register IndexController.php:47-70）。

### 4. Islandのsoft delete不整合

schemaにdeleted_atがあるがModelにSoftDeletes traitがない。forceDeleteの実際のSQL、関係snapshotの孤児化、同じUserの再登録可否を確認する。

### 5. user_authentications down名

upはuser_authentications、downはuser_authsをdropする（migration 2023_04_30_231943:14-33）。rollbackは失敗またはtable残留の可能性が高い。

### 6. Terrain復元完全性

不正JSON、未知type、重複/欠落/範囲外point、225件以外、null cellでどう失敗するか。現在はschema validationがない（Terrain.php:80-100）。

### 7. Plan validation

31件以上、巨大amount、負数、範囲外座標、未解放plan、不正対象島がAPI/executeでどこまで拒否されるか。PlanServiceはparse成功だけを見る。

### 8. 近傍範囲外座標

Terrain::getAroundCellsのy+1範囲外分岐が y-1 のPointを生成する箇所があり、typo候補（Terrain.php:277-281）。境界災害/怪獣への影響をtestする。

### 9. Statusの数値変換

float計算結果をint propertyへ代入する箇所のPHP 8.2変換/警告、round時点、負資源やoverflowの可能性を確認する（Status.php:103-148）。

### 10. turn賞の番号

newTurn作成後に旧turn->turn % 100を判定し、Achievementへ旧Turnを渡す。表示上どのturn賞になる意図か確認（ExecuteTurn.php:274-289）。

### 11. prune queryとlock

whereRaw('turn % N')は「余りがtruthyのturnを削除」する意図に見える。turn->lockForUpdate()をquery再取得せず呼ぶ効果も要確認（PruneLogs.php:60-75）。

### 12. scheduler

空のKernel、未登録cron、Cloud Run Jobの外部scheduleの関係を運用設定で確認する。

### 13. frontendの状態不整合

改名/非公開BBS成功時にPiniaが固定1000を引く箇所はserver config変更に追従しない（MainStore.ts:200-244）。非公開BBSの条件式も支払者表示と一致するかtestが必要。

### 14. API response size

225セルへimage/infoを付加したJSON実サイズ、gzip、latencyは未測定。共有world比較用に計測が必要。

### 15. N+1

turn内Achievement再query、Web画面relations、BBS commenter等をQuery Detectorまたはquery logで確認する。

## 仕様の曖昧さ

- 人口0救済は国家存続ルールとして確定仕様か暫定措置か。
- 島放棄後にUserが再登録できるか。
- 公開/非公開ログの用語と閲覧者範囲。
- 次回turn時刻をschedulerが尊重するか。
- monster_action_probablyの値域と名称probablyの意図。
- backup_logs_interval=1でprune無効が既定意図か。
- Environment係数、上限、災害率を運用で変更しない設計意図。

## 要権利確認

repository全体のlicense、hakogif画像出典、Google/Yahoo brand asset、しまにてぃとの関係は yamanity-license.md の追加確認が必要。

## 新作実装前へ引き継ぐ質問

- chunk寸法、座標系、layer ID、world拡張余白。
- turn lock/lease、再現可能乱数、失敗時のretry単位。
- plan受付締切とeffective turn。
- capital最低人口・被害・復旧・国家消滅条件。
- 防壁都市の抵抗式。
- ruleset publishと設定適用turn。
- event/outbox保持・再送・個人通知。
- PostgreSQL/JSONB採用、backup/RPO/RTO。
