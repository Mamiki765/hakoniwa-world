# エコポ

5.3.0 / v38 draft。Ownerが承認した、島に属する非取引ポイント。`nations.ecopo` は非負の通常BIGINTで、資源catalog・交易対象・資源動態には追加しない。本人の島API `/api/v1/nations/{id}` に整数 `ecopo` を追加する。公開島APIには追加しない。画面担当が既存の詳細欄へ表示できる契約までとし、このPRはUIを変更しない。

## 最終破棄を確定する場所

容量計算・仮の超過量では加算しない。既存transaction内で実際の消滅量を確定した呼出元が、`EcopoService` を一度呼ぶ。資金・資源・ポイント・auditは同じTurnまたは既存操作のtransactionに属し、失敗時には全てrollbackする。ポイント専用の第二台帳は作らない。

| 経路 | 加算する量 | 二重計上しないもの |
|---|---|---|
| `FoodOverflowResolver` | `inventoryRemaining` = `discarded_tons` | 生産直後の超過、人口消費、売却分、quoteの`overflowMoney` |
| Turn末の在庫容量適用 | 自動売却後に在庫から除いた`discarded` | 売却できず保持されている在庫、資源売却の要求額 |
| 交易配送 | 食料は上記resolver、その他は配送の最終`discardedQuantity` | `trading_post.sold`等の同じ配送を説明する別audit |
| 確定収益 | `requested - applied` のうち上限で失われた金額 | 取引手数料、未実行の売却、返金・escrow保持 |

対象の確定収益は資金繰り（指輪補正・休眠heartbeatを含む）、伐採、埋蔵金、怪獣賞金、連合賞金、観光船収益、ピザ収益、交易出品者の売上。現在の船が非食料の移動報酬を返す場合も、その確定overflowだけを対象とする。

援助の不送付・補填倉庫の未受取・売却失敗・`preserved=true`の既存在庫・消費前の食料超過には付与しない。通常消費・燃料・維持費・災害損失・島放棄の資産消去も対象外。島放棄から加算処理を呼ばず、残高は旧Nationの履歴に残り、新しい島へ引き継がない。

## 換算と交換

v38の`ecopo.points_per_money`は1億円につき1000点。資源は既存の`inventory_sale_rates`を用い、`money_units × points_per_money ÷ inventory_units`を1保管単位の点数とする。小麦・魚・鉱物・工業品は1点、怪獣肉は2点、石油は1万バレル単位で2000点。電力は明示的に0点。率を別catalogへ写さず、整数点にならない設定はauthoringで拒否する。

Turnの`finalize_turn`内、lifecycleがmembershipを終了する前に、1000万点ごとに1Pdへ交換し端数を残す。Pdは現在の島ownerの既存`ParadoxBalanceService`へ付与する。既存ledgerの`compensation`（付与）分類を使い、`metadata.reason=ecopo_exchange`と`ecopo:{world_id}:{target_turn}:{nation_id}`で出所と一意性を識別する。新たなPd台帳・balance系統・source CHECKの変更は行わない。

本人の島ログには`ecopo.exchanged`として「突然ニョワミヤが現れエコポ○○○点回収し輝石○Pd置いていきました」を表示する。`ecopo.credited`は同transactionの監査根拠で、資源動態や通常の本人ログへ各破棄の新しい行を増やさない。

## 移行と固定境界

公開使用済みv37のpayload/checksumは変更しない。v38は同じ5.3.0のN+1 draftであり、後続の5.3.0変更も同じdraftへ統合する。forward migrationは既存Nationへ0点を追加し、v37 Worldとqueued commandのdefinitionをv38へ更新する。元のrequest identity、資産、実績・船のprovenanceを保持し、未解決の公式Turnがあればpublicationを拒否する。

fresh installではappend-onlyの5.2.0 migrationがv37を要求する。この**固定原文と完全一致するpayloadだけ**を既存publisherで再生できるようにする。変更したhistorical payloadのauthoringや過去Worldのmutationを許可するものではない。新しい列とCHECKはbaseline verifierで正確に検証し、それ以外の既存schema fingerprintは維持する。

生存怪獣のRuleset境界問題は別の5.2.2緊急修正担当の範囲。このPRでは修正を重複実装せず、5.3.0を適用する前にその修正の取り込みと確認を必要とする。本番適用は行わない。

## 過去分のpreview可否

オンラインの0点開始を、不確実な遡及で止めない。今回、production auditの現存期間・欠損・最初と最後のIDは取得していない。過去分の付与や、本番を読むpreview commandは未実装。

ソース上は、次の**根拠が残っている期間だけ**を読み取りpreviewにできる。

- 食料：`resource.food_overflow_resolved.discarded_tons`を唯一の根拠にし、`trading_post.sold.overflow_discarded_quantity`等の重複表現を数えない。
- 非食料：`capacity.overflow`で`asset=resource`かつ`discarded=true`の`overflow`だけ。`preserved=true`は除外する。生成量・requested量・自動売却の失敗額から推定しない。
- 資金：伐採・資金繰り・埋蔵金・連合賞金・船の移動・怪獣報酬・交易売上の、消滅を示す個別のbounded結果だけ。public/privateの二重表現をevent familyごとに一つへ限定する。旧ピザauditなど金額不足が明示されていない経路は、確定できる欄がなければ対象外にする。

実装する前に、保全したauditの最大IDをcutoffとして固定し、online開始の境界より前に限定する。成功した公式Turnと、transactionがcommitした本人操作だけを対象にし、`turn_run_id`・nation帰属・食料子event/配送親event・怪獣killer/hostの重複を照合する。欠損経路は対象外として一覧にする。転生後の現ownerや同名の別Nationへ付け替えず、当時のNation IDへ集計する。previewは付与と分離し、適用・再実行一意性・廃棄済み島の扱いは結果を見てOwnerの別判断を得る。
