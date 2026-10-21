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

`status` は `scheduled` / `active`。#332の仮契約に合わせ `description`, `turn_interval_minutes`（時間加速は60、他はnull）, `multiplier`（狩場は2、他はnull）も返す。description は狩場の対象を明示し、Gや鍵の個数まで増える表示を避ける。`turn_acceleration` の effects は `additional_turn_hours_jst: [1,3,...,23]`, `compensation_only: true`。TOP は両種、狩場画面は狩場、地上のターン表示には時間加速を大きく表示する想定。タイトルは plain text として描画する。公開情報に管理者・確認トークン・診断情報は含めない。

## 管理室

既存の管理者認証・CSRF・private response middleware を使う。全操作は World lock で直列化し、予約・取消を audit に記録する。

管理室とTOPの既存ターン操作欄から「遅延ターン分」で時間加速、「ブースト予約」で狩場予約のフォームを開く。日時入力は端末のtimezoneと無関係にJSTとして送り、プレビュー応答の全開催日時・期間・追加枠数を表示する。「この内容で予約する」で初めて予約する。開催一覧は系列ID・開催回ID・回数・状態を表示し、取消は開催回だけ／その回と以後の同系列を確認して実行する。

予約の応答不明時はtokenを保持し、「結果を確認する」で同じ予約だけを再送する。取消も対象開催回と範囲を固定する。結果確定まで入力変更・画面を閉じる操作・手動Turn実行を抑止する。予約409は入力へ戻し、新しいプレビューを要求する。一覧再読込の失敗は予約成功と分けて表示する。

- `GET /admin/worlds/{world}/boosts`: 公開データに `current_turn`, `uncompensated_turns` を追加。開催一覧は取消済み・終了済みを含む最新500回。
- `POST /admin/worlds/{world}/boosts/preview`: `{kind,title,starts_at,ends_at?,repeat?,repeat_count?}`。`kind` は `turn_acceleration` / `hunting_ground`。
- `POST /admin/worlds/{world}/boosts/reserve`: `{token}`。preview の内容を確認してから呼ぶ。15分有効、actor/World に固定。再送は同じ `series_id`, `duplicate:true`。競合や時刻経過は409で再確認する。
- `POST /admin/worlds/{world}/boosts/{occurrence}/cancel`: `{future_series:false}` は1回だけ、true は選択回と以後の同系列の未終了回を取消。開催中なら新規受付から効果を停止。既に受け付けた戦闘・完了ターンは巻き戻さない。

狩場の繰り返しは `none`（1回）/ `daily` / `weekly`。最大52回、各回31日以内、初回は現在以降1年以内。JST の同じ時刻に全開催回を予約時に作成する。曜日集合、無期限繰り返し、月次、例外日、予約の直接編集は未対応。変更は対象回の取消後に新規予約する。各回の `(series_id, occurrence_number)` は一意で、再起動時に再生成しない。

時間加速は管理室のターン指定箇所に **遅延ターン分** ボタンを置く。preview に `kind:turn_acceleration,title,starts_at` を渡すと終了日時を計算する。未補填通常枠10なら20時間とし、`windows[].additional_slots` が10であることを実際の奇数時境界で算出して返す。開始が09:30でも翌日05:30までに奇数時枠10個を含む。時間加速の繰り返しは禁止、未終了の時間加速は1予約に限定する。確認後に手動更新などで未補填数が変われば予約は409。

未補填は「永続 `next_regular_turn_at` から現在までに到来した通常2時間枠の数」。通常更新・手動更新・追加更新はいずれも成功1回につき最古の未補填枠を1個消化する。失敗・blocked・停止中は消化しない。追加枠の時点で未補填がなければ実行せず、予約後の手動補填による過剰補填を防ぐ。プレビュー時点より後に発生した停止は追加の未補填になり、実際の補填完了はワーカー実行と停止状況による。

既存 `turn-status` の `next_scheduled_turn_at` は通常/追加の次の実行枠を返す。`regular_turn_due_at` は未補填通常枠で、遅延の判定に使う。追加更新が入っても通常偶数時をずらさない。管理室のfailed/blockedの明示retryは、新規枠の取得とは別に同じTurnRunを再利用する。

## 狩場の決算

狩場だけ、レア出現率・通常敵の鍵出現率を2倍（100%上限）、獲得XPを2倍。レア確定鍵の数量、G、装備、その他の報酬は変更しない。weighted rare と独立 rare の両抽選、単独・PT・単独skip・一括skipへ同じ効果を渡す。受付時の効果と開催回IDを固定し、transaction retry・処理中の終了・再送で再判定しない。重複データでも倍率は2倍で固定し累乗しない。宝物庫・試練・異世界・女王決闘・イベントへ拡大しない。宝物庫XPを含めるかは未決。
