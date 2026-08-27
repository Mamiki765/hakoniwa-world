# 現行秘書アイテムカタログ

> 更新日: 2026-08-28 JST  
> 対象: ver 2.8.0 / `hakoniwa-2s-plus-v18`  
> 用途: 現在実装されている秘書アイテムを一覧化し、後続アイテム案を考える際の基準にする。

この文書は**現行実装の索引**です。Gameplayの正本は immutable Ruleset と実装コードです。ここに書かれた内容とコードが食い違う場合は、最新のreview済みコード / Rulesetを優先してください。

ver 2.8.0 / Ruleset v18の秘書アイテム契約は、v17で追加されたRegular / Cursed Itemを含む現行契約をそのまま利用しています。High Quality / Artifactなど、未実装の将来レアリティや未確定アイデアはこのカタログには含めません。

## 正本への入口

- 表示名・フレーバー・カテゴリ・レアリティ・最大Lv・売却価格: `product/app/Domain/Secretary/SecretaryItemCatalog.php`
- Novice Item効果: `product/config/hakoniwa/rulesets/current/secretary.php`
- Regular / Cursed Item効果: `product/config/hakoniwa/rulesets/v17/secretary.php`
- 効果文・effect contract: `product/app/Domain/Secretary/SecretaryItemGameplayContract.php`
- 怪獣drop pool / 配分: `product/config/hakoniwa/rulesets/v17/monsters-and-military.php`
- 怪獣drop validation: `product/app/Domain/Secretary/SecretaryMonsterDropContract.php`
- NPC交易場出品: `product/app/Domain/TradingPost/TradingPostRules.php`, `product/app/Application/TradingPostTurnService.php`

---

# 1. 共通ルール

## 1.1 装備

- 装備slotは5。
- 同じItemは原則1個まで装備可能。
- 弓: category上限1。
- 衣服: category上限1。
- アクセサリー: category上限99。現行5slotでは実質的にcategory側からの追加制限はない。
- `古びた弓`だけSecretaryごとにuniqueで、starter grantとしてLv1・slot1へ付与される。

## 1.2 倉庫

通常のItem grant / 怪獣dropで扱う倉庫上限は50個。

## 1.3 レアリティ

| key | 表示 | 直接売却価格 | 現行実装 |
|---|---|---:|---|
| `novice` | ノービス | 100億円 | 実装済み |
| `regular` | レギュラー | 500億円 | 実装済み |
| `cursed` | カースド | 1億円 | 実装済み |

直接売却価格はItem Lvによらずレアリティ固定。`古びた弓`は売却不可。

## 1.4 交易場NPC出品

箱庭連合のNPC Item出品対象は、`npc_tradable=true` の**ノービス**のみ。

- Lv: 1～5（Item自身の最大Lvを超えない）
- 開始価格: `Lv × 100億円`
- `古びた弓`は対象外。
- Regular / Cursedは現行NPC出品対象外。

## 1.5 怪獣drop

対象怪獣を撃破すると、受取先の倉庫に空きがあればItemを1個生成する。

- 他島にいる怪獣を倒した場合: 撃破側75% / 怪獣所在島25%
- 自島またはhostなし: 撃破側
- 倉庫満杯時の別島へのrerouteなし
- `メカいのら` / `メカいのら零式`はdrop対象外
- `古びた弓`はdrop poolに入らない

### 怪獣別レアリティ比率とLv上限

| 怪獣 | Novice | Regular | Cursed | Drop Lv上限 |
|---|---:|---:|---:|---:|
| いのら | 70% | 25% | 5% | Item最大Lvの30% |
| サンジラ | 70% | 25% | 5% | 30% |
| レッドいのら | 60% | 30% | 10% | 50% |
| ダークいのら | 60% | 30% | 10% | 50% |
| いのらゴースト | 60% | 30% | 10% | 70% |
| あおいのら | 60% | 30% | 10% | 70% |
| クジラ | 50% | 35% | 15% | 80% |
| キングいのら | 40% | 40% | 20% | 100% |

実際のdrop Lvは1～`floor(Item最大Lv × 上表%)`。最低上限はLv1。

---

# 2. 現行Item一覧

| key | 名前 | カテゴリ | レアリティ | 最大Lv | Player交易 | NPC出品 | 怪獣drop |
|---|---|---|---|---:|---|---|---|
| `old_bow` | 古びた弓 | 弓 | ノービス | 1 | 不可 | なし | なし |
| `ring` | 指輪 | アクセサリー | ノービス | 10 | 可 | あり | あり |
| `secretary_suit` | 秘書のスーツ | 衣服 | ノービス | 10 | 可 | あり | あり |
| `inora_bracelet` | いのらの腕輪 | アクセサリー | ノービス | 10 | 可 | あり | あり |
| `hoarder_talisman` | 蓄える者のタリスマン | アクセサリー | ノービス | 10 | 可 | あり | あり |
| `good_person_treasure` | 善人の秘宝 | アクセサリー | ノービス | 20 | 可 | あり | あり |
| `vault_key` | 金庫の鍵 | アクセサリー | ノービス | 10 | 可 | あり | あり |
| `monster_repellent_incense` | 怪獣避けのお香 | アクセサリー | ノービス | 10 | 可 | あり | あり |
| `fullness_herb` | 満腹草 | アクセサリー | ノービス | 10 | 可 | あり | あり |
| `elf_bow` | エルフの弓 | 弓 | レギュラー | 10 | 可 | なし | あり |
| `longshot_bow` | 遠当ての弓 | 弓 | レギュラー | 10 | 可 | なし | あり |
| `mechanical_bow` | 機械弓 | 弓 | レギュラー | 10 | 可 | なし | あり |
| `collar` | 首輪 | アクセサリー | カースド | 11 | 可 | なし | あり |

---

# 3. Item詳細

## 古びた弓

- key: `old_bow`
- カテゴリ: 弓
- レアリティ: ノービス
- 最大Lv: 1
- 入手: starter grantのみ
- Player交易: 不可
- 直接売却: 不可
- 効果: **10%の確率で、自領の地上にいる安全に攻撃可能な怪獣へ1ダメージ。**
- 攻撃タイミング: missile解決後、通常怪獣行動前
- 備考: Secretaryごとにunique。
- フレーバー: 秘書が捕らえられていた施設の最奥から見つかった、大きく古ぼけた弓。宝石があしらわれており、どこか不思議な力を感じさせる。

## 指輪

- key: `ring`
- カテゴリ: アクセサリー
- レアリティ: ノービス
- 最大Lv: 10
- 効果: **資金繰り時の収入 +Lv億円。**
- stacking: 装備中のLv合計。
- フレーバー: 貴金属が使われた豪華な指輪。魔法の道具ではないが、贈り物にはぴったりだ。

## 秘書のスーツ

- key: `secretary_suit`
- カテゴリ: 衣服
- レアリティ: ノービス
- 最大Lv: 10
- 効果: **秘書本人が経験値を得る際、Lv%の確率で獲得経験値を2倍。**
- 対象: passive skill経験値 / 怪獣経験値
- 例外: `少子化対策`の経験値は対象外。
- フレーバー: 秘書がはじめて袖を通した銘柄のスーツ。まだ着慣れないのか、時々恥ずかしそうにしている。

## いのらの腕輪

- key: `inora_bracelet`
- カテゴリ: アクセサリー
- レアリティ: ノービス
- 最大Lv: 10
- 効果: **自島の通常怪獣自然出現率 +10% × Lv。**
- フレーバー: 怪獣いのらのモチーフが刻まれた腕輪、怪獣が恐れられるこの世界でこんなものをつけたがるのは余程の物好きだろう

## 蓄える者のタリスマン

- key: `hoarder_talisman`
- カテゴリ: アクセサリー
- レアリティ: ノービス
- 最大Lv: 10
- 効果: **あらゆる国家資源の最大保有量 +Lv%。**
- フレーバー: 先立つものはいくらあっても損はない。使わなければいつまでも先に進めないときはあるが。

## 善人の秘宝

- key: `good_person_treasure`
- カテゴリ: アクセサリー
- レアリティ: ノービス
- 最大Lv: 20
- 効果: **KARMAの下限をLvだけ低くする。**
- 判定snapshot: Turn開始時
- フレーバー: ★あなたは良い心を持っている

## 金庫の鍵

- key: `vault_key`
- カテゴリ: アクセサリー
- レアリティ: ノービス
- 最大Lv: 10
- 効果: **資金最大値 +Lv%。**
- フレーバー: 決して複製できない精巧な鍵。この金庫の鍵を作らせた富豪は、暗証番号が思い出せなくて開ける事ができなくなったという。

## 怪獣避けのお香

- key: `monster_repellent_incense`
- カテゴリ: アクセサリー
- レアリティ: ノービス
- 最大Lv: 10
- 効果: **自島の通常怪獣自然出現率 -Lv%。**
- 最終出現率は0未満にならない。
- フレーバー: 炊けばいのらが出現しなくなると信じられているお香。なんとも言えない香りがする…

## 満腹草

- key: `fullness_herb`
- カテゴリ: アクセサリー
- レアリティ: ノービス
- 最大Lv: 10
- 効果: **食料最大値 +2% × Lv。**
- フレーバー: 万病に効くとされていた薬草は、後の時代にて消化に良い酵素が含まれていると判明した。消化は免疫力、医食同源である。

## エルフの弓

- key: `elf_bow`
- カテゴリ: 弓
- レアリティ: レギュラー
- 最大Lv: 10
- 効果: **(11 + Lv)%の確率で、自領の地上にいる安全に攻撃可能な怪獣へ1ダメージ。**
- 発動率: Lv1 12% ～ Lv10 21%
- 攻撃タイミング: missile解決後、通常怪獣行動前
- フレーバー: 永い時を生きた古木の材で継ぎ直された弓。エルフの魔力との親和性が高い。

## 遠当ての弓

- key: `longshot_bow`
- カテゴリ: 弓
- レアリティ: レギュラー
- 最大Lv: 10
- 効果: **(11 + Lv)%の確率で、自領の地上怪獣または地上の生存中「あおいのら」へ1ダメージ。**
- 発動率: Lv1 12% ～ Lv10 21%
- 攻撃タイミング: missile解決後、通常怪獣行動前
- フレーバー: アクアマリンの飾りがついた木製の弓。海の怪獣に効果がありそうだ。

## 機械弓

- key: `mechanical_bow`
- カテゴリ: 弓
- レアリティ: レギュラー
- 最大Lv: 10
- 通常効果: **(9 + Lv)%の確率で、自領の地上にいる安全に攻撃可能な怪獣へ1ダメージ。**
- 通常発動率: Lv1 10% ～ Lv10 19%
- finisher: 通常1ダメージでは危険状態を作るHP2怪獣に対し、通常発動率の40%で2ダメージの撃破攻撃。
- finisher発動率: Lv1 4% ～ Lv10 7.6%
- 攻撃タイミング: missile解決後、通常怪獣行動前
- フレーバー: 特殊なからくりによって、通常より強く弦を引き絞れるよう補強された弓。扱いは難しいが、力を込めた強烈な一撃を放てる。

## 首輪

- key: `collar`
- カテゴリ: アクセサリー
- レアリティ: カースド
- 最大Lv: 11
- 条件: Turn開始時KARMAが1以上
- 効果1: **得られる難民 +(4 + Lv)%**
- 効果2: **街への攻撃で得る正のcrime pointsを (4 + Lv)% の確率で2倍**
- 効果率: Lv1 5% ～ Lv11 15%
- フレーバー: それをつけさせるのは狂った愛か支配欲か、はたまた自分の傍から離したくないと思わせるエルフの魔性なのか。

---

# 4. 現行drop pool

## Novice

- `ring`
- `secretary_suit`
- `inora_bracelet`
- `hoarder_talisman`
- `good_person_treasure`
- `vault_key`
- `monster_repellent_incense`
- `fullness_herb`

## Regular

- `elf_bow`
- `longshot_bow`
- `mechanical_bow`

## Cursed

- `collar`

---

# 5. 後続Item追加時の使い方

新しいItemを考える際は、最低限次をこのカタログへ追記してからRuleset化する。

```text
名前 / key
カテゴリ
レアリティ
最大Lv
同Item装備制限（既定1から外す場合のみ）
Player交易可否
NPC交易可否
入手経路
Lvごとの効果式
フレーバーテキスト
```

将来レアリティ、船・探索・海底財宝など未実装systemと結びつくItemは、実装契約が確定するまではこの「現行カタログ」へ混ぜず、別のidea memoで管理する。
