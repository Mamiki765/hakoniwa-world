# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-08-30 JST  
> 対象リポジトリ: `Mamiki765/hakoniwa-world`  
> 配置先: `product/docs/handoffs/development-history-and-current-handoff.md`  
> 用途: 開発経緯、現在も有効な設計判断、release boundary、次の作業開始点の引継ぎ  
>
> この文書は、GitHub上のPR・commit・CI・review evidenceとOwnerの明示決定を照合した統合handoffです。
> 仕様・実装・運用状態が食い違う場合は、最新のreview済みコード、immutable Ruleset、DB schema / forward migration、
> ADR / decision、運用文書、Ownerの最新明示決定を優先してください。

## Maintenance ownership

このhandoffは、OwnerとWeb版ChatGPTのdevelopment-advisor workflowが管理する開発状況の外部記憶です。

Codexおよびimplementation agentはread-onlyの参考資料として利用してください。Ownerがhandoff更新そのものを明示的に依頼しない限り、編集・再生成・format・SHA更新・commitを行ってはいけません。

implementation agentは完了した作業をcode、test、PR本文、CI、review evidenceとして報告し、handoffへの確定反映は別workflowで行います。

---

# 0. 情報の読み方

- **GitHub確認**: PR、commit、branch、CI、review threadなどGitHubで確認した事実
- **Owner決定**: Ownerが会話または実装指示で明示した現行方針
- **将来候補**: Ownerメモ・設計案であり、現行仕様として自動採用してはいけないもの
- **未確認**: repositoryとOwner確認だけでは確定できないもの

## 0.1 正本の優先順位

```text
最新のreview済みコード / immutable Ruleset / DB schema・forward migration
  > ADR・decision・運用文書
  > Ownerの最新明示決定
  > この統合handoff
  > 古いhandoff / roadmap
  > AIの記憶・推測
```

古いhandoffの「次に行うこと」は、後続versionですでに完了・変更・撤回されている場合があります。古いTODOを現在の未完了作業として復活させないでください。

---

# 1. 現在地

## 1.1 一行要約

```text
main / production:
  application 2.8.0
  immutable surface Ruleset hakoniwa-2s-plus-v18 / 18
  checksum 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b
  productionはOwner確認済み2.8.0 / v18

3.0.0 release candidate:
  PR #110 "Stabilize 3.0.0 release" はopen / mergeable
  base: release/3.0.0-alpha
  application: 3.0.0
  pre-handoff reviewed code HEAD: 95d52e5454d756551cac980c963b038f5a5c077c
  exact-head Quality #416 / run 33317002926: success
  P0 / P1 / P2: 0
  unresolved review threads: 0
  surface Ruleset v18は変更なし
  main merge / production deploy / OCI操作は未実施

3.0.0 player Underground:
  正式intro、契約、4 growth path、通常探索、Combat Lv / EXP / STP、有限SP、
  戦技・護身・祝福Skill Tree、active最大5、宿、銀行、装備Shop、500枠宝物庫までplayer利用可能
  Trial正式release、覚醒、地底map / facility、random drop / affix / unique、party / marketは未実装
```

このhandoff更新自体はPR #110へ積むdocs-only commitです。そのためPR head SHAは上記`95d52e...`から進みます。作業再開時はPR #110のexact HEAD、Quality、review threadを再確認してください。

## 1.2 main / production baseline

**GitHub確認 / Owner確認**

```text
main application: 2.8.0
Ruleset key: hakoniwa-2s-plus-v18
Ruleset version: 18
checksum: 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b
PR #99 merge commit: c91e66d330cd4f8ae5f5297a794fd27582e4ddcf
production: Owner確認済み2.8.0 / Ruleset v18
```

2.8.0 production deployとv18 migrationは完了済みとして扱います。このhandoff更新ではproductionへ接続していません。production DB、TurnRun、web health、official Turn、asset実体の現況が必要な作業ではrepository stateから推測せず、Ownerの明示確認または許可されたread-only確認を行ってください。

## 1.3 PR #101〜#110 integration evidence

```text
PR #101  deterministic Underground combat laboratory
         merge commit: 5e295b591aa81c705ec4b622638cb9a1882cbb6d

PR #102  Secretary-owned Underground persistence foundation
         merge commit: 7183cfd0a997972630fdad0c811042af2a844bfa

PR #103  Underground expedition runtime foundation
         merge commit: 358bcda9f4eab339959f9c1ff3924c9108878277

PR #104  first-player Underground tutorial flow
         merge commit: abe80fff60159c0ae0dfb8552c9c9ef4425a0c39

PR #105  Underground combat build laboratory alpha-v1
         merge commit: ecea67972b6439cf70886d6fc5fb935fda035875

PR #106  Underground contract / growth path / player-facing playtest
         merge commit: 0abe33c7d1e8785468e2560ed9b7e1e7269f9e22

PR #107  Underground exploration and player growth
         final head: c41158e73cdc4e32673e67d6aa1952c19082c913
         merge commit: b1d79c4436414a589cdeb6a813a94f16b09738a9

PR #108  Underground status and skill progression
         final head: 93fb86d4e62314efeafb3018ce9dbcd150e21bc0
         merge commit: 267b442b2e2a3ab28788394a7c91142638ab7af1

PR #109  Underground equipment progression and shallow balance
         final head: aad30f97807b58bc50cb3391dc29b514f0e0dbf9
         merge commit: 0e8725be2f96c4af46190fcefb602af1fc6246ba
         exact-head Quality: 33312286443 success

PR #110  Stabilize 3.0.0 release
         base: 0e8725be2f96c4af46190fcefb602af1fc6246ba
         reviewed code head before this handoff update: 95d52e5454d756551cac980c963b038f5a5c077c
         exact-head Quality: 33317002926 success
         status: open / mergeable / Owner review
```

PR #110はこのhandoff commitでheadが進むため、merge判断時は新しいexact-head CIを確認してください。

## 1.4 PR #110最終stabilizationで確定したもの

- application versionを`3.0.0`へ正式化。alpha互換versionは挟まない
- Level Up時、battle resultへ大きな赤系`LEVEL UP！`とLv before → after / STP deltaを表示
- `/manual/underground`としてplayer向け「箱庭ダンジョン」manualを追加
- active Skill TreeへUI専用の静的`recommended_stats`を追加し、player-facingには`依存： 武力 / 技巧`等で表示
- `recommended_stats`はcombat / heal / AI / requirement / balance simulationへ使用しない
- `輝石循環`は`依存： ー`
- production未適用のUnderground alpha migration 10本を正式3.0.0 release migration 1本へrebaseline
- canonical schema dumpを3.0.0 final schema / migration ledgerへ更新
- exact 2.8.0 production-equivalent upgradeとfresh installの両経路を回帰検証
- manualの報酬説明をruntimeどおり「戦闘に勝利するとEXPと輝石の欠片Gを獲得」に修正
- release baseline testはTurn後の最終terrainではなく`land_clear`成功auditを検証し、同turn災害によるflakyを除去

PR #110 reviewで検出されたrelease blockerは解消済みです。

- P1: 3.0.0 source migration ledgerが実際にはexactではなかった
  - 修正後はcanonical exact 2.8.0 ledger 54件との完全一致を要求
  - unexpected row、過去production migration欠落、retired alpha ledgerをschema変更前にfail closed
- P2: manual冒頭が敗北時にもEXP/Gを得るように読めた
  - 勝利時のみ取得する表現へ修正

Owner報告のlocal repository-wide serial suiteは`845 tests / 16,256 assertions / 21分09秒 / failure 0`。最終review済みcode head `95d52e...`に対するQuality #416も全job成功、PHPUnit 16/16を含む全16 shard成功です。

---

# 2. 3.0.0 player contract

## 2.1 基本loop

```text
Secretaryメイン
↓ 地下へ
正式intro / Tutorial / 契約
↓
4 growth pathから1つ選択
↓
地下メイン
├ 周囲を探索
├ status / STP
├ Skill Tree / active skill設定
├ 装備Shop / 宝物庫
├ 宿
└ 銀行
```

Tutorial / story / development playtestは通常探索のprogression / current HP / economyを不正に変更しない境界を維持します。productionではplayer-facingの開発用「力試し」は公開しません。

## 2.2 Combat Lv / EXP / STP

Player Combat Lvにgameplay上限はありません。PR105 laboratoryのLv上限やsimulation rangeをplayer contractへ流用しないでください。

Lv2以降の自然成長と手動STP entitlement:

```text
戦技: 生命+1 武力+2 技巧+1 精神+1 敏捷+0 / STP+5
護身: 生命+2 武力+1 技巧+1 精神+1 敏捷+0 / STP+5
祝福: 生命+1 武力+1 技巧+1 精神+2 敏捷+0 / STP+5
自由: 生命+1 武力+1 技巧+1 精神+1 敏捷+0 / STP+6
```

- growth pathとSkill Treeは別mechanic。戦技growthを選んでも護身 / 祝福treeへSPを使える
- Lv1未使用STPは0
- multiple level-upはlevel delta分を一度だけsettle
- 敏捷は全growth pathで自然成長0。STP / equipmentで伸ばす

## 2.3 5能力値

- **生命**: 最大HP、物理防御、護身系の攻撃 / 障壁 / 回復 / counterに関係
- **武力**: 通常攻撃と物理skillの中心
- **技巧**: 通常攻撃の一部、戦技 / 短剣系、会心、一部祝福攻撃に関係
- **精神**: 魔法防御、祝福攻撃 / 回復 / 障壁の中心。最大MPは増やさない
- **敏捷**: initiative、回避、action impairment resistanceに関係。extra actionは現行仕様ではない

Player-facing exact formulaはmanualへ固定しすぎず、current codeをauthorityとします。

## 2.4 Skill Tree / SP

- player-facing tree: **戦技 / 護身 / 祝福**
- internal `miracle` / `miracle_*` identityはstable keyとして保持し、表示名変更を理由にrenameしない
- 各treeはcurrent authoring上100SP規模
- 契約時のinitial SPは20
- active skillは最大5
- weapon requirementを持つactive skillは、装備weaponが不適合な間だけbattle AI候補から外す
- persisted active slot自体は消さず、適合weaponへ戻すと再び利用可能
- passive / active acquisition、SP消費、loadoutはserver-authoritative
- `recommended_stats` / player-facing「依存」はguide metadataでありcombat authorityではない

祝福treeについて、現行のheal routeは無料ではありません。`聖晶弾`取得後に`治癒祈祷`を取得するcurrent cost structureを、古い設計メモを理由に0SPへ戻さないでください。初期20SP内でhealへ到達できる現在の難度をOwnerは許容しています。

## 2.5 装備 / Shop / 宝物庫

Formal 3.0.0 equipment slots:

```text
weapon x1
armor x1
accessory x1
```

- weaponは空にできない
- armor / accessoryはunequip可能
- Secretary-owned one-row-per-instance persistence
- vault capacity 500 instances、equipped itemも数える
- starter knifeはexactly-once owned instance、初期weaponとして装備
- starter knifeは通常売却不可 / 0G
- equipped itemは売却不可
- shop catalogは30個のdeterministic Common装備
  - 12 weapons: dagger / rapier / longsword / crystal_staff × 3 rank
  - 3 armor
  - 15 accessories: 5 stats × 3 rank
- player shopのweapon styleにshieldはない
- buyはcarried Gのみ。bank auto-withdrawなし
- shop gear saleは`floor(buy / 2)`、carried Gへ戻す
- buy / sell / equip / unequipはUUID idempotency、row lock / concurrency contractを持つ
- max HP増加装備へ替えても増加分を無料healしない。最大HP低下時はcurrent HPを新上限へclamp

アクセサリー3枠化は3.0.0仕様ではありません。ハクスラ要素拡張時の3.1.0以降候補として扱い、現行schema / balanceを勝手に変更しないでください。

## 2.6 通常探索 / battle settlement

- normal exploration後cooldown 10秒
- one battleはbuilt-in AIでatomic resolve
- max 100 rounds
- HPはbattle間persist
- MPはpersistせず、毎battle 10,000から開始
- victory: authored EXP + shard G
- defeat: EXP 0、carried Gを`floor(balance / 2)`まで減らす、banked Gは保持、HPは最大へ復帰
- 100-round withdrawal: authored victory EXPの`floor(1 / 4)`、shard gain / lossなし
- defeat / withdrawal / victory settlementは一度だけ
- `G`はGoldではなく**輝石の欠片のgram表記**

### 浅層enemy pool

```text
25% 地底鼠       EXP36 / 10G
25% 洞窟蟲       EXP40 / 12G
20% 腐食スライム EXP46 / 14G
10% 再生肉塊     EXP52 / 16G
10% 狂信者       EXP58 / 18G
 9% 迷い人の影   EXP72 / 22G
 1% 輝石虫       EXP1150 / 0G
```

輝石虫はHP1、各hit独立99% complete guard、guard失敗後に第二のevasion判定をしない特殊enemyです。100-round withdrawalなら287 EXPです。

PR109 final shallow tuningでは`迷い人の影`だけを強化しました。Lv1 Rank1の200-seed観測は、戦技0/200、護身28/200、祝福0/200、自由0/200。Lv20 Rank3では全growth build 200/200です。これは観測値であり将来の永久hard gateではありません。

## 2.7 宿 / 銀行

- 宿: carried 10Gでcurrent HPを最大へ回復。bank auto-withdrawなし
- 銀行: carried / bankedをrow lock下でatomic transfer
- defeatで失うのはcarriedだけ、bankedは安全
- MPは銀行 / 宿 / persistenceの対象にしない

## 2.8 Battle history / detail retention

- player-facing地下mainの履歴表示は**最新5件**
- backendはcompatibility / audit用にbounded compact historyを保持可能
- battle detail action / presentation log retentionは1時間
- listでdetailをeager loadしない
- detailは個別battle open時に取得
- expiry後もcompact battle record / idempotency / aggregateは保持
- historical presentationをcurrent skill / enemy catalogから再解釈しない
- prune command: `underground:prune-battle-logs`
- OCI host cron登録は3.0.0 production deploy時のoperator作業

---

# 3. 3.0.0 migration / installation contract

## 3.1 Production source

正式3.0.0 production upgradeでsupportするsourceは**exact 2.8.0 / surface Ruleset v18**のみです。

Canonical source migration ledger:

- 54 migrations
- 最後は`2026_08_27_000000_publish_v18_undersea_city`
- Underground alpha migration ledger / tablesが存在しない
- unexpected extra migration rowも許可しない

3.0.0 release migration:

```text
2026_08_30_050000_rebaseline_3_0_0_underground_release
```

はsource ledger 54件との完全一致をschema変更前に検証します。retired alpha ledger、過去production migration欠落、unexpected row、pre-existing Underground tableはfail closedです。

## 3.2 Rebaseline

3.0.0-alpha中に追加されたUnderground migration 10本はproduction未適用だったため、正式release historyには残さず、最終schemaを直接作成する1本へconsolidateしました。

保持するもの:

- 2.8.0以前のproduction migration history
- v17 / v18 migration
- immutable surface Ruleset v18 payload / checksum
- World / Nation / player / queue / Turn / event / audit / Secretary / Item等の既存business data

supportしないもの:

- development alpha DBのUnderground data migration
- alpha-only ledgerからのin-place upgrade

Production 2.8.0にはUnderground profile自体がないため、upgradeで既存player dataをresetせず、初回Underground利用時にprofileとstarter equipmentを既存lazy-create contractで作成するのがcanonicalです。

## 3.3 Fresh install equivalence

次の2経路がcanonical final 3.0.0 schemaへ到達することをtestで確認します。

```text
A. exact production-equivalent 2.8.0 DB
   → 3.0.0 release migration

B. empty DB
   → final 3.0.0 schema dump
```

Fresh schema dumpのmigration ledgerは3.0.0 release migrationを含む55件です。

---

# 4. Production safety / release boundary

## 4.1 現時点でまだしていないこと

- PR #110 merge
- `release/3.0.0-alpha`から`main`へのrelease integration
- production deploy
- production migration実行
- OCI host cron変更
- production backup / restore operation

Ownerの明示指示なしにこれらを実行しないでください。

## 4.2 3.0.0 release時の最低確認

1. PR #110のexact HEADとQuality successを確認
2. unresolved P0 / P1 / P2 = 0、review thread = 0を確認
3. verified production backup / restore boundaryを確認
4. sourceがexact 2.8.0 / v18であることを確認
5. unresolved failed / blocked surface TurnRunがないことを確認
6. application build / migration / recreate / cache clearをrunbookどおり実施
7. web health、login、surface、Underground lazy-create / entryのsmokeを確認
8. `underground:prune-battle-logs` wrapperの実配置pathを確認し、OCI host cronへ登録
9. automatic Turn retryは作らない

Migration失敗時はverified 2.8.0 backup restoreをrecovery boundaryとし、destructive production repairを即興で行わないでください。

## 4.3 Ruleset boundary

3.0.0でsurface Rulesetは`hakoniwa-2s-plus-v18 / 18`のままです。Underground gameplay identityはsurface Rulesetではありません。

Surface gameplay変更が必要になった場合、immutable v18を書き換えずv19以降を追加してください。

---

# 5. Architecture / design principles

## 5.1 目標

目標は「箱庭諸島2＋の完全コピー」ではなく、

```text
世界がつながった箱庭諸島2
```

を作ることです。

維持する中心構造:

- shared World
- 共通map
- Nation ownership / territory
- 同一map上のNation相互作用
- World expansion
- deterministic Turn processing

Undergroundはsurface gameを置き換えるものではなく、Secretary中心のTurn非依存side gameです。

## 5.2 Underground modular boundary

```text
pure combat core:
  DB / Laravel / World / Nation / MapCell / Turnなし

Underground application / persistence:
  User / Secretary / Underground専用table

future bridge:
  party borrowing / market / Nation-owned facility / surface benefitだけ
```

- User-persistent Secretaryとsurface Nation lifecycleを分離
- Secretary profile / skill / Underground progressionはNationを越えて残る
- Underground entitlementはSecretary-owned
- future Nation-owned地下facilityはNation lifecycleに従う
- Turn RNG / World seedへUndergroundを接続しない

## 5.3 Implementation style

優先:

- existing canonical path再利用
- 標準処理＋最小の明示差分
- small boundary
- representative test owner
- invariant / transaction / lock / retry / idempotency / DB integrity
- runtime performanceの実測

避ける:

- 1仕様のための巨大generic framework
- 将来用途だけを理由にした未使用schema / abstraction
- review fixへの無関係な意味変更
- 到達不能な極端値へのhot-path過剰防御
- performance改善でRNG / phase順 / sequential causalityを変えること

## 5.4 `_references`

`_references/`は原則read-onlyです。FFAいく改などは設計思想・情報配置・progression比較のreferenceとして使用してよいですが、asset / HTML / CSS / text / ruleをそのままcopyしません。

---

# 6. 開発経緯の圧縮要約

## 1.4.x

- 共有World上のterritory、Capital保護、伝言板 / 秘密通信
- TOP重大ニュース / 公開島ログ / owner-only log
- immutable Ruleset v3

## 1.5.x

- signed/current MapSpace bounds、World expansion、registration時自動chunk拡張
- World災害scale、海底基地経験値 / owner表示、H2寄りsettlement growth
- performance / CI sharding

## 1.6.x

- Nation abandonmentをphysical deleteではなくlifecycle state transitionへ
- surface cleanup / membership解除 / re-registration

## 1.7.0 / v6

- bulk queue / queue上限30
- defense / monumentのhidden behavior
- sea region naming
- production config cache

## 2.0.0 / v7

- User-persistent Secretary
- Secretary name / passive skill / development XP
- Nationを越えるSecretary generation boundary

## 2.1.0 / v8

- defense interception
- Secretary rename / historical snapshot

## 2.2.x / v9-v10

- Secretary warehouse / equipment foundation / inquiry
- request fingerprint / retry conflict safety / privacy hardening
- application version single source of truth

## 2.3.x / v11

- formal equipment effects、Old Bow、Ring
- monster dispatch / expanded monsters
- exact v10→v11 migration
- runtime / test responsibility cleanup

## 2.4.0 / v12-v13

- compatibility cutoff / current baseline rebaseline
- dormancy / recovery / KARMA
- migration lock / exact source / unresolved TurnRun fail closed

## 2.5 line / v14-v15

- public Secretary profile / image / biography / equipment presentation
- capacity modifier / monster damage experience / forest management

## 2.6.x / v16

- oil / Trading Post / Novice equipment
- current Ruleset authoring domain split / current-only executable runtime
- old executable historical runtimeをcurrent treeからretire

## 2.7.0 / v17

- theme
- Regular / Cursed Item
- monster Item drop
- population skills
- canonical Bow execution

## 2.8.0 / v18

- resource forecast / unemployment / labor saturation
- Nation event window
- undersea city
- exact v17→v18 migration
- PR #99 main merge、Owner確認production release

## 3.0.0 development line

### PR #101

- DB-free deterministic pure combat laboratory
- one actor / one round / one action envelope
- built-in AI / private deterministic RNG / reproducibility

### PR #102

- Secretary-owned Underground profile / layer entitlement
- World / Nation / MapCell / current Turn identityを持たないpersistence

### PR #103

- XP / shard / battle runtime / cooldown / Trial backend foundation
- battle summary / detail log / UUID idempotency / row locking

### PR #104

- current User自身のSecretaryだけが利用できるfirst-player tutorial / intro shell
- Tutorial reward / return flow / server-authoritative finite-state progression
- Trial content identity pinning / battle detail retention foundation

### PR #105

- alpha-v1 pure combat / build laboratory
- 5 stats、typed damage / heal / barrier / status
- 3 Skill Tree、active最大5、representative AI / equipment laboratory
- player growthのhard capやplayer equipment catalogではない

### PR #106

- formal intro / contract / 4 growth paths / player-facing battle presentation
- growth identity persistence
- self-contained battle presentation / bounded history

### PR #107

- player normal explorationを正式接続
- actual Secretary stats / starter knife / persistent HP / EXP / Lv / natural growth / STP settlement
- inn 10G / banked shard balance / atomic transfer
- no player Combat Lv cap

### PR #108

- status / manual STP allocation
- finite SP / 3 player Skill Trees / active最大5
- battle-duration taunt targeting contract
- weapon requirement-aware active skill runtime

### PR #109

- formal Secretary-owned equipment instances
- 500-slot vault / 30 Common shop items / buy / half-price sell / equip / unequip
- normal explorationをactual equipment loadoutへ接続
- shallow enemy tuning / cooldown countdown / production playtest hide
- player-facing history latest5 / active skill jump / inn transient message

### PR #110

- `3.0.0`正式version
- Level Up presentation
- player manual
- static skill dependency guidance
- alpha-only migration 10本→production release migration 1本rebaseline
- fresh install / exact 2.8.0 forward upgrade equivalence
- exact 2.8.0 migration ledger fail-closed hardening
- release regression / browser / CI stabilization

---

# 7. 3.1.0以降のOwner memo / 未実装候補

この節はcurrent 3.0.0 contractではありません。実装前にOwnerへ再確認してください。

## 7.1 Trial / 覚醒 / 地底map

Ownerの現時点の方向:

- Trial 1 clear後に**覚醒**を解禁する案
- Trial clearごとにUnderground mapを**4マスずつ**解放する案
- 3.1.0方向で扱い、3.0.0へ戻し入れない

覚醒後に`4 growth方向 × 3種類 = 12 Limit Break`とする案はconcept段階で未決定です。

## 7.2 ハクスラ拡張

- 現行3.0.0はweapon1 / armor1 / accessory1
- 将来のハクスラ感強化としてaccessory 3枠案あり
- random drop / affix / unique / enhancement / enchantと近いreleaseで検討する方が自然
- 3.0.0 schemaへ先回りして追加しない

## 7.3 敏捷方向

敏捷は現状initiative / evasion / action-impairment resistanceです。将来、敏捷中心の第4tree / buildを追加する案がありますが未決定です。

Action economyを壊しやすいextra actionより、速度依存damageや先制時bonus等を優先候補として検討します。

## 7.4 地下facility / surface bridge

Owner memo候補:

- 地底都市: Capital population max +10,000
- 地底農場: farm scale +10,000
- 地底工場: factory scale +30,000
- 地底ミサイル基地: missile count +1、missile experienceはSecretaryへ
- 地底防衛施設: final defense line +1候補
- build cost目安: 各2,000億円
- 解放4 slotから選択して設置する方向

基本Underground access自体をdestructible surface entranceへ依存させない方向が望ましいです。将来bridge facilityを作る場合も、Secretary-owned progressionとNation-owned facility lifecycleを混同しないでください。

地底ミサイル基地を実装する場合、地下baseが現在のNation Capital座標をsurface launch originとして参照する案があります。未決定であり、surface Ruleset変更を伴うためUG-04 / Ruleset decisionを通してください。

## 7.5 UG-04

次へ進む前に`docs/open-questions.md`のUG-04で停止します。

- borrowed Secretary / party
- Underground market
- Nation-owned Underground facility placement / lifecycle
- surface benefit / surface Ruleset bridge

---

# 8. 古い計画の扱い

- 2.3.1時点のcurrent-only runtime候補は2.4.0 / 2.6.1で発展済み。未完TODOとして復活させない
- 古い2.4.0 Item / auction案は実際の2.4.0とは異なる。Trading Postは2.6.0で実装済み
- 2.5.0の船placeholderは実際の2.5 lineに採用されなかった。船 / port / fuelは将来候補
- ver 2.9.0 Item拡張案は破棄ではないが現在pause。再開時はcurrent Item catalogとOwnerの新決定を確認
- generic modifier framework / generic map Item等は未実装候補であり、自動的に作らない

---

# 9. 次の担当者の開始手順

1. `AGENTS.md`
2. `product/docs/handoffs/development-history-and-current-handoff.md`
3. `docs/README.md`
4. `docs/open-questions.md`
5. Underground作業ならcurrent roadmap / architecture docs
6. task-specific current docs
7. current branch HEAD、open PR、CI、review threadをGitHubで再確認
8. raw referenceが本当に必要な場合だけ`_references/`をread-onlyで確認

Historical / Audit / Future / MIXED文書を無差別に読まないでください。

現在の基準点:

```text
main / production:
  application 2.8.0
  Ruleset hakoniwa-2s-plus-v18 / 18
  checksum 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b

release candidate:
  PR #110 open
  application 3.0.0
  reviewed code baseline before handoff update: 95d52e5454d756551cac980c963b038f5a5c077c
  Quality #416 success on that code baseline
  handoff docs-only commit後のexact HEAD / CIを再確認すること

release boundary:
  surface Ruleset v18 unchanged
  production remains 2.8.0
  no main merge / production deploy / OCI cron change without Owner instruction
```

---

# 10. 次のAI / Codexへ渡す開始プロンプト

```text
Mamiki765/hakoniwa-worldの開発相談を引き継いでください。

最初にAGENTS.md、product/docs/handoffs/development-history-and-current-handoff.md、
docs/README.md、docs/open-questions.mdを読み、GitHubの現物と照合してください。

main / productionはapplication 2.8.0、immutable surface Ruleset
hakoniwa-2s-plus-v18 / version 18 / checksum
40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8bです。

3.0.0 release candidateはPR #110です。
PR #101〜#109はrelease/3.0.0-alphaへmerge済みで、PR #110がapplication 3.0.0正式化、
Level Up表示、箱庭ダンジョンmanual、Skill TreeのUI専用「依存」metadata、
3.0.0 migration rebaseline、fresh / exact 2.8 upgrade regressionをまとめています。

PR #110のreview済みcode baselineは95d52e5454d756551cac980c963b038f5a5c077cで、
Quality run 33317002926はsuccess、P0/P1/P2 0、review thread 0でした。
ただしhandoff更新commitによりPR headは進むため、作業開始時にexact HEADとCIを再確認してください。

3.0.0 player Undergroundでは通常探索、Combat Lv / EXP / natural growth / STP、有限SP、
戦技・護身・祝福Skill Tree、active最大5、宿、銀行、formal equipment、Shop、500枠宝物庫が利用可能です。
Combat Lvにgameplay上限はありません。HPは持越し、MPは毎battle 10,000から開始します。
GはGoldではなく輝石の欠片のgram表記です。

Formal equipment slotはweapon1 / armor1 / accessory1です。
アクセ3枠、random drop、affix、unique、覚醒、Trial正式release、地底map / facility、party / marketは3.0.0にありません。

3.0.0 production migrationはexact 2.8.0 migration ledger 54件とimmutable v18 stateだけをsourceとしてsupportし、
Underground alpha ledger / table、unexpected migration、過去migration欠落をfail closedします。
Alpha DB data migrationはproduction contractではありません。2.8既存player dataはresetせず、Underground profile / starter equipmentは初回利用時lazy createします。

Owner明示指示なしにPR merge、main変更、production接続、deploy、backup、cron操作を行わないでください。
product/docs/handoffs/development-history-and-current-handoff.mdは通常のimplementation agentにはread-onlyです。
```

---

# 11. Gitへ格納するときの注意

この文書へ次を含めないでください。

- token / API key / password
- SSH秘密鍵
- DB接続文字列
- production IP
- private email
- OAuth secret
- backup passphrase
- private inquiry content
- hidden story condition / identity / background lore
- local absolute file path

Git履歴へ入れた秘密情報はfile削除後も残ります。

---

# 12. 最終要約

```text
main / production:
  2.8.0 / immutable surface Ruleset v18

3.0.0:
  PR101 pure combat
  PR102 Secretary-owned persistence
  PR103 expedition runtime / history
  PR104 first-player tutorial flow
  PR105 5-stat / Skill Tree combat laboratory
  PR106 formal intro / contract / growth paths
  PR107 normal exploration / persistent HP / Lv / STP / inn / bank
  PR108 status / manual STP / finite SP / 3 Skill Trees / active loadout
  PR109 formal equipment / Shop / 500-slot vault / shallow balance
  PR110 release stabilization / manual / dependency guide / migration rebaseline / version 3.0.0

release state:
  PR110はOwner review中
  review済みcode baseline 95d52e...でP0/P1/P2 0
  Quality #416 success
  handoff commit後のexact HEAD / CIを再確認してからmerge判断
  productionはまだ2.8.0

post-3.0 candidate:
  Trial / 覚醒 / 4-cell Underground map progression
  ハクスラ拡張とaccessory複数枠
  Limit Break concept
  agility-oriented build concept
  Underground facility / surface bridge
  party / marketはUG-04 decision待ち
```

この文書の役割は、失われた会話を推測で埋めることではありません。

```text
何が確定したか
何が後に変更されたか
何がcurrent release contractか
何が将来候補にすぎないか
次にどこから再開すべきか
```

を一つの場所から追えるようにすることです。
