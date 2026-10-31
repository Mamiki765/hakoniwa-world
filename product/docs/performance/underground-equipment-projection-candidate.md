# 地底装備snapshot共有の残候補（BU-02）

確認基準: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。**重複読取は静的に確認済み。本PRでは未採用。**

`UndergroundIntroService::projectState()`はcombatLoadoutとsummaryを呼ぶ。`UndergroundEquipmentLoadoutResolver::summary()`はratingRequirementBps→combatLoadoutと、自身のequipped SELECTを行い、一状態応答で同じequipped集合を3回読む。`SecretaryUndergroundStatusPresenter::present()`にもcombatLoadout後のequipped SELECTがあり2回読む。generated definition検証/研磨適用も反復する。inventory countは別SQLであり、この3回/2回に含めない。

同一操作のrows・検証済definition・aggregateを明示的に渡せば3→1/2→1 SELECTを狙える。profileを跨ぐcacheや、equip/unequip/polish/sell/market変更後の古いsnapshot再利用は不可。公開statusはlabel/IL/qualityのみを返す秘匿境界を維持する。

追加照合でresolverが既存relationを使わず必ずqueryすること、公開presenterにも同じ集合取得があることを確認した。一方、SQL listenerによる削減実測・mutation直後の能力/HP/rating・公開秘匿のfocused比較は未実施である。BU-01と異なりmutable装備のsnapshot寿命を設計する変更になるため、同PRには入れず独立候補として保持する。速度効果はDB往復と装備数に依存する。

## English summary

Repeated equipped-item reads are confirmed statically: three reads in an intro-state projection and two in public status. A single-operation snapshot could remove duplicates, but mutation freshness and public-field restrictions need separate focused validation. Deferred from the immutable-catalog change; no persistent equipment cache is proposed.
