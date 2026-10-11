# 一括計画・保管庫 API 契約

UI 実装担当向け。認証済み本人の API を使う。表示設定・配置・確認 dialog は UI の責務。
本変更は計画受付と所有品の売却保護を扱う code release であり、Turn の対象判定、装備能力、価格、抽選、Ruleset v36 を変更しない。

## 一括計画

基底 URL は `/api/v1/nations/{nation}/map-spaces/{mapSpace}/command-queue`。
通常登録の `position` と一括の `position` はともに**選択した行の前**へ挿入する。
通常数量は既存 `DevelopmentPlanQuantity` の整数 1〜99。一括は各計画の数量1を維持する。
枠上限は World の Ruleset に従う（現行30）。固定値を UI の対象判定に使わない。

### Preview

`POST {base}/bulk/preview`

```json
{"action":"clear_all","position":5,"expected_version":7}
```

`action` は `clear_all`、`level_all`、`reclaim_clear_all`、`reclaim_level_all`。
荒地・焼け野原の自国領、または既存サーバー判定で埋立て可能な浅瀬を対象とする。
セルは y→x 順、埋立てはセルごとに埋立て→地ならし／整地の2計画組。
候補選定と両方針の枠計算は apply と同じ経路を使う。preview は計画の追加・取消・日課加算をしない。

`data` の主要な応答例（既存29計画、候補3計画）：

```json
{
  "preview_token":"opaque authenticated token",
  "request_key":"server-generated UUID",
  "expected_version":7,
  "expires_at":1791684000,
  "queue_limit":30,
  "candidate_count":3,
  "available_count":1,
  "overflow_required":true,
  "policies":{
    "overflow":{"inserted_count":3,"cancelled_count":2,"skipped_count":0,"cancelled_items":[{"id":128,"queue_position":28},{"id":129,"queue_position":29}]},
    "fill_available":{"inserted_count":1,"cancelled_count":0,"skipped_count":2,"cancelled_items":[]}
  }
}
```

件数の単位は**計画行**。埋立て系は1セル=2候補。`available_count` は未使用枠数。
`cancelled_count` は押し出される既存計画、`skipped_count` は入らない新候補。
各 `policies.{policy}.cancelled_items` は、サーバーの apply 合成経路から返す**取り消される既存計画の確定集合**。
要素は `{id: integer, queue_position: integer}`、挿入前の位置順。生成候補の切捨ては含めず、`fill_available` は空配列。
消失行の表示は、同じ `expected_version` の `GET {base}` 応答 `data.items` を **id で照合**し、
既存の `command_name`、`command_suffix`、`quantity`、座標・target context 等を使用する。
`queue_position` も挿入前の行位置を示す。件数から末尾を独自推定してはならない。
表示中の queue version が異なる、または対象 id/位置を照合できない場合は queue を取得し直して再 preview する。
apply はこの集合を決めた snapshot を lock 下で再検証するため、古い確認から別の計画を黙って消さない。
2計画組を分割しないため、空き1枠だけなら埋立て系は0件追加する。

`overflow_required=false` なら UI の確認を挟まず `overflow` で apply できる。
true の場合だけ「溢れさせる」=`overflow`／「溢れないところまで入れる」=`fill_available`／キャンセルを選ぶ。
キャンセルは apply を呼ばない。`overflow` は既存の末尾押し出し契約を維持する。
`fill_available` は既存の計画を保持し、空き枠の範囲だけ候補の先頭から追加する。

### Apply と状態変更

`POST {base}/bulk` に preview の値をそのまま渡す。

```json
{
  "action":"clear_all","position":5,"expected_version":7,
  "request_key":"preview の UUID","preview_token":"preview の token",
  "overflow_policy":"fill_available"
}
```

token は actor・Nation・map space・action・position・version・候補/キュー snapshot に結び付く有効1時間の確認情報。
既存の管理 preview/apply の `Crypt` による確認情報認証、期限確認、実行済み再読取を再利用する。
既存の World→owner→queue lock、queue optimistic version、UUID の完了記録を維持する。
候補またはキューが変わった未実行 token は409で拒否し、計画を変更しない。
`bulk_plan_preview_changed` または queue の version conflict を受けたら新しい状態を取得して preview し直す。
溢れる結果になったら再度選択を求める。古い選択を自動再適用しない。
危険な施設上書き等の既存登録時検証も apply 時に引き続き行う。

応答 `data` は既存 `queue`、`candidate_count`、`inserted_count`、`truncated_count`、`duplicate`、`daily_quest` と、
`cancelled_count`、`skipped_count` を返す。`truncated_count=cancelled_count+skipped_count`。
同一 UUID/token/方針の再送は完了結果を再読取し、追加・取消・日課を重複適用しない。
同一 UUID の方針変更は409 `command_request_conflict`。完了後は token の期限を過ぎても同じ結果を再読取できる。

移行中の既存 UI との互換性のため、token/方針を省略した旧 `/bulk` は従来の直接末尾押し出し動作を維持する。
新 UI は必ず上記 preview/token 方式を使用する。token と方針は片方だけ送れない。
旧 staged-position の修復は従来の登録時経路に残る。これは画面上の有効計画とは別の破損修復である。

## 保管庫の保護

`underground_owned_equipment.is_protected` は NOT NULL boolean、初期値 false。
固定・生成装備、通常保管庫・共鳴結晶庫で同じ保存先と売却経路を使う。
所有品の共通 projection（保管庫、ショップ所持品、装備中等）へ `is_protected` を追加する。
`sellable` は catalog 上の通常売却可否のまま。UI は `is_protected`、装備中状態、`sellable` を併せて扱う。

`PUT /api/v1/me/underground/equipment/items/{itemId}/protection`

```json
{"request_id":"server-issued UUID","is_protected":true}
```

トグル要求ではなく希望状態の指定。解除は false。
既存 `POST /api/v1/me/underground/requests` で、この PUT method/path 用の receipt を取得し、
`X-Underground-Receipt` を送る（24時間の既存受付契約）。所有 profile の lock と既存 mutation fingerprint/UUID 台帳を使う。
receipt の取得入力は `{"method":"PUT","path":"/api/v1/me/underground/equipment/items/123/protection"}`。
取得応答は `data={request_id,token,expires_at,format}`。`request_id` を PUT 本文へ、`token` を上記 header へ渡す。
同じ UUID の逆状態への使い回しは409 `underground_request_conflict`、他人の item は409 `underground_equipment_not_owned`。
応答は既存 mutation state (`operation=equipment_protection`, 残高, 装備 summary)。非装備品の状態は vault を再取得する。
PUT 成功応答の形は `data={operation:"equipment_protection",shard_balance,banked_shard_balance,vault:{used,capacity,rating_requirement_bps,equipped}}`。
`equipped` は slot ごとの所有品 projection／null。保護を設定した非装備品そのものはこの PUT 応答へ含めない。
`GET /api/v1/me/underground/equipment/vault?page=1&inventory=equipment`（結晶は `inventory=resonance`）で、
`data.items[*].id` と `data.items[*].is_protected` を読み直す。`is_protected` は boolean。
保護中でも装備・取り外し・研磨は可能。売却保護以外のゲーム能力は変えない。

売却のサーバー保証：

- 個別売却（通常装備と共鳴結晶共通）は409 `underground_equipment_protected`。
- 一括 preview は保護品を常に除外する。条件反転でも保護品を候補へ戻さない。
- 一括確定は全候補を lock 下で再検証し、1件でも保護品なら409 `underground_bulk_sell_preview_changed`。
  全件を残し、Gも加算しない。preview 後の保護設定にも適用する。
- 既存の装備中・売却不可判定と、一括確定の catalog identity／ID／canonical 価格、UUID 再送契約を維持する。
  preview 後に取得した品は追加売却しない。

## 売却条件の方向とエンチャント情報

既存 `POST /api/v1/me/underground/equipment/vault/bulk-sell/preview` の条件へ、任意の `filter_modes` と `affix_keys` を加える。

```json
{
  "item_level_max":200,
  "rarities":["regular","high_quality"],
  "categories":["weapon","armor","accessory"],
  "weapon_styles":["dagger"],
  "affix_keys":["physical_damage_bps"],
  "filter_modes":{"weapon_styles":"exclude","affix_keys":"exclude"}
}
```

`filter_modes` のキーは `item_level_max`、`quality_percent_max`、`rarities`、`categories`、`weapon_styles`、`affix_keys` のみ。
値は `match`（該当）／`exclude`（非該当）。省略時は `match` で従来どおり。
有効な各条件を AND で結合し、同じ選択群の中は OR。
IL/Quality は「指定上限以下」を該当とし、反転はそれを超える測定値を対象とする。
Quality が欠測/null の品は Quality 条件を指定した場合どちらの方向でも除外する。0と同一視しない。
IL/Quality 上限省略はその条件を使用しない。
`rarities`、`categories`、`weapon_styles` は従来どおり配列を必ず送る。
空配列の match はその群に該当なし、exclude はその群で除外なし。
武器種条件は武器だけに適用し、防具やアクセサリーには適用しない。

`affix_keys` は1つ以上の選択 key を**持つ**品への存在条件。空配列／省略は affix 条件なし。
exclude は選択 key を1つも持たない品。固定装備等の affix 空配列も該当する。
共鳴結晶の同一 key 重複は存在判定だけに使い、合計値・強弱・優劣を決めない。
未知の key は拒否する。

`GET .../equipment/vault?inventory=equipment|resonance` の `data.bulk_sell_options` に
`filter_modes=["match","exclude"]` と `affixes=[{key,label,kind,target},...]` を返す。
現在の既存 generator catalog から通常品／結晶に対応する候補を取得し、UI 独自の名称辞書を作らない。
実所有品 `affixes` は既存の `{key,label,kind,target,value,quality_bps}` と、該当する rating／effective 値を維持する。
IL、種類、割合/固定量、研磨、装備要求側倍率が異なるため、label が同じだけでは数値の強弱を決められない。
「同名 affix の最良」「最良装備」「弱い装備を自動売却」は Owner 未決として実装しない。

## Migration と検証境界

追加 forward migration は保護列、bulk 完了記録の nullable fingerprint、
既存地下 request operation CHECK への `equipment_protection` 追加だけを行う。
既存装備・価格・affix・request identity を再生成しない。既存 migration／baseline schema dump は編集しない。
fresh install も既存 DB upgrade も baseline + 追加 migration を使う。
保護と完了 identity を失う rollback を禁止する。本番適用は別 Owner 承認が必要。
