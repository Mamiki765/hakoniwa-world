# 露店・地上合成・チケット・倉庫APIの入口

2026-10-19、`release/5.5.1` の `93bb230b8cff546e43d1b89baf4c7cd916ff616f` と照合した本体側の技術索引。コード統合と本番適用は別であり、本書は適用済みの証拠ではない。

初回合意とJSON例は[5.5.0の記録](../releases/5.5.0-market-synthesis.md)に出所を残す。現行のHTTP契約は本体の[route](../../routes/web.php)、各controller・service、画面の型と既存テストを照合する。巨大なJSON例や別の定義authorityを本書へ複製しない。5.5.0の7日期限は[5.5.1の無期限化](../releases/5.5.1-indefinite-market.md)で更新された。

## 共通の受付と再送

以下のpathは`/api/v1`を起点とし、本人の認証session・通常のCSRF保護を使う。成功は`data` envelope、業務拒否は`message`と`code`。入力validationの`errors`と業務codeは区別する。HTTP statusの個別対応はcontrollerを正本とする。

地底mutationは共通[client](../../resources/js/api/client.ts)が先に`POST /me/underground/requests`へ`method`と完全な`/api/v1/me/underground/...` pathを送り、server発行のUUID・tokenを取得する。`X-Underground-Receipt`・`X-Underground-Request-Id`を付け、bodyに`request_id`がある操作は発行UUIDへ置き換える。取消・seenも受付対象で、GETは対象外。地上の合成・倉庫・チケットへこの地底受付を混ぜない。

受付はprofile・HTTP operation・発行/期限・formatに結び付き、24時間。入口とprofile lock後に検証する。期限後は保存済み結果の再読取だけを許し、未知・削除済み要求を新しい受付へ自動差替えして再実行しない。詳細は[受付service](../../app/Application/Underground/UndergroundRequestAdmission.php)と[middleware](../../app/Http/Middleware/RequireUndergroundRequestAdmission.php)。

露店の出品・購入・受取、合成、倉庫拡張は内容を結び付けた`request_id`と結果snapshotを保存し、同一内容の再送は保存結果を返す。別内容でのキー再利用は拒否する。取消・seenは結果receiptを追加する操作ではなく、対象状態・IDを検証して処理する。既存ガチャの`request_key`は別の契約であり、`request_id`へ改名しない。

## 地底交流場の露店

[controller](../../app/Http/Controllers/Api/UndergroundMarketController.php)・[service](../../app/Application/Underground/UndergroundMarketService.php)・[出品可否](../../app/Application/Underground/UndergroundEquipmentLoadoutResolver.php)を正本とする。地上競売所への露店追加ではない。

| method・path | 入力・結果の要点 |
|---|---|
| `GET /me/underground/market` | filterは`category`・`weapon_style`・`il_min`・`quality_min`・`sort`・`purchasable_only`・`page`。`listings`・`page`・`has_more`でページ送りし、`purchase_il_cap`・`carried_shards`・`fee`を返す |
| `GET /me/underground/market/listable` | 所持品の`listable`・`listable_reason`・`market_minimum_price`・`is_listed`と、`listing_limit`・`active_count`を取得する |
| `GET /me/underground/market/mine` | `active`・`sold`・旧`expired`の一覧と`listing_limit`・`unclaimed_total`。売却履歴は品物の移動・NPC売却後も`item_snapshot`を使う |
| `POST /me/underground/market/listings` | `request_id`・`equipment_id`・`price`。最低価格はNPC売値、同時active上限は5件 |
| `POST /me/underground/market/listings/{listingId}/purchase` | `request_id`・`expected_price`。購入lock内で価格・状態・所持IL・手持ちG・保管庫容量を再検査する |
| `DELETE /me/underground/market/listings/{listingId}` | 本人のactive出品を取消。売却済みは拒否する |
| `POST /me/underground/market/proceeds/claim` | `request_id`・任意の`listing_ids`。NULLなら未受取全件。受取額・対象ID・受取後手持ちGをsnapshotへ保存する |
| `POST /me/underground/market/expired/seen` | 画面に表示した旧期限切れの`listing_ids`だけ送る。GETでまとめて既読にしない |

`purchasable`と`purchasable_only`はIL条件を表す。購入ボタンは`blocked_reasons`も確認し、`own_listing`・`il_cap_exceeded`・`insufficient_carried_shards`・`vault_full`・`resonance_full`を表示する。IL上限は装備中だけでなく、装備可能な全所持装備・共鳴結晶の最大値。装備不能グラムを除外する。保管庫容量は購入済み拡張を含み、出品中も枠を使う。

手数料10%、受取`floor(price × 9 / 10)`、支払は手持ちG。現在のactive一覧・新規出品の`expires_at`はNULL。旧v40の再送snapshotや終端履歴に残る日時は書き換えない。地上の`GET /me/underground/distorted-stone-reminder`と地底stateの`market_attention`は、`unclaimed_count`・`unclaimed_total`・`unseen_expired_count`で統一する。

画面の型・配線は[UndergroundMarket](../../resources/js/components/UndergroundMarket.vue)・[EquipmentItemCard](../../resources/js/components/EquipmentItemCard.vue)、既存検証は[UndergroundMarketTest](../../tests/Underground/Feature/UndergroundMarketTest.php)・[受付test](../../tests/Underground/Feature/UndergroundRequestAdmissionTest.php)・[画面test](../../resources/js/components/UndergroundMarket.underground.test.ts)を参照する。

## 地上合成

`GET /me/secretary/synthesis`は`recipes`・`available`・`crafted_recipe_keys`・`warehouse`を返す。候補ごとにserverが`recipe_key`・`material_ids`・`base_id`・素材表示と`preview`を決める。`preview.result_level`は同種合算・固定レシピの両方に含み、`consumed_count`・`warehouse_delta`と合わせて表示する。

`POST /me/secretary/synthesis`は`request_id`・`recipe_key`・提示された`material_ids`・`base_id`を送る。POST時に同じ素材集合と土台を再検証し、別の所持品へ勝手に差し替えない。同種合算は装備中土台を優先してLv9999以内に収め、土台が9999なら未装備同士へ自動fallbackしない。取引預託中素材を除外し、固定レシピは未装備の素材を使う。未解決Turn guardと装備versionの更新を維持する。

結果は`result`・`consumed_ids`・`base_id`・`equipment_version`・`warehouse`・`first_craft`を返す。通常入手の`max_level`と合成後保持上限を分離し、夢魔の固定成果物はLv1。正本は[controller](../../app/Http/Controllers/Api/SecretarySynthesisController.php)・[service](../../app/Application/SecretarySynthesisService.php)・[定義](../../config/hakoniwa/rulesets/v40/secretary.php)。型と既存検証は[SecretarySynthesis](../../resources/js/components/SecretarySynthesis.vue)・[backend test](../../tests/Feature/SecretarySynthesisTest.php)・[画面test](../../resources/js/components/SecretarySynthesis.surface.test.ts)。

## チケット部分使用と倉庫拡張

`POST /me/secretary/tickets/draw`は既存の`ticket_item_id`・`request_key`に任意の`draw_count`を加える。省略時は残Lvの全消費。回数も再送内容に含め、別回数で同じキーを再利用しない。結果は`items`・残る場合の`ticket`・`warehouse`。全消費なら`used - 1 + draw_count`、部分消費なら`used + draw_count`で容量を判定し、所持品表示の`max_draw_count`も同じ規則を使う。空き0/Lv1は1回、空き0/Lv2は0回、空き2/Lv3は3回、空き2/Lv4は2回となる。

正本は[controller](../../app/Http/Controllers/Api/SecretaryTicketGachaController.php)・[service](../../app/Application/SecretaryTicketGachaService.php)・[presenter](../../app/Application/SecretaryItemPresenter.php)。[所持品型](../../resources/js/types.ts)・[使用dialog](../../resources/js/components/SecretaryTicketDrawDialog.vue)・[既存test](../../tests/Feature/SecretaryItemEffectsTest.php)を合わせて確認する。

`POST /me/secretary/warehouse/expand`は`request_id`・`expected_expansions`を送り、既に提示した拡張回数との一致、最大回数、Pd残高を確認する。100Pdで+50を最大4回、基本50から上限250。返却は`capacity`・`used`・`expansions`・`max_expansions`・`next_cost_paradox`・`paradox_balance`。結果snapshotを再送時に返し、追加課金しない。

正本は[controller](../../app/Http/Controllers/Api/SecretaryWarehouseController.php)・[service](../../app/Application/SecretaryWarehouseService.php)・[共通容量](../../app/Application/SecretaryItemGrantService.php)。型は[拡張dialog](../../resources/js/components/SecretaryWarehouseExpandDialog.vue)、既存検証は[SecretarySynthesisTest](../../tests/Feature/SecretarySynthesisTest.php)にある。地底の装備・共鳴結晶の保管庫とは別の容量である。
