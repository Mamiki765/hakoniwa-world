# 開発計画応答の一括取得

基準: main `2e93ec918774ad64d787aba99fc59a7bc3e54d16`（5.8.3）。
対象: `CommandQueueController::serializeQueue()` を使う一覧、通常登録・編集・取消・並替・bulkの応答。

## 変更と意味の保持

- 表示中の計画に現れる固定座標だけをmap space内で一括取得し、terrain/facilityをeager loadする。同じ座標の反復でも再SELECTしない。
- 整数のtarget Nation IDをまとめて名称を取得する。名称を取得できても、現行の投影で記念碑発射になる行だけへsuffixを付ける。
- 記念碑selectorの名称は、既存quantity resolverが必要とした時だけrequest内で一括取得する。catalog種別判定をControllerへコピーせず、無効化済み選択肢の名称と欠落時のfallbackを維持する。
- 船追従計画と地下枠を固定セル取得へ含めない。投影順、費用、quantity、ID/version、応答項目、秘匿、登録・Turnのfresh lock検証を維持する。cacheをrequest間へ持ち越さない。Ruleset・schema・RNG・ゲーム資産の計算は変更しない。

## 測定と確認

PHP 8.5.8 / PostgreSQL 18.4、Debug32x32合成World・1自国・同じ首都facilityを対象とするfinance計画。Q=1/30をそれぞれ1GETした。基準版は先行監査の固定SHA測定、変更後は今回の隔離runtime測定。

| 指標 | 基準 Q1 | 基準 Q30 | 変更後 Q1 | 変更後 Q30 |
|---|---:|---:|---:|---:|
| 全SQL | 17 | 104 | 17 | 17 |
| 対象MapCell SELECT | 1 | 30 | 1 | 1 |
| terrain SELECT | 1 | 30 | 1 | 1 |
| facility SELECT | 1 | 30 | 1 | 1 |

既存代表へSQL上限と応答の確認を追加し、変更前に30件のMapCell取得と記念碑名称の重複取得で失敗することを確認した。変更後は、空queue、30計画、隠し上書き、記念碑target/名称、無効化済みselector、船追従・沈没後の価格、地下枠、通常mutationと過去版再送のfocused代表が成功した。再利用Worldを使う船の4caseは正規scoped dispatcherで1ファイル・4識別子だけを実行した。

PHPStan level 6でruntime変更2ファイル、Pintで変更PHP 3ファイルを確認した。全suite/CI、本番、PHP 8.5.12での実行は未確認。少数の合成fixtureのSQL件数であり、P95・負荷限界や本番時間の改善率を示さない。読取時点は一括化されるが、APIへ新しいtransactionやlockは追加していない。

## 追加確認と採否

- 採用: 同座標/同Nation/同selectorの反復取得を、この応答の局所batchへ変更する。
- 既存のまま保持: 船は既にID一括取得済み。地下枠と船追従にはセル投影を行わない。記念碑名はenabledで絞らず、登録時のselector検証と表示時の名称解決を区別する。
- 今回不採用: 危険効果のbefore/after間でのprojection memo共有。同queue objectのitemsを差し替えるため、同じmemoを共有すると誤ったcache hitを作る。別transaction/別requestのcacheやWorld lock変更もこの差分へ含めない。
- 静的確認のみ: 不存在target cell/Nationはlookup missのnullを維持する。非通常selectorの既存fallbackはquantity resolverに残す。unsupportedなDB破損状態を網羅する新規fixtureは追加しない。

## English summary

Queue responses now batch fixed target cells, target Nation names, and lazily requested monument labels within one response. Ship-following plans and underground slots keep their existing projection rules; IDs, costs, ordering, disabled selector labels, fallback values, locks, and gameplay remain unchanged.

In an isolated PHP 8.5.8/PostgreSQL 18.4 fixture with 30 plans targeting one capital, total SQL queries fell from 104 to 17; target cell, terrain, and facility reads each fell from 30 to 1. Focused response, replay, ship, and underground checks passed. This is a query-count result, not a production latency benchmark. Rulesets, schema, and RNG were not changed.
