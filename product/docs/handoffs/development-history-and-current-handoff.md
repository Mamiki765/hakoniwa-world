# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-09-05 JST（PR #148 hotfix反映後、Ownerの明示依頼による更新）
> 対象リポジトリ: `Mamiki765/hakoniwa-world`  
> 配置先: `product/docs/handoffs/development-history-and-current-handoff.md`  
> 用途: 現在有効なrelease boundary、Owner決定、production境界、次の作業開始点の引継ぎ  
> 状態: 3.5.0、migration統一 #147、Hotfix 3.5.1 #148はmainへmerge済み。設定上のapplication_versionは3.5.0のまま。次のOwner-approved scopeは3.5.2 UI / UX改善で、相談時点ではCodexへ未依頼・未実装。最新productionのexact SHA / migration ledgerは独立未確認
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
- 実装済み、Owner要望、設計上の推奨案、未決事項、レビューでの仮説を区別する。提案を実装済みcontractとして記録しない。

---

# 1. 現在地

## 1.1 GitHub / application / production

3.5.0はSurfaceの船システム、港、航行、missile連携、visibilityを追加したrepository release boundaryである。2026-09-05の今回照合では、remote mainにmigration統一 #147とHotfix 3.5.1 #148が含まれることを確認した。

```text
今回確認したruntime baseline:
  4e4d9b85524df5b31a874ec5083c9f74be5cedcd
  Merge pull request #148: Hotfix 3.5.1

application_version（実際のconfig値）: 3.5.0
hotfix呼称: 3.5.1
3.5.0 feature-freeze anchor: 22203ae9f7b06607bfa6a5a6821bb948e011f634
Surface Ruleset: hakoniwa-2s-plus-v20 / version 20
Underground combat: secretary-underground-alpha-v3
次のOwner-approved scope: 3.5.2 UI / UX改善
```

#148はControllerと既存testの修正であり、application_versionの更新を含まない。このため「hotfix実装済み」と「画面のversion表示が3.5.1」は同じ事実ではない。本handoff更新でもruntimeのversion値は変更していない。

production情報は時点を区別する。

- migration統一の承認時点: Ownerはproductionがapplication 3.4.0 / Surface Ruleset v19で、旧3.5.0 migration 4本は未適用と明示した。#147はこの確認済み前提で行われた。
- その後: Ownerはブラウザで左右のコマンド／開発計画の復旧を確認し、今回「hotfixまで実装済み」として引継ぎ更新を依頼した。
- 最新実環境のexact checkout SHA、application設定値、migration ledger: 今回Web側からOCIへ独立照会していない。古い「productionは3.4.0」という記録を現在も成立すると断定しない。

本更新は文書作業のみ。production deploy / migration / OCI操作は行わない。以後の本番操作前には実際の状態を別Owner gateで確認し、既に適用されたmigrationを再統合・resetしない。

## 1.2 3.5.0と後続hotfixの構成

| PR / commit | 内容 | merge / anchor commit |
|---|---|---|
| #140 | Surface water ownership修正、限定backfill、Ruleset v20開始 | `cea7f39992c5885317b6102aa42469ea32fcaaba` |
| #141 | Ship persistence / projection、港 | `763a0993e5533eb9318048af925abb9f48206ade` |
| #142 | 船舶建造、任意廃船 | `c2ed1e66ca50d93119ea4766fc718402dcdc3446` |
| #143 | 進路API、randomized cell processing内の航行、燃料 / 報酬 / 秘書XP、lifecycle、forced displacement、Monster / 壊滅event連携 | `dd76f7fbe0eb0bbcb07420a20c4b62f379d8899f` |
| #144 | Ship-first missile impact、visibility、探索船表示 | `22203ae9f7b06607bfa6a5a6821bb948e011f634` |
| #146 | Release 3.5.0をmainへ昇格 | `c79a5dac056a20609137477e6ac0c112fab4990d` |
| #147 | 旧3.5.0 migration 4本を1本へ統一 | `146687a5d5f3f2703f405be6532fae562da3bd58` |
| #148 | Hotfix 3.5.1: command定義JSONのarray contractを復旧 | `4e4d9b85524df5b31a874ec5083c9f74be5cedcd` |

3.5.0のSurface gameplay sliceは船システムで閉じ、NPC海賊船、Ship修理、destination pathfinding、Ship Lv / XP、generic Actor / Spawn / Ship AI frameworkは含めない。

## 1.3 Version / Ruleset / identity

```text
application_version（config）:
  3.5.0
  PR #148 Hotfix 3.5.1のコードを含む

Surface Ruleset:
  hakoniwa-2s-plus-v20
  checksum: fdc8ca06a567aaa5a17860ad26fcecca50c4aa5a25a7ad430f6017178d485b5e

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

3.5.0はSurface gameplay semantic changeのため、当時のproduction baseline v19から1世代だけ進めたv20を全Ship sliceで共有する。v21は作成していない。#147 / #148でもfinal v20 payloadとcombat identityは変更していない。

## 1.4 Supported migration / production ledger

3.5.0へ進めるsupported upgrade sourceはexact application 3.4.0 / Surface Ruleset v19である。これはupgrade経路の定義であり、現在のproductionがまだsource上にあるという確認ではない。3.4.0のmigrationは別releaseとして維持する。

```text
product/database/migrations/2026_09_03_020000_add_underground_custom_ai.php
product/database/migrations/2026_09_04_000000_rebaseline_3_5_0_release.php
```

3.5.0の1本は、final v20のappend-only publish、current live referenceのstable-key rebind、facilityのないowned shallow / seaの限定修復、Ship schema / integrity guard、`ship_operations` constraint / backfillを1 transactionで適用する。movement、forced displacement、missile / visibilityを含むfinal v20 payloadを直接publishし、開発途中draft間のpayload照合は行わない。正当なfacility付きwater ownershipは維持する。

旧3.5.0 migration 4本は、production未適用というOwner-confirmed baselineに基づき#147で削除した。旧4本を適用済みのlocal / CI databaseはsupported upgrade sourceではなく、当該開発DBはfresh/resetして新ledgerへ揃える前提である。これはproduction reset許可ではない。旧4本適用済みproduction向けのcompatibility pathは設けていない。

`FreshInstallRebaselineTest`のfresh installとexact application 3.4.0 / v19 → application 3.5.0 / v20 regressionをsupported upgradeの正本とする。#148はmigrationを追加・変更していない。今回のUI改善でもmigrationを作らない。

## 1.5 3.5.0 Shipのplayer-facing contract

- ShipはNation-ownedのWorld actorであり、Monsterや秘書itemとは別domainである。1 cellにShipは最大1隻。
- 通常航行はseaだけ。canonicalにseaへ擬態するfacilityとだけ同居でき、publicな海底油田等とは同居できない。Map画像はShipを優先するがunderlying terrain / facilityは保持する。
- 港は条件を満たす中立shallowを通常landへ変換し、建設Nation所有のport facilityを置く。建設費は1,000億円。港数はShip capacityを増やさない。
- Nation-wideで漁船・観光船・探索船を各最大3隻保有できる。「船舶建造」で船種を選び、成功時は1 turnを消費する。spawn候補がない場合は費用もturnも消費しない。任意廃船は自国Ship選択時の通常commandで、成功時1 turn消費、返金なし。
- 漁船は500億円 / HP 1 / 成功航行ごとに石油10,000バレルで魚7,000t。観光船は1,500億円 / HP 2 / 石油20,000バレルで20億円。探索船は1,000億円 / HP 2 / 石油10,000バレル、直接報酬なし、visibility 3 hex。報酬は既存capacity経路を使う。
- Shipはrandomized Surface cell processing内でIDごと1 turn最大1回だけ通常eventを処理する。process_cells開始時の自国port有無snapshotを航行条件とし、同turn内の最後の港喪失は次turnから航行停止に反映する。
- `heading = null`はrandom mode。明示進路が不能な場合は移動可能な隣接seaからbounded random fallbackを1回試し、その後random modeへ戻る。進路変更はturnを消費しない。
- 成功航行1 cellごとに所有Nationの秘書`ship_operations`へ基礎XP +1。既存passive skill experience modifierを通常どおり適用する。required XPは65,535、表示は「準備中」、固有gameplay効果はない。
- active NationのShipだけが通常航行する。dormant / recoveryでは航行と進路操作を停止するが、missile、壊滅event、水棲Monsterの外部作用は受ける。abandonedになったNationのShipは除去する。
- forced displacementは隣接valid sea、近い自国portのdistance 1 → 2の順で無料退避し、不可能なら破棄する。通常航行event、燃料、報酬、秘書XPは消費しない。外国Shipが破棄された場合はterrain変更NationへKarma +1。複数port探索の実装とOwner意図の差は1.7の未決事項を参照する。
- 水棲MonsterはShipを破壊してからatomicに進入する。cellを壊滅させる既存eventはShipも沈没させ、壊滅でないeventはShip専用damageを追加しない。
- missileは既存interception後にShip-firstでimpactする。通常 / PP / SPPは1 damage、陸地破壊弾は即時撃沈。沈没後の後続弾は最新状態のunderlying terrain / facilityへ作用できる。外国player Ship撃沈はKarma +1。
- visibilityは自国land / 通常Shipのdistance 0〜1、探索船の0〜3で、擬態facilityのidentityと表示可能なownerを現在の表示時だけ開示する。永続Fog of Warはない。緑枠toggleはdefault OFFで、操作権限やprivate情報は増やさない。
- Ship修理、Ship Lv / XP、NPC海賊船は3.5.0の範囲外。NPC海賊船は3.6.0以降の別Owner gateへDeferredする。

## 1.6 Hotfix 3.5.1の完了状態

- 原因: `scuttle_ship`のfilter除外でCollectionの数値keyが飛び、`commands`がJSON arrayではなくobjectになった。frontendの`.filter()`が失敗して左右panelが消えた。
- 修正: `CommandQueueController`の最終map後に`values()`を追加してlistへreindexする。
- 回帰: 既存`DomesticCommandExecutionTest`へ、廃船を除外した応答でもraw JSONの`data.commands`がarrayであることを追加確認する。
- head: `a76738a1c0585f6949c204a8b6340df3cf5e43ef`。Quality `33924054076`のsuccessを前回独立レビューで確認した。Ownerもブラウザ上の復旧を報告した。
- 修正のためにgameplay semantics、Ruleset、schema、migrationは変更していない。hotfixを再実装したり、frontendで壊れたobjectを無条件に吸収する方式へ戻さない。

## 1.7 2026-09-05独立レビューの残件

前回レビューは固定commitの静的確認、OwnerのAPI / Console / screenshot、限定したJSON / CSS再現に基づく。本番の認証付き操作や全PHPUnitの独立再実行ではない。以下を「全て修正済み」や「本番で全件再現済み」と書かない。

| 項目 | 状態 / 次の扱い |
|---|---|
| 廃船の選択→保存のShip同一性 | P2指摘。UIが座標だけ送るため、船Aを選択後にそのcellへ船Bが来るとBを保存対象にし得る。登録後のID固定とは別。実DB再現後、表示したShip IDの期待値確認を最小修正候補とする |
| forced displacementの港探索 | 現行は距離順の最寄り1港だけを調べる。別港の空きも探すかはOwner未決。UI改善で勝手に全港探索へ変更しない |
| AI設定の既存入口 | キャラ欄にdisabledの「AI設定 / 準備中」が残る。editor本体は実装済み。3.5.2の導線改善対象 |
| theme / CSS | 選択中の白文字＋coral等のcontrast、未定義`--surface`参照、entrypoint間の上書き整理が改善対象 |
| 覚醒満タンの黄背景という指摘 | `app.css`の後段overrideを確認して誤検出として撤回済み。実entrypointを見ずに再度不具合と断定しない |
| version表示 | hotfix呼称とconfig値の差を記録。次のUI releaseのfinalizationで整合を取る候補であり、本handoff更新でruntimeは変更しない |

大量の理論上の異常値testを足すより、実API応答とbrowser描画をつなぐ代表操作確認を優先する。Ship全体の作り直し、追加cache / framework、migration再整理を指示するレビューではない。

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
- battle作成、EXP/G settlement、drop grantを同一transaction / profile lockで処理
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

# 6. 次release: 3.5.2 UI / UX改善と将来候補

## 6.1 Ownerの最新依頼と実装状態

Ownerは3.5.2を地上・島開発・地底RPG・戦闘・関連育成画面のUI / UX改善として依頼している。2026-09-05の相談時点では、この依頼をCodexへまだ一度も送っていない。Webでの設計・相談を進めている段階であり、既にAstraがUIを変更した／未commitのUI差分があると推測しない。

実装担当はSolでも構わないというOwner指示。Astra単体性能試験への固定は不要となった。Luna Max等は必要な限定作業に任意利用してよいが、主担当がUIの統一と契約を保持する。

詳細な実装設計・投入promptは、Ownerへ渡す同チャットの `3.5.2-ui-ux-design-and-sol-instructions.md` を参照する。これはチャット添付の設計書名であり、repository内に同名ファイルが存在するという意味ではない。

## 6.2 地上・コマンドのOwner意図

- コマンド一覧・予約一覧が縦に長すぎる。一覧はcompactにし、説明と編集buttonを全行へ常駐させない。
- 旧箱庭の一行形式は密度の参考。スマホへ極小一行を強制する仕様ではない。名称の上へ数量・上下・取消buttonが重なる状態を解消する。
- 数量・種類入力を一覧の上へ固定し、下のcommandを選んだ後に上へ戻らせる操作を解消する。
- 操作元の近くへ小窓を出し、選択command・対象座標・挿入位置を忘れずに入力できるようにする。狭い画面でのsheet等は設計案であり、配置の固定指定ではない。
- コマンドと選択マスを覆わない位置を優先する。全ての非重複が不可能なmobile / keyboard表示時は対象文脈を小窓へ残し、閉じると元の操作位置に戻れることを優先する。
- ターン消費あり／なしを一覧と予約の両方で見分けやすくする。青／茶色は候補で、色だけへ依存しない。消費なしqueue commandを「即時実行」と誤表示しない。
- 小窓表示中・送信待ちの対象すり替え、stale responseでのdraft破棄、背景keyboard操作の漏れを防ぐ。
- quantity_semantics、将来計画の登録可否、費用、bulk、queue limit、既存の競合検知は維持する。

## 6.3 地下戦闘のOwner意図

- 番号付きの均一な文章と全状態一覧を主役にせず、登録した秘書が戦っていると感じられる構図にする。
- 敵には原則画像がなく、案内人等の例外がある。画像あり／なしの双方で自然に成立させ、画像を必須にしない。
- HPは現在／最大。MP最大値は基本10,000固定で、主表示は現在値だけでよい。将来のMP回復量エンチャを最大MP変動と読み替えない。
- 障壁はHPの近くにまとめてよい。0の障壁、存在しないbuff/debuff、0のstack等を常時列挙しない。HP0・MP0は重要な状態なので隠さない。
- CT待ちがある場合の「技名: あと2」等は表示候補。既存保存データに値がなければ推測せず保留する。
- HP赤／MP緑、数値の上下、左右の構図は固定仕様ではない。
- 会心・回復・敵の危険行動へ色・文字・配置で強弱をつける。味方会心gold系／敵会心danger系／回復green系は候補で、必ず色以外でも意味を伝える。
- 覚醒発動は`Awaken!`や「覚醒中」等で識別できるようにする。ゲージ満タンと発動済みを分ける。満タン時のfillのみ変える既存contractは維持する。
- 画像・vitals等を一人分の小さな表示componentへ分ける程度でPT戦や変身画像への将来拡張を考える。PT戦本体・変身画像登録schemaは今作らない。
- 確定battleを表示するだけで、演出再生やskipにより戦闘実行・報酬を再発させない。時点がround-endだけなら行動直後と偽装しない。
- 現在の秘書profileを過去battleの途中状態に使わず、historical snapshot/hash・combat identityを変更しない。

## 6.4 実装・検証の境界

UI構図や小さいcomponent分割は実装担当が判断してよい。既存definition／保存情報から必要な表示fieldだけを追加投影する案は可だが、データ不足を新しいgameplay engine・trace保存・migrationで埋めない。

PC/mobile・Light/Dark、特にDark、長い名称・画面端・数字keyboard・画像なしを実ブラウザで確認する。frontend mockだけで完了とせず、実APIから一覧を取得しコマンドを登録する代表操作を確認する。

原則1本のUI改善PRへまとめ、foundation-onlyの別PRを作らない。小さな意味のあるcommitは可。十分な成果で終了し、usageを使い切ることは目標にしない。main merge / production deploy / OCIは別Owner gate。

## 6.5 将来候補・今回の非目標

- NPC海賊船はapplication 3.6.0以降へDeferred。3.5.0にpirate schema / AI / 捕虜 / raid / rescueの先行実装はない
- 案内人名変更後の新しい「リカ」再戦
- Unique装備
- 汎用装備エンチャ、装備強化。MP回復量等の効果は今後検討するが数値未確定
- 技巧 / criticalの再設計。現行式と単発／多段、他能力との比較を別途検討する
- status potencyの再設計
- Trial 2以降
- 第三狩場以降
- manual combat
- party / market
- 覚醒時の専用変身画像登録

3.5.2 UI改善以外のscopeは今回一括承認していない。published v20 payloadは上書きしない。FFA / 原作箱庭のUIは参考であってcodeや画像を複製する対象ではない。原作箱庭2.3は前回Drive調査で原本未特定であり、2＋を2.3原本として代用しない。

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

1. remote `main`、対象branch、worktreeを確認し、#147 / #148を含む最新の意図したbaseを確定する。cleanは未commit差分がないという意味であり、正しいbranchにいる証拠ではない。staleなorigin/mainや別作業commitを調べずresetしない。
2. 次のOwner-approved scopeは3.5.2 UI / UX改善。Ownerから実装指示書を受け取ったら、その範囲で`release/3.5.2`を作成または確認して進める。既にUI実装が始まっていると推測しない。
3. hotfixを維持し、1.7の未修正／未決／撤回済みを区別する。Shipの再設計や未決の全港退避をUI改善へ混ぜない。
4. production操作は今回不要。別途許可された場合だけ、実際のcheckout SHA・version・migration ledgerを確認し、古いproduction記録で判断しない。
5. Surface v20、combat v3、historical snapshotを維持し、UIのためのmigrationやRuleset追加を作らない。
6. 最初から全docsを読み直さず、次の関連入口とtask-specific codeへ進む。handoffはread-onlyで、終了時に更新材料をOwnerへ報告する。

最初に読むcurrent file:

```text
AGENTS.md
product/docs/handoffs/development-history-and-current-handoff.md
docs/README.md
docs/open-questions.md
product/config/hakoniwa.php
product/resources/js/components/CommandQueuePanel.vue
product/app/Http/Controllers/Api/CommandQueueController.php
product/app/Application/CommandQueueService.php
product/resources/js/components/UndergroundPanel.vue
product/resources/js/components/UndergroundAiEditor.vue
product/app/Application/Underground/UndergroundAlphaV1BattleProjector.php
product/resources/js/App.vue
product/resources/js/state/mapState.ts
product/resources/css/app.css
product/resources/css/hakoniwa.css
product/package.json
product/vite.config.js
```

migration／Ship runtime自体の調査が別途必要な時だけ、`SurfaceShip*Service`、`MissileImpactResolver`、current migrations、`FreshInstallRebaselineTest`等へ広げる。historicalなPR bodyやsimulation reportだけを読んでcurrent contractを再構成しない。
