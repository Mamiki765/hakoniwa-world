# 国家ライフサイクル

## 目的

共有世界における無活動国家を、短期保護、長期領土解放、地図からの退去へ段階的に移す。国家・ユーザー・歴史は物理削除せず、復帰と監査を可能にする。正式判断はADR-0004を正本とする。

状態と時刻境界は確定済みだが、Lifecycle Job、turn連携、占領、沈没batchは最初のMVP縦切りに含めない。実装前に`docs/open-questions.md`の`Required before`を確認する。

## 状態一覧

| 状態 | 開始条件 | 経済・人口 | 災害・自動発展 | 攻撃・領土 | 復帰 |
|---|---|---|---|---|---|
| active | 無活動30日未満 | 通常処理 | 通常処理 | 通常処理 | 対象外 |
| dormant_frozen | 30日以上180日未満 | 完全凍結 | 停止 | 全領土保護。怪獣討伐専用ミサイルだけ例外 | 残存状態からactive |
| dormant_contestable | 180日以上365日未満 | 完全凍結 | 停止 | 首都以外を占領可能 | 残存領土からactive |
| sunken_archived | 365日以上または放棄 | 停止 | 停止 | 地図上の領土・首都なし | 旧領土を戻さず再入植候補 |

境界はUTCの経過時間で判定する。30日、180日、365日の各到達時刻を含んで次stateとする。

## active

資源生産・消費、人口増減、災害、ランダムイベント、怪獣、国境、攻撃、commandを通常処理する。新規保護や復帰者保護はactiveへ重ねる期限付きpolicyであり、別stateにしない。

## dormant_frozen

30日間有効な活動がない国家を凍結する。

停止するもの:

- 資金、食料、人口、各種資源の増減。
- 生産、維持費、消費、飢餓。
- 災害、ランダムイベント。
- 村発生、施設成長等の自動発展。
- 怪獣の新規発生、既存怪獣の移動・破壊。
- 国境侵食と占領。
- 一般攻撃と通常ミサイル。

凍結中に未実行commandを保持、cancel、失効のどれにするかは未決定である。少なくとも凍結後に通常commandを実行して資源や地図を変えない。

### 怪獣討伐ミサイルの例外

例外commandはmonster_extermination等の明示typeとし、発射時・実行時の両方で次を検証する。

- 対象nationがdormant_frozenまたはdormant_contestableである。
- 指定したtarget q、rに既存怪獣がいる。
- damage対象をその怪獣identityだけに限定する。
- 散布・誤差後のセルに怪獣がいなければ無被害の失敗とする。
- 地形、施設、人口、資源、所有権、国境を変更しない。
- 通常攻撃用payloadから討伐modeへ偽装できない。

討伐の費用、成功率、誰が発射できるかは戦闘仕様で決めるが、休眠国家への対国家攻撃には転用できない。

## dormant_contestable

180日到達後も経済・人口・災害・イベント・自動発展の凍結を継続する。首都以外の現在領土だけが占領可能になる。

占領時はownership historyへ元所有者、取得者、q、r、turn、理由を記録する。元の国家が復帰しても、取得済みセルを自動返還しない。首都セル、首都施設、首都所有権は保護する。

専用占領処理以外の一般damageを許可する決定ではない。休眠人口・施設を攻撃で変動させない。dormant_frozenと同じ怪獣討伐専用ミサイルの例外は継続する。占領時に通常施設を除去、停止、取得のどれにするか、防壁抵抗をどう扱うか、首都周辺の保護ringは未決定である。

## sunken_archived

365日到達または明示的放棄により、地図上の存在を終了する。

- 残存セルを海、ownerなし、人口なしへ変える。
- 首都施設とその他設置施設を現在地図から除去する。
- nationの現在capital参照を終了し、過去capital historyは残す。
- user、nation、identity、event、称号、統計、ranking履歴、領土履歴を物理削除しない。
- 現在resource balanceを生産・消費対象にしない。
- accountは認証可能なまま残す。

履歴画面では「当時所有していた領土」と「現在所有する領土0」を区別する。

## 明示的放棄

本人による放棄は365日経過を待たず、同じ沈没operationをreason=user_abandonmentとして実行する。誤操作・乗っ取り対策として、実装前に次を決める。

- 再認証。
- 国家名等の確認入力。
- 待機期間と取消期間。
- 実行予定の明示。
- 管理監査と重要event。
- 取消後のcooldown。

放棄requestを受けただけで大規模map cleanupを無監査実行しない。

## 復帰

dormant_frozenまたはdormant_contestableの本人が有効な活動を行うとactiveへ戻す。

- 現時点の残存領土・首都・資源から再開する。
- 他国に占領された領土を返さない。
- 凍結中の生産、人口増加、消費、災害を再計算しない。
- 未受領turn分を一括付与しない。
- 復帰state changeとlast_active_atを同じtransactionで保存する。
- 復帰者保護は別policyとrulesetで決める。

sunken_archivedは直接activeへ戻さない。再入植は新しい空き領域を探索し、旧領土を変更しない。

## last_active_at

last_active_atはUTC instantを正本とし、表示だけAsia/Tokyoへ変換する。

更新する操作:

- 本人が対話的loginを完了した。
- 認証済みgame screenを本人が明示的に利用した。
- commandを登録・変更した。
- 復帰操作を行った。

更新しないもの:

- session cookieが残っているだけの状態。
- background polling、WebSocket heartbeat、token refresh。
- Discordや公開ページの閲覧。
- 管理者・Botによる代理read。

書込み負荷軽減のため更新頻度をまとめる場合も、30日境界を誤らない精度を保証する。

## データモデル案

### nations

- lifecycle_state
- last_active_at
- lifecycle_changed_at
- lifecycle_reason
- current_capital_cell_id。active、dormant_frozen、dormant_contestableでは非null、sunken_archivedだけnull。

### nation_state_transitions

- nation_id
- before_state、after_state
- reason
- effective_at、processed_at
- actor_type、actor_id nullable
- last_active_at_snapshot
- idempotency_key
- metadata

### lifecycle_transition_operations

- nation_id、target_state、reason
- status
- started_at、completed_at
- last_processed_chunk
- affected_cell_count、removed_facility_count
- failure_code、retry_count
- idempotency_key

### territory_history

現在所有権と分離し、nation、map_space、q、r、from_turn、to_turn、change_reason、counterparty_nationを保持する案とする。履歴は現在map cellを巻き戻す命令として使わない。

## 定期判定と排他

Schedulerは専用LifecycleEvaluationJobを定期投入する。Jobはstate、last_active_at、UTC nowから目標stateを純粋に導出し、同じidempotency keyならeventと変更を重複させない。

turn worker、登録、占領、沈没はworld lockを共有する。turn開始時のFreezeでnation stateを固定し、途中でactiveから休眠へ変えない。Lifecycle Jobが先にlockを取った場合はturn側が完了を待つ。

状態変更eventにはbefore、after、reason、effective_at、processed_atを含める。管理者例外も同じeventと監査経路を使い、DBのstateだけを直接編集しない。

## 大量領土の沈没

最初に単一transactionの実行時間・lock時間を計測する。過大な場合はtransition operationを作り、所有chunkをmap_space_id、chunk_r、chunk_qの安定順でbatch処理する。

各batchは「現在も対象nationが所有するセル」だけを更新し、checkpointを保存する。再実行は完了chunkを飛ばす。処理中は当該国家へのturn、占領、復帰をtransition lockで停止する。全batchと首都・施設除去を検証した最終transactionでsunken_archivedを確定する。

## 状態別ターン対象

| フェーズ | active | dormant_frozen | dormant_contestable | sunken_archived |
|---|---|---|---|---|
| Upkeep・Production | 実行 | 対象外 | 対象外 | 対象外 |
| Domestic commands | 実行 | 対象外 | 対象外 | 対象外 |
| 通常攻撃 | 対象 | 対象外 | 対象外。占領処理は別 | 対象外 |
| 怪獣討伐例外 | 通常戦闘 | 怪獣だけ対象 | 怪獣だけ対象 | 対象外 |
| Territory | 通常 | 変更なし | 首都以外を占領可能 | 所有セルなし |
| Disasters・Actors | 実行 | 対象外 | 対象外 | 対象外 |
| Population・Consumption | 実行 | 対象外 | 対象外 | 対象外 |
| 自動村発生 | 実行 | 対象外 | 対象外 | 対象外 |

## 必須テスト

- 29日23:59:59、30日、179日23:59:59、180日、364日23:59:59、365日のUTC境界。
- 同じLifecycle Jobを複数回実行してevent・地図変更が重複しない。
- dormant_frozenで全resource、人口、施設、領土が変化しない。
- 怪獣討伐ミサイルが怪獣以外へ一切damageを与えない。
- dormant_contestable移行前に占領できず、移行後も首都は占領できない。
- 復帰時に領土と生産を巻き戻さない。
- sunken_archivedでcurrent領土・首都がなく、user・nation・履歴が残る。
- 沈没batch失敗後にcheckpointから再実行できる。
- turnとLifecycle Jobの同時実行が直列化される。

## 未決定事項

- 首都周辺の占領保護ring。
- dormant_contestableの施設・防壁の扱い。
- 凍結時のqueued commandをcancel、保持、expireのどれにするか。
- 復帰者保護期間と対象。
- 放棄の待機・取消期間。
- 再入植の初期資源、旧国家名、nation identity、ranking、保護期間。
- 沈没時の希少item、地下、宇宙asset。
