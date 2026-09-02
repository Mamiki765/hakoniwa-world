# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-09-03 JST
> 対象リポジトリ: `Mamiki765/hakoniwa-world`  
> 配置先: `product/docs/handoffs/development-history-and-current-handoff.md`  
> 用途: 現在有効なrelease boundary、Owner決定、production境界、次の作業開始点の引継ぎ  
> 状態: application 3.3.0は機能freeze済み。PR #128〜#130とTest運用整理を含み、finalizationとmain昇格だけを行う
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

`main`の3.2.0を起点に`release/3.3.0`を作成し、PR #128〜#130を順にmergeした。3.3.0のgameplay scopeはここでfreezeし、以後このreleaseへ加えるのはapplication version、current handoff、必要最小限のversion testだけである。exact HEADとPR状態はremoteで確認する。

```text
main / 3.3.0開始base:
  HEAD: 347679a78c0694756c88d1cf7ad187d5db11b787
  application: 3.2.0
  Surface Ruleset: hakoniwa-2s-plus-v19 / version 19

release/3.3.0 feature-freeze anchor（PR #130 merge後、finalization前）:
  0d9a24e502ede4cec81219217c5027f1fe49b03c
  application at anchor: 3.2.0
  application after finalization: 3.3.0
  Surface Ruleset: hakoniwa-2s-plus-v19 / version 19

remaining release operations:
  1. finalization PR -> release/3.3.0
  2. Release 3.3.0 PR -> main
  3. production deploy / migrationは別Owner gate
```

3.3.0の確定構成:

| PR / commit | 内容 | merge / anchor commit |
|---|---|---|
| `fe5cd48` | `AGENTS.md`の恒久Test運用整理 | `fe5cd487f1430bd88d806a7652395e0b41547202` |
| #128 | STP直接入力 | `18007f5db696eb3bc9a596e74520c4931b23e7f3` |
| #129 | 案内人の部屋、SP/STP振り直し、成長方針切替 | `5312d1eb859ee9f64679819dfea73cfd47a2983c` |
| #130 | 条件指定preview付き宝物庫一括売却 | `0d9a24e502ede4cec81219217c5027f1fe49b03c` |

custom AI / gambit、Trial 2、追加狩場、Unique、enhancement、enchant、manual combat、party、marketは3.3.0に含めない。

## 1.2 Version / Ruleset / identity

3.3.0で維持するidentity:

```text
application:
  3.3.0

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

3.3.0はSurface Ruleset payloadを変更せずv19を維持する。3.2.0で確定したUnderground combat / exploration / equipment identityも変更しない。これらはSurface Rulesetとは別authorityである。

## 1.3 Supported upgrade / migration

Owner確認済みproduction sourceはapplication 3.2.0。3.2.0から3.3.0へ進む際に新たに適用されるmigrationは次の2本だけ。

```text
product/database/migrations/2026_09_03_000000_add_underground_respec.php
product/database/migrations/2026_09_03_010000_add_underground_bulk_sale_operation.php
```

どちらもforward-onlyで、順に次を行う。

- `underground_profiles.last_respec_at`を追加し、intro request operationへ`respec`を追加
- intro request operationへ`equipment_bulk_sell`を追加

既存migrationは履歴として維持し、3.3.0 finalizationでrebaseline、削除、書換えしない。`FreshInstallRebaselineTest`のexact 3.2.0 → 3.3.0 regressionをsupported upgradeの正本とする。

## 1.4 Production状態

2026-09-03のOwner確認ではproduction sourceはapplication 3.2.0。3.3.0のmain mergeはproduction deploy / migrationの許可を意味しない。3.3.0を適用する作業は別Owner gateであり、作業前に実環境で必ず次を再確認する。

- production checkoutとremote最新`main`のexact SHA
- application 3.2.0とmigration ledgerの一致
- backup / preflight / migrate手順
- 上記2本だけが3.2.0 → 3.3.0の新規migrationであること
- migrate後のapplication 3.3.0、`last_respec_at`、request operation constraint

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

# 5. application 3.3.0の確定contract

## 5.1 Feature-freeze scope

3.3.0に含める変更は次だけである。

1. PR #128: STP直接入力
2. PR #129: 案内人の部屋、SP / STP一括振り直し、成長方針切替
3. PR #130: 条件指定preview付き宝物庫一括売却
4. release開始時の`AGENTS.md` Test運用整理

custom AI / gambitは3.4.0へDeferredした。3.3.0ではAIのschema、API、player設定、battle snapshot、runtime actionを追加しない。

## 5.2 STP直接入力

- 5能力の今回配分値を非負整数で直接入力できる
- 合計は現在の未使用STPを超えないようUIとserverの双方で制限する
- 確定時は既存のserver-side validation、UUID idempotency、profile lockを維持する
- 確定済みSTPと装備補正は別に計算し、配分確定は従来どおり取り消せない

## 5.3 案内人の部屋と再振り

上位tabは`地下メイン / 装備ショップ / 案内人の部屋 / 宝物庫`。案内人の部屋では会話placeholderと再振りを表示する。

再振りは1回のtransactionで次を行う。

- SPを全返却し、active skill slotを全解除
- 手動配分STPを全返却
- 選択した成長方針へ切り替え、現在Combat Lvまでの自然成長とSTP entitlementを再計算
- 費用`Combat Lv x 10G`を手持ちの輝石のかけらだけから支払う
- 成功後24時間は再実行不可。active Trial中、費用不足、cooldown中、validation failureでは状態を変えない
- Combat Lv / XP、装備、inventory、Trial progress、覚醒、解禁、intro履歴は維持する
- max HP低下時だけcurrent HPを下方clampし、回復はしない
- 同一UUID / 同一payloadは元の成功結果を返し、別payload conflictと同時実行を安全に拒否する

## 5.4 条件指定型の宝物庫まとめ売り

- filterはItem Lv上限、rarity、category、canonical weapon style。選択肢とlabelはserver catalogから投影する
- previewは装備中itemと通常売却不可itemを除外し、具体的なitem ID、canonical売却価格、合計を返す
- confirmはpreviewで示したIDと価格だけを再検証し、preview後に取得したitemを追加しない
- 所有、存在、装備状態、売却可否、価格を全件再検証してから、同一transactionでdeleteと手持ちG creditを行う
- UUID idempotency、profile lock、request ledgerによりretryや同時requestの二重creditを防ぐ
- filter表示設定はclient preferenceであり、通常dropをauto-sellしない

## 5.5 3.3.0のnon-goals

custom AI / gambit、Trial 2以降、追加狩場、Unique、enhancement、enchant、manual combat、party、marketは実装しない。Surface Ruleset v19と3.2.0のcombat / exploration / equipment identityを維持する。

---

# 6. 次の自然な開始点

## 6.1 3.3.0 release close-out

1. finalization専用PRでapplication version、version期待値、current handoffだけを更新する
2. exact 3.2.0 → 3.3.0 upgrade、fresh install、docs validation、static checkをfocusedで確認する
3. finalization PRのexact-head Quality、Codex review finding 0、unresolved thread 0を確認して`release/3.3.0`へmergeする
4. `Release 3.3.0` PRを`main`向けに作成し、同じgateとscope auditを満たした場合だけmergeする
5. main merge後は3.4.0実装へ進まず、release証跡を報告して停止する

## 6.2 Production deploy

production sourceはOwner確認済みapplication 3.2.0。3.3.0のdeploy、production migration、OCI操作はrelease close-outに含まれず、別Owner gateで行う。

## 6.3 次release 3.4.0 / custom AI開始時確認

次のapplication releaseは3.4.0。custom AI / gambitは3.4.0へDeferredする。以下は今回の調査から得た**推奨案 / 開始時確認事項**であり、3.4.0のOwner-approved実装仕様ではない。branch、schema、API、UI、runtime実装を始める前にOwnerが再確認する。

1. Secretaryごとに有効なAI設定は1つとし、初期presetを複製または初期化して編集する
2. 正規化済みAI rule全文とSHA-256をbattle snapshotへ保存し、導入時はcombat identity更新を検討する
3. Trial中も戦闘間のAI変更を許可し、次の戦闘から適用する
4. Awakening ruleは時間を消費せず、HP 20%条件はdefault presetへ移す
5. empty conditionは`always`、jumpはforward-only、使用不能時は習得済みattackからnormal attackへdeterministic fallbackする

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

このTest運用原則は3.3.0開始時の独立commitで`AGENTS.md`へ恒久反映済み。

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

3.3.0 close-out / 3.4.0 Underground作業では、必要範囲で次を読む。

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
product/app/Application/Underground/
product/app/Domain/Underground/Combat/
product/resources/js/components/UndergroundPanel.vue
product/resources/js/components/UndergroundEquipmentShop.vue
product/resources/js/components/UndergroundEquipmentVault.vue
product/tests/Underground/
product/tests/Feature/FreshInstallRebaselineTest.php
```

historicalなPR bodyやsimulation reportだけを読んでcurrent contractを再構成しない。上記current code / docsとremote exact HEADを先に確認する。
