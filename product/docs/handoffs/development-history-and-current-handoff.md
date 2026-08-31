# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-08-31 JST  
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

このhandoffには一般開発に不要なhidden story lore、hidden identity、hidden battle条件を書きません。

---

# 0. 情報の読み方

- **GitHub確認**: PR、commit、branch、CI、review threadなどGitHubで確認した事実
- **Owner決定**: Ownerが会話または実装指示で明示した現行方針
- **設計中**: 方針はあるが数値・ontology・runtime contractが未確定のもの
- **将来候補**: 自動採用してはいけない案
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
main:
  application 3.0.0
  merge commit a76f49013efdcea7a1b519873356fcd4386cbaaf
  immutable surface Ruleset hakoniwa-2s-plus-v18 / version 18
  checksum 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b

production:
  Owner運用確認上、3.0.0 deploy後のhakoniwa-webでUnderground log prune wrapperを手動実行しexit 0
  root host cronへ03:15 JSTのUnderground battle-detail pruneを登録済み

release/3.1.0:
  PR #112 Trial 1 balance simulation foundation をmerge済み
  #112 final head: 2dd43f6abee52f9233365fe2d40a4dbdfdd2b7c5
  #112 merge commit: 84ec606be3b08451fa495082e2ff59c0a20f4bcf
  exact-head Quality #427 / run 33355290402: success
  Surface Ruleset / application versionは変更なし
  Trial 1はまだplayer-facing正式releaseではない
```

このhandoff更新前の`release/3.1.0` headは`84ec606...`です。このdocs commitでbranch headは進むため、次作業開始時は新しいexact HEADを確認してください。

## 1.2 3.0.0 release evidence

**GitHub確認**

```text
PR #110 Stabilize 3.0.0 release
  final head before merge: 4af03dc0688f2125ad9ab7fd6ce5d18f4e319b64
  merge commit into release/3.0.0-alpha: 1eb5f0174dfde4174ad25f035873981290a6c041

PR #111 Release 3.0.0 — 箱庭ダンジョン
  base: main
  head before merge: 1eb5f0174dfde4174ad25f035873981290a6c041
  exact-head Quality #424 / run 33318491862: success
  merge commit into main: a76f49013efdcea7a1b519873356fcd4386cbaaf
```

`product/config/hakoniwa.php`のmainは`application_version = 3.0.0`で、current Surface Rulesetはv18のままです。3.0.0 releaseでSurface Ruleset payloadは変更していません。

## 1.3 3.0.0 production operator state

**Owner確認**

3.0.0 deploy後、既存OCI host上でUnderground battle-detail retention cleanupを追加済みです。

手動確認結果:

```text
hakoniwa-web: healthy
Pruned 0 expired Underground battle log(s).
exit: 0
```

root crontabへ以下を登録済み:

```cron
CRON_TZ=Asia/Tokyo
15 3 * * * /usr/bin/flock -n /run/lock/hakoniwa-underground-log-prune.lock /usr/bin/env HAKONIWA_PROJECT_DIR=/home/ubuntu/apps /home/ubuntu/apps/hakoniwa-world/product/docker/cron/prune-underground-battle-logs.sh >> /var/log/hakoniwa-underground-log-prune.log 2>&1
```

このjobは期限切れの`underground_battle_logs`詳細だけを物理削除します。compact battle recordは保持します。shell側でautomatic retryを追加しないでください。

既存production hostにはTurn cronとbackup cronも存在します。Turn retry contractは従来どおり、failed / blocked turnをcronが自動retryせず、原因確認後にoperatorが明示的manual retryします。

---

# 2. 3.0.0 Underground player contract

## 2.1 基本loop

```text
Secretaryメイン
↓ 地下へ
intro / Tutorial / 契約
↓
4 growth pathから1つ選択
↓
通常探索
├ battle
├ Combat Lv / EXP / STP
├ Skill Tree / active skill
├ 装備Shop / 宝物庫
├ 宿
└ 銀行
```

UndergroundはSurface Turnとは独立したside gameです。通常探索cooldownは10秒。1回の探索battleはcanonical combatでatomicに解決します。

## 2.2 Battle persistence / result

- battle最大100 rounds
- HPは通常battle間でpersist
- MPはpersistせず、各battle開始時10,000
- 勝利: authored EXP + G
- 敗北: EXP 0、carried shard balanceは`floor(balance / 2)`、bankは安全、HPは全快へ戻す
- withdrawal / 100-round stalemate: base victory EXPの1/4、0G
- `G`はGoldではなく輝石の欠片のgram表現
- 詳細battle log retentionは1時間。期限後もcompact summaryは保持
- player-facing recent battle historyはlatest 5

## 2.3 Combat Lv / EXP / STP

Player Combat Lvにgameplay上限はありません。laboratoryのrangeをplayer上限として流用しないでください。

Lv2以降の自然成長と手動STP entitlement:

```text
戦技: 生命+1 武力+2 技巧+1 精神+1 敏捷+0 / STP+5
護身: 生命+2 武力+1 技巧+1 精神+1 敏捷+0 / STP+5
祝福: 生命+1 武力+1 技巧+1 精神+2 敏捷+0 / STP+5
自由: 生命+1 武力+1 技巧+1 精神+1 敏捷+0 / STP+6
```

- growth pathとSkill Treeは独立mechanic
- Lv1未使用STPは0
- 敏捷は全pathで自然成長0。STP / equipmentで伸ばす
- 自由はmanual STPが1/Lv多い代わりに、specialist pathの将来価値はmechanic側でも確保する方向

## 2.4 5能力値

- **生命 / vitality**: 最大HP、物理防御、護身系効果
- **武力 / might**: 通常攻撃・物理攻撃の中心
- **技巧 / finesse**: 通常攻撃の一部、戦技 / dagger系、critical、一部祝福攻撃
- **精神 / spirit**: 魔法防御、祝福damage / heal / barrier。最大MPは増やさない
- **敏捷 / agility**: initiative、evasion、action impairment resistance。現行ではextra actionなし

## 2.5 Skill Tree / SP

- player-facing tree: **戦技 / 護身 / 祝福**
- internal `miracle` keyはstable identityとして保持
- initial SP: 20
- active skill最大5
- growth pathとSkill Treeは独立
- server-authoritative acquisition / SP消費 / active loadout
- `recommended_stats` / UI上の`依存`はguide-onlyでcombat authorityではない
- `輝石循環`のUI依存表記は`ー`
- `短剣乱舞`はdagger / rapier requirement
- `盾撃`はshield requirementなし

祝福heal routeは無料ではありません。現行では`聖晶弾`→`治癒祈祷`で初回heal到達に11SPを要します。古い案を理由に0SPへ戻さないでください。

## 2.6 Equipment / Shop / vault

Formal 3.0.0 slots:

```text
weapon x1  必須
armor x1
accessory x1
```

- Secretary-owned one-row-per-instance persistence
- vault capacity 500、equippedを含む
- starter knife exactly once、初期weapon。売却不可 / 0G
- equipped itemは売却不可
- Shopはdeterministic Common 30 items
  - weapons 12 = 4 styles × 3 ranks
  - armor 3
  - accessories 15 = 5 stats × 3 ranks
- buyはcarried Gのみ
- normal Shop sellは`floor(buy / 2)`
- random affix / enchant / unique / Item Lv progressionは3.0.0 player contractではない

## 2.7 Shallow hunting ground baseline

代表enemy:

```text
地底鼠       25% EXP36   10G
洞窟蟲       25% EXP40   12G
腐食スライム 20% EXP46   14G
再生肉塊     10% EXP52   16G
狂信者       10% EXP58   18G
迷い人の影    9% EXP72   22G
輝石虫        1% EXP1150  0G
```

浅層Shop progression目安:

```text
Rank1 gross 280G  ≒ 6m20
Rank2 cumulative net 980G  ≒ 17m
Rank3 cumulative net 3060G ≒ 45m20
```

---

# 3. 3.0.0 migration / release boundary

3.0.0 release migration:

`product/database/migrations/2026_08_30_050000_rebaseline_3_0_0_underground_release.php`

- source contractはproduction-equivalent 2.8.0 ledger 54 migrations
- fresh 3.0.0 ledger 55 migrations
- retired alpha Underground migrationsをproduction ledgerへ持ち込まない
- existing Surface / Nation / player dataをresetしない
- Underground profile / starter gearはlazy-created
- `down()`はforward-only / backup restore recovery前提

3.0.0 release後のproduction migrationを「まだ未公開alphaだからfreshでよい」と扱わないでください。以後は通常のmigration / explicit conversion boundaryです。

---

# 4. 3.1.0 current track

## 4.1 branch / integration state

**GitHub確認**

```text
release/3.1.0
  created from main 3.0.0 baseline a76f49013efdcea7a1b519873356fcd4386cbaaf

PR #112 Trial 1 balance simulation foundation for 3.1.0
  final head: 2dd43f6abee52f9233365fe2d40a4dbdfdd2b7c5
  merge commit: 84ec606be3b08451fa495082e2ff59c0a20f4bcf
  exact-head Quality #427 / run 33355290402: success
  merged into release/3.1.0
```

#112は**simulation foundation**です。Trial 1 player-facing runtime、clear persistence、reward、Awakening、第二狩場、Underground facilityはまだ正式releaseされていません。

## 4.2 Trial 1 simulation contract

Trial 1 provisional/final-balance candidate:

- 10 consecutive battles
- HPはbattle間でcarry
- 勝利後、次battle前に最大HPの20%回復、max HPでcap
- MPは各battle 10,000へreset
- defeat / withdrawalでTrial終了
- battle最大100 rounds
- Trial 1 clear前のinitial 20SPのみ
- Rank 3 Shop gear
- no Awakening
- no enchant / random loot
- Lv25 / Lv30 / Lv35 checkpoint

20% interbattle healは、10% / 20% / 30%比較のうち4growthをLv30で30–70%帯へ残した唯一の候補です。正式runtimeへ持ち込む際も20%をcurrent candidateとします。

## 4.3 Trial 1 final 1,000-seed result

Lv30 / Rank3 / interbattle heal 20%:

| Growth build | Clear | Defeat | Withdrawal | 平均boss round |
|---|---:|---:|---:|---:|
| 戦技 | 327 / 1000 | 673 | 0 | 19.222 |
| 護身 | 436 / 1000 | 564 | 0 | 32.715 |
| 祝福 | 476 / 1000 | 524 | 0 | 44.039 |
| 自由 | 335 / 1000 | 665 | 0 | 35.577 |

Owner意図は「Lv30 / Rank3で4pathともギリギリ突破可能」「祝福は10連戦の持久型として他pathよりやや高いclear率を許容」です。現candidateはこれを満たしています。

祝福simulation buildはinitial 20SP中19SP使用です。

Lv25は全build 0%。Lv35は戦技100%、護身100%、祝福99.9%、自由98.4%。「Lv30で勝負、+5Lvなら明確に楽」というescape curveを許容します。

## 4.4 Wyvern final balance candidate

Battle 10 boss: **ワイバーン**

主要値:

```text
max HP: 1600
physical defense: 60
magical defense: 229
weapon power: 180
self regeneration: current trial manifest valueをauthorityとする
```

旧candidateのMDef 300は、祝福が倒されずに100-round withdrawalへ偏る壁になっていました。MDefを229へ調整し、長期戦pressureをphase transitionへ移した結果、selected final scenarioでは全build withdrawal 0です。

Round 40 transition:

```text
天井が崩落し、ワイバーンは宙に舞い上がる……！
```

- round 40開始時に一度だけ発火
- `飛翔`status
- all outgoing damage +100%
- transition自体はactionを消費しない
- RNG consumptionを増やすためのfake actionにしない
- build-specific bonus / anti-healではなく全build共通のlong-fight pressure

final 1,000 seedsでflight transition発動:

```text
戦技: 0 / 1000
護身: 0 / 1000
祝福: 892 / 1000
自由: 224 / 1000
```

これにより、祝福は低火力ゆえ第二phaseへ入りやすいが、回復阻害やhidden handicapなしで勝敗が付く設計になっています。

## 4.5 #112 P2 final-HP correction

Codex reviewで、stalemate / withdrawal時にも`final_hp = 0`としてoperator reportへ集計されるP2を検出しました。

修正後:

- actual defeatのみ`final_hp = 0`
- clearは最終battle remaining HPを保持
- stalemate / withdrawalも最終battle remaining HPを保持
- `trial_end_average_hp_all_trials`へwithdrawal survivabilityが正しく反映

selected final 1,000-seed scenarioは全build withdrawal 0のため、clear / defeat / withdrawal classificationとbalance結論は変わりません。heavy 1,000-seed report再生成は不要と判断し、focused regression + exact-head Qualityで検証しています。

---

# 5. 次の3.1.0実装開始点

## 5.1 最優先: Trial 1をsimulationから正式runtimeへ

次sliceの中心は、#112のbalance candidateをplayer-facing Trial 1 runtimeへ昇格することです。

最低限のscope:

1. server-authoritative 10連戦progression
2. HP carry + 勝利後20% heal
3. per-battle canonical MP reset
4. defeat / withdrawal stop
5. attempt / clear persistenceを必要最小限で追加
6. 20-round warning
7. 40-round flight transitionのplayer-facing強調
8. Trial 1 clear rewardをexactly-onceでsettle

Round 20のOwner演出案:

```text
洞窟が崩れそうだ……
```

- warningのみ。能力変化なし
- player-facingでは目立つ赤系表示

Round 40:

```text
天井が崩落し、ワイバーンは宙に舞い上がる……！
```

- player-facingでも目立つ赤系表示
- combat-side transitionは#112でfoundation済み

## 5.2 Trial 1 clear reward / unlock direction

**Owner決定 / current design direction**

Trial 1を含むTrial progressionは、地下RPGを遊んだ報酬として地底領域を開放する構造です。

```text
Trial 1 clear -> 地底4slot unlock
Trial 2 clear -> +4slot
Trial 3 clear -> +4slot
Trial 4 clear -> +4slot
```

Trial 1 clear後の3.1.0 direction:

- SP +40
- Awakening unlock
- second hunting ground / deeper layer unlock
- first Underground 4 cells / slots unlock

SP +40はclear rewardとしてexactly-onceである必要があります。retry / duplicate request / replayで二重付与しないでください。

## 5.3 Awakening — 設計中、未実装

現時点のOwner design:

- 強力だが恒常balanceを大きく歪めないboss / Trial向け切り札
- 発動時HP / MP全回復
- 5種主能力値を+30%（HP / MPそのものを直接+30%する意味ではない）
- Awakening technique stockを1回分得る
- techniqueはgrowth tendency / selected crystalごとに複数、目安3種
- 1battle中に2回Awakeningする設計にはしない
- Awakening gaugeはbattle間carryする方向
- 雑魚戦で毎回使うのではなく、狩りで蓄積してTrial / bossへ持ち込む想定

未確定:

- gauge exact threshold。会話中の`512`は例でありauthorityではない
- round経過 / 被弾によるexact gain formula
- multi-hitを何単位でgain扱いするか
- exact AI activation contract。HP20% triggerは候補だが正式runtime未確定
- 4growth × Awakening techniqueの具体効果
- transformation visual

将来gainを実装する場合、弱いenemyを意図的に引き延ばすことやmulti-hit enemyを充電器にすることが最適化にならないかsimulationしてください。

## 5.4 second hunting ground / Item Lv / enchant

Trial 1 clear後にLv30+向け第二狩場を開放する方向です。

- shallowより大幅に効率を上げる
- enemyがenchant付きequipmentをdropし始める
- ここからItem Level progressionを本格的に有効化
- second areaを約10時間遊んだ時点でTrial 2-readyになるprogressionを目安にする
- Trial 2自体は3.2.0へ分離してもよい

具体的なenchant pool / rarity ontologyはOwner未確定です。implementation agentが勝手に正式ontologyを作らず、必要時にOwnerへ確認してください。

## 5.5 地底表示 / facility — current visual direction

地底はSurfaceと同サイズの第二hex mapを作る必要はありません。現在のOwnerイメージは、島画面で地上と伝言板の間に小さいside-view断面を置く方向です。

基本形:

```text
土 土 管 土 土
道 道 梯 道 道
```

- `管`: 地上入口 / 土管
- `梯`: 下層へ続く固定軸
- `道`: buildable slot、左右2ずつ = 1layer 4slot
- Trial clearごとに4slotずつ下へunlock

専用tabは必須ではありません。小規模表示なので、Surface UIと伝言板の間に直接表示してよい方向です。

facilityのactual effect / cost / lifecycleは正式実装前に再確認してください。過去候補には地底都市、地底農場、地底工場、地底ミサイル基地、地底防衛施設等がありますが、古い数値を無確認でcurrent contractへ昇格しないでください。

Secretary-owned progressionとNation-owned facility lifecycleを混同しないこと。少なくともTrial clear / unlocked depthのようなRPG progressionは島の通常施設破壊と同じ理由で消える設計にしない方向です。

---

# 6. 3.1.0で今はやらないもの

次sliceへ勝手に混ぜないもの:

- Trial 2 / 3 / 4正式runtime
- 4growth分のAwakening technique全実装を一気に行うこと
- enchant / rarity ontologyの独断確定
- accessory 3枠化
- party / multiplayer dungeon
- player market
- Surface Ruleset v18の変更
- production OCI操作
- main merge
- handoffのagent自主更新

release/3.1.0は小branchを重ねて進め、各PRをOwner review後にmergeしてください。

---

# 7. integration history quick reference

```text
PR #101  deterministic Underground combat laboratory
PR #102  Secretary-owned Underground persistence foundation
PR #103  Underground expedition runtime foundation
PR #104  first-player Underground tutorial flow
PR #105  Underground combat build laboratory alpha-v1
PR #106  Underground contract / growth path / player-facing playtest
PR #107  Underground exploration and player growth
PR #108  Underground status and skill progression
PR #109  Underground equipment progression and shallow balance
PR #110  Stabilize 3.0.0 release
PR #111  Release 3.0.0 — 箱庭ダンジョン
PR #112  Trial 1 balance simulation foundation for 3.1.0
```

重要なrelease commits:

```text
#110 merge into release/3.0.0-alpha:
  1eb5f0174dfde4174ad25f035873981290a6c041

#111 merge into main:
  a76f49013efdcea7a1b519873356fcd4386cbaaf

#112 final head:
  2dd43f6abee52f9233365fe2d40a4dbdfdd2b7c5

#112 merge into release/3.1.0:
  84ec606be3b08451fa495082e2ff59c0a20f4bcf
```

---

# 8. 作業開始時checklist

新しいimplementation sliceを開始する前に:

1. `release/3.1.0`のexact HEADを確認
2. open PR / unresolved review threadを確認
3. current application version / Surface Rulesetを確認
4. handoffはread-onlyとして読む
5. 今回scopeとnon-goalをOwner指示から固定
6. heavy simulationはCIへ常設せず、small deterministic smokeとmanual balance runを分離
7. production / OCI / main操作はOwner明示指示なしに行わない
8. migrationが必要なら3.0.0 release済みboundaryを前提にforward-safeに設計

次の自然な開始点は、**Trial 1正式runtime + clear reward SP+40 + first 4 underground slots unlock**です。Awakening本体、第二狩場、enchant、facility効果はreviewableな別sliceへ分割してください。
