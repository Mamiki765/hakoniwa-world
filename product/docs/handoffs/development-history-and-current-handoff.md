# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-09-02 JST  
> 対象リポジトリ: `Mamiki765/hakoniwa-world`  
> 配置先: `product/docs/handoffs/development-history-and-current-handoff.md`  
> 用途: 現在有効なrelease boundary、Owner決定、production境界、次の作業開始点の引継ぎ  
> 状態: PR #126でapplication 3.2.0をmainへ昇格済み。PR #127でOwner承認済みhandoff refreshをmainへ追従する
>
> この文書はOwnerとWeb版ChatGPTが管理する。Codex / implementation agentはread-onlyで利用し、Ownerがhandoff更新そのものを明示的に依頼した場合だけ編集してよい。

---

# 0. この文書の読み方

- 作業開始時は、まずremoteの`main`、対象release branch、対象PRのexact HEADを取得する。この文書のSHA記載だけでcheckout先を決めない。
- release boundaryとOwner decisionはこのhandoffを正本とする。細かな実装値はcurrent code、migration、test、architectureを正本とする。
- 文書とcurrent code / schema / accepted ADRが矛盾した場合、都合よく統合せず、矛盾箇所と影響をOwnerへ報告する。
- GitHubでmainへmerge済みであることは、productionへdeploy / migrate済みである証拠ではない。
- production deploy、OCI操作、production DB操作、main mergeはそれぞれ別のOwner gateである。
- `_references/`はread-only。過去のroadmap、audit、historical reportをcurrent authorityとして使わない。

---

# 1. 現在地

## 1.1 GitHub / release状態

PR #126で、完成した`release/3.2.0`は`main`へ昇格済み。merge後にOwnerがhandoff更新を明示承認したため、PR #127がこの文書だけをmainへ追従するdocs-only PRである。PR #127のopen / merged状態とexact HEADはremoteで確認する。

```text
main（PR #126 merge後）:
  HEAD: fa37e61020a4a5ac3cc6971fa718655879bcca78
  application: 3.2.0
  Surface Ruleset: hakoniwa-2s-plus-v19 / version 19

release/3.2.0 feature/finalization anchor:
  95b6c08f29554f38e6331ba5cf3c0f283a365d61
  application: 3.2.0
  Surface Ruleset: hakoniwa-2s-plus-v19 / version 19

main promotion:
  PR #126 Release 3.2.0
  merge commit: fa37e61020a4a5ac3cc6971fa718655879bcca78

handoff follow-up:
  PR #127 Refresh the current development handoff for 3.2.0
  base: main
  head: release/3.2.0
```

このhandoff refreshは、PR #126のmerge直後にOwner管理文書だけを追加したもの。PR #127のcurrent exact HEADはremoteから再取得すること。

3.2.0の構成PR:

| PR | 内容 | merge / anchor commit |
|---|---|---|
| #121 | 地底マップと覚醒ゲージのUI整理 | `48b64ece3e9363f45a171a31f77d6846cbdadbc2` |
| #122 | 敏捷の相対combo・回避、combat identity v2、Trial 1再調整 | `2cd9c88de37c13ce1b3109264524447936741f51` |
| #123 | ハクスラ装備基盤、アクセサリー3枠、generated equipment | `f83159657e94fc34301b5abf7f23b0fb9cdb986b` |
| #124 | 浅層drop、第二狩場「黒晶洞」、狩場選択・再探索 | `fe1a9b6f28e2328c3a12011b142363afff038b4e` |
| #125 | application 3.2.0 finalization、supported upgrade確認 | `95b6c08f29554f38e6331ba5cf3c0f283a365d61` |
| #126 | release/3.2.0 → main昇格 | `fa37e61020a4a5ac3cc6971fa718655879bcca78` |
| #127 | Owner承認済みcurrent handoff refresh | status / merge commitはremote確認 |

PR #121〜#126はmerge済み。PR #127がまだopenなら、そのexact HEADでQualityとCodex reviewを確認してからOwnerがmerge判断する。PR #127がmergedでも、production適用状態は別に確認する。

## 1.2 Version / Ruleset / identity

3.2.0で確定したidentity:

```text
application:
  3.2.0

Surface Ruleset:
  hakoniwa-2s-plus-v19
  checksum: b65752b88e9daf3c9b64e6d28b72847315d521dfe65b704f4cd8fd622e1368c9

Underground combat:
  secretary-underground-alpha-v2

exploration selector/runtime:
  secretary-underground-exploration-alpha-v2

hunting-ground content:
  shallow_caves: secretary-underground-exploration-alpha-v1
  black_crystal_cave: secretary-underground-black-crystal-cave-alpha-v1

exploration drop:
  secretary-underground-exploration-drop-alpha-v1

equipment shop catalog:
  secretary-underground-shop-equipment-alpha-v2
  legacy v1 catalog remains readable

generated equipment:
  secretary-underground-drop-equipment-alpha-v1
```

3.2.0はSurface Ruleset payloadを変更していない。v19を維持し、v20は作らない。Undergroundのcombat / exploration / equipment identityはSurface Rulesetとは別authorityである。

## 1.3 Supported upgrade / migration

3.1.0 / 3.1.1相当のmainから3.2.0へ進む際に新たに適用されるmigrationは1本だけ。

```text
product/database/migrations/2026_09_02_000000_expand_underground_hackslash_equipment.php
```

このmigrationはforward-onlyで、主に次を行う。

- 既存`equipped_slot = accessory`を`accessory_1`へ変換
- 装備slot constraintを`weapon / armor / accessory_1 / accessory_2 / accessory_3`へ更新
- generated equipmentのimmutable payload、instance identity、generator identity、source battleを保存する列とconstraintを追加

既存の3.0.0 → 3.1.0 canonical migrationは引き続き履歴として存在する。3.2.0のために既存migrationをrebaseline、削除、書換えしない。

## 1.4 Production状態

2026-09-02のこの更新時点では、3.2.0はproductionへ未適用。

Ownerが一度production hostで`main`をpull / build / migrateしたが、その時点のmainは`78d4750`のままで、migration結果は`Nothing to migrate`だった。これは3.2.0 migrationがまだmainに存在しなかったためであり、3.2.0 schema変更はproductionへ入っていない。

PR #126 merge後にdeployする場合も、作業前に必ず次を実環境で再確認する。

- checkoutがremote最新`main`である
- `config('hakoniwa.application_version')`が3.2.0相当である
- `2026_09_02_000000_expand_underground_hackslash_equipment.php`が存在する
- backup / preflight / migrate手順はcurrent operations文書に従う

---

# 2. 3.1.xから継承する主要contract

## 2.1 封印の地 / Trial 1

player-facing名称:

```text
封印の地
└ 地下に眠る古代遺跡
```

- 全10戦
- HPは戦闘間でcarry
- battle 1〜9勝利後、次戦前にcurrent max HPの20%を回復しmax HPでcap
- 実際に1以上回復した結果へ`体力が少し回復した`を表示
- MPは各戦闘開始時にcanonical maximum 10,000へreset
- battle 1〜9勝利結果に`次の階層へ`を表示し、直接次battleへ進める
- battle 10 clear、defeat、withdrawalでは`次の階層へ`を表示しない
- defeat / withdrawalでrun終了
- 途中progressは永続化され、画面更新後も再開可能
- active run中は宿を使用できない
- 初回clear時のみSP +40、first-clear story、覚醒、地底layer 1 / 4 slotsを解禁
- repeat clear可能。初回報酬とstoryは再発しない
- Trial 1全10戦の報酬合計は800 EXP / 205G

## 2.2 Awakening

- persistent gauge、internal maximum 1000
- player-facing UIでは`0 / 1000`等の数値を出さずprogress barのみ
- 満タン時も外枠、card背景、layoutは通常状態のまま。変えるのはゲージfill色だけ
- 一戦につき最大1回
- current default AIはgauge fullかつHP 20%以下で発動
- 発動時HP / MP全回復
- 戦闘終了まで5主能力値+30%
- growth pathごとの固定Awakening techniqueあり
- 武技・守護・加護のtechniqueはactionを消費し、自由のtechniqueはactionを消費せず通常行動へ続く

## 2.3 地底facilityとsurface map

ownership:

- Trial progression / layer unlockはSecretary-owned
- facilityはNation-owned
- 同じSecretaryがNationを作り直してもlayer entitlementは残るが、旧Nationのfacilityは残らない

facility contract:

```text
1 layer = 4 facility slots
地底都市: 首都effective population maximum +10,000
地底農場: aggregate farm workforce capacity +10,000
地底工場: aggregate factory workforce capacity +30,000
地底ミサイル基地: missile capacity +1
```

- build / removalはofficial Turnを1消費
- entranceと固定梯子はfacility slotではない
- Surface MapCellや3D coordinate persistenceは使用しない
- 地底マップは自島画面の開発画面と伝言板の間に置く。別ページへ分離しない
- 見出しは`首都地下`
- 地上と同じ正方形tile sizeの5列連続mapとして表示
- facility名や座標計算式をtile上へ常時overlayしない
- tile選択時だけ赤枠を付け、詳細欄へfacility名とflavor座標を表示

## 2.4 公開表示

- 他人の秘書プロフィールにも戦闘Lvを表示
- 他人の島でも、そのNationの地底mapを閲覧可能
- 自作・委託等の非AI秘書画像は、閲覧者側の画像設定が未設定でも表示可能
- AI画像についての既存consent boundaryは維持
- 公開mapからOwner専用のfacility操作権限やprivate detailを漏らさない

---

# 3. application 3.2.0の主要contract

## 3.1 敏捷の戦闘効果

敏捷はinitiativeだけでなく、相手との相対差に応じた攻撃・防御補助を持つ。

相対差は、実質的に次のbounded値を使う。

```text
self <= opponent: 0
self > opponent: (self - opponent) / (self + opponent)
```

- 有限の敏捷比で効果を止めるhard saturationは置かず、式自体のnatural saturationを使う
- 相手以下では敏捷由来のcombo / evasion bonusは0
- initiativeは実効敏捷が高い側を先とし、同値時のみ既存tie-break
- action impairment resistanceの既存contractも維持

combo:

- damage actionごとに1回だけ2・3・4連続ヒットを抽選
- 追加action、追加damage event、追加critical、追加status判定、追加Awakening gain、native hit数追加は発生させない
- post-mitigation damageへ最終倍率を1回掛ける
- native multi-hitでもaction単位の同じcombo結果を使う
- combat logは最初の成立damage行へ`N連続ヒット！`を1回だけ表示
- evasion / complete guard等でdamageが成立しない場合はcombo表示を出さない

防御metric:

- `damage_prevented`は、その時点のcurrent HP + barrierで実際に吸収可能な量まで
- terminal hitのoverkillを防御量へ含めない
- このmetric修正はcombat outcome、RNG、実damageを変えない

代表値:

```text
敏捷比 3.0:
  evasion bonus 8.00%
  2 / 3 / 4 combo 1.63% / 0.57% / 0.30%
  expected damage x1.0367
  expected incoming damage x0.9200

Lv30・同量17 STP:
  武力専門に対する攻撃寄与 37.77%
  精神専門に対する攻撃寄与 34.31%
  生命の物理EHP寄与に対する防御寄与 29.73%
```

Trial 1のワイバーン敏捷は8から12へ更新済み。装備dropや黒晶洞の都合で、この確定したTrial 1 / Wyvern balanceを再調整しない。

## 3.2 装備枠とcatalog

装備枠は計5つ。

```text
weapon x1
armor x1
accessory_1 x1
accessory_2 x1
accessory_3 x1
```

- weaponは必須で外せない
- armorと3 accessoryは個別に装備・交換・解除可能
- 3 accessoryのbody stats / modifiersはcanonical combat snapshotへ各1回だけ加算
- 装備変更でmax HPが増えても無料回復しない
- max HPが下がった場合、current HPを新maxまでclampする
- 宝物庫capacityは500。装備中itemも含む
- fixed equipment catalog v1は既存owned item / snapshot解決のため保持
- current shop catalogはv2

## 3.3 generated equipment / Item Lv / rarity / affix

generated itemは取得時のimmutable payloadをDBへ保存し、後のconfigから再生成しない。同じbattleから複数itemをgrantしない。

Item Lv:

- runtime generatorはItem Lv 1〜60だけを扱う
- body性能はanchor間を補間する
- 60超を勝手に外挿しない
- Item Lv本体を装備更新の主軸とし、rarityでbody全体へhidden倍率を掛けない
- 1狩場前の良Relicが次狩場Noviceを上回る場合は許容
- 2狩場先ではbody差により通常は更新対象になる設計

rarity:

| player-facing | internal | weapon / armor | accessory |
|---|---|---|---|
| ノービス | fixed shop common | affix 0 | affix 0 |
| レギュラー | generated common | 1枠 | 1枠、出現率・値50% |
| ハイクオリティ | generated uncommon | 2枠 | 2枠、出現率・値50% |
| アーティファクト | generated rare | 3枠 | 2枠、出現率・値80% |
| レリック | generated epic | 4枠 | 2枠、出現率・値100% |
| ユニーク | reserved unique | 3.2.0では生成しない | 3.2.0では生成しない |

- affix qualityは80〜100%
- 同じaffix keyは1装備内で重複させない
- 自動的な接頭辞 / 接尾辞で装備名を変更しない。rarity、Item Lv、affixは別表示
- player-facingでは内部`miracle_damage_bps`を`魔法攻撃力アップ`と表示
- 代表affix: 5能力、物理攻撃力、魔法攻撃力、治癒力、護壁力、critical率、critical damage、MP効率、最大HP、物理防御、魔法防御
- generated装備の売却価格は同Item Lv Novice curveを基準に10%。rarity倍率を付けて主要G farmにしない

## 3.4 通常探索drop

- 通常探索のvictory時のみ抽選
- 1戦につき最大1個
- Trial、defeat、withdrawalではdropしない
- battle seedからdrop domainを分離し、同じrequest retryは同じ結果
- battle作成、EXP/G settlement、drop grantを同一transaction / profile lock内で処理
- `source_battle_id`単位で二重grantを防ぐ
- 宝物庫満杯でもEXP/Gは確定し、itemだけ持ち帰れない
- 満杯時にauto-sellしない。失われたitem概要をbattle snapshotへ保存

profile別presence / rarity:

| profile | presence | Regular | HQ | Artifact | Relic |
|---|---:|---:|---:|---:|---:|
| standard | 20.06% | 91.18% | 6.83% | 1.94% | 0.05% |
| elite | 38.15% | 78.64% | 15.73% | 5.24% | 0.39% |
| rare | 100% | 50% | 30% | 15% | 5% |

狩場全体のencounter profile比率はstandard 70% / elite 29% / rare 1%。長期期待値:

```text
Regular: 約22.004% / battle
HQ: 約2.999% / battle
Artifact: 約1.002% / battle
Relic: 約0.100% / battle
any equipment: 約26.105% / battle
```

category抽選:

```text
weapon 20%
armor 20%
accessory 60%
```

## 3.5 第二狩場「黒晶洞」

解放条件:

```text
Trial 1 first clear
```

- server側でもlockを検証する
- Item Lv帯は30〜60
- 不純物の多い黒い輝石が多く見られるため`黒晶洞`と呼ばれる。黒色は階層rankではなく土地の特徴

encounter 8種:

```text
黒晶蝙蝠
黒晶獣
晶殻蟲
黒晶術師
破晶の狂戦士
黒晶再生体
黒晶の番人
黒晶虫
```

役割:

- 黒晶蝙蝠: 高敏捷
- 黒晶獣: 基準物理型
- 晶殻蟲: 高物理防御、魔法に弱い
- 黒晶術師: 魔法攻撃型、物理に弱い
- 破晶の狂戦士: telegraph + heavy
- 黒晶再生体: self-regeneration
- 黒晶の番人: 強敵、telegraphと小規模damage reduction
- 黒晶虫: 1% rare、99% complete guard、1400 EXP / 0G、rare profileで必ず装備抽選

weight込み長期期待値:

```text
240.25 EXP / battle
61.53 G / battle
10戦換算: 2402.5 EXP / 615.3G
```

Trial 1一周800 EXP / 205Gの約3倍を、黒晶洞10戦の期待値目標としている。

黒晶洞解放後、shopへItem Lv40 Noviceを追加。

```text
黒晶の短剣
黒晶の細剣
黒晶の長剣
黒晶の杖
黒晶の胸当て
黒晶の護符（5能力）
```

## 3.6 探索UI / retry

- 狩場ボタンを増殖させず、`周囲を探索`1ボタン + 狩場selectorにする
- 解禁済み狩場が2つ以上なら右側へ`▼`を表示
- 最後に選んだ狩場はbrowser localStorageへ保存。server persistenceではない
- 保存keyが不存在 / lockedなら`shallow_caves`へfallback
- 戦闘結果に`もう一度ここを探索する`を表示
- repeatは現在selectorではなく、表示中battle snapshotのhunting-ground keyを使う
- 正常終了後のrepeatは新しいrequest UUIDを発行する
- 通信失敗retryでは、同じhunting ground + 同じexploration intentの時だけpending UUIDを再利用
- 狩場またはintentが変わった場合、旧pending requestを破棄して新UUIDを作る

## 3.7 Snapshot / compatibility

- 新しいcombat結果はcombat identity v2をsnapshot / reportへ保存
- 既存v1 battle snapshotはhistorical recordとして残し、migrationしない
- shallow cavesのcontent identityは旧v1を維持し、既存request replay / seed compatibilityを保つ
- exploration selector全体だけv2へ進め、黒晶洞は独自content identityを持つ
- fixed v1 equipment rowをgenerated itemへ変換しない
- persisted generated payloadをcurrent configで再解釈しない

---

# 4. 3.2.0のnon-goals / 将来候補

3.2.0へは含めない。

- 案内人の部屋
- SP / STP再振り
- 成長方針の再選択
- STP直接入力
- 宝物庫のまとめ売り
- custom AI / gambit
- 案内人名変更後の新しい「リカ」再戦
- Unique装備
- 装備強化 / enchant追加system
- 技巧 / criticalの再設計
- status potencyの再設計
- Trial 2以降
- 第三狩場以降
- party / market
- Surface Ruleset v20

3.2.0の装備や黒晶洞を理由に、確定済みTrial 1 / Wyvern / combat coefficientを再調整しない。追加balance変更は新release scopeとidentity影響を確認して行う。

---

# 5. release/3.3.0のOwner方向性

## 5.1 Theme

3.3.0は、3.2.0で追加したハクスラを遊びやすくする**育成・管理・戦術設定release**を候補とする。

優先候補:

1. 案内人の部屋
2. SP / STP再振り
3. STP直接入力
4. 宝物庫まとめ売り
5. custom AI / gambit

新狩場、Trial 2、Unique装備を3.3.0へ自動的に含めない。

## 5.2 案内人の部屋

上位tab順:

```text
地下メイン / 装備ショップ / 案内人の部屋 / 宝物庫
```

入口表示:

```text
（案内人名）「あら、どうしたんですか？」
```

初期menu案:

```text
【少しお話がしたい】
「あ、あー……話題が思い浮かんだらまた来てちょうだいな？」

【SPをリセットしたい】

【STP・成長方針（クラス）をリセットしたい】

【あなたの呼び方を変えたい】
```

`少しお話がしたい`は、3.3.0時点では未実装placeholderでよい。

## 5.3 Respec共通contract

- 費用は`Combat Lv x 10G`
- Gはgoldではなく、輝石の欠片の重量単位gram
- 手持ちGからのみ支払い、銀行からauto-withdrawしない
- 1回成功すると24時間再実行不可
- active Trial中は拒否し、run途中だけbuildが変わる状態を作らない
- UUID request idempotency必須
- duplicate requestはcooldown / balance再評価より先に元の成功結果を返す
- 異なるUUIDの同時実行はprofile lockで直列化し、最初の1件だけ成功
- 費用不足、cooldown、active Trial、validation failureでは状態を変えない
- Combat Lv、EXP、装備、inventory、Trial progress、覚醒状態は維持
- max HP低下時はcurrent HPをclampするだけで、回復させない

SP reset:

- skill allocationを全返却
- `skill_points_unspent = skill_points_total`
- active skill slotを全解除
- STPとgrowth pathは維持

STP reset:

- manual allocated STPを0へ戻す
- current growth pathとCombat Lvから正規entitlementを再計算し、未使用STPへ返す
- SP allocationは維持

### 実装前にOwner決定が必要な点

画面上のactionを、次のどちらにするか未確定。

A. `SP reset`と`STP reset`を別actionにし、24時間cooldownを共有する  
B. `SP + STPをまとめてreset`する1 actionにする

Ownerの元の希望はSPとSTP / 成長方針を別項目として見せること。ただし成長方針変更まで含めてheavyになる場合、**SP / STP一括resetだけを先に仮実装する縮退案を許容**している。

この選択をAgentが独断で確定しない。

## 5.4 成長方針の再選択

候補contract:

- intro FSMを巻き戻さず、現在の4 growth path選択UIを再利用
- DB上で一度NULLへ戻さず、1 transactionで旧pathから新pathへ切替
- 新pathをLv1から現在Lvまで選択していたものとして自然成長とSTP entitlementを全再計算
- 旧path由来のallocated / unspent STPをそのまま持ち越さない

理由:

```text
戦技 / 護身 / 祝福: 1 levelあたり未使用STP +5
自由: 1 levelあたり未使用STP +6
```

異なるentitlementをそのまま持ち越すと、path変更で不正な増減または説明不能なpointが残る。

この再計算contractはOwner承認後に実装する。heavyなら、3.3.0第一段階はgrowth path維持のresetだけで止めてよい。

## 5.5 案内人の呼び名変更 / リカ

通常rename案:

```text
【あなたの呼び方を変えたい】
「はい♪　全然かまいませんよ？」
[ 案内人の名前 ] [送信]
```

- 初回namingに使った`shopkeeper_name`、既存true-name branch、過去story / battle snapshotを上書きしない
- 必要なら別のdisplay name fieldを持ち、現在表示だけを変える

Owner構想では、特定NG wordとして`リカ`を入力した場合に特殊戦闘へ分岐する。

初回構想:

```text
「……随分と物を知ったガキですね」
「捨てた名を知っているものには、太い太い釘を刺してもらわねばなりませんね？」
```

既に一度踏んだ後の構想:

```text
「……実に愚かね」
「もしかして今なら勝てるとでも思ったのかしら？」
```

この再戦は専用content identity、勝敗後処理、履歴契約を要する可能性がある。再振りPRへ無理に混ぜず、heavyならrenameだけまたはfuture hookまでで止める。

## 5.6 STP直接入力

現在の`+1 / -1`連打を減らすUI改善。

- current backendが能力別deltaを受けられるならfrontend中心の小PRにする
- server-side validation、合計未使用STP上限、request idempotencyは維持
- 再振りcoreへ不要に混ぜない

## 5.7 宝物庫まとめ売り

3.2.0でgenerated itemが増えるため3.3.0候補。

必須境界:

- equipped itemを売却対象にしない
- 選択itemをserver側でauthoritativeに再検証
- creditとdeleteをatomicに行う
- duplicate / concurrent requestで二重creditしない
- auto-sellを通常dropの既定挙動にしない

rarity以下、Item Lv以下、お気に入り保護等のfilter UIは未決定。Owner確認なしに複雑な条件DSLを先行実装しない。

## 5.8 Custom AI / gambit

3.2.0から3.3.0へ延期した独立機能。別combat engineを作らず、current canonical combat / Priority AIへ局所統合する。

rule案:

- 最大16行
- 1行1〜2条件
- 条件なしは`always`
- action: normal attack、defend、skill、awakening、forward jump
- jumpは後方行へのみ。逆流、自分自身、範囲外をrejectし、loop不能にする
- 条件不成立、MP不足、cooldown、weapon不一致、未習得 / 未装備skill、awakening unavailableは次行へ進む
- 全ruleが成立しなければ、使用可能な既習得attack skillを優先し、それもなければnormal attack
- 棒立ち、random fallbackは作らない

Awakening rule:

- hard requirementは`unlocked / gauge full / battle内未使用`
- HP 20%以下をawakening action自体のhard requirementにしない
- current default挙動はpresetの`HP 20%以下 -> Awakening`で再現
- customではboss時や無条件等、HPに依存しないactivationを許可
- techniqueのaction消費contractは既存どおり

例:

```text
1. Trial battle 9以下なら 3へ進む
2. 覚醒
3. 通常rule...
```

forward-only jumpで、Trial 1の1〜9戦では覚醒を温存し、10戦目で試行できる。

保存、snapshot、replay、effective rules hash、concurrencyが大きくなる場合は独立PRにする。主要QoLを不安定化させるなら3.3.0後半または次releaseへ延期する。

---

# 6. 次の自然な開始点

## 6.1 PR #127がopenの場合

PR #126はmainへmerge済み。このhandoff follow-upについて次を行う。

1. PR #127 current exact HEADを確認
2. Qualityを新HEADで完走
3. Codex reviewを新HEADへ依頼
4. unresolved finding 0を確認
5. Ownerがdocs-only PR #127をmainへmerge

PR #127が既にmergedならこの節を飛ばし、mainのexact HEADとproduction状態を確認する。handoffをmergeしただけでproduction deploy済みとは扱わない。

## 6.2 Production deploy

PR #126 merge後、Ownerの明示指示で別作業として行う。

- backup / preflight
- remote main exact SHA確認
- build
- migration `2026_09_02_000000_expand_underground_hackslash_equipment.php`
- service recreate / cache clear
- application version、accessory 3 slots、generated equipment schemaのpost-deploy確認

実際に適用した後は、このhandoffのproduction状態を更新する。

## 6.3 release/3.3.0開始gate

- 3.2.0がmainへmerge済みであること
- production baselineをOwnerが再確認すること
- latest mainから`release/3.3.0`を作ること
- Plan modeでcurrent profile / skill / equipment / intro / Trial / mutation ledgerを再読すること
- Respecの`別action共有cooldown`か`SP/STP一括reset`かをOwnerが決めること
- growth path再選択を第一段階へ含めるかOwnerが決めること
- Plan承認前にmigration、branch実装、commitを開始しないこと

---

# 7. Test / review / Agent運用

## 7.1 Test authority

Quality CIはPHPUnit全集合を16 shardへ分割して全件実行する。local直列全suiteは同じ集合を重複実行していた。

今後の原則:

- localはfocused testsを優先
- migrationではfresh install / supported upgradeを追加確認
- PostgreSQL concurrency、environment固有contract等、CIに含まれないものだけlocal追加
- CI failureの再現が必要な場合だけ対象をlocal実行
- repository-wide回帰の最終authorityはexact-head CI
- source / test設定 / dependencyが変わっていなければ同じ全suiteを理由なく繰り返さない

AGENTS.mdへ恒久反映する場合は3.2.0 gameplay/promotionと分けたdocs-only作業にする。

## 7.2 Container / worktree

過去に、test fileだけcurrent worktreeからmountし、container内`composer.json` / vendorが古いimageのままという世代混在が起きた。

- 1回のverificationでは1つのworktree / exact HEADへ揃える
- source、composer metadata、dependency、test設定を別世代で混在させない
- stale dev/test containerをfailure evidenceとして扱う前に、実行環境のsource identityを確認
- PRのruntime codeを直す前に、environment由来かfocused testで切り分ける

## 7.3 Simulation artifact

- 新しいbalance simulationはcompact aggregateを原則とする
- 保存するのはsource commit、identity、manifest hash、seed start/count、aggregate metrics、abnormal count、reproduction command
- seedごとのraw result、action log、巨大JSONを通常artifactとして増やさない
- 既存historical 1000 / 10000 seed JSONは当時の証拠として変更しない
- 通常のPlan / reviewでhistorical巨大JSON全文をcontextへ読まない。必要keyだけ抽出する

## 7.4 Subagent

利用可能なら、低riskで機械的な次の作業はLuna Maxへ委譲してよい。

- read-only inventory
- schema / test参照箇所の列挙
- 数値表の照合
- focused regression追加
- frontend参照箇所の棚卸し

main agentが保持するもの:

- Owner intent
- release scope
- identity / Ruleset / migration境界
- balance最終判断
- transaction / idempotency / concurrency
- review dispositionとmerge判断

各PR開始時に委譲可能作業を最初に分解する。subagent成果はmain agentが確認してから採用する。

---

# 8. 最初に読むcurrent file

3.2.0 / 3.3.0 Underground作業では、必要範囲で次を読む。

```text
AGENTS.md
product/docs/handoffs/development-history-and-current-handoff.md
docs/README.md
docs/open-questions.md
product/docs/architecture/ruleset-authoring.md
product/docs/architecture/current-ruleset-baseline.md
docs/architecture/underground-combat-laboratory.md
product/docs/manual/underground.md
product/config/hakoniwa.php
product/config/underground-alpha-v1.php
product/config/underground-equipment.php
product/database/migrations/2026_09_02_000000_expand_underground_hackslash_equipment.php
product/app/Application/Underground/
product/app/Domain/Underground/Combat/
product/resources/js/components/UndergroundPanel.vue
product/resources/js/components/UndergroundEquipmentShop.vue
product/resources/js/components/UndergroundEquipmentVault.vue
product/tests/Underground/
product/tests/Feature/FreshInstallRebaselineTest.php
```

historicalなPR bodyやsimulation reportだけを読んでcurrent contractを再構成しない。上記current code / docsとremote exact HEADを先に確認する。
