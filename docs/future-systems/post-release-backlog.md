# 初回公開後backlog

PR23は箱庭諸島２S＋の初回production baselineを固定する。以下は初回公開へ含めず、別roadmapとowner decisionまでextension boundaryだけを維持する。

## 賞（ver 1.3.0で実装済み）

AWARD-01は`docs/decisions/ADR-0009-ver-1.3.0-awards-and-classic-top.md`で決定済みである。新しい賞、賞の取消、gameplay bonus、別周期、historical award backfillは引き続き将来のowner decisionとする。

## Combatと報復

- 報復・反撃system
- dormant territoryへの攻撃・占領
- Capital周辺保護と防壁

PR22のpublic/private missile visibilityとactive Nation限定targetを維持し、報復予約やhidden metadataを先行追加しない。

## Lifecycle

- 30日経過による休眠状態遷移Job
- detailed dormancy、復帰、領土解放、沈没
- player放棄、取消期間、再認証、cooldown、再入植

PR23の登録turn、経過turn、連続資金繰り回数は表示だけに使い、state transitionを起こさない。T-02はOpenのまま維持する。

## 資源と施設

- 石油resource stockpile
- 万barrel単位
- 油田からの石油resource生産
- その他の追加resource
- その他の追加facility

現在の海底油田による直接資金収入と枯渇は維持する。石油を新しい保有resourceとして追加する変更は別roadmapとする。

## UIと運用

- measured UI redesign
- tooltip改善
- 高度なaccessibility polish
- moderation通報管理画面、期限管理、高度appeal workflow、自動禁止語判定
- アカウント・Nation・turnの停止、島沈没、人口被害、強制地形変更などの管理・天罰system
- stale-running自動回収、backoff付きretry、retry上限、外部通知
- continuous WAL archive、PITR、RPO 15分以内
- event archive、集約、削除（100万eventまたは実測問題で再判断）

PR23は初回公開に必要な安全性とoperator手順だけを実装し、これらの将来機能を空schemaや未使用hookとして先行させない。

---

## 秘書の地底探索RPG（Owner構想メモ・未実装）

> Status: FUTURE / ROADMAP  
> 記録日: 2026-08-28  
> この節は会話中の企画案を失わないための保存場所であり、現行gameplay、2.9.0 scope、Ruleset v19、schema、migration、実装承認ではない。実装開始時には改めてOwner decision、設計gate、独立roadmapを作る。

### 企画の核

秘書が、不思議な力で封印された地底世界へ迷い込み、探索と戦闘を行うside content。

- 基本は秘書一人での探索。
- 最大4人partyを候補とする。
- browser上で動く自動戦闘を基本とし、playerは装備、skill、行動方針を整える。
- playerが細かく設定しなくても遊べるが、調整したいplayerにはAI設定の深さを残す。
- class名を秘書へ後付けするのではなく、skill treeと装備の選び方からattacker / tank / healer / balancedの役割が生まれる。
- 地底は箱庭本編の経済を必須にする攻略contentではなく、秘書を育て、装備を拾い、戦闘logを見る暇つぶし寄りのcontentを想定する。

地底は現行の共有地上Worldとは別のgameplay境界とする。現行のNation、MapCell、Turn、Secretary Item、倉庫をそのまま流用するかは未決定であり、将来実装を理由に先行schemaやgeneric frameworkを追加しない。

### 世界観

- 地底は視界が悪く、狭い通路や部屋が連続する。
- 怪獣を射抜くほどの巨大な弓は、十分に引き絞る空間がなく使用できない。
- 秘書は短い武器、盾、輝石を媒体にした魔法などで身を守る。
- 輝石の力を限界まで引き出すと、秘書は「真の姿」または戦闘形態を解放し、大技を放つことがある。
- 真の姿は世界観上の覚醒表現であると同時に、将来の3:4 profile image追加理由にもできる。
- 秘書の性別、種族、体格、経歴はplayer設定を尊重し、武器やskillをエルフだけにrace lockしない。ペリドットは公式例・標準イメージとして扱えても、全秘書を同じ人物像へ固定しない。

### 戦闘形式の第一候補

#### 行動枠

各秘書は次を持つ。

- 装備skill: 5個
- 通常攻撃: 常設
- 防御: 常設
- 覚醒 / Limit相当: 通常5枠とは別枠

通常攻撃をskill枠へ入れさせず、5枠すべてをbuildの個性へ使えるようにする。

初期候補はround制で、各actorは原則1 roundに1 action。敏捷は行動順、先制、割込み、cooldown補助へ使い、初期版から行動回数そのものを無制限に増やさない。速度が攻撃、回復、resource獲得、状態異常、cooldownを同時に倍加して最強能力になることを避ける。

#### AI設定

playerは0〜20件程度の条件分岐を、上から優先順に設定できる。

例:

```text
1. 自分のHPが30%以下
   → 自己治癒

2. 敵が大技を準備中
   → 盾撃

3. 敵がBREAK中
   → 必殺技

4. 常時
   → 連続斬り
```

条件候補は、複雑なscript言語にせず、次程度へ限定する。

- 自分 / 味方 / 敵のHP割合
- 戦闘不能の有無
- 状態異常 / buff / debuffの有無
- 敵が大技を準備中か
- 敵人数 / 味方人数
- 自分の固有resource量
- 敵の弱点 / BREAK状態
- skillが使用可能か
- bossか通常敵か

設定が0件の場合、または全条件が不成立の場合は、装備skillと現在状態からbuilt-in AIが行動する。ライト層はskillを5つ選ぶだけで遊べる。

戦闘logには、可能なら「なぜその行動を選んだか」を短く残し、playerがAI設定を直しやすくする。

### 役割とskill tree

固定classは作らず、少なくとも次の3方向を候補とする。

- **戦技**: 攻撃、会心、連撃、BREAK、防御貫通、追撃、処刑
- **護身**: HP、軽減、盾、受け流し、挑発、かばう、反撃
- **祝福**: 回復、障壁、浄化、強化、弱体、光属性攻撃、輝石術

戦技を深く取ればattacker、護身を深く取ればtank、祝福を深く取ればhealer / supportになる。balancedは専用の第四treeではなく、3treeを必要な割合で取った結果として成立させる。

各treeには、buildの方向を決めるkey nodeを置ける。

例:

- 戦技: BREAK中の敵への追撃、低HP敵への処刑、combo継続
- 護身: 防御成功時に闘志を得る、反撃強化、味方を守る
- 祝福: 余剰回復を障壁へ変換、浄化時に恩寵獲得、光攻撃強化

### 役割職も一人旅で戦えるための還元

一人旅でtankやhealerの役割行動を死にskillにしない。ただし、party人数が少ないほど無条件に火力倍率が上がる仕組みを基本案にはしない。party人数、召喚、一時離脱で性能が不自然に変わり、役割と無関係な編成最適化を生みやすいためである。

代わりに、役割行動そのものから攻撃へ使えるresourceを得る。

#### Tank候補: 闘志

- 防御、受け流し、かばう、挑発成功、敵の大技を止めることで闘志を得る。
- 挑発対象がすでに自分を狙っていた場合は、target変更の代わりに少量の闘志を得る。
- 盾撃、反撃、大技で闘志を消費する。
- 防御力や最大HPをそのまま100%攻撃力へ変換せず、複合係数または上限付き変換にする。

これにより一人旅では挑発が完全な空振りにならず、partyでは本来どおり味方を守る。

#### Healer候補: 恩寵

- 光属性攻撃、実効回復、浄化、障壁で恩寵を得る。
- 大回復、蘇生、強い光魔法で消費する。
- 余剰回復は全量をdamageへ変換せず、一部を障壁または次の光攻撃強化へ変換できる。
- 難しい戦闘では回復行動が増えるため、attackerとの差は自然に広がる。

#### Attacker

attackerは役割行動を経由せず、攻撃からcombo、BREAK、処刑、追撃へ直接つながる。tank / healerの攻撃skillを実用的に保ちながら、瞬間火力、周回速度、条件付きの上限はattackerが最も高くなるようにする。

### 火力目標の仮置き

同level・同装備grade・単体継続戦闘の初期目標:

| build | 相対火力の候補 | 主な強み |
|---|---:|---|
| attacker | 100 | 周回速度、瞬間火力、高い天井 |
| balanced | 88〜92 | 敵を選ばない、AI事故が少ない |
| tank | 78〜84 | 高耐久、妨害、反撃、失敗しにくい |
| healer / support | 75〜82 | 回復、浄化、立て直し、長期戦 |

これは木人相当の仮目標。難しい実戦ではtank / healerが役割行動へactionを使うため、実戦damage差はさらに広がる。

同じbuildが、安全性、周回速度、resource効率のすべてで他buildを上回った場合はbalance失敗とする。

### 能力値とインフレ耐性

能力値は複雑すぎない5系統程度を候補とする。

- 生命: HP、物理耐性、障壁、tank skill
- 武力: 物理攻撃、武器skill
- 技巧: 会心、状態異常、弱点、命中安定
- 精神: 魔法攻撃、回復、魔法耐性、支援
- 敏捷: 行動順、割込み、cooldown補助、回避

確定名・確定数ではない。

防御は単純な`攻撃力 - 防御力`ではなく、同level帯の基準値と防御値の比率で軽減する方式を候補とする。攻撃と防御が同じ比率で成長した時に、拡張後も戦闘tempoが大きく崩れないようにする。

固定値だけのdamage、回復、毒、障壁は高levelで空気になりやすいため、能力値係数、対象最大HP割合、percent低下、resource割合などを組み合わせる。bossへの最大HP割合damageには使用者能力基準の上限を置く。

MP、闘志、恩寵、覚醒などの戦闘resourceは0〜100の固定scaleを基本候補とし、levelとともに最大値が何万へ膨らむ設計を避ける。

### 覚醒 / 真の姿

戦闘中に0〜100の覚醒gaugeを貯め、満たした時に輝石の力で真の姿を解放する。

獲得方法はbuildごとに役割へ沿わせる。

- attacker: damage、弱点、会心、BREAK、combo
- tank: 防御、受け流し、かばう、大技阻止
- healer: 実効回復、浄化、障壁、危機からの立て直し
- balanced: 複数の小さな獲得条件

覚醒は、単発のLimit技、数actionの変身、または変身後に専用大技を1回使う方式を候補とする。継続時間とdamage倍率は未決定。

AI条件には、boss戦、敵BREAK中、自分HP条件、party危機時などの覚醒triggerを設定できる余地を持つ。

覚醒gaugeは戦闘内resourceの第一候補であり、account側のPDを毎戦消費させる前提にはしない。

PDはParadigmでありPeridotでもある輝石resourceとして、箱庭本編の奇跡、時間短縮、特別行動などへ使う別候補。覚醒gaugeとPDを同一にするか、PDで覚醒を補助できるかは別decisionとする。

### 装備とhack-and-slash

地底では、敵、宝箱、部屋、bossなどから装備が継続的に落ちるhack-and-slash要素を候補とする。

装備instanceは概念上、次を持てる。

- base item / 武器種
- item levelまたは装備grade
- rarity
- 1〜3個程度のaffix
- 稀なunique effectまたは特定skill変化

rarityは単なるattack / HPの桁差だけでなく、affix数、roll範囲、特殊効果、build変化の幅で差を付ける。

古い装備を即座に無価値にしにくいaffix例:

- percent damage / resistance
- 闘志・恩寵・覚醒の獲得量
- cooldown短縮（上限あり）
- 状態異常付与 / 耐性
- 特定skillの追加効果
- 防御成功時、回復時、BREAK時などのproc
- HP条件、敵人数、boss条件による効果
- 回復の一部を障壁へ変換
- damageの一部を吸収

`固定50 damage追加`、`HP100回復`だけのaffixへ依存しない。発動率、回避率、軽減率、cooldown短縮などにはcapを設け、100%回避、完全無敵、無限行動を防ぐ。

地底装備と現行Secretaryの5つのpresent装備を同じinventory / slotへ統合するかは未決定。現行の秘書装備contractを、将来案だけを理由に変更しない。

#### 特殊化（性能変更ではなくキャラクリ）

特殊化は強化、再抽選、affix変更、Item Power引上げではない。装備の性能、rarity、affix、unique effect、base item identityを一切変えず、装備名とフレーバーテキストだけを変更できるキャラクターカスタマイズ要素とする。

- 特殊化された装備名は赤字で表示する。
- 元のbase item名、性能、affixは内部で保持し、詳細画面や比較、検索、売買判定では確認可能にする。
- custom nameやフレーバーをgameplay effectとして解釈しない。
- 赤字は特殊化表示として予約し、rarity表示と混同しない。
- 名称とフレーバーを自由入力にするか選択式にするか、文字数、禁止語、再編集、初期化、取引時の保持、作者表示は未決定。

長期のhack-and-slashで装備を数値だけの消耗品にせず、「この秘書のために名付けた装備」という愛着と経歴を残すことが目的である。

#### 探索基地と装備庫

地底装備はdrop数が多くなるため、現在の少数のpresent Item倉庫とは分け、探索基地に専用装備庫を持たせる案を第一候補とする。

- 探索装備庫の容量はpresent倉庫と別枠。
- 探索終了直後のlootを一時的に置く帰還品箱を候補とする。
- 満杯時に即消滅させず、自動分解・自動保護・警告の順序を別途決める。
- 初期容量、拡張費、account / Secretary / Nationのどこへ永続化するかは未決定。

島を作り直しても秘書が引き継がれる現行contractとの整合上、地底装備を島施設の破壊だけで失う設計にはしない。

#### 地下経済と輝石の欠片

不要装備を地下NPCへ渡す場合は島資金ではなく、地下内で使う特殊通貨「輝石の欠片」へ変換する。

- NPCへの納品、売却、分解から島資金を生成しない。
- 輝石の欠片は、鑑定、探索用品、装備庫拡張、特殊化など地下側の機能へ使う候補とする。
- 装備を島資金へ変える経路は、需要のある装備を他playerへ売るplayer間取引に限定する。
- 強い装備を購入して攻略を楽にし、井戸やcheckpointの解禁を早めるplayer選択を許容する。
- 高性能装備の使用条件、戦闘level差、Item Power差、到達深度による性能制限は未決定。

この構造では地下がNPC経由で島資金を無限生成せず、地下探索者と攻略時間を短縮したいplayerの間でのみ資金が移動する。

### 武器候補

エルフの定番は弓だけではない。創作上は、細身の剣、葉形の短剣、槍、双剣、杖・魔法媒体も十分にエルフらしい。

地底向けの初期武器候補:

| 武器 | 戦い方 |
|---|---|
| 短剣 / ナイフ | 高技巧、状態異常、連撃、回避反撃 |
| 葉身の短剣 / 細身剣 | balanced、受け流し、刺突、輝石による魔力付与 |
| 短槍 / 葉槍 | 間合い、敵の行動阻害、BREAK、複数対象 |
| 輝石媒体 / 杖 | 光属性攻撃、回復、障壁、浄化 |
| 小盾 | 防御、挑発、盾撃、反撃。片手武器との組合せ |

ペリドットの標準イメージには、**葉形の短剣または細身の片手剣 + 輝石**が最も自然な候補。弓使いらしい技巧、身軽さ、魔力操作を残しつつ、狭い地底でも戦える。

短剣だけでは頼りなく見える場合、刃へ輝石の魔力をまとわせる、短時間だけ光の刃を伸ばす、受け流しから魔法矢のような近距離追撃を放つなど、弓の才能を別の形へ翻訳できる。

ただし武器種は秘書のraceや外見で制限しない。ムキムキの秘書が大盾や重武器を使うbuildも排除しない。

### Party

最大4人partyは、自分の秘書1人と、他playerから借りる秘書最大3人による非同期partyを第一候補とする。

- 貸出側は装備skill 5個、装備、party用AI設定、覚醒条件を登録する候補。
- 探索開始時に貸出状態をsnapshot化し、途中の装備変更で探索結果を遡って変えない。
- 借りた秘書の装備を奪取、複製、改名、特殊化できない。
- 貸出報酬、同じ相手を繰り返し借りる制限、friend / guild優遇は未決定。
- 戦闘levelやItem Power差をそのまま適用するか、探索主の到達帯へ同期するかは未決定。

party人数補正は敵HPを人数倍するだけにせず、敵action、全体攻撃、複数target、召喚、BREAK耐性などを組み合わせる。hard trinityを必須にはせず、tank / healerがいると安全性とresource効率が上がる程度を目指す。

### 長期進行の目標候補

ゆっくり育て続けるインフレ型やり込みgameとして、次を体感目標の候補とする。

- 約100時間の累計探索で本編の最終bossへ届く。
- 約1000時間の累計探索、装備厳選、特殊化、skill tree、AI調整を経て、裏bossのsolo討伐を狙える。

単なる放置時間だけで勝利を保証せず、長時間で挑戦可能な装備と成長を得たうえで、buildとAI設定を詰める余地を残す。具体時間、放置上限、season、catch-upは未決定。

### 戦闘levelと成長軸

正式な戦闘level制度は未決定である。本節の「同level」は火力比較のための仮表現であり、経験値、level cap、能力成長、skill pointとの関係を確定していない。

実装前に少なくとも次を分離するか検討する。

- 秘書本人の探索熟練または戦闘level
- 井戸、階層、checkpointによる攻略進行
- 装備のItem Power / grade

player間取引と貸出partyがあるため、level差、Item Power差、到達深度差をどう扱うかはmarketとparty設計の重要gateとする。

### 未決定事項

- round制、ATB、tick制の最終選択
- 貸出秘書のsnapshot、報酬、level / Item Power同期
- 装備slot数、探索装備庫の初期容量、拡張、帰還品箱、自動分解
- 輝石の欠片の獲得量と用途、player間marketの手数料
- 特殊化名・フレーバーの入力方式、再編集、初期化、取引時の保持
- 地底装備と現行Secretary Itemの関係
- 敗北penalty、帰還、checkpoint
- 戦闘level、探索熟練、skill point、Item Power、井戸進行の関係
- 永続成長、season、階層reset
- 覚醒の継続時間と専用skill
- PDとの関係
- 状態異常耐性とboss仕様
- enemy catalog、探索map、room生成
- 本編経済へ与えるrewardの有無と上限
- PvPの有無。初期候補はPvEのみ

### 実装開始時の境界

- この節だけを根拠に実装を開始しない。
- 現行RulesetやSecretary Itemを上書きしない。
- 地底のためだけに未使用のgeneric combat / modifier / map frameworkを先行作成しない。
- 最初の縦切りでは、1人、少数skill、built-in AI、短い戦闘、少数装備baseとaffixから始める。
- balanceは数式、simulation、極端build、実playerの周回時間を使って調整する。
- 実装scopeが確定した時点で、current handoff、docs guide、open questions、ADR、Ruleset / persistence boundaryを別途更新する。
