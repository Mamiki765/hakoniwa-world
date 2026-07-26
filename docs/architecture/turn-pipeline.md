# ターン処理パイプライン

## 目的

箱庭諸島2＋が持つ共有世界の処理順と、やまにてぃが持つApplication Service・DB transactionの方向性を、再実行可能で監査できる独立設計へ置き換える。

## 実行入口

Schedulerは、next_turn_atを過ぎた稼働中worldごとにturn jobを投入する。Webリクエストはターンを直接進めない。管理者の手動実行も同じApplication Serviceを使い、理由と実行者を監査する。

開始時に次を保証する。

- world_idとturn_numberの一意なturn_runを作る。
- 世界単位の排他ロックを取る。
- ruleset_version、乱数seed、予定時刻、実行元を固定する。
- 既に完了した同一runは再適用せず結果を返す。

## 推奨フェーズ

| 順序 | フェーズ | 主な処理 | 主な出力 |
|---:|---|---|---|
| 1 | Freeze | 対象世界、命令締切、ruleset、seedを固定 | turn context |
| 2 | Validate commands | 所有、費用、対象、重複、期限を再検証 | accepted、rejected commands |
| 3 | Upkeep | 施設維持費、継続効果、期限切れ効果 | 国家資産差分 |
| 4 | Production | 農業、工業、採掘、人口に基づく生産 | 資源・資金差分 |
| 5 | Domestic commands | 整地、建設、研究等の国内命令 | セル・施設差分 |
| 6 | Inter-nation commands | ミサイル、援助、諜報等 | 攻撃・外交イベント |
| 7 | Territory | 国境影響、防壁抵抗、占領判定 | 所有権差分 |
| 8 | Disasters and actors | 災害、怪獣、自然変化 | 被害・移動イベント |
| 9 | Population and consumption | 人口増減、食料消費、飢餓、首都最低保証 | 人口・食料差分 |
| 10 | Aggregate | 国家統計、ランキング用投影、活動判定 | nation summaries |
| 11 | Lifecycle projection | 確定済みstate、保護policy、統計を投影 | nation summaries、state snapshot |
| 12 | Persist and publish | 状態、イベント、outbox、次回時刻を確定 | committed turn |
| 13 | Post-commit | 通知配送、キャッシュ温め、古い履歴整理 | 非同期job |

この順序は暫定である。特に領土を命令前後のどちらで解決するか、人口増加と災害の前後、攻撃と防御施設の同時性はゲーム仕様として決定し、ruleset版とテストで固定する。

## 箱庭諸島2＋との差

旧C版は国境判定を先に行い、国家単位の収支・先頭命令、全セル処理、難民、世界災害、集計を続ける。共有世界という性質は残すが、配列全走査、順位ID再割当、ファイル保存を前提にしない。

やまにてぃは維持・生産、国内計画、他島計画、災害、セル処理、集計という有用な分割を持つ。一方、全島・全セルを一括ロードし、実行seedや多重起動防止を記録しない点は採らない。

## 命令の状態遷移

commandはqueued、accepted、executed、failed、cancelled、expiredを持つ。登録時検証とターン時検証を分ける。登録後に所有権や資金が変わるため、ターン時の再検証が正本となる。

各commandはclient_request_idまたは同等の冪等キー、nation_id、scheduled_turn、type、target map_space/q/r、payload version、priorityを持つ。表示用odd-q座標をcommandへ保存しない。実行結果は構造化理由コードを記録し、表示文へ直接結合しない。

1国家1ターンに何件実行するか、順序変更・繰返し・予約を許すかはconfiguration-management.mdとcommands仕様で確定する。

## 乱数

turn_runにmaster seedを保存し、用途ラベルと安定対象IDから派生ストリームを作る案を推奨する。これにより、ログ出力を1件追加しても災害結果がずれるような暗黙の乱数消費を防ぐ。

用途例はdisaster-selection、missile-hit、terrain-growth、monster-moveである。同じseed、ruleset、入力snapshotから同じ結果になる決定性テストを設ける。暗号用途の乱数とは分離する。

## transactionと再試行

暫定案では1世界1ターンのゲーム状態、domain event、outboxを1 transactionで確定する。世界規模が大きくなり時間制限を超える場合は、次の順で対策する。

1. 対象集合とクエリを最適化し、全セル走査を除く。
2. bulk書込みと読取投影を分離する。
3. 世界を安全に分割できるフェーズだけを並列計算し、確定順を統一する。
4. 最後にcheckpoint型sagaを検討する。

途中commitだけを追加すると、攻撃だけ成功して収支が戻るなどの半端状態を生む。分割時はphase_runの入力hash、出力hash、再開条件、公開境界が必要である。

## 外部障害

Discord webhook、メール、分析基盤はturn transaction内で呼ばない。通知内容をoutbox_messagesへ保存してcommitし、別workerが指数backoff、最大試行、dead-letter状態で配送する。通知失敗は管理画面に表示するが、世界を停止しない。

## 国家休眠とターン対象

正式なstateはADR-0004のactive、dormant_frozen、dormant_contestable、sunken_archivedである。人口0や敗北を理由に国家を即時削除しない。新規・復帰保護はstateではなく期限付きpolicyとする。

UTCのlast_active_atに基づく30日、180日、365日の遷移は、Schedulerが投入する専用LifecycleEvaluationJobが所有する。turn途中で時刻判定してstateを変更しない。Lifecycle Jobとturn workerは同じworld lockで直列化し、Freezeで今回のstate snapshotを固定する。

- activeだけがUpkeep、Production、通常command、災害、人口、消費、自動発展の対象になる。
- dormant_frozenは資源・人口・地図を完全凍結し、一般攻撃と占領の対象外にする。
- dormant_contestableも経済・人口・災害を凍結するが、首都以外の領土だけを専用占領処理の対象にできる。
- sunken_archivedは現在地図上のセルを持たず、ターン対象外にする。

dormant_frozenまたはdormant_contestable内の既存怪獣に対する明示的な討伐ミサイルだけを例外とする。実行時にtarget q、rの怪獣identityを検証し、誤差後に怪獣がいなければ無被害の失敗とする。地形、施設、人口、所有権へdamageを適用しない。

365日沈没または明示的放棄は、領土量が小さければ単一transaction、大きければ冪等なlifecycle transition operationとchunk checkpointで処理する。全地図cleanup完了後にsunken_archivedを確定し、user、nation、event、統計、領土履歴は物理deleteしない。

## 観測性

turn_runに各フェーズの所要時間、読取・更新セル数、受理・失敗命令数、イベント数、再試行数を記録する。ログにはseed、ruleset_version、world_id、turn_number、run_idを相関キーとして含める。プレイヤー向けイベントと運用ログは別に保持する。

## 必須テスト

- 同じ入力とseedの再現性。
- job二重投入時に1回だけ確定すること。
- フェーズ途中例外で状態・event・outboxが全て戻ること。
- 通知失敗でもターンが完了すること。
- 同時国家登録とターン開始の締切境界。
- turnとLifecycleEvaluationJobの排他、30日・180日・365日のUTC境界。
- dormant_frozenの状態不変と怪獣討伐ミサイルの無副被害。
- dormant_contestableで首都以外だけが占領可能であること。
- 国境、首都、災害、ミサイルの順序を固定するシナリオ。
- 大量チャンクでの実行時間、lock時間、メモリ上限。
