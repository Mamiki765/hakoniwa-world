# 予約ブースト API（5.8.0、画面担当向け）

サーバー側・予約・API・管理室フォーム・テストは dots の #334。TOP と該当画面の大きな予告・開催表示はラピスの #332。固定HEAD・表示上の調整点・統合検証は[画面連携の引継ぎ](boost-ui-integration.md)を参照する。画面全体の大幅変更や帯の重複実装は行わない。

全パスは `/api/v1` 以下、応答は `{data: ...}`。World は既存と同じ数値 ID。日時は UTC の ISO8601、入力は秒と offset 必須。画面は `Asia/Tokyo` に変換する。期間は `[starts_at, ends_at)`。同種の重複は拒否、異種は併用できる。

## 公開一覧

`GET /public/worlds/{world}/boosts`（ログイン不要）。`{data: BoostSchedule[], meta: {server_now, timezone: Asia/Tokyo}}` を返す。取消済み・終了済みは含めず、種別ごとに開催中または次の開催回を1件返す。各要素:

```json
{
  "id": 123,
  "series_id": 45,
  "occurrence_number": 1,
  "kind": "hunting_ground",
  "title": "狩場のお祭り",
  "starts_at": "2026-11-01T00:00:00+00:00",
  "ends_at": "2026-11-01T20:00:00+00:00",
  "status": "scheduled",
  "effects": {"rare_encounter_multiplier": 2, "normal_key_chance_multiplier": 2, "xp_multiplier": 2, "scope": "hunting_ground"}
}
```

`status` は `scheduled` / `active`。`description`, `turn_interval_minutes`（時間加速は予約した追加枠の間隔、他はnull）, `multiplier`（狩場は2、他はnull）も返す。例えば30分の指定なら `turn_interval_minutes:30` となる。この値は通常ターンも含めた更新間隔を保証せず、次回時刻の計算には使わない。description は実際の予約間隔・追加上限・未補填条件を含め、狩場ではGや鍵の個数まで増える表示を避ける。

`turn_acceleration` の effects は次の形式。`additional_turn_scheduled_ats` は実際に保存した追加枠のUTC時刻一覧で、通常枠との衝突を除いた後のもの。旧 `additional_turn_hours_jst` は返さない。

```json
{
  "additional_turn_interval_minutes": 30,
  "additional_turn_limit": 7,
  "additional_turn_scheduled_ats": ["2026-11-01T09:30:00+00:00", "2026-11-01T10:00:00+00:00", "2026-11-01T10:30:00+00:00", "2026-11-01T11:30:00+00:00", "2026-11-01T12:00:00+00:00", "2026-11-01T12:30:00+00:00", "2026-11-01T13:30:00+00:00"],
  "compensation_only": true
}
```

上の日時は契約の例で、本番予約ではない。TOP は両種、狩場画面は狩場、島のターン表示には時間加速を大きく表示する。タイトルとdescriptionは plain text として描画する。公開情報に管理者・確認トークン・診断情報は含めない。

## 管理室

既存の管理者認証・CSRF・private response middleware を使う。予約・取消は World lock で直列化し、audit に記録する。

管理室とTOPの既存ターン操作欄から「遅延ターン分」で時間加速、「ブースト予約」で狩場予約のフォームを開く。日時入力は端末のtimezoneと無関係にJSTとして送り、時間加速では秒00の開始・終了、追加上限、追加間隔を指定する。プレビュー応答の全開催日時・期間・追加枠数・実際の追加時刻をJSTで表示する。「この内容で予約する」で初めて予約する。開催一覧は系列ID・開催回ID・回数・状態を表示し、取消は開催回だけ／その回と以後の同系列を確認して実行する。

予約の応答不明時はtokenを保持し、「結果を確認する」で同じ予約だけを再送する。取消も対象開催回と範囲を固定する。結果確定まで入力変更・画面を閉じる操作・手動Turn実行を抑止する。初回の4xx拒否は入力へ戻すが、応答不明後の再送で単なる409・認証エラー等になっても初回の未成立とは扱わない。予約が409かつ `code:boost_reservation_rejected` の場合だけ、World lock下で同じrequest_idの不在を確認した確定拒否なので、新しいプレビューへ戻せる。成立済みなら確認期限後でも同じ予約を返す。一覧再読込の失敗は予約成功と分けて表示する。

- `GET /admin/worlds/{world}/boosts`: 公開データに `current_turn`, `uncompensated_turns` を追加。開催一覧は取消済み・終了済みを含む最新500回。
- `POST /admin/worlds/{world}/boosts/preview`: `{kind,title,starts_at,ends_at?,additional_turns?,interval_minutes?,repeat?,repeat_count?}`。`kind` は `turn_acceleration` / `hunting_ground`。時間加速の明示指定では `ends_at`, `additional_turns`, `interval_minutes` の3つを一緒に送る。部分指定は拒否する。狩場は従来通り開始・終了と有限繰り返しを使う。
- `POST /admin/worlds/{world}/boosts/reserve`: `{token}`。preview の内容を確認してから呼ぶ。15分有効、actor/World に固定。再送は同じ `series_id`, `duplicate:true`。競合や時刻経過は409で再確認する。
- `POST /admin/worlds/{world}/boosts/{occurrence}/cancel`: `{future_series:false}` は1回だけ、true は選択回と以後の同系列の未終了回を取消。開催中なら新規受付から効果を停止。既に受け付けた戦闘・完了ターンは巻き戻さない。

狩場の繰り返しは `none`（1回）/ `daily` / `weekly`。最大52回、各回31日以内、初回は現在以降1年以内。JST の同じ時刻に全開催回を予約時に作成する。曜日集合、無期限繰り返し、月次、例外日、予約の直接編集は未対応。変更は対象回の取消後に新規予約する。各回の `(series_id, occurrence_number)` は一意で、再起動時に再生成しない。

### 時間加速の追加枠

`additional_turns` は整数1〜500、`interval_minutes` は整数5〜120。開始・終了は秒00、期間は最大31日。開始を基準に `starts_at + n * interval_minutes`（nは0以上）の候補を作り、JST偶数時00分の通常枠を除き、`[starts_at, ends_at)` に入る先頭N枠だけ保存する。指定回数が期間内に入らなければpreviewを409で拒否し、期間や間隔の修正を求める。時間加速の繰り返しは禁止、未終了の時間加速は1予約に限定する。確認後に手動更新などで未補填数が変われば予約も409で再確認する。

preview は確認tokenに加え、`kind`, `title`, `repeat`, `repeat_count`, `duration_hours`, `current_turn`, `next_regular_turn_at`, `uncompensated_turns`, `additional_turns`, `interval_minutes`, `effective_additional_turns`, `grace_seconds`, `windows` を返す。`windows[]` は `starts_at`, `ends_at`, `additional_slots`, `additional_scheduled_ats` を持つ。時間加速では `additional_slots` は指定上限N、`additional_scheduled_ats` は保存するN枠のUTC時刻一覧。`effective_additional_turns` は `min(N, プレビュー時の未補填数)` で、成功回数の保証ではない。狩場では追加上限・間隔・graceはnull、実効追加数と枠数は0、時刻一覧は空配列。

例（本番予約ではない）: 日曜18:00〜23:00、追加7回、30分間隔なら18:30 / 19:00 / 19:30 / 20:30 / 21:00 / 21:30 / 22:30を予約する。通常18:00 / 20:00 / 22:00は維持する。間隔は候補列の間隔なので、通常枠を除いた後の追加時刻が常に30分おきになるわけではない。

**遅延ターン分** ボタンは未補填数Nを追加上限、間隔120分、終了を開始+2N時間へ入力する補助機能で、自動予約しない。端数時刻の不足や通常枠との衝突を避けるため、開始を次のJST奇数時00分へ揃え、プレビューで時刻を確認する。Nが0、500超、期間31日超なら全量を自動入力せず個別指定を案内する。未補填10なら20時間・奇数時の追加10枠が従来例に対応する。

旧APIとの互換として3項目をすべて省略したpreviewは、開始+未補填数×2時間を終了とし、期間内の奇数時00分を追加枠にする。追加枠の間隔は120分。新しい管理室フォームはこの省略形に依存せず、3項目を明示する。

未補填は「永続 `next_regular_turn_at` から現在までに到来した通常2時間枠の数」。通常更新・手動更新・追加更新はいずれも成功1回につき最古の未補填枠を1個消化する。失敗・blocked・停止中は消化しない。追加枠の時点で未補填がなければ実行せず、予約後の手動補填による過剰補填を防ぐ。プレビュー時点より後に発生した停止は追加の未補填になる。

ホストcronは毎分の起動契機に留め、アプリケーションの共通due判定・World lock・一意な実行枠で1起動最大1ターンを実行する。空振り時にゲーム状態を更新しない。追加枠は限定grace内の最新dueだけを候補とし、失効枠へ遡及せず、停止・失敗・手動補填によって成功数は上限を下回る。常駐workerは追加しない。具体的なgraceと起動手順は[運用手順](../operations/turn-scheduler-worker.md)を参照する。

既存 `turn-status` の `next_scheduled_turn_at` は通常/追加の次の実行枠を返す。`regular_turn_due_at` は未補填通常枠で、遅延の判定に使う。追加更新が入っても通常偶数時をずらさない。管理室のfailed/blockedの明示retryは、新規枠の取得とは別に同じTurnRunを再利用する。

長期停止の未補填基準を再開時刻へ変えるか、失効した追加枠を別時刻へ再配置するかは未決。現実装はカーソルを自動で現在へ進めず、過去枠の回収・再配置・無制限catch-upを行わない。

## 狩場の決算

狩場だけ、レア出現率・通常敵の鍵出現率を2倍（100%上限）、獲得XPを2倍。レア確定鍵の数量、G、装備、その他の報酬は変更しない。weighted rare と独立 rare の両抽選、単独・PT・単独skip・一括skipへ同じ効果を渡す。受付時の効果と開催回IDを固定し、transaction retry・処理中の終了・再送で再判定しない。重複データでも倍率は2倍で固定し累乗しない。宝物庫・試練・異世界・女王決闘・イベントへ拡大しない。宝物庫XPを含めるかは未決。
