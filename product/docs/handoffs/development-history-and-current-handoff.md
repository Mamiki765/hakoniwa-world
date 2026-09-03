# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-09-03 JST  
> 対象リポジトリ: `Mamiki765/hakoniwa-world`  
> 配置先: `product/docs/handoffs/development-history-and-current-handoff.md`  
> 用途: 現在有効なrelease boundary、Owner決定、production境界、次の作業開始点の引継ぎ  
> 状態: application 3.4.0のrepository release boundaryは確定。productionはapplication 3.3.0。次の候補releaseは3.5.0船システム
>
> この文書はOwnerとWeb版ChatGPTが管理する。Codex / implementation agentはread-onlyで利用し、Ownerがhandoff更新そのものを明示的に依頼した場合だけ編集してよい。

---

# 0. この文書の読み方

- 作業開始時は、まずremoteの`main`、対象release branch、対象PRのexact HEADを取得する。この文書のSHA記載だけでcheckout先を決めない。
- release boundaryとOwner decisionはこのhandoffを正本とする。細かな実装値はcurrent code、migration、test、architectureを正本とする。
- 文書とcurrent code / schema / accepted ADRが矛盾した場合、都合よく統合せず、矛盾箇所と影響をOwnerへ報告する。
- GitHubでmainへmerge済みであることと、productionへdeploy / migrate済みであることは別の事実として扱う。
- production deploy、OCI操作、production DB操作、main mergeは、それぞれ明示されたOwner gateの範囲だけで行う。
- `_references/`はread-only。過去のroadmap、audit、historical reportをcurrent authorityとして使わない。

---

# 1. 現在地

## 1.1 GitHub / application / production

application 3.4.0はPR #134〜#136でcustom AIを実装し、release close-outの対象になった。
main昇格の完了状態とexact SHAは毎回remoteで再確認し、この文書の記載だけでcheckout先を決めない。

```text
application: 3.4.0
3.4.0 feature-freeze anchor: 0c14ceb5aca270c474a03a085cc80004ec8f0b46
Surface Ruleset: hakoniwa-2s-plus-v19 / version 19
Underground combat: secretary-underground-alpha-v3
```

2026-09-03、Ownerはapplication 3.3.0をproductionへ適用済みと報告した。application 3.4.0のrepository releaseとproduction deploy / migrationは別のOwner gateであり、このhandoff更新時点でproductionは3.3.0のままである。

このhandoff更新ではOCIへ独立照会していない。次回production操作前には、実環境のcheckout SHA、application version、migration ledgerを再確認すること。

## 1.2 3.4.0の構成

| PR / commit | 内容 | merge / anchor commit |
|---|---|---|
| #134 | custom AI保存・検証・API | `7e016b27ebefc036ec70d7731aca61c7c2e45c8b` |
| #135 | custom AI戦闘適用、snapshot / hash、combat identity v3 | `e29df7e78897ee346626bcc3ceec409f33a71d91` |
| #136 | AI編集画面、player manual、default preset簡潔化 | `0c14ceb5aca270c474a03a085cc80004ec8f0b46` |

PR #134〜#136は`release/3.4.0`へmerge済み。3.4.0の新機能はcustom AIだけであり、Surface gameplay、Ruleset payload、production環境は変更していない。

船システム、Trial 2、追加狩場、Unique、enhancement、enchant、manual combat、party、marketは3.4.0に含めない。

## 1.3 Version / Ruleset / identity

```text
application:
  3.4.0

Surface Ruleset:
  hakoniwa-2s-plus-v19
  checksum: b65752b88e9daf3c9b64e6d28b72847315d521dfe65b704f4cd8fd622e1368c9

Underground combat:
  secretary-underground-alpha-v3

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

3.4.0はSurface Ruleset payloadを変更せずv19を維持した。custom AIのbattle snapshot契約に伴いcurrent Underground combat identityだけをv3へ更新した。historical combat v2 snapshotは再解釈しない。exploration / equipment identityは変更していない。これらはSurface Rulesetとは別authorityである。

## 1.4 Supported migration / production ledger

production sourceの3.3.0から3.4.0へ進む際に新たに適用されるmigrationは次の1本だけ。

```text
product/database/migrations/2026_09_03_020000_add_underground_custom_ai.php
```

このmigrationは`underground_profiles.custom_ai_rules`をnullable JSONBとして追加し、intro request operationへ`ai_configuration`を追加する。既存profileは`null`、すなわちdefault presetのまま維持する。

既存migrationは履歴として維持し、rebaseline、削除、書換えを行っていない。`FreshInstallRebaselineTest`のexact 3.3.0 → 3.4.0 regressionをsupported upgradeの正本とする。

production deploy / migrationは別のOwner gateである。次回のdeploy前にはproductionが3.3.0であることと、上記migrationが未適用であることを実環境で再確認する。

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
- default presetはgauge fullかつHP 20%以下で覚醒を試みる。custom設定では明示的な覚醒ruleが必要
- 覚醒activation自体は通常actionの時間を消費せず、同じturnの残りrule評価と既存の覚醒戦技orderingへ進む
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

# 3. application 3.2.0で確定した主要contract

## 3.1 敏捷の戦闘効果

敏捷はinitiativeだけでなく、相手との相対差に応じた攻撃・防御補助を持つ。

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

Trial 1のワイバーン敏捷は12。装備dropや黒晶洞の都合だけで、この確定したTrial 1 / Wyvern balanceを再調整しない。

## 3.2 装備枠 / generated equipment

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
- 装備変更でmax HPが増えても無料回復しない
- max HPが下がった場合だけcurrent HPを新maxまでclampする
- 宝物庫capacityは500。装備中itemも含む
- fixed equipment catalog v1は既存owned item / snapshot解決のため保持
- current shop catalogはv2

Generated item:

- 取得時のimmutable payloadをDBへ保存し、後のconfigから再生成しない
- Item Lv 1〜60
- Item Lv本体を更新の主軸とし、rarityでbody全体へhidden倍率を掛けない
- 1狩場前の良Relicが次狩場Noviceを上回る場合は許容するが、2狩場先では通常更新対象になる設計
- rarityはノービス、レギュラー、ハイクオリティ、アーティファクト、レリック。ユニークは予約のみで未実装
- player-facingでは内部`miracle_damage_bps`を`魔法攻撃力アップ`と表示
- generated装備の売却価格は同Item Lv Novice curveを基準に10%。rarity倍率は付けない

## 3.3 通常探索drop / 黒晶洞

- 通常探索のvictory時のみdrop抽選
- 1戦につき最大1個
- Trial、defeat、withdrawalではdropしない
- battle seedからdrop domainを分離し、同じrequest retryは同じ結果
- battle作成、EXP/G settlement、drop grantを同一transaction / profile lock内で処理
- `source_battle_id`単位で二重grantを防ぐ
- 宝物庫満杯でもEXP/Gは確定し、itemだけ持ち帰れない
- 満杯時にauto-sellしない

第二狩場`黒晶洞`はTrial 1初回clearで解禁する。Item Lv帯は30〜60。不純物の多い黒い輝石が多く見られるため黒晶洞と呼ばれ、黒色は階層rankを表さない。

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

weight込み長期期待値:

```text
240.25 EXP / battle
61.53 G / battle
10戦換算: 2402.5 EXP / 615.3G
```

Trial 1一周800 EXP / 205Gの約3倍を、黒晶洞10戦の期待値目標としている。

## 3.4 探索UI / compatibility

- `周囲を探索`1ボタン + 狩場selector
- 解禁済み狩場が2つ以上なら`▼`を表示
- 最後に選んだ狩場はbrowser localStorageへ保存
- 戦闘結果に`もう一度ここを探索する`を表示
- repeatは表示中battle snapshotのhunting-ground keyを使い、新UUIDを発行
- 通信失敗retryでは同じhunting ground + 同じintentの場合だけpending UUIDを再利用
- combat identity v1 / v2の既存snapshotはhistorical recordとして残しmigrationしない
- shallow caves content identity v1と旧request replay互換を維持
- persisted generated payloadをcurrent configで再解釈しない

---

# 4. application 3.3.0から継承する確定contract

## 4.1 STP直接入力

- 5能力の今回配分値を非負整数で直接入力できる
- 合計は現在の未使用STPを超えないようUIとserverの双方で制限する
- 既存server-side validation、UUID idempotency、profile lockを維持する
- 確定済みSTPと装備補正は別に計算し、通常配分の確定は従来どおり取り消せない

## 4.2 案内人の部屋と再振り

上位tab:

```text
地下メイン / 装備ショップ / 案内人の部屋 / 宝物庫
```

案内人の部屋には会話placeholderと再振りflowを置く。

再振りは1回のtransactionで次を行う。

- SPを全返却し、active skill slotを全解除
- 手動配分STPを全返却
- 選択した成長方針へ切り替え、現在Combat Lvまでの自然成長とSTP entitlementを再計算
- 費用`Combat Lv x 10G`を手持ちの輝石のかけらだけから支払う
- 成功後24時間は再実行不可
- active Trial中、費用不足、cooldown中、validation failureでは状態を変えない
- Combat Lv / XP、装備、inventory、Trial progress、覚醒、解禁、intro履歴は維持する
- max HP低下時だけcurrent HPを下方clampし、回復はしない
- 同一UUID / 同一payloadは元の成功結果を返し、別payload conflictと同時実行を安全に拒否する

成長方針ごとのSTP entitlement差:

```text
free_black: 1Lvごと6 STP
その他3種: 1Lvごと5 STP
```

旧方針由来の総量をそのまま持ち越さず、新方針をLv1から選んでいたものとしてcanonical entitlementを再計算する。intro FSMは巻き戻さない。

## 4.3 条件指定型の宝物庫まとめ売り

filter:

- Item Lv上限
- rarity
- category
- canonical weapon style

contract:

- 選択肢とlabelはserver catalogから投影する
- previewは装備中itemと通常売却不可itemを除外し、具体的item ID、canonical売却価格、合計を返す
- confirmはpreviewで示したIDと価格だけを再検証し、preview後に取得したitemを追加しない
- 所有、存在、装備状態、売却可否、価格を全件再検証してから、同一transactionでdeleteと手持ちG creditを行う
- UUID idempotency、profile lock、request ledgerによりretryや同時requestの二重creditを防ぐ
- filter表示設定はclient preferenceであり、通常dropをauto-sellしない

---

# 5. application 3.4.0 custom AIの確定contract

custom AIはSecretaryごとに有効な設定を1つだけ持つ。保存値`null`はdefault preset、空のcustom rule list `[]`はruleなしの有効なcustom設定であり、同じ意味に畳み込まない。

- 最大16 rules、1 rule最大2 conditions
- 同じrule内のconditionsはAND、empty conditionは`always`
- OR / NOT / nested logic / generic DSLは導入しない
- skill actionは「条件成立後に使用を試み、現在使用不能ならfallbackせず次ruleへ進む」
- default presetでは同じskillを指す`skill_ready`条件を付けない。`skill_ready`自体は、skill Aの可否からjumpまたは別actionを選ぶruleに利用できる
- jumpはforward-onlyとし、loopを構造的に作れない
- 全rulesを評価してもactionを実行できなかった場合だけ、現在使用可能な習得済みattackをcanonicalな決定順で選び、それもなければnormal attackへdeterministic fallbackする
- idle turnは作らない
- Awakening activationはaction時間を消費しない。HP 20%以下はhard availabilityではなくdefault preset上のconditionとする
- Awakening hard availabilityはunlock済み、gauge full、battle内未使用の3条件とする
- 各battle snapshotへ、そのbattleで実際に使用したnormalized AI rules全文とSHA-256 hashを固定する
- Trial中のAI変更はbattle間だけ許可し、次battleから適用する。生成済みbattleへは影響させない
- historical combat v2は再解釈せず、current combat identityは`secretary-underground-alpha-v3`とする
- custom AI未設定playerはdefault presetを使用し、3.3.0までのdefault AIの意図した挙動を可能な限りbehavior-equivalentに維持する

APIは既存のowner-only Underground boundary、profile row lock、UUID idempotency、request fingerprint、単一transactionを再利用する。canonicalだが未習得のskillもruleとして保存でき、実戦では使用不能として次ruleへ進む。

実装分割:

```text
PR #134: AI設定の保存・検証・API
PR #135: 戦闘適用・AI snapshot/hash・combat identity v3・覚醒順序
PR #136: AI編集画面・説明文書・default preset簡潔化
```

---

# 6. 次release候補と将来候補

- application 3.5.0船システム
- 案内人名変更後の新しい「リカ」再戦
- Unique装備
- 装備強化 / enchant追加system
- 技巧 / criticalの再設計
- status potencyの再設計
- Trial 2以降
- 第三狩場以降
- manual combat
- party / market
- Surface Ruleset v20。3.5.0船システムがSurface semanticを変更する場合の候補であり、PlanとOwner判断より先にpublishしない

新しいreleaseへ入れる場合は、Ownerがscope、identity、migration、balance影響を明示する。

---

# 7. Test / review / Agent運用

## 7.1 Test authority

Quality CIはPHPUnit全集合をshardへ分割して全件実行する。

- localはfocused testsを優先
- migrationではfresh install / supported upgradeを追加確認
- PostgreSQL concurrency、environment固有contract等、CIに含まれないものだけlocal追加
- CI failureの再現が必要な場合だけ対象をlocal実行
- repository-wide回帰の最終authorityはexact-head CI
- source / test設定 / dependencyが変わっていなければ同じ全suiteを理由なく繰り返さない

この原則は`AGENTS.md`へ恒久反映済み。

## 7.2 Container / worktree

過去に、test fileだけcurrent worktreeからmountし、container内`composer.json` / vendorが古いimageのままという世代混在が起きた。

- 1回のverificationでは1つのworktree / exact HEADへ揃える
- source、composer metadata、dependency、test設定を別世代で混在させない
- stale dev/test containerをfailure evidenceとして扱う前に、実行環境のsource identityを確認
- runtime codeを直す前に、environment由来かfocused testで切り分ける

## 7.3 Simulation artifact

- balance simulationはcompact aggregateを原則とする
- source commit、identity、manifest hash、seed start/count、aggregate metrics、abnormal count、reproduction commandだけを保存する
- seedごとのraw result、action log、巨大JSONを通常artifactとして増やさない
- 既存historical 1000 / 10000 seed JSONは当時の証拠として変更しない
- 通常のPlan / reviewでhistorical巨大JSON全文をcontextへ読まない

## 7.4 Subagent

利用可能なら、低riskで機械的な作業はLuna Max等のsubagentへ委譲してよい。

委譲候補:

- read-only inventory
- schema / test参照箇所の列挙
- 数値表の照合
- focused regression追加
- frontend参照箇所の棚卸し
- CI failure原因調査

main agentが保持するもの:

- Owner intent
- release scope
- identity / Ruleset / migration境界
- balance最終判断
- transaction / idempotency / concurrency
- review dispositionとmerge判断

subagent成果はmain agentが確認してから採用する。

---

# 8. 次に作業するagentが最初に行うこと

1. remote `main`と対象release branchをfetchし、application 3.4.0のmain昇格状態とexact SHAを確認する
2. production操作を伴う場合、実環境がapplication 3.3.0であること、checkout SHA、migration ledgerを再確認し、別Owner gateを得る
3. 次候補の3.5.0船システムは、Owner-approved Planより先にbranch、migration、code、Rulesetを作らない
4. ShipとMonsterを別domainとして扱い、座標・候補cell・occupancy確認等の小さいprimitiveを超える万能Actor frameworkを先行実装しない
5. 3.5.0でSurface Ruleset v20が必要なら、production baseline v19からの1世代だけを全gameplay sliceで共有し、v21を作らない

最初に読むcurrent file:

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
product/database/migrations/2026_09_03_000000_add_underground_respec.php
product/database/migrations/2026_09_03_010000_add_underground_bulk_sale_operation.php
product/database/migrations/2026_09_03_020000_add_underground_custom_ai.php
product/app/Application/Underground/
product/app/Domain/Underground/Combat/
product/resources/js/components/UndergroundPanel.vue
product/resources/js/components/UndergroundEquipmentShop.vue
product/resources/js/components/UndergroundEquipmentVault.vue
product/tests/Underground/
product/tests/Feature/FreshInstallRebaselineTest.php
```

historicalなPR bodyやsimulation reportだけを読んでcurrent contractを再構成しない。current code / docsとremote exact HEADを先に確認する。
