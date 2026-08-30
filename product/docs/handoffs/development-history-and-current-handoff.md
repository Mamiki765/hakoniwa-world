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
  application 3.0.0-alpha.2
  PR #101〜#106 merge済み
  PR #106 merge baseline 0abe33c7d1e8785468e2560ed9b7e1e7269f9e22
  surface Ruleset v18は変更なし
  production deploy未実施

first-player Underground:
  正式導入story、ジャイアントラットTutorial、脱出、案内人命名、optional hidden story battle、
  契約、4 growth path選択、地下メイン、報酬なし「力試し（α）」まで到達可能
  周囲を探索 / 試練 / 実shopは準備中でplayer操作不可

next implementation:
  PR107: 周囲を探索、実Secretaryのalpha-v1戦闘、共通starter knife、EXP/Lv自然成長、未使用STP foundation
  PR108予定: status画面、STP配分、SP、戦技・護身・奇跡のplayer skill tree

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

## 1.3 active development / 3.0.0-alpha.2

**GitHub確認**

```text
active branch: release/3.0.0-alpha
application on branch: 3.0.0-alpha.2
PR #106 final head: 6b5951da23357cec709ad7b4e15ce736c2df6f75
PR #106 merge baseline: 0abe33c7d1e8785468e2560ed9b7e1e7269f9e22
exact-head Quality: 33292819206 success
final-head review: major issueなし
unresolved review threads: 0
surface Ruleset: hakoniwa-2s-plus-v18 / 18（payload/checksum不変）
production deploy: 未実施
```

このhandoff更新はPR #106 merge後のdocs-only commitです。exact branch HEADはこのcommitで進むため、作業開始時にGitHubで再確認してください。

## 1.4 PR #101〜#106 integration evidence

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

PR #105  feat: add Underground combat build laboratory alpha-v1
         final head: 7a4d2fcb23b4ff8fe551caa48e216068eda5fa14
         merge commit: ecea67972b6439cf70886d6fc5fb935fda035875
         exact-head Quality: 33280898676 success
         final-head review: major issueなし
         unresolved review threads: 0

PR #106  feat: add Underground contract, crystal path, and alpha-v1 playtest
         final head: 6b5951da23357cec709ad7b4e15ce736c2df6f75
         merge commit: 0abe33c7d1e8785468e2560ed9b7e1e7269f9e22
         exact-head Quality: 33292819206 success
         final-head review: major issueなし
         unresolved review threads: 0
```

## 1.5 現在playerが到達できるもの

```text
Secretaryメイン
↓ 地下へ
Owner作成の正式な落下・状況整理story（OKで進行）
↓
ジャイアントラットTutorial auto battle
↓ XP +5 / 欠片 +0 / Lv1維持
正式な採取・脱出story
↓
Secretaryメインへ帰還、戦闘Lv 1を表示
↓ もう一度地下へ
銀髪の案内人との会話
↓
案内人を一度だけ命名
├ 通常名 → 案内人の役割説明
└ implementation-only hidden alias
   → deterministic alpha-v1 scripted loss
   → XP / 欠片 / cooldown / Trial penaltyなし
   → aftermath / 案内人の役割説明
↓
契約
↓
戦技（赤） / 護身（青） / 祝福（緑） / 自由（黒）からgrowth pathを一度だけ選択
↓
選択後story
↓
地下メイン
↓
報酬なし「力試し（α）」
```

地下メインで表示できるもの:

- Secretary名
- 戦闘Lv / XP
- 輝石の欠片。player-facing単位`G`はGoldではなくgram
- 命名した案内人名
- 選択したgrowth path
- 生命 / 武力 / 技巧 / 精神 / 敏捷
- derived HP、固定MP 10,000、自然MP回復300 / round
- Lv2以降の自然成長予定とSTP量
- Tutorial / story / playtest battle history
- PR105代表4 buildと3 opponentによる報酬なしplaytest

力試しのbattle UIは、戦闘開始から実際のevent順に縦へ読み、決着後に初めてresultを表示する構造です。
`末尾へ`で明示的に結果へ移動できます。通常ログへAI debug理由や職業別flavor textを混ぜません。

準備中で操作不可:

- 周囲を探索
- 試練
- 実shop transaction
- STP手動配分
- player skill tree / loadout / AI editor
- formal inventory / drop

## 1.6 直近の作業方向

PR #105でalpha-v1のbattle/build laboratory、PR #106で正式story・契約・growth path・player-facing playtestまで通過しました。
次はOwnerが確定したPR107を実装します。

### PR107確定scope

1. application `3.0.0-alpha.3`
2. 地下メインの`周囲を探索`を解禁し、浅層1狩場を実装
3. PR103 settlement / lock / idempotencyを再利用しつつ、alpha-v0固定actorを実Secretaryのalpha-v1 snapshotへ置換
4. PR105 laboratoryのlevel倍率をplayer成長へ流用せず、`Lv1 baseline + 自然成長 + 手動STP`で現在statsを構成
5. Lvアップ時にgrowth pathごとの自然成長と未使用STPをlevel delta分だけsettle
6. PR108用のSTP persistence foundation。PR107では配分UIなし
7. 全growth path共通の最低rank `護身用ナイフ`をstarter equipmentとして使用
8. 浅層enemy pool、10秒cooldown、最大100 round、victory / defeat / withdrawal settlement
9. EXP curveを旧FFAいく改と比較し、序盤のalpha curveを決定
10. detail log / bounded history / self-contained presentationのPR106 contractを維持

PR107の具体promptは別途Ownerからimplementation agentへ渡します。

### PR108予定scope

- 地下status画面
- STP手動配分
- SP persistence
- 戦技 / 護身 / 奇跡の3 skill tree
- node prerequisite / SP消費 / passive反映
- active skill最大5
- acquired skillをbuilt-in AIへ接続

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

FFAいく改はUndergroundの情報配置、battle logの読順、level / EXP設計思想を比較するreferenceです。
HTML / CSS / asset / text / game ruleをそのままcopyせず、current codeへ依存させません。

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

# 3.19 3.0.0-alpha.1〜alpha.2 — Underground foundationから正式intro / playtestへ

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
- story本文は当時`（ダミー）`
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
- この100時間contractはPR106 review follow-upで1時間へ短縮された

## PR #105: alpha-v1 combat / build laboratory

- player-inaccessibleな`secretary-underground-alpha-v1`
- 5能力値: 生命 / 武力 / 技巧 / 精神 / 敏捷
- Lv1標準HP 500、固定最大/開始MP 10,000、自然回復300 / round
- ratio-based defenseとtyped damage / heal / barrier / status
- 戦技 / 護身 / 奇跡の3つの100-point tree
- 120-point、active最大5の4代表build
- priority AI、条件不成立時fallback
- 闘志 / 恩寵、guard / parry / barrier / counter
- deterministic equipment、Item Levelとrarityの分離
- 4 weapon styles: dagger / rapier / shield / crystal staff
- alpha-v0とalpha-v1はcanonical one-action-per-actor envelopeを共有
- DB / player access / schema / surface Ruleset変更なし

最終10,000-seed観測:

```text
pressure ratio:
  attacker 100
  tank 82.660
  healer 80.062
  balanced 90.762

appropriate median rounds:
  16 / 17 / 19 / 19

all four builds:
  10,000 wins
  0 losses
  0 stalemates
  0 abnormal seeds
```

これらはcurrent alpha-v1 laboratoryの観測値です。将来のplayer contentに対する永久hard gateではありません。

## PR #106: formal intro / contract / growth path / alpha-v1 playtest

- application version `3.0.0-alpha.2`
- PR104のplaceholder storyをOwner作成の正式本文へ置換
- one-way finite-state introを契約・輝石選択・選択後storyまで拡張
- 案内人の一度だけの命名、safe plain-text、hidden branchはimplementation-only
- 既存PR104 profileは命名やlegacy lossを再実行せず正式shop説明へresume
- 契約timestamp、growth path key / identity / selected timestampをSecretary-owned profileへ保存
- growth pathは戦技 / 護身 / 祝福 / 自由
- 全pathのLv1能力は合計100、自由にもLv1手動配分特例なし
- MP 10,000固定、自然回復300をplayer-facing表示
- hidden story battleはalpha-v1 canonical pathを通るLv1254相当の圧倒的な護身型
- story battleはprogression / G / cooldown / Trial / normal defeatへ影響しない
- `力試し（α）`でPR105代表4 buildと3 opponentを報酬なしで比較可能
- battleはserverでatomic resolveし、UIは実際のevent順に縦へ表示
- result / rewardは決着後に表示し、明示的な`末尾へ`を提供
- AI debug reason、private seed、raw manifest、internal DB identityは非公開
- battle historyはbounded recent summary
- detail logは個別取得、1時間retention
- settlement時に自己完結したplayer-facing presentationを保存し、後日の表示でcurrent catalogを再解釈しない
- `underground_battles` compact summary / aggregate / idempotency identityは保持
- Surface / Underground local full suiteを分離し、Quality CIはrepository全体を維持
- normal exploration / Trial / actual Shop economy / STP allocation / skill acquisitionは未解禁

### PR106後のOwner decision

Lv1基準能力はPR105のcurrent代表build baselineへ追従して構いません。
将来STP / growth resetを行う場合、その時点の最新Lv1 baselineへ戻す方向です。
既に手動配分したSTPをbalance updateだけで勝手に再配分する意味ではありません。
このdecisionは、Lv1 statsをgrowth identityへ永久固定する案より新しいOwner決定です。

---

# 4. 設計方針がどう変化したか

## 4.1 Ruleset

- 初期～1.x: gameplay変更ごとにimmutable Rulesetを積み重ね、historical snapshotを保持
- 2.4.0: current standalone baseline、unsupported historical direct upgrade終了
- 2.6.1: current authoring domain split、old executable runtime退役
- 2.8.0: current surface Ruleset v18、v17を直前supported sourceとして保持
- 3.0.0-alpha.1〜alpha.2: application versionは進むがsurface Ruleset v18は不変。Underground identityはsurface Rulesetではない

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

3.0.0-alphaはproduction未投入です。alpha開発中はappend-only migrationを積みますが、正式3.0.0公開前に、
2.8.0 productionからfinal 3.0.0 schemaへ進むproduction-facing forward boundaryとfresh schema dumpをrebaselineする予定です。
100時間→1時間のようなalpha内部の中間変遷をproductionで無意味に順番実行させないことを目標とします。

## 4.3 Test

- representative contract owner
- same invariantの重複matrixを避ける
- pure Underground combatはDBなし
- Underground FeatureはUser / Secretary / Underground tablesを基本とし、World / Nation / Turn fixtureを持ち込まない
- 10,000〜1,000,000戦はmanual simulator
- usual CIはsmall deterministic smoke

Current local routing:

```text
Surface full:      composer test:surface
Underground full:  composer test:underground
Repository-wide:   composer test:all
composer test:     repository-wide compatibility alias
```

通常のUnderground featureではfocused tests、Underground full、relevant static/frontend、exact-head Qualityを実行します。
意味なくlocal Surface fullやrepository-wide serialを追加しません。Quality CIはSurface / Underground両方の全file coverageを維持します。

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
  application 3.0.0-alpha.2
  PR #101〜#106 merged
  PR #106 merge baseline 0abe33c7d1e8785468e2560ed9b7e1e7269f9e22
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
詳細presentationは短期情報であり、current catalogからhistorical battleを再構築しません。

## 5.4 public / private information

publicへ漏らさない:

- secret facility identity / coordinate
- missile owner-only detail
- raw metadata
- OAuth provider identifiers
- inquiry private content
- private deterministic seed
- internal Underground snapshot / DB identity
- hidden alias一覧や案内人の背景設定

Underground APIはcurrent User自身のSecretaryをserver側で解決し、client supplied Secretary IDをownership根拠にしません。

## 5.5 production safety

- Owner明示指示なしにproductionへ接続・変更しない
- destructive DB commandsをproductionで使わない
- unresolved failed/blocked TurnRunを跨いでsurface Ruleset変更しない
- automatic Turn retryしない
- migration失敗後もwebを復帰させるdeploy手順を使う
- `_references/`を変更しない
- asset binaryをGitへ追加しない
- 3.0.0-alpha.2をproductionへdeployしない
- 3.0.0正式deploy時にUnderground detail-log pruneのOCI host cronを設定する

## 5.6 Underground runtime contract

- stamina / daily / weekly obligationなし
- Turn同期なし
- normal battle後10秒cooldown
- one battleはauto AIでatomic resolve
- max 100 rounds
- victory / defeat / withdrawalを一度だけsettle
- combat level / XP / Trial progress / area layerは独立
- defeatは欠片半減
- normal withdrawalはbase victory XPの`floor(1 / 4)`を取得、欠片loss / gainなし
- Trial first clearだけ1 layer解禁
- Trial runは自身のcontent identityへpin
- detail action/presentation log retention 1時間
- history listはbounded summary、detailは個別取得
- compact battle recordは保持
- actual OCI cron registrationは3.0.0 deploy時operator作業
- `G`は別currencyではなく輝石の欠片のgram表記

PR103のnormal hunt / Trial runtimeはbackend foundationです。PR106時点ではplayer通常探索・Trialへ公開していません。

## 5.7 alpha-v1 growth / build contract

Current Lv1 baseline:

| growth path | 生命 | 武力 | 技巧 | 精神 | 敏捷 | Lv1無装備HP |
|---|---:|---:|---:|---:|---:|---:|
| 戦技 | 18 | 34 | 30 | 8 | 10 | 484 |
| 護身 | 40 | 22 | 10 | 16 | 12 | 660 |
| 祝福 | 22 | 8 | 16 | 42 | 12 | 516 |
| 自由 | 26 | 22 | 20 | 20 | 12 | 548 |

全path共通:

- Lv1合計100
- max / start MP 10,000
- natural MP recovery 300 / round
- 自由にもLv1手動配分特例なし

Lv2以降のOwner決定:

```text
戦技: 生命+1 武力+2 技巧+1 精神+1 敏捷+0 / STP+5
護身: 生命+2 武力+1 技巧+1 精神+1 敏捷+0 / STP+5
祝福: 生命+1 武力+1 技巧+1 精神+2 敏捷+0 / STP+5
自由: 生命+1 武力+1 技巧+1 精神+1 敏捷+0 / STP+6
```

各Lv合計10point相当。敏捷は自然成長しません。
実際のlevel-up settlementとSTP persistenceはPR107、manual allocationはPR108予定です。

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
- Underground pure combat / persistence / runtime / formal first-player intro / alpha-v1 playtest

未実装または未確定:

- 船 / port / fuel / 本格海上actor
- 複数MapSpaceの本格利用
- Underground player normal exploration / Trial / skill progression / formal inventory
- generic map Item
- generic modifier framework
- 防壁都市
- dormant territory occupation

候補を現行仕様として扱わないでください。

---

# 7. 現在の残件

## 7.1 PR107 — 周囲を探索 / growth runtime

Owner確定scope:

### player battle loop

```text
地下メイン
↓ 周囲を探索
server-side weighted enemy selection
↓
実Secretaryのalpha-v1 auto battle
↓
victory / defeat / 100-round withdrawal
↓
EXP / 輝石の欠片 / Lv / natural growth / unspent STP settlement
↓
地下メインへ戻る
```

PR103のlock / idempotency / settlementを再利用しますが、alpha-v0固定actor / fixed loadoutをplayerへそのまま公開しません。
PR105のlaboratory level倍率もplayer成長へ流用せず、current statsは概念上、
`latest Lv1 baseline + path natural growth × (Lv - 1) + manually allocated STP`で構成します。

### common starter knife

全growth pathが同じ最低rank `護身用ナイフ`から開始します。
特定path専用ではなく、全5statsへ小さいbalanced bonusを持つCommon / Item Lv1相当のfixed starterです。
PR107ではfull inventory / affix / shop ownershipを作りません。上位装備の段階で短剣・細剣・盾・輝石杖等へ細分化します。

### shallow enemy weights

```text
25% 地底鼠       速い・低HP・弱い
25% 洞窟蟲       標準
20% 腐食スライム 遅い・高HP・弱〜標準
10% 再生肉塊     毎round少量回復
10% 狂信者       予告後に強攻撃。built-in AIの防御が有効
 9% 迷い人の影   複雑なmechanicなしの少し強いVanilla
 1% 輝晶虫       bonus / metal系
```

敵設計は「大半Vanilla、一部だけ厄介」を維持します。

輝晶虫のOwner方向:

- HP1
- 各hitごとに99% complete guard
- normal damage reduction / guard capとは別trait
- 非常に高いEXP、浅層Vanilla平均のおおむね25倍を初期目安
- 100roundで倒せなくてもnormal withdrawalの1/4 EXPを得る
- multi-hit buildが将来有利になる

### EXP / growth

現行の算術級数curveを旧FFAいく改と比較します。
変更自体を目的にせず、序盤のalpha growthが破綻していなければ維持または小修正で構いません。

- multiple level-upをlevel deltaで一度だけsettle
- Lv1ではSTP 0
- Lv2以降、pathごとのunspent STPを付与
- PR107ではmanual allocation UIなし
- battle resultでLv before→afterとSTP deltaを表示

### normal result

- victory: authored EXP + shard G
- withdrawal: authored EXPの`floor(1 / 4)`、shard 0
- defeat: EXP 0、shard balanceを`floor(balance / 2)`
- max 100 rounds、決着時は早期終了
- cooldown 10秒
- dropなし。0件のdrop欄を常設表示しない
- `G`を別currencyとして二重表示しない

## 7.2 PR108予定 — status / STP / skill tree

- status画面で生命 / 武力 / 技巧 / 精神 / 敏捷を表示
- unspent STPをserver-authoritativeに配分
- SPはSTPと別resource
- 戦技 / 護身 / 奇跡の3 treeをplayer persistenceへ接続
- node prerequisite、investment gate、SP消費
- passiveを実戦へ反映
- active skill最大5
- built-in AIが取得skillを使用
- AI editorは公開後でもよい

Ownerの長期方向:

```text
契約時:          20 SP
Trial 1初回clear: +40 SP
Trial 2初回clear: +40 SP
合計:           100 SP
```

Trial 2まででcurrent 1 treeを全取得できる相当量です。
100 / 0 / 0でも、60 / 40 / 0でも、34 / 33 / 33でもよく、混成の最深部到達が遅いことは選択の代償として許容します。

Trial 3以降は第4treeを増やすのではなく、戦技 / 護身 / 奇跡の既存3 tree自体をさらに深く伸ばす方向です。
状態異常、party buff、support、防御、control等の新しい枝は実際のcontent / team playを見て追加します。

## 7.3 Trial / shop

Trial backend contract:

- 10〜20連戦程度＋area boss候補
- battle間progress保持
- 明示帰還 / defeat / Trial stalemateでbattle 1へreset
- first clearで4マス＝1 layer解禁
- Trial固有content identity変更時だけactive runを安全reset
- trial clear SP rewardはOwner方向だが、PR107ではplayer Trialを解禁しない

Shop:

- 案内人命名・正式説明・shellのみ実装
- 宿 / 装備shop / 銀行は準備中
- actual inventory / price / buy / sell / storageは未実装

## 7.4 battle log / operations

Current contract:

- action / presentation detail retention 1時間
- history listはbounded recent summary
- listでdetail logをeager loadしない
- detailは個別battleを開いた時だけ取得
- settlement時にself-contained player-facing presentationを固定
- current Ruleset / skill / enemy catalogからold battleを再解釈しない
- detail expiry後もcompact summaryは正常表示
- prune command: `underground:prune-battle-logs`
- wrapper: `product/docker/cron/prune-underground-battle-logs.sh`
- `underground_battles` compact record / aggregate / idempotencyは保持

重要:

repositoryへcommand / wrapper /運用手順を追加しただけで、OCI hostのcrontab自体は変更していません。
3.0.0 production deploy時に実際のrepository配置pathを確認してhost cronへ登録してください。
Turn cronとは独立し、automatic retry loopを作りません。

将来、playerが明示公開 / 保存したbattleだけ長期保存する案はあります。
長期保存する場合もruntime replayではなくimmutable presentation / HTML snapshot等で固定する方向で、現在は未実装です。

## 7.5 3.0.0 production release作業

PR107 / PR108終了後の公開作業として、少なくとも次が残ります。

- alpha migration chainの棚卸し
- current 2.8.0 production → final 3.0.0 schemaのforward boundaryへrebaseline
- final fresh schema dump / migration ledger更新
- exact 2.8.0 source upgrade testとfresh install equivalence
- application version final化
- backup / restore boundary確認
- OCI hostへUnderground detail-log prune cron登録
- production deploy

これらをPR107へ混ぜません。

## 7.6 ver 2.9.0 Item拡張の一時保留

破棄していません。再開時は`product/docs/items/current-item-catalog.md`を確認し、新Item / rarity / acquisition / effectのOwner決定を既存実装と混同しないでください。
High Quality / Artifactは未実装です。

## 7.7 UG-04

次を実装する前に`docs/open-questions.md`のUG-04で停止します。

- borrowed Secretary / 最大4人party
- party snapshot / 同時利用 / reward配分
- Underground market
- Nation-owned facility placement / lifecycle
- surface benefit / published Rulesetとの関係

## 7.8 handoff maintenance

この文書はOwner / Web版ChatGPT development-advisor workflowが節目で更新します。
Codex / implementation agentは通常のfeature実装やreview対応では変更しません。

次回更新候補:

- PR107 merge
- PR108 specification / merge
- 3.0.0 production rebaseline / deploy方針確定
- ver 2.9.0再開

---

# 8. 次の担当者の開始手順

1. `AGENTS.md`
2. `product/docs/handoffs/development-history-and-current-handoff.md`
3. `docs/README.md`
4. `docs/open-questions.md`
5. Underground作業なら`docs/roadmap/3.0.0-alpha-underground.md`
6. `docs/architecture/underground-combat-laboratory.md`
7. PR #101〜#106のmerged code / migration / tests
8. task-specific current docsだけ追加
9. current branch HEAD、open PR、CI、review threadをGitHubで再確認
10. FFA等のraw sourceが必要な仕様だけ`_references/`をread-only監査

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
  application 3.0.0-alpha.2
  PR #101〜#106 merged
  PR #106 merge baseline 0abe33c7d1e8785468e2560ed9b7e1e7269f9e22
  surface Ruleset v18 unchanged
  production deploy not performed

next:
  PR107 周囲を探索 / real alpha-v1 player combat / starter knife / growth / STP foundation
  PR108 status / STP allocation / SP / three player skill trees
  Trial / actual shop remain disabled
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
active branchはrelease/3.0.0-alpha、applicationは3.0.0-alpha.2です。
PR #101〜#106はmerge済みで、PR #106 merge baselineは
0abe33c7d1e8785468e2560ed9b7e1e7269f9e22です。
exact branch HEADはdocs-only commit等で進むため開始時に再確認してください。
3.0.0-alpha.2はproductionへdeployしていません。

PR101はDB-free pure combat、PR102はSecretary-owned profile/layer entitlement、
PR103はXP・欠片・normal hunt/Trial runtime・10秒cooldown・battle history、
PR104はfirst-player Tutorial shell、PR105は5 stats / 3 tree / equipment / statusを持つalpha-v1 laboratory、
PR106は正式story、案内人命名、契約、4 growth path、報酬なしalpha-v1 playtestを実装しました。
Normal exploration、Trial、実shop、STP配分、player skill treeはまだ準備中です。

UndergroundはTurn、World、Nation、MapCell、surface Rulesetと分離します。
pure combat testへDBやsurface fixtureを持ち込まず、Feature testもUser / Secretary /
Underground専用tableを基本にしてください。

次のPR107はOwner決定済みです。
applicationを3.0.0-alpha.3へ上げ、周囲を探索を解禁し、実Secretaryのalpha-v1 combat、
共通の護身用ナイフ、浅層weighted enemy pool、EXP / Lv / natural growth / unspent STP settlementを実装します。
PR105 laboratoryのlevel倍率や120-point completed buildをplayer通常探索へ流用しません。

Lv2以降は、戦技・護身・祝福が自然5 + STP5、自由が自然4 + STP6、敏捷自然成長0です。
PR107ではSTP persistence foundationだけ作り、manual allocationはPR108へ回します。
浅層enemyはVanilla 70%、一要素20%、強めVanilla 9%、輝晶虫1%のOwner案です。
輝晶虫はHP1、hitごとに99% complete guard、高EXP、100-round withdrawalでも通常どおり1/4 EXPです。

battle detailは1時間retention、historyはbounded summary、old battle presentationをcurrent catalogから再解釈しません。
OCI host cron自体は未登録なので、3.0.0 deploy時にoperator作業が必要です。

PR108ではstatus画面、STP配分、SP、戦技 / 護身 / 奇跡tree、active最大5を予定します。
SP長期案は契約時20、Trial 1 +40、Trial 2 +40。Trial 3以降は新treeではなく既存3 treeを深く伸ばす方向です。

UG-04のparty、market、Nation-owned underground facility、surface benefitへはOwner決定前に入らないでください。
product/docs/handoffs/development-history-and-current-handoff.mdはCodex / implementation agentにはread-onlyです。
Owner明示指示なしにmain merge、production接続、deploy、backup、cron操作を行わないでください。
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
  PR104 Tutorial shell、ジャイアントラット、帰還、店員命名、地下main
  Trial固有content identity pinning

3.0.0-alpha.2:
  PR105 alpha-v1 5 stats / HP / fixed MP / 3 skill trees / status / AI / equipment laboratory
  PR106 formal story、案内人、contract、4 growth paths、hidden alpha-v1 story battle、力試し（α）
  chronological FFA-inspired battle presentation
  bounded history、detail log 1時間、self-contained presentation
  application 3.0.0-alpha.2、surface Ruleset v18不変
  production deploy未実施

next:
  PR107 周囲を探索、real player alpha-v1、starter knife、EXP / natural growth / STP foundation
  PR108 status / STP allocation / SP / player skill trees
  Trial / actual shopは準備中
  UG-04は未決定
  3.0.0公開前にmigration rebaselineとOCI battle-log prune cron登録が必要
```

この文書の役割は、失われた会話を推測で埋めることではありません。

```text
何が確定したか
何が後に変更されたか
何が現在も候補にすぎないか
次にどこから再開すべきか
```

を一つの場所から追えるようにすることです。
