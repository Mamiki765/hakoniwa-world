# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-09-02 JST  
> 対象リポジトリ: `Mamiki765/hakoniwa-world`  
> 配置先: `product/docs/handoffs/development-history-and-current-handoff.md`  
> 用途: 現在有効なrelease boundary、Owner決定、次の作業開始点の引継ぎ
>
> この文書は、GitHub上のPR・commit・CI・review evidenceとOwnerの明示決定を照合した統合handoffです。
> 仕様・実装・運用状態が食い違う場合は、最新のreview済みcode、immutable Ruleset、DB schema / forward migration、
> accepted ADR / decision、operations文書、Ownerの最新明示決定を優先してください。

## Maintenance ownership

このhandoffは、OwnerとWeb版ChatGPTのdevelopment-advisor workflowが管理する外部記憶です。

Codexおよびimplementation agentはread-onlyとして利用してください。Ownerがhandoff更新そのものを明示的に依頼しない限り、編集・再生成・format・SHA更新・commitを行ってはいけません。

個別機能の詳細仕様はcurrent code、test、architecture文書を正本とします。このhandoffには一般開発に不要なhidden story lore、hidden identity、hidden battle条件を書きません。

---

# 0. 情報の読み方

- **GitHub確認**: PR、commit、branch、CI、review threadから確認した事実
- **Owner決定**: Ownerが会話または実装指示で明示した現行方針
- **次release**: まだ未実装だが、次に実装することがOwner決定済みのもの
- **未確認**: repositoryとOwner確認だけでは確定できないもの

## 0.1 正本の優先順位

```text
最新のreview済みcode / immutable Ruleset / DB schema・forward migration
  > accepted ADR・decision・operations文書
  > Ownerの最新明示決定
  > このhandoff
  > historical handoff / roadmap
  > AIの記憶・推測
```

古いhandoffのTODOを現在の未完了作業として自動復活させないでください。過去版の詳細はGit historyを参照してください。

---

# 1. 現在地

## 1.1 main / release状態

**GitHub確認**

```text
main:
  application: 3.1.0
  Ruleset: hakoniwa-2s-plus-v19 / version 19
  checksum: b65752b88e9daf3c9b64e6d28b72847315d521dfe65b704f4cd8fd622e1368c9

3.1.0 release merge:
  PR #118 Release 3.1.0
  merge commit: 96b36706ebee211126ab24ca817ef3a5633e3613

AGENTS.md整理:
  main commit before this handoff update: e5718fbb70201f523ec689a4e3c00e9d4ae16cea
  日本語の恒久原則へ整理済み
```

このhandoff更新commitによりmain HEADはさらに進みます。次作業開始時は必ずremote mainのexact HEADを取得してください。

## 1.2 production確認境界

このhandoff更新時点で、3.1.0のproduction deploy、production migration、OCI操作を実行した証拠はこのworkflowにはありません。

production作業前に、実際の稼働application version、migration ledger、未解決TurnRun、backup状態を再確認してください。mainへmerge済みであることをproduction適用済みの証拠にしないでください。

## 1.3 3.1.0 release migration

3.0.0から3.1.0へのsupported production upgradeは、次のcanonical migration 1本です。

```text
product/database/migrations/2026_09_01_000000_rebaseline_3_1_0_release.php
```

PR #112〜#116の開発途中migrationはrelease stabilizationでretire済みです。

3.1.0 stabilization evidence:

- exact 3.0.0 / v18 -> 3.1.0 / v19 upgrade: pass
- fresh install: pass
- local `composer test:all`: 867 tests / 17,223 assertions / 25:29
- stabilization exact-head Quality: run `33533539123` success
- queued Surface / Underground definition resolutionはcanonical resolverへ集約済み
- long-running Composer test scriptsは300秒process timeoutを無効化済み

---

# 2. 3.1.0で追加された主なplayer-facing機能

## 2.1 封印の地 / Trial 1

player-facing名称:

```text
封印の地
└ 地下に眠る古代遺跡
```

主な現行contract:

- 全10戦
- HPは戦闘間でcarry
- 勝利後、次戦前に最大HPの20%を回復し、最大HPでcap
- MPは各戦闘開始時にcurrent canonical maximumへreset
- defeat / withdrawalでrun終了
- Trial 1 clear前はAwakeningを使用できない
- 初回clear時にSP +40
- 初回clear story / unlockはexactly once
- repeat clear可能
- Trial 1 clear報酬EXPは800
- GはTrial全体で浅層10戦より高い報酬となるようWyvernへ集約
- Trial 1 clearで地底layer 1 / 4 slotsを解禁

現在の実装には3.1.1で修正するplayer-flow問題があります。詳細は第5章を参照してください。

## 2.2 Awakening

Trial 1初回clear後に解禁されます。

主な現行contract:

- persistent Awakening gauge
- gauge maximum: 1000
- 一戦につき最大1回
- default AI activation: gauge fullかつHP 20%以下、player action前、action非消費
- 発動後は戦闘終了まで5主能力値+30%
- 発動時HP / MP全回復
- status、debuff、cooldown等はcleanseしない
- 戦闘終了後は通常能力へ戻し、persist HPは通常max HPへclamp
- growth pathごとの固定Awakening techniqueあり

Awakening gaugeの数値`current / max`表示は3.1.1で廃止し、progress barだけを表示します。内部maximum 1000やgain formulaは変更しません。

## 2.3 地底map / facility

- Trial 1初回clear後、1 layer = 4 slotsを表示
- slot persistenceは固定4要素のlayer構造
- Trial progression / layer unlockはSecretary-owned
- facilityはNation-owned
- Surface MapCellや3D coordinate persistenceは使用しない
- Underground slotではUnderground commandだけを表示
- Surface cellではUnderground commandを表示しない

facility:

```text
地底都市: 首都effective population maximum +10,000
地底農場: aggregate farm workforce capacity +10,000
地底工場: aggregate factory workforce capacity +30,000
地底ミサイル基地: missile capacity +1
```

- build cost: 1000億
- removal cost: 50億
- build / removalはofficial Turnを1消費
- 地底ミサイル基地はown Nation capital cellのSurface scan時を処理anchorとする
- 地底ミサイルの怪獣討伐EXPはSecretaryのsurface側monster experienceへ入る

## 2.4 Surface追加

- 領土破棄
- 自国領の海、浅瀬、荒地、人口・施設なし平地だけを対象
- Turn消費なし
- terrainは変えずownershipだけ解除

---

# 3. Ruleset / authorityの現行境界

3.1.0のcurrent Rulesetはv19ひとつです。

```text
v19
├ Surface command definitions
├ Surface production definitions
└ underground_facility_development
```

- Surface command catalogとUnderground command catalogはtarget context上分離する
- version authorityは同じv19に属する
- Ruleset外に第二のdefinition authorityや第二のcatalog versioningを作らない
- productionで実際に使用されたv19 snapshotはimmutable
- 詳細は`product/docs/architecture/ruleset-authoring.md`を正本とする

今後のimplementation agentはAGENTS.mdの日本語版を読み、Ownerの制約を別systemへ迂回してはなりません。

---

# 4. 3.1.1 release方針

## 4.1 releaseの目的

3.1.1は、3.1.0で見つかったUnderground player-flowと表示の修正releaseです。

新しいgameplay system、balance拡張、Ruleset変更、facility追加は行いません。

## 4.2 封印の地の連戦flow修正

**Owner決定**

現在は各戦闘結果から`地下メインへ戻る`ことができ、10連戦中に地下メインへ戻って宿へ泊まり、再び途中戦から続行できる状態です。これは10連戦contractに反します。

3.1.1では次のflowへ変更します。

```text
戦闘1勝利
↓
戦闘結果の最下部に「次の階層へ」
↓
戦闘2
↓
...
↓
戦闘10 / clear result
```

要件:

- battle 1〜9の勝利結果に`次の階層へ`を表示
- `次の階層へ`から直接次battleへ進む
- battle間に地下メイン、宿、銀行、通常探索等を挟まない
- client側の表示非表示だけに依存せず、server-authoritativeなrun progressionを維持する
- defeat / withdrawalは従来どおりrun終了
- battle 10 clearでは`次の階層へ`を表示しない

## 4.3 地下メインへ戻った場合のrun reset

**Owner決定**

進行中のTrialから地下メインへ戻った場合、何戦目まで進んでいてもそのrunを途中保存しません。

```text
地下メインへ戻る
↓
進行中Trialを終了 / reset
↓
次回Trial開始はbattle 1
```

- battle indexをresumeしない
- 宿を使って途中戦から再開できない
- resetがduplicate requestやrefreshで不整合を起こさないようcurrent idempotency contractへ統合する
- clear済みhistory、first-clear entitlement、rewardは巻き戻さない

## 4.4 20% interbattle healの確認と表示

**Owner決定**

現行contractの20%回復がruntimeで実際に適用されているかを確認します。

- 対象: 次battleがある勝利時
- 回復量: current canonical max HPの20%
- max HPでcap
- heal後HPを次battleへcarry
- MP reset contractは変更しない

勝利結果または勝利ログへ、実際に回復が発生したことをplayerへ示す文言を追加します。

```text
体力が少し回復した
```

表示だけを追加して実際のhealが欠けたままにしないこと。逆にhealが発生しない場面で文言だけを表示しないこと。

## 4.5 Awakening gauge表示

**Owner決定**

Awakening gaugeは数値をplayerへ表示しません。

現状:

```text
0 / 1000
```

3.1.1:

```text
覚醒ゲージ
[ progress barのみ ]
```

- `current / max`数値を削除
- barの進捗だけで蓄積状況を示す
- gauge未蓄積時は青、満タン時は金色という既存表示contractを維持
- accessibility上必要なprogress semanticsは維持してよい
- gauge maximum、gain formula、persistence、AI発動条件は変更しない

---

# 5. 3.1.1 non-goals

3.1.1へ混ぜないもの:

- Trial 2以降
- 第二狩場
- enchant / random drop / Item Lv progression
- Awakening gauge balance変更
- Awakening technique balance変更
- Underground facility追加・数値変更
- Surface Ruleset semantic change
- v20追加
- party / team battle
- production deploy / OCI操作
- unrelated cleanup / broad refactor
- handoffのagent自主更新

必要な修正がこの境界を超える場合は、実装前にOwnerへ報告してください。

---

# 6. 3.1.1 verification重点

最低限確認するcontract:

1. Trial battle 1〜9勝利後に`次の階層へ`が表示される
2. `次の階層へ`で次のbattle indexへ進む
3. battle 10 clearでは次階層actionを表示しない
4. 連戦中に地下メイン / 宿を挟んで途中戦をresumeできない
5. 地下メインへ戻ると進行中runがresetされ、次回はbattle 1
6. first-clear reward / SP +40 / layer unlockをresetしない
7. 20% healがexact current combat integer policyで適用される
8. heal後HPが次battleへcarryされる
9. 実際にhealした勝利結果に`体力が少し回復した`が出る
10. defeat / withdrawalでは不正なheal表示を出さない
11. Awakening gaugeの`0 / 1000`等の数値がplayer-facing UIから消える
12. gauge bar、blue->gold、unlock visibility、accessibility semanticsは維持

変更はUnderground player-flow / presentationが中心です。既存の代表runtime / player access / frontend testへregressionを追加し、無関係なtest matrixを増やさないでください。

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
PR #111  Release 3.0.0
PR #112  Trial 1 balance simulation foundation
PR #113  Trial 1 player-facing release / first-clear progression
PR #114  Awakening core
PR #115  territory abandonment / Underground surface map
PR #116  Nation-owned Underground facilities
PR #117  Stabilize 3.1.0 release
PR #118  Release 3.1.0
```

重要commit:

```text
3.0.0 main merge:
  a76f49013efdcea7a1b519873356fcd4386cbaaf

3.1.0 release stabilization merge into release/3.1.0:
  a0568a90674b3b455bd884628e7dc2d80229bdea

3.1.0 main merge:
  96b36706ebee211126ab24ca817ef3a5633e3613

AGENTS.md日本語整理:
  e5718fbb70201f523ec689a4e3c00e9d4ae16cea
```

---

# 8. 次作業開始時checklist

1. remote mainをfetchし、このhandoff更新後のexact HEADから3.1.1作業を開始
2. open PR / unresolved review threadを確認
3. application 3.1.0 / Ruleset v19 / checksumを確認
4. productionの実versionとmigration ledgerを別途確認し、GitHub stateから推測しない
5. 3.1.1 scopeを第4〜6章に固定
6. Trial flow、20% heal、Awakening gauge UI以外へscopeを広げない
7. handoffはread-onlyとして扱う
8. merge / production deploy / OCI操作はOwnerの明示許可なしに行わない

次の自然な開始点は、**3.1.1: 封印の地の連戦導線・途中run reset・20%回復表示・Awakening gauge数値非表示**です。
