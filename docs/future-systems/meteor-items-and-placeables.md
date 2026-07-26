# 隕石アイテムと設置物

## 目的

隕石落下時に一定確率で発見されるアイテムを、落下地点の採取可能物、国家倉庫、首都への設置物として扱う。希少アイテムは破壊時にも完全消滅させず、争奪と回収の余地を残す。

## 背景

災害を単なる損失ではなく、riskを伴う機会へ変える構想である。設置中だけ国家や周辺領土へ効果を与えるため、所有、場所、状態、durability、効果sourceを明確に分ける必要がある。

## 既存作品との関係

- meteor eventがitem discoveryの契機になる。
- map space上のcoordinateとlayerに落下物を置く。
- commandsが回収、輸送、設置、撤去、修理を扱う。
- resourcesが回収・修理費を扱う。
- modifiersが設置中の効果を表す。
- turn eventsとoutboxが発見・争奪・破壊を通知する。
- capital policyが首都への設置可否と保護範囲を制約する。

## 暫定設計

隕石から得たitemを個別instanceとして作り、ground、inventory、placed、damaged等の状態を明示する。希少placeableは通常被害で即消滅させず、durability、回収、修理、争奪を通じて状態を変える。

## itemのライフサイクル

```text
definition
→ meteor drop candidate
→ ground drop
→ claimed or recovered
→ nation inventory
→ placed
→ damaged
→ repaired / withdrawn / captured / lost
```

consumableは使用後にconsumedへ遷移する。希少なplaceableはdurabilityが0でもlostまたはdisabledとして残し、回収・修理できる案を基準にする。

## データモデル例

### item definitions

stable key、category、rarity、stackable、max stack、placeable、durability policy、allowed layers、allowed placement、modifier definitions、visibility、metadata schema versionを持つ。

### item instances

definition_id、serial identity、state、owner_nation_id nullable、quantity、durability、metadata、created_event_id、updated_turnを持つ。

state候補はgrounded、inventory、placed、damaged、consumed、lost、captured_pendingである。stateとowner・coordinateの許可組合せを不変条件として検証する。

### item locations

groundまたはplaced時のmap_space_id、signed axial q、r、placement_slot、claim_stateを持つ。inventory時はnation inventory relationを持ち、同時にcoordinateを有効にしない。UI用odd-q座標は保存しない。

### item transitions

instance、from_state、to_state、actor_nation、command、event、coordinate、turnを監査する。希少品は移動履歴を長く保持する。

## 処理フロー

隕石からの付与は次の順で行う。

1. 隕石eventが確定し、落下セルとimpact strengthを決める。
2. rulesetのloot profileからitem付与抽選を行う。
3. 当選したinstanceをgroundedとして落下地点に作る。
4. 所有者は即決せず、落下セルのownerや回収commandに基づいてclaimする。
5. 発見visibilityに応じてpublicまたはprivate eventを作る。

隕石の発生抽選とitem抽選は用途別乱数streamを分け、item追加で隕石位置が変化しないようにする。

## 回収

落下地点を所有しているだけで自動回収する案と、回収commandを要求する案がある。暫定推奨は短い保護期間中はセル所有国だけが回収でき、その後は到達・占領条件を満たす国家が争奪できる方式である。

回収完了後は国家inventoryへ入る。capacity、輸送距離、必要資源、敵対行為扱いは未決定。落下地点が中立地でも、遠隔から即回収できないよう位置関係を検証する。

## 首都への設置

初期案では自国首都に設置可能とする。placement slotを有限にし、設置中だけ国家全体または首都周辺へmodifier sourceを作る。設置・撤去はcommandとして次turnに確定し、同じitemの重複設置をDB制約とstate machineで防ぐ。

将来、首都以外の施設や地下・宇宙へ設置する場合はallowed placementを追加する。任意coordinateを許す前に、所有、占領、破壊、回収の規則を定める。

## 効果例

- 隕石発生率の加算または減算。
- 食料生産量の加算。
- 魔力生産の倍率。
- 国境抵抗。
- 災害被害軽減。
- 研究速度向上。

効果数値は本Phaseで確定しない。item definitionはmodifier stable keyを参照し、任意式を実行しない。

## 代替案

破壊・所有権変更時の主な代替は次の通りである。

設置セルが被害または所有権変更を受けた場合の候補は次の通り。

| 挙動 | 長所 | 欠点 |
|---|---|---|
| 無効化 | 希少品を失わず復旧可能 | 争奪のrewardが弱い |
| durability減少 | 段階的被害を表現 | 修理balanceが必要 |
| 完全消滅 | 緊張感が高い | 希少品喪失で離脱を招く |
| 現地drop | 誰でも回収可能 | state競合と争奪ルールが必要 |
| 元所有者inventoryへ戻る | 喪失感が小さい | 安全な撤退として悪用可能 |
| 新所有者が奪取 | 占領rewardが明確 | 強者の雪だるま化 |
| 一定期間保護 | 復旧機会を与える | 状態とUIが増える |

暫定推奨は、通常被害ではdurability低下、0でdisabledかground drop、領土変更では短い争奪期間を経て回収可能とする。希少品は通常処理で完全消滅させず、管理上の削除だけを監査付きで行う。

首都施設自体は占領・消滅しないが、設置itemまで絶対安全にするかは別問題である。首都被害により一時無効化・durability低下する案が、攻撃を無意味にしない。

## 利点

災害を損失だけでなく探索・争奪機会に変え、国家のbuildへ一時的または希少な差異を与えられる。definitionとinstanceの分離により、個体履歴とbalance定義を両立できる。

## 欠点

所有、位置、durability、回収競合の状態が増え、希少品喪失はplayer離脱につながり得る。国家間移転を許すと複製・RMT・不正取引対策も必要になる。

## ゲームバランス上の懸念

- 希少itemの効果を複利倍率だけにしない。
- 同じitemのstackingとslot capを設ける。
- 隕石増加itemが自己増殖loopを作らないcapを置く。
- 強国領土へ落ちる確率が面積に比例しすぎる問題を補正する。
- 中立地や国境へのdropで争奪を生む一方、新規国家保護を侵害しない。
- recover・repairに資源sinkを設けるが復帰不能にしない。

## 性能上の懸念

item instance数はセル数より少ない想定だが、消費済み履歴は増える。active instanceとhistoryを分離またはpartitionする。範囲効果は設置物変更時にchunk modifier cacheを更新し、全turnで全itemを全セルへ照合しない。

## セキュリティ上の懸念

- item instance IDを推測されても他国itemを操作できない認可。
- 回収・設置時にowner、coordinate、state、turnを再検証。
- 同じinstanceを二重回収・複製できない一意制約とlock。
- 非公開dropをAPI、ログ、Mariachangへ漏らさない。
- 管理者付与・回収は理由とactorを監査。

## 未決定事項

- 自動回収かcommand回収か。
- ground dropの保護期間と争奪条件。
- inventory capacityと国家間移転。
- 首都slot数、item重複、設置・撤去時間。
- durability 0と領土変更時の最終挙動。
- season終了時の希少item持越し。

## MVP縦切りで必要か

不要。item table、UI、専用event・modifier・state machineを最初のMVP縦切りへ実装しない。不変のcoordinateと将来拡張境界を利用し、gameplayの核が安定してから追加する。

## 後回しにできるもの

国家間取引、首都以外への設置、inventory capacity、複数slot、修理専門施設、season持越し、希少品の公開図鑑は後回しにできる。
