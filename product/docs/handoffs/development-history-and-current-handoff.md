# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-08-30 JST  
> 対象リポジトリ: `Mamiki765/hakoniwa-world`  
> 配置先: `product/docs/handoffs/development-history-and-current-handoff.md`  
> 用途: 開発経緯、現在も有効な設計判断、廃止・変更された計画、現行作業の引継ぎ  
>
> この文書は、過去の引継ぎ資料、GitHub上のPR・commit・CI、review evidence、Ownerの明示決定を照合した統合handoffです。
> 会話の一字一句を復元するものではありません。仕様・実装・運用状態が食い違う場合は、最新のreview済みコード、
> immutable Ruleset、DB schema・forward migration、ADR／decision、運用文書、Ownerの最新明示決定を優先してください。

## Maintenance ownership

このhandoffは、OwnerとWeb版ChatGPTのdevelopment-advisor workflowが管理する開発状況の外部記憶です。

Codexおよびimplementation agentは、この文書をread-onlyの参考資料として読み、現在の方針・経緯の把握に利用して構いません。
ただし、Ownerがhandoff更新そのものを明示的に依頼しない限り、編集・再生成・format・SHA更新・commitを行ってはいけません。

implementation agentは、完了した作業をcode、test、PR本文、CI、review evidenceとして報告します。
handoffへの確定反映は、それらとGitHubの現物、Owner decisionを確認した後に別workflowで行います。

---

# 0. 情報の読み方

この文書では、情報の由来を次のように扱います。

- **GitHub確認**: PR、commit、branch、CIなど、2026-08-30時点のGitHubで再確認した事実
- **Owner決定**: Ownerが会話または実装指示で明示した現行方針
- **当時資料**: その時点の正式な引継ぎ資料に記録されていた事実
- **後続確認**: 後の引継ぎやGitHubから、過去の未確定事項が完了したと確認できるもの
- **将来候補**: Ownerメモ・設計案であり、現行仕様として自動的に採用してはいけないもの
- **未確認**: 残存資料とGitHubだけでは確定できないもの

## 0.1 再構成に使用した残存資料

1. `hakoniwa-world_ver-1.4.0_handover_2026-08-11.md`
2. `hakoniwa-world_ver-1.7.0_to_2.0.0_handover_2026-08-16.md`
3. `hakoniwa-world-current-handoff-after-ver-2.3.1.md`
4. `hakoniwa-world-ver-2.6.1-handoff.md`

これらの資料間の空白はGitHub上の主要release PRで補いました。

## 0.2 正本の優先順位

```text
最新のreview済みコード / immutable Ruleset / DB schema・forward migration
  > ADR・decision・運用文書
  > Ownerの最新明示決定
  > この統合引継ぎ
  > 各時点の古い引継ぎ
  > AIの記憶・推測
```

古い引継ぎに書かれた「次に行うこと」は、後のversionですでに完了・変更・撤回されている場合があります。
古いTODOを現在の未完了作業として復活させないでください。

---

# 1. 現在地

## 1.1 一行要約

```text
main / production:
  application 2.8.0
  immutable surface Ruleset hakoniwa-2s-plus-v18 / 18
  productionはOwner確認済み2.8.0 / v18

active development:
  branch release/3.0.0-alpha
  application 3.0.0-alpha.1
  PR #101〜#104 merge済み
  PR #104 merge baseline abe80fff60159c0ae0dfb8552c9c9ef4425a0c39
  surface Ruleset v18は変更なし
  production deploy未実施

first-player Underground:
  Secretary画面からダミー導入、ジャイアントラットTutorial、脱出、店員命名、
  optional scripted loss、shop説明、地下メインまで到達可能
  周囲を探索 / 試練 / 実shopは準備中でplayer操作不可

next design focus:
  Underground正式equipment、戦技・護身・奇跡の3 skill tree、status effect、AI条件、
  normal hunt / Trial contentとbalance

paused, not discarded:
  ver 2.9.0 Item拡張（Ownerの具体案待ち）
```

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

2.8.0 production deployとv18 migrationは完了済みとして扱います。
このhandoff更新ではproductionへSSH接続せず、production DB、TurnRun、web health、official Turn、asset実体を独立再確認していません。
productionの現況が必要な作業ではrepository stateから推測せず、Ownerの明示確認または許可されたread-only確認を行ってください。

## 1.3 active development / 3.0.0-alpha.1

**GitHub確認**

```text
active branch: release/3.0.0-alpha
application on branch: 3.0.0-alpha.1
PR #104 merge baseline: abe80fff60159c0ae0dfb8552c9c9ef4425a0c39
surface Ruleset: hakoniwa-2s-plus-v18 / 18（payload/checksum不変）
production deploy: 未実施
```

このhandoff更新はPR #104 merge後のdocs-only commitです。exact branch HEADはこのcommitで進むため、作業開始時にGitHubで再確認してください。

## 1.4 PR #101〜#104 integration evidence

```text
PR #101  feat: add deterministic Underground combat laboratory
         final head: 98152548cc83d3b668350058af377cb54fb4288a
         merge commit: 5e295b591aa81c705ec4b622638cb9a1882cbb6d

PR #102  feat: add Underground persistence foundation
         final head: cd0f1454a63489849bef2edcac1eeff07eb0eb9f
         merge commit: 7183cfd0a997972630fdad0c811042af2a844bfa
         exact-head Quality: 33216093313 success

PR #103  feat: add Underground expedition runtime foundation
         final head: 4f193dc9d15c3c512185d195989eac2ef3b4d3a5
         merge commit: 358bcda9f4eab339959f9c1ff3924c9108878277
         exact-head Quality: 33233088059 success

PR #104  feat: add first-player Underground tutorial flow
         final head: f9fe96452a50c3c056d64d6b3eafc748d8804d75
         merge commit: abe80fff60159c0ae0dfb8552c9c9ef4425a0c39
         exact-head Quality: 33252076672 success
         final-head review: findingなし
         unresolved review threads: 0
```

## 1.5 現在playerが到達できるもの

```text
Secretaryメイン
↓ 地下へ
ダミーstory（OKで進行）
↓
ジャイアントラットTutorial auto battle
↓ XP +5 / 欠片 +0 / Lv1維持
ダミー脱出
↓
Secretaryメインへ帰還、戦闘Lv 1を表示
↓ もう一度地下へ
ショップ店員とのダミー会話
↓
店員を一度だけ命名
├ 通常名 → shop説明
└ exact temporary trigger「ダミー」
   → deterministic scripted loss
   → penaltyなし
   → aftermath / shop説明
↓
地下メイン
```

地下メインで表示できるもの:

- Secretary名
- 戦闘Lv / XP
- 輝石の欠片
- 命名した店員名
- Tutorial / story battle log

準備中で操作不可:

- 周囲を探索
- 試練
- 実shop

## 1.6 直近の作業方向

PR #104でUG-03のfirst-player Tutorial導線まで通過しました。
次は、PR103の仮runtimeをそのままplayerへ公開するのではなく、RPG本体のbattle/build specificationをOwnerと設計してから進めます。

優先候補:

1. 正式なUnderground装備・武器種・loadout
2. `戦技 / 護身 / 奇跡`の3 skill tree
3. status effect / buff / debuffの最小mechanic
4. 最大5 skill＋通常攻撃＋防御＋将来の覚醒枠
5. 条件を上から評価するAI設定とbuilt-in fallback
6. normal hunt / treasure / Trialのcontentとbalance
7. 案内人shopの実機能

Ownerの現在の設計方向は後述します。まだ実装contractではない数値・案をCodexが勝手に確定してはいけません。

ver 2.9.0のSecretary Item拡張は破棄ではありません。Ownerの具体的なItem案を待つ間の一時保留です。
現行Itemの索引`product/docs/items/current-item-catalog.md`は引き続き正本として使います。

---

# 2. プロジェクトの目的と設計思想

## 2.1 目指すもの

目標は「箱庭諸島2＋の完全コピー」ではありません。

```text
世界がつながった箱庭諸島2
```

を作ることです。

維持する中心構造:

- shared World
- 共通map
- Nation ownership
- 領土
- 同一map上の他Nationとの相互作用
- World expansion
- deterministic Turn processing

Undergroundはこのsurface gameを置き換えるものではなく、Secretary中心のTurn非依存side gameです。

## 2.2 legacy sourceの扱い

```text
raw source
  > current versioned Ruleset / runtime
  > review済みanalysis・ADR
  > AIの記憶
```

- 箱庭諸島2の基本感覚を重視
- 箱庭諸島2＋の共有World向け構造は必要に応じ採用
- 2＋差分を無条件に正解扱いしない
- 明らかなlegacy bugやcopy mistakeを「原作だから」で再現しない
- 2S＋独自仕様はOwner decisionとして分離する
- `_references/`は原則read-only

## 2.3 実装スタイル

優先:

- existing canonical pathの再利用
- 標準処理＋最小の明示差分
- 小さい境界
- runtime performanceの実測
- Ownerにも処理順が読めるtop-level構造
- invariant、transaction、lock、retry、idempotency、DB integrityの維持

避ける:

- 1仕様のための巨大generic framework
- 将来用途だけを理由にした未使用table/state/abstraction
- Resolver → Policy → Strategy → Adapterの無目的な多層化
- review修正へ無関係な意味変更を混ぜる
- 到達不能な極端値のためにhot pathへ過剰防御を重ねる
- performance改善でRNG、phase順、sequential causalityを変える

---

# 3. 開発経緯

# 3.1 ver 1.4.0 — 「他の島が存在する共有箱庭」へ

主要機能はPR #33～#36で構成されました。

- 手動領土拡張とactive Nation間の自動領地感化
- Capital distance 2保護
- 伝言板・秘密通信
- TOP重大ニュース / 公開島ログ / owner-only logの3層化
- public-safe DTOとevent-time snapshot
- 援助、怪獣、award、首都変更、command、missileのwriter補完
- immutable Ruleset v3

当時の「次のTODO」には後続versionで完了したものが含まれます。現在のTODOとして復活させないでください。

# 3.2 ver 1.4.1 — ログとUIの安定化

- TOP public command log補完
- owner timeline整理
- routine internal logをplayer-facing表示から除外
- `build_decoy`表示改善
- message board位置調整
- H2 / H2＋ / 2S＋仕様由来監査

# 3.3 ver 1.5.0 — World拡張とH2 realignment

- signed/current MapSpace bounds
- `WorldMutationLock`
- safe World expansion
- Nation登録候補枯渇時の自動chunk拡張
- 16cell chunk rotation
- World全体災害の面積scale
- 海底基地経験値 / Lv / 発射数 / owner表示 / missile resistance
- 海際人口依存除去
- H2寄りsettlement growth
- Ruleset v4 → v5

# 3.4 ver 1.5.1 / PR #50 / PR #53 — 性能とCI

- PHPUnit memory 512M標準化
- fixture軽量化
- 実測bottleneckのN+1 / hydrate削減
- gameplay / RNG / retry不変
- PHPUnit 16 shards、backend-static、frontend、documentation
- local focused / CI full

# 3.5 ver 1.6.0 / 1.6.1 — Nation lifecycle

- Nation abandonmentをphysical deleteではなく状態遷移で実装
- surface cleanup、membership / Capital解除、同Account再登録
- public major news
- 伐採結果`forest → plain`修正
- published Ruleset payloadは後続v6まで不変

# 3.6 ver 1.7.0 — 1.xの完成点

- application 1.7.0 / immutable Ruleset v6
- bulk queue、queue上限30、ここから下を削除
- 防衛施設再建の隠し自爆
- 記念碑再建の隠し飛翔
- 「何かとてつもないもの」
- 防衛施設SPP耐性
- 16×16 chunk由来の海域名、ペリドット海域
- production config cache
- hidden featureの発動条件はmanualへ攻略情報として公開しない

# 3.7 ver 2.0.0 — User-persistent Secretary

- Userに永続するSecretary
- 一度きりの命名UI
- deterministic Hakoniwa calendar
- passive skill / development XP / defense interception
- immutable Ruleset v7
- Nationを越えてSecretaryが残るgeneration boundary
- Turnへの影響はattempt-start snapshot

# 3.8 ver 2.1.0 — 防衛迎撃とSecretaryプロフィール編集

- immutable Ruleset v8
- 防衛施設の周辺迎撃 radius 1～2
- decoy除外、target自身が防衛施設なら周辺迎撃skip
- self / enemy missile共通
- Secretary迎撃との優先順
- Secretary renameとhistorical name snapshot

# 3.9 ver 2.2.0 — 倉庫・装備基盤と問い合わせ

- Secretary熟練度 / 装備 / 倉庫
- User-owned inventory capacity 50
- 5-slot equipment foundation
- `old_bow` Lv1初期grant
- ゲーム内問い合わせ、plain text、optional image、admin API
- current Ruleset v9

# 3.10 ver 2.2.1 — correctness / idempotency / privacy / CI

- immutable Ruleset v10
- request fingerprint / duplicate retry conflict-safe
- OAuth identity serialize
- inquiry security
- public災害messageからprivate情報を除外
- application version single source of truth
- CI artifact・shard coverage fail-closed

# 3.11 ver 2.3.0 — 装備効果と怪獣拡張

- formal Ruleset v11
- atomic equipment mutation / optimistic concurrency
- 古びた弓のcanonical monster damage
- 指輪の資金繰りbonus
- `monster_dispatch`、メカいのら / 零式
- 《海獣》あおいのら
- メカいのら零式のHP1核爆発
- exact v10→v11 migration、live referencesだけrebind、terminal history保持

# 3.12 ver 2.3.1 — runtime解決とtest責務整理

- Ruleset history archive
- Core / Balance / Flavorの概念分類
- Secretary Item effect catalogとmonster behaviorのresolve削減
- test責務整理
- gameplay / checksum / schema / RNG / phase順不変

current-only runtimeと物理分割は当時の必須未完ではなく、後の2.4.0 / 2.6.1へ発展しました。

# 3.13 ver 2.4.0 — compatibility cutoff、dormancy、KARMA

- current v11 standalone baseline
- canonical schema dumpとfresh install rebaseline
- unsupported historical direct upgrade終了
- historical persisted records保持
- manual / automatic dormancy、resumption、abandonment
- immutable Ruleset v12
- KARMA / sanctions / recovery、immutable Ruleset v13
- migration lock、exact source identity、unresolved TurnRun fail-closed

# 3.14 ver 2.5.0開発線 — Secretary公開プロフィールと成長

- public Secretary Main tab、image / biography / equipment presentation
- image provenance、AI image viewer preference
- capacity multiplier
- immutable Ruleset v14
- monster damage experience、forest management
- immutable Ruleset v15

古い「2.5.0は船system」はplaceholderであり、船は現在も将来候補です。

# 3.15 ver 2.6.0 — 石油、交易場、Novice装備

- oil、単位万バレル、capacity 5000、2億円 / unit
- 海底油田500 units / Turn
- immutable Ruleset v16
- Trading Post resource / Item listing、escrow、bid、10% fee
- Novice bow / clothing / accessory
- same Item原則1、accessory高上限
- generic marketplace / modifier frameworkは作らない

# 3.16 ver 2.6.1 — current authoring / runtime rebaseline

- current v16を10 gameplay domainへ分割
- Behavior / Data / Flavorをmachine-inspected分類
- current-only executable Ruleset runtime
- formal v1～v15 PHPとhistorical upgrade chainをcurrent treeから退役
- fresh install final-v16 direct baseline
- already-current v16 business-data invariance
- Trading Post bidder/status/effect tooltip改善
- handoff ownershipをAGENTSへ記録
- PR #93でmainへmerge、Owner確認でrelease済み

# 3.17 ver 2.7.0 — theme、Item拡張、怪獣drop、人口skill

- system / light / dark theme
- Regular / Cursed rarity
- エルフの弓 / 遠当ての弓 / 機械弓 / 首輪
- monster Item drop、foreign host reward 75% / 25%
- Trading Post winner / seller event
- 少子化対策 / 不屈 / population_high_water
- owner map queue tooltip
- inventory 50はsoft-cap
- Bow executionを`SecretaryBowAttackService`へcanonical化
- immutable Ruleset v17

# 3.18 ver 2.8.0 — 資源推計、海底都市、Ruleset v18

- owner resource forecast、失業率 / 労働力飽和
- Nation event window 12 Turn
- 海底都市、首都から3000人移住、人口3000開始
- 工業品1000 + 鉱物1000 / Turn維持費、2:1代替
- 不足時の都市単位famine、通常飢餓との二重適用なし
- fire即消滅、disguised neutral sea presentation
- command previewもviewer-safe、raw executionは別
- foreign undersea city破壊KARMA +3、foreign wasteland取得+1
- exact v17→v18 forward migration
- PR #99でmainへmerge
- Owner確認でproduction 2.8.0 / Ruleset v18

# 3.19 3.0.0-alpha.1 — Underground foundationからfirst-player導線へ

## PR #101: deterministic Underground Combat Laboratory

- playerから到達不能
- DB-free pure PHP combat domain
- World / Nation / MapCell / Turn / surface Ruleset非依存
- identity `secretary-underground-alpha-v0`
- round制、原則1 actor / 1 round / 1 action
- 通常攻撃 / 防御常設
- built-in AI
- private deterministic RNG
- same input + same seed完全再現
- max-round stalemate明示
- manifest-driven balance simulator
- heavy simulationは通常CI外
- Underground専用test suite

10,000-seedの初期観測値はlaboratoryの観測であり、player-facing balance targetではありません。

## PR #102: Secretary-owned persistence foundation

- `underground_profiles`をSecretaryと1:1でlazy create
- `unlocked_area_layers >= 0`、default 0
- Nation / World / MapCell / current Turn identityを持たない
- Secretary正式削除時のみcascade
- `1 layer = 4 facility slots`を保存せず派生
- 梯子はslotではない
- 空cell / slotを事前生成しない
- combat level / XP / checkpoint / layerは独立state
- 将来の実配置地下facilityはNation-owned
- Nation破棄時facilityは消えるがSecretary-owned entitlementは残る

## PR #103: expedition runtime foundation

- PR101 canonical coreをatomic adapterから再利用
- Secretary-owned combat level / XP、輝石の欠片
- 通常探索・sequential Trial runtime
- battle後10秒のserver-authoritative cooldown
- persistent Trial battle間progress
- defeat時は欠片残高を`floor(balance / 2)`へ減らす
- Trial defeat / 明示帰還はbattle 1へreset
- browser close / logoutではprogress保持
- 100-round stalemate:
  - 通常探索は安全撤退
  - Trialはrun失敗、battle 1へreset
  - 欠片loss / rewardなし
  - base victory XPの`floor(1 / 4)`を獲得
- Trial first clearだけ`unlocked_area_layers +1`
- 1 Trial = 1 layer = 4 slots
- next Trial sequential unlock
- row lock、UUID request identity、fingerprint、transaction retry
- battle summary / action log

## PR #104: first-player Underground tutorial flow

- application version `3.0.0-alpha.1`
- current authenticated User自身のSecretaryからのみentry
- server-authoritative one-way finite-state intro
- story本文は`（ダミー）`
- ジャイアントラットTutorial
- starter knifeは正式Itemではなく固定combat projection
- Tutorial reward XP +5 / 欠片0 / Lv1維持
- Tutorial後に一度Secretaryメインへ戻り戦闘Lv表示
- 2回目entryで店員命名
- temporary exact trigger `ダミー`
- trigger時はdeterministic scripted loss
- scripted lossはXP / 欠片 / cooldown / Trialへ影響しない
- shop説明後に地下メイン解禁
- normal hunt / Trial / 実shopは準備中
- canonical battle historyを再利用

### PR104 review follow-up

- active Trial runへTrial固有`trial_content_identity`を保存
- application versionや他Trialの変更ではresetしない
- 当該Trial自身のgameplay content identity mismatch時だけbattle 1へ安全reset
- 欠片 / XP / unlock / first-clear / layerは保持
- battle detail retentionを1000時間から100時間へ変更
- existing expiryもforward migrationで`finished_at + 100 hours`へ短縮
- `underground_battles` compact record / aggregate / idempotency identityは保持
- 古い戦闘のplayer-facing詳細・damage summaryを永久表示することは要件ではない
- final-head Quality success、review findingなし、unresolved thread 0

---

# 4. 設計方針がどう変化したか

## 4.1 Ruleset

- 初期～1.x: gameplay変更ごとにimmutable Rulesetを積み重ね、historical snapshotを保持
- 2.4.0: current standalone baseline、unsupported historical direct upgrade終了
- 2.6.1: current authoring domain split、old executable runtime退役
- 2.8.0: current surface Ruleset v18、v17を直前supported sourceとして保持
- 3.0.0-alpha.1: application versionは進むがsurface Ruleset v18は不変。Underground identityはsurface Rulesetではない

surface gameplayを変更する場合はimmutable v18を書き換えずv19以降へ追加します。
Underground gameplayは専用versioned content / runtime identityで扱い、surface Turn snapshotへ混ぜません。

## 4.2 Migration

一貫して残すもの:

- forward-only
- exact source identity
- transaction / lock
- fingerprint / provenance
- production dataをrepository stateから推測しない
- backup restoreをrecovery boundaryにする

Undergroundでもmerged migrationを編集せず、新しいschema / data変更はforward migrationを追加します。

## 4.3 Test

- local focused / CI full
- 16-shard CI
- representative contract owner
- same invariantの重複matrixを避ける
- pure Underground combatはDBなし
- Underground FeatureはUser / Secretary / Underground tablesを基本とし、World / Nation / Turn fixtureを持ち込まない
- 10,000〜1,000,000戦はmanual simulator
- usual CIはsmall deterministic smoke

## 4.4 User / Secretary / Nation

- surface Nation lifecycleとUser-persistent Secretaryを分離
- Secretary profile / skill / inventory / Underground progressionはNationを越えて残る
- Underground layer entitlementはSecretary-owned
- 将来の実配置地下facilityはNation-owned
- active NationなしでもUnderground progression自体は保持される

## 4.5 Underground modular boundary

```text
pure combat core:
  DB / Laravel / World / Nation / MapCell / Turnなし

Underground application / persistence:
  User / Secretary / Underground専用table

bridge:
  party borrowing / market / Nation-owned facility / surface benefitのみ
```

通常auto battleは一戦をatomicに解決します。将来のmanual round combatは特殊area用の別runtime候補であり、通常combatをpersistent-round化しません。

---

# 5. 現在も有効な重要契約

## 5.1 current identity

```text
main / production:
  application 2.8.0
  surface Ruleset hakoniwa-2s-plus-v18 / 18
  checksum 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b
  PR #99 merge c91e66d330cd4f8ae5f5297a794fd27582e4ddcf

supported surface migration source:
  hakoniwa-2s-plus-v17 / 17
  checksum 8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3

active development:
  release/3.0.0-alpha
  application 3.0.0-alpha.1
  PR #101〜#104 merged
  PR #104 merge baseline abe80fff60159c0ae0dfb8552c9c9ef4425a0c39
  surface Ruleset v18 unchanged
  production deploy not performed
```

## 5.2 deterministic Turn

surface Turn retryは:

- same World
- same target turn
- same Ruleset
- same seed
- same command intent

で再現します。UndergroundはTurn RNG / World seedへ接続しません。

## 5.3 historical data

退役するのはcurrent treeのold executable codeであり、production historyではありません。
RulesetVersion、definitions、commands、fingerprint、provenance、TurnRun、seed、events、historical World、Nation / map / resource / Secretary / Item historyを保持します。

Underground battle compact recordもdetail expiry後に保持し、idempotency / audit / 将来統計へ利用可能です。

## 5.4 public / private information

publicへ漏らさない:

- secret facility identity / coordinate
- missile owner-only detail
- raw metadata
- OAuth provider identifiers
- inquiry private content
- private deterministic seed
- internal Underground snapshot / DB identity

Underground APIはcurrent User自身のSecretaryをserver側で解決し、client supplied Secretary IDをownership根拠にしません。

## 5.5 production safety

- Owner明示指示なしにproductionへ接続・変更しない
- destructive DB commandsをproductionで使わない
- unresolved failed/blocked TurnRunを跨いでsurface Ruleset変更しない
- automatic Turn retryしない
- migration失敗後もwebを復帰させるdeploy手順を使う
- `_references/`を変更しない
- asset binaryをGitへ追加しない
- 3.0.0-alpha.1をproductionへdeployしない

## 5.6 Underground runtime contract

- stamina / daily / weekly obligationなし
- Turn同期なし
- normal battle後10秒cooldown
- one battleはauto AIでatomic resolve
- max 100 rounds
- victory / defeat / withdrawalを一度だけsettle
- combat level / XP / Trial progress / area layerは独立
- defeatは欠片半減
- Trial first clearだけ1 layer解禁
- Trial runは自身のcontent identityへpin
- detail action log retention 100時間
- compact battle recordは保持
- actual OCI cron registrationはdeploy時operator作業

---

# 6. 古い計画の扱い

## 6.1 2.3.1でDeferredだったcurrent-only runtime

2.3.1時点では必須未完ではありませんでした。その後2.4.0でcurrent baseline、2.6.1でcurrent authoring分割とcurrent-only runtimeへ発展しました。

## 6.2 2.4.0の初期Ownerメモ

Item / auction / shop中心の当時案と異なり、実際の2.4.0はcompatibility cutoff、rebaseline、dormancy、KARMA / recoveryでした。
auctionは2.6.0のTrading Postとして実装され、遺物shopは現行仕様ではありません。

## 6.3 2.5.0の船placeholder

実際の2.5.0開発線はSecretary公開プロフィール、capacity modifier、monster damage experience、forest managementです。船は未実装の将来候補です。

## 6.4 1.7.0時点の2.x候補

実現済み:

- Secretary / Account persistence
- KARMA
- Item / warehouse / equipment
- Trading Post
- proficiency
- oil
- Underground pure combat / persistence / runtime / first-player Tutorial shell

未実装または未確定:

- 船 / port / fuel / 本格海上actor
- 複数MapSpaceの本格利用
- Underground正式equipment / skill tree / status / normal content
- generic map Item
- generic modifier framework
- 防壁都市
- dormant territory occupation

候補を現行仕様として扱わないでください。

---

# 7. 現在の残件

## 7.1 次のUnderground設計slice

PR #104 merge後、first-player導線は完成しました。次は実装へ直行せず、RPG本体の仕様をOwnerと固めます。

### 確定済みの方向

- 固定classは作らない
- skill tree候補は`戦技 / 護身 / 奇跡`
- balanced専用treeは作らず、3方向を混ぜた結果として成立
- 最大5 skill装備
- 通常攻撃 / 防御はslot外
- 覚醒 / 真の姿は別枠の将来Limit候補
- 基本は秘書AIによるauto battle
- 条件未設定 / 全条件不成立はbuilt-in AI fallback
- 将来、1roundごとにplayerが選ぶ特殊戦闘areaを候補とする

### Ownerの現在のbalance方向（未実装）

- 序盤HPはおおむね500前後の感覚
- 終盤HPは数万〜数十万へインフレし得る
- MPはLvで増やさず大きな固定値にする方向
- 最大MP `10,000`が有力候補
- exact HP formula、MP recovery、skill costは未決定
- attackerは周回速度と上限火力で明確に優位
- tank / healerもsolo可能
- tankは防御等を攻撃価値へ還元、healerは実効回復等を攻撃価値へ還元する方向

これらを現行code contractとして扱わないでください。PR105等へ実装する前に数値・mechanic・acceptanceを明示決定します。

### 次に決める必要があるもの

- weapon categoriesとstarter equipment
- skill treeの取得方式 / reset / cost
- status effectの最小mechanic
- MP 10,000固定を正式決定するか
- MP自然回復 / action回復 / skill cost帯
- base statsとlevel scaling
- AI条件数・condition vocabulary・priority editor
- equipment rarity / Item Power / affix / unique effect
- normal huntの狩場・enemy・drop
- treasure vault
- Trial 1のbattle列・boss・適正round
- shopの購入 / 売却 / 預かり

## 7.2 normal hunt / Trial / shop

backend runtimeは存在しますが、player UIでは準備中です。

Normal hunt構想:

- level帯ごとの狩場を選択
- leveling / equipment farm
- rare treasure encounter
- battle後10秒cooldown

Trial構想:

- 10〜20連戦程度＋area boss
- battle間progress保持
- 明示帰還 / defeat / Trial stalemateでbattle 1へreset
- first clearで4マス＝1 layer解禁
- Trial固有content identity変更時だけactive runを安全reset

Shop:

- 店員命名・説明stateのみ実装
- actual inventory / price / buy / sell / storageは未実装

## 7.3 battle log / operations

Current contract:

- action detail retention 100時間
- daily prune command: `underground:prune-battle-logs`
- wrapper: `product/docker/cron/prune-underground-battle-logs.sh`
- intended schedule: daily 03:15 JST
- `underground_battles` compact record / aggregate / idempotencyは保持

重要:

repositoryへwrapperと運用手順を追加しただけで、OCI hostのcrontab自体は変更していません。
3.0.0-alpha.1を将来deployする場合、実際のrepository配置pathを確認してhost cronへ登録してください。
Turn cronとは独立し、automatic retry loopを作りません。

将来候補:

- 通常detailは24〜100時間の短期保持
- playerが明示公開 / 保存したbattleだけ長期保存
- 長期保存はruntime replayではなくimmutable presentation / HTML snapshot等で固定

これは未実装案です。現在の要件として先回りしないでください。

## 7.4 ver 2.9.0 Item拡張の一時保留

破棄していません。再開時は`product/docs/items/current-item-catalog.md`を確認し、新Item / rarity / acquisition / effectのOwner決定を既存実装と混同しないでください。
High Quality / Artifactは未実装です。

## 7.5 UG-04

次を実装する前に`docs/open-questions.md`のUG-04で停止します。

- borrowed Secretary / 最大4人party
- party snapshot / 同時利用 / reward配分
- Underground market
- Nation-owned facility placement / lifecycle
- surface benefit / published Rulesetとの関係

## 7.6 handoff maintenance

この文書はOwner / Web版ChatGPT development-advisor workflowが節目で更新します。
Codex / implementation agentは通常のfeature実装やreview対応では変更しません。

次回更新候補:

- battle / equipment / skill specificationのOwner決定
- 次PR merge
- alpha.2 milestone
- production deploy方針確定
- ver 2.9.0再開

---

# 8. 次の担当者の開始手順

1. `AGENTS.md`
2. `product/docs/handoffs/development-history-and-current-handoff.md`
3. `docs/README.md`
4. `docs/open-questions.md`
5. Underground作業なら`docs/roadmap/3.0.0-alpha-underground.md`
6. `docs/architecture/underground-combat-laboratory.md`
7. PR #101〜#104のmerged code / migration / tests
8. task-specific current docsだけ追加
9. current branch HEAD、open PR、CI、review threadをGitHubで再確認
10. raw sourceが必要な仕様だけ`_references/`をread-only監査

Historical / Audit / Future / MIXED文書を無差別に読まないでください。

現在の基準点:

```text
main / production:
  application 2.8.0
  Ruleset hakoniwa-2s-plus-v18 / 18
  checksum 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b
  production Owner確認済み

active development:
  release/3.0.0-alpha
  application 3.0.0-alpha.1
  PR #101〜#104 merged
  PR #104 merge baseline abe80fff60159c0ae0dfb8552c9c9ef4425a0c39
  surface Ruleset v18 unchanged
  production deploy not performed

next:
  equipment / 3 skill trees / status / AI / balance specification
  normal hunt / Trial / actual shop remain disabled
```

---

# 9. 次のAI / Codexへ渡す開始プロンプト

```text
Mamiki765/hakoniwa-worldの開発相談を引き継いでください。

最初にAGENTS.md、product/docs/handoffs/development-history-and-current-handoff.md、
docs/README.md、docs/open-questions.mdを読み、GitHubの現物と照合してください。
Underground作業ではdocs/roadmap/3.0.0-alpha-underground.mdと
current Underground architectureを追加で読んでください。

main / productionはapplication 2.8.0、immutable surface Ruleset
hakoniwa-2s-plus-v18 / version 18です。
active branchはrelease/3.0.0-alpha、applicationは3.0.0-alpha.1です。
PR #101〜#104はmerge済みで、PR #104 merge baselineは
abe80fff60159c0ae0dfb8552c9c9ef4425a0c39です。
exact branch HEADはdocs-only commit等で進むため開始時に再確認してください。
3.0.0-alpha.1はproductionへdeployしていません。

PR101はDB-free pure combat、PR102はSecretary-owned profile/layer entitlement、
PR103はXP・欠片・normal hunt/Trial runtime・10秒cooldown・battle history、
PR104はdummy Tutorial/story、店員命名、地下メインを実装しました。
Normal hunt、Trial、実shopはUI上準備中です。

UndergroundはTurn、World、Nation、MapCell、surface Rulesetと分離します。
pure combat testへDBやsurface fixtureを持ち込まず、Feature testもUser / Secretary /
Underground専用tableを基本にしてください。

次の本命は正式equipment、戦技・護身・奇跡の3 skill tree、status effect、AI条件、
normal hunt / Trial balanceです。固定classは作らず、最大5 skill＋通常攻撃＋防御、
built-in AI fallbackを維持する方向です。
序盤HP約500、終盤は数万〜数十万、MPは10,000固定候補ですが、これはまだ未実装のOwner方向です。
数値・mechanicを勝手に確定せず、次sliceに必要なdecisionだけOwnerへ提案してください。

Trial active runはTrial固有content identityへpinし、自身のidentity mismatch時だけbattle 1へ安全resetします。
詳細battle logは100時間でpruneし、compact battle recordは保持します。
OCI host cron自体は未登録なので、deploy時にoperator作業が必要です。

UG-04のparty、market、Nation-owned underground facility、surface benefitへはOwner決定前に入らないでください。
product/docs/handoffs/development-history-and-current-handoff.mdはCodex / implementation agentにはread-onlyです。
Owner明示指示なしにmain merge、production接続、migration、deploy、backup、cron操作を行わないでください。
```

---

# 10. Gitへ格納するときの注意

この文書へ次を含めないでください。

- token / API key / password
- SSH秘密鍵
- DB接続文字列
- production IP
- private email
- OAuth secret
- backup passphrase
- private inquiry content
- local absolute file path

Git履歴へ入れた秘密情報はfile削除後も残ります。

推奨:

- 統合版はcurrent sectionを節目で更新
- 当時資料はarchiveとして保存
- 過去案は実現 / 撤回 / 未実装を明示
- exact SHAはGitHubで再確認

---

# 11. 最終要約

```text
1.4.0:
  領土、伝言板、秘密通信、public / owner log

1.5.x:
  World expansion、H2 realignment、performance、CI sharding

1.6.x:
  Nation abandonment lifecycle

1.7.0 / v6:
  bulk queue、hidden defense / monument behavior、sea regions

2.0.0 / v7:
  User-persistent Secretary

2.1.0 / v8:
  defense interception、Secretary rename

2.2.x / v9-v10:
  warehouse、equipment foundation、inquiries、idempotency / privacy hardening

2.3.x / v11:
  equipment effects、Old Bow、Ring、Aoi、Zero、runtime/test cleanup

2.4.0 / v12-v13:
  compatibility cutoff、rebaseline、dormancy、KARMA / recovery

2.5.0 line / v14-v15:
  Secretary public profile、capacity、monster EXP、forest management

2.6.x / v16:
  oil、Trading Post、Novice equipment、current-only Ruleset runtime

2.7.0 / v17:
  theme、Regular / Cursed Item、monster drop、population skills、canonical Bow

2.8.0 / v18:
  resource forecast、undersea city、exact v17→v18 migration
  main / production 2.8.0 / Ruleset v18

3.0.0-alpha.1:
  PR101 pure combat laboratory
  PR102 Secretary-owned Underground profile / layer entitlement
  PR103 XP・欠片・normal hunt / Trial runtime・10秒cooldown・history
  PR104 dummy Tutorial、ジャイアントラット、帰還、店員命名、scripted loss、地下main
  Trial固有content identity pinning
  detail log 100時間、compact record保持
  application 3.0.0-alpha.1、surface Ruleset v18不変
  production deploy未実施

next:
  equipment、戦技 / 護身 / 奇跡、status、AI、balanceをOwnerと設計
  normal hunt / Trial / actual shopは準備中
  UG-04は未決定
  OCI battle-log prune cronはdeploy時にhost登録が必要
```

この文書の役割は、失われた会話を推測で埋めることではありません。

```text
何が確定したか
何が後に変更されたか
何が現在も候補にすぎないか
次にどこから再開すべきか
```

を一つの場所から追えるようにすることです。
