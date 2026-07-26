# Modifierシステム

## 目的

施設、研究、アイテム、地形、災害が与える加算・減算・倍率・上下限効果を、巨大switchや任意コード実行にせず、安全で追跡可能な共通表現へ整理する。

## 背景

箱庭諸島2＋は効果計算が定数・配列・switchへ分散し、やまにてぃはclass化を進めながらも各Cell、Plan、Disasterに数値と分岐が分かれる。新作では共通する数値補正だけを限定的な仕組みに集約する。

## 既存作品との関係

旧実装の地形・施設固有ルールを直接移植せず、新作のresources、facilities、research、itemsが共通target keyを介して効果を供給する。首都の非破壊など、数値補正ではない規則は対象外とする。

## 対象となる効果

- 食料生産量の加算・減算。
- 魔力生産量の加算。
- 工業力、研究速度の倍率。
- 災害発生率の加算・減算。
- 国境抵抗、ミサイル命中率、防御軽減。
- 必要労働人口の減少。
- 施設の維持費、建設時間、復旧速度。

すべてをModifierだけで実装するわけではない。首都非破壊、所有権不変、command状態遷移のような不変条件は型付きdomain policyに残す。

## 暫定設計

```text
基礎値
→ 固定加算と固定減算
→ 倍率
→ 上限・下限
→ domain固有の最終不変条件
```

同じ段の合成は決定的にし、source作成順やDB取得順に依存させない。丸めは各途中段階ではなく、指定された境界で一度行う。率はbasis pointsまたは固定精度decimalを候補とする。

## Modifier定義

- target_key: food.production、disaster.rate、border.resistance等。
- operation: add、multiply、min、max、override候補。
- valueとunit。
- scope: nation、cell、facility、command、event、layer、world。
- radiusまたはarea selector。
- conditions: terrain、owner、facility state、season等の許可済み条件。
- stacking_groupとstacking_rule。
- duration: start_turn、end_turn、remaining_uses。
- source_type、source_id。
- priority。
- visibility。
- definition_version。

target、operation、unitの許可組合せをcatalogで固定する。JSONにclass名や式文字列を保存してevalする方式は採用しない。

## stacking

候補規則はstack_all、highest_only、lowest_only、replace_by_priority、unique_per_sourceである。同一効果の重複をsource identityだけで判定すると、同じ施設typeを複数建てた場合の意図が曖昧になるためstacking_groupを明示する。

倍率は原則として積算か、倍率差分の加算かで差が大きい。例として1.2と1.3を積算すると1.56、差分加算なら1.5となる。targetごとに規則を変えず、rulesetの共通規約を優先する。

overrideは理解しにくく他効果を無効化しやすいため、首都保護など明確なpolicy以外では限定する。

## 範囲効果

radius効果はHexCoordinateのdistanceを使う。source cell、target layer、owner relation、自国・同盟・敵国・中立のfilterを持つ。範囲内の全セルへ永続modifier行を複製せず、sourceと定義から導出する案を基本とする。

高頻度計算で重い場合は、chunkごとのeffective modifier投影をcacheする。source配置、破壊、所有者変更、ruleset更新でversionを変え、古いcacheを無効化する。

## データモデル例

- modifier_definitions: stable_key、target_key、operation、unit、stacking_group、stacking_rule、priority、condition schema。
- modifier_sources: source_type、source_id、definition_id、value override、start_turn、end_turn、remaining_uses。
- modifier_scopes: layer、coordinate、radius、nation relation等。複雑化する場合のみ分離。
- modifier_evaluation_traces: debugまたは管理検証用。恒久全件保存はしない。

定義の可変条件をJSONBへ持たせる場合も、許可された演算子とfieldだけをschema validationする。

## 処理フロー

1. 計算側がtarget_key、base value、対象contextを渡す。
2. 該当scopeの有効sourceを収集する。
3. conditionを型付きpredicateで判定する。
4. stacking groupごとに重複を解決する。
5. priorityと安定IDで決定的に整列する。
6. add、multiply、clampを規定順で適用する。
7. domain固有のcapと不変条件を適用する。
8. 最終値と説明用breakdownを返す。

計算結果を正本として保存するのではなく、入力sourceとrulesetを正本とする。ただしターン結果eventには適用した主要modifierと最終値を記録し、再現可能にする。

## 例

基礎食料生産1000、施設の固定加算200、研究倍率1.10、災害減衰倍率0.80、下限0の場合、規定式は (1000 + 200) × 1.10 × 0.80 = 1056 となる。どの段階で整数へ丸めるかをtarget定義に持つ。

この例は計算規約の説明であり、ゲーム数値の確定ではない。

## ゲームバランス上の懸念

- 加算は小規模国家に、倍率は大規模国家に有利になりやすい。
- 災害率減少を重ねて0にできるか、最低発生率を置くか決める。
- 命中率・軽減率は0から100%の範囲を明示する。
- 必要労働人口減少が0人以下にならない下限を置く。
- research倍率とitem倍率の複利が長期に暴走しないよう上限を試験する。
- 防壁都市は完全不可侵ではなく、耐久や抵抗として征服可能性を残す。

## 性能上の懸念

各セルごとに全modifier definitionsを検索しない。対象key、layer、chunk、nation、active turnで索引し、turn context内で共通sourceをmemoizeする。radius効果は空間索引またはsourceが属する影響chunk一覧を使う候補がある。

最適化前に、active source数、評価回数、cache hit、target別所要時間を計測する。古い投影を使って誤ったターンを確定するより、cache missで再計算する方を選ぶ。

## セキュリティ上の懸念

- 管理画面から任意PHP、SQL、正規表現、テンプレート式を実行しない。
- target key、operation、条件fieldをallowlist化する。
- valueに型・単位・範囲制約を付ける。
- 他国非公開sourceをAPI breakdownへ露出しない。
- ruleset公開前に循環、無効対象、極端値をsimulationする。

## 代替案

- 各施設classに計算を完全実装: 型安全だが横断効果と説明が重複する。
- 汎用ルールエンジン: 柔軟だが安全性・debug・性能が過剰に複雑。
- DB式評価: 運用変更しやすいが任意コード化しやすく不採用。
- 限定Modifier＋domain policy: 柔軟性と安全性の均衡がよく暫定推奨。

## 利点

加算・倍率・上限の順序を全機能で統一でき、効果の出典と計算breakdownを説明できる。新施設や研究の多くを既存targetとの組合せで追加できる。

## 欠点

scope、stacking、cache無効化が複雑になり、何でもModifier化するとdomainルールが読みにくくなる。誤ったrulesetが全世界の数値へ広く影響する。

## 未決定事項

- 倍率合成と丸めの全体規約。
- priorityの意味とoverride許可範囲。
- radius cacheの方式。
- 同盟・敵対関係をscopeへ含める時期。
- playerへどこまで計算breakdownを見せるか。
- ruleset変更で既存の期間modifierをどう移行するか。

## MVP縦切りで必要か

不要。Modifier table、合成engine、限定的な小さな核も最初のMVP縦切りへ先行実装しない。将来の生産・災害・防御を実装する前に、別々の倍率規約を作らないようtarget、add、multiply、clamp、source追跡を共通設計する。

## 後回しにできるもの

複雑な条件式、同盟relation、長距離area、player向け完全breakdown、事前計算cache、override operationは後回しにできる。
