# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-08-26 JST  
> 対象リポジトリ: `Mamiki765/hakoniwa-world`  
> 推奨配置先: `product/docs/handoffs/development-history-and-current-handoff.md`  
> 用途: 開発経緯、現在も有効な設計判断、廃止・変更された計画、現行作業の引継ぎ  
>
> この文書は、残存していた4本の引継ぎ資料とGitHub上のPR・commit・CIを照合して再構成したものです。
> 会話の一字一句を復元するものではありません。仕様・実装・運用状態が食い違う場合は、最新のreview済みコード、
> immutable Ruleset、ADR／decision、運用文書、Ownerの最新明示決定を優先してください。

---

# 0. 情報の読み方

この文書では、情報の由来を次のように扱います。

- **GitHub確認**: PR、commit、branch、CIなど、2026-08-26時点のGitHubで再確認した事実
- **当時資料**: その時点の正式な引継ぎ資料に記録されていた事実
- **後続確認**: 後の引継ぎやGitHubから、過去の未確定事項が完了したと確認できるもの
- **将来候補**: 当時の構想・Ownerメモであり、現行仕様として自動的に採用してはいけないもの
- **未確認**: 残存資料とGitHubだけでは確定できないもの

## 0.1 再構成に使用した残存資料

1. `hakoniwa-world_ver-1.4.0_handover_2026-08-11.md`
2. `hakoniwa-world_ver-1.7.0_to_2.0.0_handover_2026-08-16.md`
3. `hakoniwa-world-current-handoff-after-ver-2.3.1.md`
4. `hakoniwa-world-ver-2.6.1-handoff.md`

これらの資料の間に存在する空白は、GitHub上の主要release PRで補いました。

## 0.2 正本の優先順位

```text
最新のreview済みコード / immutable Ruleset / DB schema・migration
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
mainはver 2.6.0 / Ruleset v16。
release/2.6.1にはStage 1のPR #90がmerge済み。
Stage 2のPR #91はopenで、独立レビューが発見した`*` selector P2は
commit 28196468…で修正され、exact-head QualityとCodex再reviewは成功。
PR #91はまだmergeもproduction deployもされていない。
```

## 1.2 main

**GitHub確認**

```text
branch: main
HEAD:   1f302fc9b38eb8a6b3f00d2c7ec8712f5eb755c9
version: 2.6.0
Ruleset: hakoniwa-2s-plus-v16
```

`main`のHEADはPR #89 `release: ver 2.6.0`のmerge commitです。

ver 2.6.0でmainへ入った主要要素:

- 石油を通常のNation資源として追加
- 海底油田を直接収入から石油在庫生産へ変更
- 交易場
- 資源・秘書Itemのplayer auction
- 箱庭連合NPC出品
- escrowとcapacity-safe settlement
- bow / clothing / accessoryの装備カテゴリ
- 7種のNovice装備
- final Ruleset v16
- application version 2.6.0

## 1.3 production

**当時資料**

ver 2.6.1引継ぎには、ver 2.6.0がproductionへdeploy済みで、次が確認されたと記録されています。

- v15→v16 migration成功
- production WorldがRuleset v16へ移行
- web再起動成功
- official Turn成功
- 箱庭連合の資源出品生成
- 石油・交易場・Novice装備の稼働

ただし、この統合文書の作成時にはproductionへ接続して再確認していません。
productionの現況が必要な作業では、GitHubの状態から推測せず、Ownerの明示確認または許可されたread-only確認を行ってください。

## 1.4 release/2.6.1

**GitHub確認**

```text
branch: release/2.6.1
HEAD:   8c405395bacd14c3d8c3b4c6ca725caed77c2b70
```

このHEADはPR #90 Stage 1のmerge commitです。

Stage 1:

- current v16 authoringを10 domainへ分割
- Behavior / Data / Flavor分類を導入
- historical Rulesetの人間向けarchiveを追加
- gameplay、RNG、schema、migration、API、UI、production dataを変更しない
- application versionはこの段階では2.6.0のまま
- v16 payload/checksum不変

## 1.5 PR #91

**GitHub確認**

```text
PR:       #91
title:    refactor: rebaseline current v16 and retire historical Ruleset runtime
state:    open
draft:    false
mergeable: true
base:     release/2.6.1
base SHA: 8c405395bacd14c3d8c3b4c6ca725caed77c2b70
head:     codex/ver-2.6.1-current-rebaseline
head SHA: 28196468d0c43c2af9343a3b7fc0ae87f02c3035
commits:  2
merge:    未実施
deploy:   未実施
```

PR #91の主要目的:

- executable Ruleset authoring、validation、normal runtime、test bootstrap、operator pathをcurrent v16だけへrebaseline
- application versionを2.6.1へ変更
- v17は作らない
- historical Ruleset PHP、roadmap PHP、upgrade services、適用済みold Ruleset migrations、historical test ownersをcurrent treeから退役
- historical DB rows、presentation、provenance、idempotency、audit、historical World read compatibilityを保持
- historical World mutationはfail closed
- fresh installはfinal-v16 schema baselineから直接開始
- already-current v16 DBへの2.6.1適用はbusiness-data no-op

## 1.6 PR #91のP2発見と解決

### 発見

最初の実装HEAD:

```text
60ad4fc275ed67feca6e99cd652587f28eb4930e
```

独立レビューは次のP2を発見しました。

```text
CurrentRulesetAuthoringInspectorの`*`は文書上「list indexだけ」に一致する契約だが、
実装はctype_digit()だけを見ており、数値キーのassociative mapにも一致する。
```

代表例:

```text
facility_definitions
└─ missile_base
   └─ launch_capacity_by_level
      ├─ 1
      ├─ 2
      ├─ 3
      ├─ 4
      └─ 5
```

このcontainerは`array_is_list()`ではfalseですが、旧実装では数値segmentなので`*`に一致していました。

### 修正

修正commit:

```text
28196468d0c43c2af9343a3b7fc0ae87f02c3035
fix: restrict ruleset wildcards to list indexes
```

修正内容:

- traversal時に、pathの各segmentがlist container由来かを保持
- `*`は数字であり、かつlist indexとして記録されたsegmentにだけ一致
- numeric-key mapの`launch_capacity_by_level`は明示selectorへ変更
- true list、文字列key map、数値key mapを区別するregression testを追加
- authored leaf比較からinspection metadataを除外
- coverageを維持
- v16 checksumを維持
- gameplay、balance、published payloadを変更しない

維持された契約:

```text
domains:  10
leaves:   1,841
Behavior: 1,210
Data:       455
Flavor:     176
checksum: 331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d
```

### 修正後の検証

**GitHub確認**

- exact-head Quality run `32912425822`: success
- exact head: `28196468d0c43c2af9343a3b7fc0ae87f02c3035`
- Codex再review: major issueなし
- PRはopenのまま
- merge、deployなし

最初の独立レビューは旧HEADでP2以外のP0～P2を発見していません。
新HEADへの別個の独立review投稿は確認できませんが、P2の修正は提示された第1案どおりに限定され、
exact-head CIとCodex再reviewは成功しています。

## 1.7 直近の作業順

1. PR #91のcurrent head/state/Quality/reviewを再確認
2. Ownerがmerge可否を判断
3. 指示があればPR #91を`release/2.6.1`へmerge
4. `release/2.6.1 → main`のrelease PRを別に作る
5. release PRのexact-head Qualityと最終確認
6. Owner指示後にmainへmerge
7. production deployは別の明示指示とrunbookに従う
8. application version 2.6.1、Ruleset v16、checksum、official Turnを確認

PR mergeとproduction deployを一つの暗黙作業として扱わないでください。

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

## 2.2 legacy sourceの扱い

仕様由来を判断するときの優先順位:

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

## 当時の中心課題

ver 1.4.0の残存資料は、PR #36が`release/1.4.0`へmergeされた時点のものです。
主要機能はPR #33～#36で構成されました。

### PR #33: 領土拡張・領地感化

- 手動`territory_expand`
- 中立陸地の取得
- active他国の荒地・焦土取得
- 6方向隣接
- ownerだけ変更しterrain/facility/population保持
- monster occupancy除外
- Capitalからhex distance 2以内を保護
- active Nation間の自動領地感化
- randomized sequential処理
- 同Turn連鎖
- immutable Ruleset v3

### PR #34: 伝言板・秘密通信

- 各島の伝言板
- 最新16件表示、最大100件保持
- 140文字
- login user投稿
- 非参加者を観光客として扱う
- User単位cooldown
- 100億円の秘密通信
- unauthorized viewerには本文を非表示
- historical World書込不可

### PR #35: ログの3層化

- TOP専用重大ニュース
- TOP集約・各島で見られる公開島ログ
- 自島owner-onlyログ
- turn grouping
- public-safe DTO
- fail-closed allowlist
- raw metadataをそのまま公開しない
- 秘密通信監査eventを公開ログへ混ぜない

### PR #36: player-facing writer補完

- 援助
- 怪獣
- award
- 首都変更
- 通常command
- missile
- event-time Nation role snapshot
- actual transferred / applied value
- 公開情報とowner-only detailの分離

## 後続確認された完成項目

後の1.7.0引継ぎでは、1.4.0の到達点として次も記録されています。

- production destructive DB command guard
- mobile workspace改善
- missile scorched mark
- 伝言板・ログ・領土の正式release
- Ruleset v3

1.4.0当時の「次のTODO」は、後続versionですでに処理されたものを含みます。
現在のTODOとして再登録しないでください。

---

# 3.2 ver 1.4.1 — ログとUIの安定化

後続引継ぎで確認される主な内容:

- TOP public command log補完
- owner timeline整理
- routine internal logをplayer-facing表示から除外
- `build_decoy`表示改善
- message board位置調整
- H2 / H2＋ / 2S＋仕様由来監査

大機能追加より、1.4.0で導入した交流・ログ基盤のpresentationと安全性を整えた段階です。

---

# 3.3 ver 1.5.0 — World拡張とH2 realignment

主な到達点:

- signed/current MapSpace bounds
- `WorldMutationLock`
- safe World expansion
- Nation登録候補枯渇時の自動chunk拡張
- 16cell chunk rotation
- World全体災害の面積scale
- 海底基地の経験値、Lv、発射数、owner表示
- 海底基地のmissile resistance
- 海際人口依存を除去
- H2寄りのsettlement growth
- Ruleset v4 → v5

この段階で、初期60×60に固定されたゲームから、shared Worldを安全に拡張できる構造へ進みました。

---

# 3.4 ver 1.5.1 / PR #50 / PR #53 — 性能とCIの整理

## ver 1.5.1

- PHPUnit memory 512M標準化
- test fixture軽量化
- 意味のある60×60 testは維持
- player-facing version 1.5.1

## PR #50

実測bottleneckだけを改善:

- settlement cell-loop N+1削減
- immutable catalog再利用
- Nation economy / sales / aggregate query削減
- disaster / monster spawnの不要hydration削減
- gameplay、RNG、retry不変

## PR #53

CI sharding:

- PHPUnit 16 shards
- `backend-static`
- `frontend`
- `documentation`
- aggregate `backend`
- check表示数と同時job数を区別

以後の原則:

- localはfocused
- full correctness gateはCI
- serial fullと全shardを無意味に二重実行しない
- performance evidenceは関連tree/environmentが変わったときだけ再測定

---

# 3.5 ver 1.6.0 / 1.6.1 — Nation lifecycle

## ver 1.6.0

Nation abandonment:

- owner-only
- exact-name confirmation
- Nationをphysical deleteしない
- Account/history保持
- surface cleanup
- affected monster除去
- membership / Capital解除
- 同Accountで別名再登録
- public major news

## ver 1.6.1

- 伐採成功後を`forest → plain`へ修正
- runtime hotfix
- published v1～v5 payload不変
- 後のv6でpublished logging metadataも`plain`へ正式化

この時期から、player dataを消すのではなく、状態遷移と履歴保持でlifecycleを表現する方針が明確になります。

---

# 3.6 ver 1.7.0 — 1.xの完成点

PR #55は当時資料ではreview中でしたが、**GitHub確認では2026-08-16にmerge済み**です。

主な内容:

- application version 1.7.0
- immutable Ruleset v6
- v5→v6 forward migration
- selected-position起点のbulk queue
- bulk commandの挿入
- queue上限30
- `ここから下を削除`
- 防衛施設への再建commandによる隠し自爆
- 記念碑への再建commandによる隠し飛翔
- 「何かとてつもないもの」
- 防衛施設のSPP耐性
- 16×16 chunk由来の海域名
- `(0,0)`を含むchunkをペリドット海域
- defense / decoy asset mapping修正
- production config cache
- logging result terrainを`plain`へ正式化

重要な設計判断:

- 海域はフレーバーであり、mapの常時色分けをしない
- hidden featureの発動条件をmanualで攻略情報として公開しない
- 事故防止warningは表示してよい
- monument flightはtargetの完全16×16 chunkから厳密1/256
- partial edge chunkはtargetから除外／安全失敗
- gameplay変更は新Rulesetへ載せる
- migration overrideは専用one-shotで、他のguardを迂回しない

1.7.0で、1.xの「共有地上World」をかなり完成させ、2.xのAccount-persistent要素へ進む土台ができました。

---

# 3.7 ver 2.0.0 — User-persistent Secretary

PR #56 `Implement Secretary v1 for ver 2.0.0`で導入。

主な内容:

- Userに永続するSecretary v1
- 一度きりの命名UI
- deterministic Hakoniwa calendar
- 4つのpassive skill
- Turn開始時batch load
- production bonus
- development XP
- final-defense interception / XP
- immutable Ruleset v7
- forward-only migration
- Nationを越えてSecretaryが残る2.x generation boundary

重要な境界:

- SecretaryはNationではなくUser側の長期progression
- Turnへの影響はattempt-start snapshot
- same-turnで獲得したXPによるlevel upは次のTurn attemptから効果
- migrationはNation登録履歴のあるUserをexact setでbackfill
- unresolved production TurnRun時はfail closed

PR #57でSecretary skill presentationを圧縮し、表示契約を整えました。

---

# 3.8 ver 2.1.0 — 防衛迎撃とSecretaryプロフィール編集

PR #58で確認できる主要内容:

- application version 2.1.0
- raw source再監査に基づく防衛施設の周辺迎撃
- immutable Ruleset v8
- 通常 / PP / 陸破 / SPP
- radius 1～2
- radius 3は範囲外
- 真の防衛施設だけが迎撃
- decoyは対象外
- target cell自体が防衛施設なら周辺迎撃をskip
- self-fired / enemy-fired共通
- monster cellでも同じ
- Secretary迎撃との優先順を明確化
- owner向け防衛ログ集約
- Secretary rename
- historical name snapshot

この時期の特徴は、legacy sourceで不足していた挙動をraw sourceから再監査し、
runtime-only hotfixではなく新しいimmutable Rulesetへ正式化したことです。

---

# 3.9 ver 2.2.0 — 倉庫・装備基盤とゲーム内問い合わせ

PR #62で導入。

Secretary:

- secondary tabs: 熟練度 / 装備 / 倉庫
- User-owned Secretary-persistent inventory
- capacity 50
- 5-slot equipment foundation
- `old_bow` Lv1を一度だけgrant
- slot 1へ初期装備
- 2.2.0時点ではItem gameplay effectなし

問い合わせ:

- login user向けゲーム内問い合わせ
- exactly 5 categories
- plain text
- optional image 1枚
- server MIME validation
- 最大10MB
- admin向けlatest 5 / pagination / detail API
- attachment tokenは推測困難なrandom value
- DB rowと本文はDB backup対象
- attachment fileは当時DB backup契約外

Ruleset:

- currentはv9
- v10はまだ作らない
- Turn processing不変

---

# 3.10 ver 2.2.1 — correctness / idempotency / privacy / CI hardening

PR #63。

主な内容:

- immutable Ruleset v10
- wheat production overflowの解決stageを、population nutrition consumption後へ
- forward-only idempotent v10 migration
- queued command registrationのfingerprint
- duplicate retry conflict-safe化
- Nation creation error contract安定化
- inquiry image validation / private delivery header強化
- public typhoon messageから施設情報を除外
- OAuth identity creation/linking serialize
- command-definition query削減
- `/me` identity query削減
- application versionのsingle source of truth
- CI artifact・shard coverageのfail-closed化

この段階で、後の2.3.0 migrationが依存するrequest fingerprint / provenanceの基盤が整いました。

---

# 3.11 ver 2.3.0 — 装備効果と怪獣拡張

PR #65～#71で構成され、formal Ruleset v11へ進みました。

## Secretary equipment mutation

- 5 slots
- equip / unequip / replace
- atomic mutation
- `equipment_version` optimistic concurrency
- User-global
- Nation / MapSpace別に分けない
- Turn開始時snapshot
- active Nationなしでは効果なし

## 古びた弓

- 10% chance
- 自領surface monsterへ1 damage
- missile確定後、通常怪獣行動前
- canonical `MonsterDamageService`
- hardening等の確実な無効攻撃を避ける
- v1～v10 historical TurnRunでは発動しない

## 指輪

- max Lv10
- category上限5
- same-item上限5
- 資金繰り時に装備Ring Lv合計と同じ億円を追加
- explicit / automatic fundingの両方
- capacity共通

## 怪獣派遣

一つのcommand:

```text
monster_dispatch
```

selector:

```text
1 = メカいのら
2 = メカいのら零式
```

server-side resolverがmonster keyとcostの正本。

## 《海獣》あおいのら

- World-level disaster category
- 中立海・浅瀬へspawn
- 陸地から4hex以上
- HP 2～3
- spawn turn defer
- canonical movement / occupancy / defenseを再利用
- water侵入とfinal neutral sea resultだけ差分化
- 海を足場に次Turn以降も侵攻
- 中立海上撃破時はkillerへ資金
- host meatなし

## メカいのら零式

- HP 4
- dispatch only
- HP1で通常怪獣stageを迎えると核爆発
- huge meteor相当
- self explosion reward / kill stat / XPなし
- chain explosionなし

## formal v11 migration

- World / release lock
- unresolved next non-dry TurnRun guard
- exact v10 source preflight
- request provenance backfill
- queued commandだけv11へrebind
- alive monsterだけv11へrebind
- current kill statだけv11へrebind
- Worldを最後にactivate
- completed / failed / cancelled historyは元Rulesetへ残す
- forward-only、rollbackはbackup restore

---

# 3.12 ver 2.3.1 — 新機能ではなく整理

PR #72は`release/ver-2.3.1`へmergeされ、その後のversionの土台になりました。

変更したこと:

- Ruleset history archive
- Core / Balance / Flavorの概念分類
- Secretary Item immutable effect catalogをTurn準備で一度resolve
- monster behaviorをunique definitionごとに一度resolve
- test責務の整理
- current hot pathの重複validation / resolution削減

変更しなかったこと:

- gameplay
- Ruleset payload/checksum
- v12
- schema migration
- balance
- RNG
- phase順
- lock / transaction / retry
- Core / Balance / Flavorの物理分割
- current-only runtime
- duration-aware sharding

重要な経緯:

- **2.3.1ではcurrent-only runtimeと物理分割を「未完の必須作業」にはしなかった**
- その後2.4.0でcurrent baselineとhistorical compatibility cutoffを進め、
  2.6.1 Stage 1でcurrent authoringをdomain分割・3分類し、
  Stage 2でcurrent-only runtimeを実装する流れへ発展した

---

# 3.13 ver 2.4.0 — compatibility cutoff、dormancy、KARMA

2.4.0は、古いOwnerメモにあったauction/shop中心のversionではありません。
実際には、current applicationの対応境界を整理し、Nation lifecycleとKARMAを導入した重要なreleaseです。

## PR #73: compatibility cutoff audit

- historical execution compatibilityとproduction data保持を分離
- historical Ruleset/migration/test依存を棚卸し
- current v11がstandaloneでないことをblockerとして発見
- direct current-schema fresh install baselineがないことを発見
- production source stateをrepoから推測しない方針

## PR #74: current Ruleset baseline

- v11 inheritance/patch chainをstandalone resolved payloadへ
- strict array equalityとchecksum不変を証明
- normal configのauthored Ruleset inputsを21→1
- historical authoringはupgrade/operator validation専用catalogへ
- `SecretaryService`のv7直接依存を除去
- gameplay、balance、published row不変

## PR #75: install / upgrade rebaseline

- PostgreSQL canonical schema dump
- fresh installはcurrent schema + current catalogs + exact v11から直接開始
- historical publication/repair migrationをfresh installで再演しない
- existing productionはexact 2.3.1/v11だけをsupported sourceにする
- unresolved non-dry TurnRun zero
- business-table digestとfingerprint preservation
- backup restore → forward re-upgradeをrecovery boundaryにする

## PR #76: historical compatibility retirement / test rationalization

- 46 historical migrationsをcurrent treeから削減
- 24 historical migration/Ruleset/release test filesを削減
- unsupported direct v2～v10 upgradeを終了
- historical DB rows / provenance / presentationは保持
- generic request-time provenance interpretationを残す
- PHPUnit files 114→90
- identifiers 1,056→約730
- Quality wall timeを大幅短縮
- current contract、lock、rollback、RNG、idempotencyは維持

## PR #77 / #78: dormancy beta / Ruleset v12

- manual 1～7 day dormancy
- automatic idle/collapse dormancy
- exact-turn resumption
- automatic abandonment
- Capital distance 2 protection
- outside-radius interactionは継続
- winter presentation
- public-safe protection / lifecycle logs
- existing idle_counterを保持
- new v12 Nationは2000から開始
- immutable Ruleset v12

## PR #82: KARMA and recovery / Ruleset v13

- application versionを2.4.0
- immutable Ruleset v13
- KARMA accounting
- sanctions
- victim reduction
- recovery entry / exit
- action restriction
- monster / recovery handling
- structured audit events
- API / UI
- v12→v13 forward migration

## PR #83: recovery hotfix

- canonical crime exit
- same-Turn recovery re-entry priority
- Inquiry current Nation contextをactive/dormant/recoveryへ
- migration preservation hashをstream化しmemory負荷を抑制
- v13 checksum不変

このversionで、pre-release全履歴をcurrent treeで永久に実行可能にし続ける方針から、
「supported current baseline + immutable persisted history」へ大きく転換しました。

---

# 3.14 ver 2.5.0開発線 — Secretary公開プロフィールと成長要素

独立した`release: ver 2.5.0` PRは確認できないため、この文書では「2.5.0開発線」と表現します。
後続2.6.0のbaseにこれらの機能が含まれています。

## PR #84: Secretary公開プロフィール / Ruleset v14

- public Secretary Main tab
- responsive image
- basic information
- biography
- 5 equipment slots
- owner-only image/profile editing
- image provenance
- viewer-side AI image visibility
- owner fallback preference
- Secretary Lv = passive level sum
- 同じpercentageをmoney/food capacity multiplierへ
- immutable Ruleset v14
- exact v13→v14 migration

## PR #85: monster experience / forest management / Ruleset v15

- actual monster damageからmissile base / seabed base / Old Bow experience
- canonical damage pathを再利用
- attributable historical Old Bow final blowだけ一度補償
- facility experienceは再計算しない
- `forest_management` passive
- logging / planting EXP
- logging income bonus
- forest growth bonus
- Internal Affairs levelを5 skill sumへ
- immutable Ruleset v15
- exact v14→v15 migration

旧2.3.1引継ぎの「2.5.0は船system」という記載はOwnerのplaceholderであり、
実際の2.5.0開発線では実装されていません。船は現在も将来候補です。

---

# 3.15 ver 2.6.0 — 石油、交易場、Novice装備

## PR #86: 石油 / Ruleset v16

- resource key `oil`
- 表示: 石油
- 単位: 万バレル
- initial 0
- capacity 5000
- sale value 2億円 / unit
- 海底油田は500 oil units / Turn
- v15→v16 forward migration
- existing Nationsへoil balance/policy row
- balance/history保持

## PR #87: 交易場

- resource listing
- Secretary Item listing
- bids
- cancel
- goods escrow
- money escrow
- late Turn phase settlement
- capacity enforcement前
- 10% fee
- deterministic 箱庭連合listing
- resource slots
- Novice Item listing
- generic marketplace frameworkは作らない

v16はrelease branch内で未公開だったため、PR #87の内容を同じfinal v16へ統合し、
v17を増やさない判断を採用しました。

## PR #88: Novice装備

- bow / clothing / accessory
- accessoryは実質高上限
- same Itemは原則1個
- 7種のNPC-tradable Novice Item
- existing catalog / snapshot / capacity / monster / KARMA / trading post pathを再利用
- item-source stackingを明文化
- deferred generic modifier frameworkは作らない

## PR #89: release

- completed `release/2.6.0`をmainへ
- merge commit `1f302fc9...`
- application version 2.6.0
- final Ruleset v16

---

# 3.16 ver 2.6.1 — current authoringとruntimeのrebaseline

## Stage 1 / PR #90

- current v16を10 gameplay domainへ分割
- scalar leafをBehavior / Data / Flavorへexactly once分類
- current entrypointはexplicit composition
- historical formal / roadmap archive
- gameplay、schema、migration、API、UI不変
- v16 checksum不変

Stage 1初期coverage:

```text
Behavior 1,203
Data       456
Flavor     182
```

Stage 2 reviewでclassificationを修正:

- `/resource_definitions/*/unit`: Flavor→Behavior
- `unit_label`: Flavor
- `/secretary/capacity_bonus/cap = null`: Data→Behavior sentinel

最終:

```text
Behavior 1,210
Data       455
Flavor     176
```

## Stage 2 / PR #91

- application version 2.6.1
- current-only executable Ruleset runtime
- formal v1～v15 PHP退役
- roadmap PHP退役
- historical catalog/bootstrap退役
- upgrade/rebaseline services 6個退役
- applied Ruleset migrations 6個退役
- historical test owners退役
- fresh install final-v16 direct baseline
- already-current v16 business-data invariance
- historical DB records保持
- Gitをold executable sourceのauthorityにする
- Markdownは人間向けindexでありpayload restore sourceではない

supported source:

```text
already-final-v16 database
```

v15以前:

```text
recorded ver 2.6.0 Git release
→ v16 migration
→ official Turn
→ 2.6.1
```

current treeからv11→v16 chainを再演することは2.6.1のsupport contractではありません。

---

# 4. 設計方針がどう変化したか

## 4.1 Ruleset

### 初期～1.x

- gameplay変更ごとにimmutable Rulesetを積み重ねる
- historical TurnRun snapshotを保持
- live mutable referencesだけstable keyでrebind

### 2.3.1

- historyとCore / Balance / Flavorの概念を文書化
- 物理分割は保留
- current-only runtimeも保留

### 2.4.0

- current v11をstandalone化
- normal runtimeをcurrent authored input 1個へ
- fresh installをcanonical schema baselineへ
- unsupported direct historical upgradeを終了
- historical persisted dataは保持

### 2.6.1

- current v16 authoringをdomain分割
- Behavior / Data / Flavorをmachine-inspected metadataへ
- old executable authoring・upgrade chainをcurrent treeから退役
- Git historyをold implementation authorityへ

## 4.2 Migration

一貫して残すもの:

- forward-only
- unresolved TurnRun fail-closed
- exact source identity/checksum
- transaction
- lock
- stable-key live rebind
- fingerprint/provenance保持
- terminal historyをrebindしない
- backup restoreをrecovery boundaryにする

変化したこと:

```text
全歴史をfresh installで再演
↓
current schema dump + current publication
```

```text
古い全versionからcurrentへ直接upgrade
↓
明示されたsupported production baselineだけをforward upgrade
```

重要:

> Repository state is not evidence of production migration state.

schema dumpやapplication versionだけから、本番がmigration済みだと推測しないでください。

## 4.3 Test

進化:

- 512M標準化
- fixture軽量化
- bottleneckの実測
- 16-shard CI
- local focused / CI full
- test identifier equivalence
- representative contract owner
- historical replay testの削減
- expensive evidenceの無意味な再実行を避ける

削減してはいけないもの:

- current migration
- second-run idempotency
- forced rollback
- transaction
- concurrency
- lock
- unresolved TurnRun
- request fingerprint/provenance
- current retry
- RNG identity
- checksum / immutability
- DB constraint / trigger
- occupancy
- capacity
- production turn-stop regression

## 4.4 UserとNation

1.x:

- Nation中心のgameplay
- lifecycleはphysical deleteせずhistory保持

2.x:

- SecretaryをUser-persistentへ
- inventory / equipment / skill / profileをNationから分離
- active Nationがない間はTurn効果なし
- Turn開始時snapshotでdeterminismを守る

この境界は今後のlong-term progressionでも維持するのが自然ですが、
新しいAccount systemを勝手に設計してはいけません。

---

# 5. 現在も有効な重要契約

## 5.1 current identity

```text
application main: 2.6.0
PR #91 candidate: 2.6.1
current Ruleset:
  key: hakoniwa-2s-plus-v16
  version: 16
  checksum: 331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d
v17: なし
```

## 5.2 deterministic Turn

retryは:

- same World
- same target turn
- same Ruleset
- same seed
- same command intent

で同じ結果を再現する。

変えてはいけないもの:

- phase順
- RNG stream identity
- random draw順
- transaction境界
- lock順
- randomized sequential causality
- event ordering
- request fingerprint semantics

## 5.3 historical data

退役するのはcurrent treeのold executable codeであり、production historyではありません。

保持:

- RulesetVersion rows
- definitions
- completed / failed / cancelled commands
- request key
- fingerprint bytes
- provenance
- TurnRun
- seed
- audit / events
- historical World read
- historical definition references
- Nation / map / resource / Secretary / Item history

historical World mutationはfail closed。

## 5.4 public / private information

publicへ漏らさない:

- secret facility identity/coordinate
- missile owner-only detail
- exact private asset change
- raw metadata
- OAuth provider identifiers
- inquiry private content
- admin-only detail

public eventはevent発生時点のsafe snapshotを使い、
後からcurrent map owner等を推測しない。

## 5.5 production safety

- Ownerの明示指示なしにproductionへ接続・変更しない
- destructive DB commandsをproductionで使わない
- unresolved failed/blocked TurnRunを跨いでRuleset変更しない
- automatic retryしない
- migration失敗後もwebを必ず復帰させるdeploy手順を使う
- off-host backupとrestore可能性を重視
- `_references/`を変更しない
- asset binaryをGitへ追加しない

---

# 6. 古い計画の扱い

## 6.1 2.3.1でDeferredだったcurrent-only runtime

当時は未実装かつ必須TODOではありませんでした。

その後:

- 2.4.0でcurrent baseline / compatibility cutoff
- 2.6.1 Stage 1でcurrent authoring再編
- 2.6.1 Stage 2でcurrent-only runtime

へ発展しました。

したがって、2.3.1の記述だけを見て「まだ未実装」と判断しないでください。

## 6.2 2.4.0の初期Ownerメモ

2.3.1引継ぎには:

- 休眠
- Item入手
- 資源auction
- Item auction
- 遺物shop

が候補として書かれていました。

実際の2.4.0:

- compatibility cutoff
- install/upgrade rebaseline
- historical test/runtime retirement
- dormancy
- KARMA / recovery

auctionは2.6.0の交易場として実装されました。
遺物shopは現行仕様ではありません。

## 6.3 2.5.0の船placeholder

2.3.1引継ぎでは「ver 2.5.0: 船system」とだけ記録されていました。

実際:

- Secretary公開プロフィール
- capacity modifier
- monster damage experience
- forest management

が実装されました。

船は未実装の将来候補です。

## 6.4 1.7.0時点の2.0候補

候補だったもの:

- 海content / 船
- 複数MapSpace
- Secretary / Account persistence
- KARMA
- Item / warehouse / auction
- proficiency / buff
- oil

実現したもの:

- Secretary / Account persistence
- KARMA
- Item / warehouse / equipment
- auction
- proficiency
- oil

未実装または未確定:

- 船
- 本格海上actor
- port/fuel
- 複数MapSpaceの本格利用
- 地下
- generic map Item
- generic modifier framework
- 防壁都市
- dormant territory occupation

候補を現行仕様として扱わないでください。

---

# 7. 現在の残件

## 7.1 PR #91

現時点で確認できる状態:

- P2修正済み
- exact-head Quality success
- exact-head Codex review major issueなし
- open / mergeable
- mergeなし
- deployなし

Owner判断前に勝手にmergeしない。

## 7.2 release/2.6.1

PR #91 merge後:

- release branch統合確認
- release→main PR
- exact-head CI
- release boundary確認
- production deploy判断

## 7.3 migration policyの明文化

2.6.1引継ぎで、別の小PR候補として記録されたもの:

- Ownerがproduction baselineを明示しない限りexisting migrationはappend-only
- schema / persisted-data changeにはnew forward migration
- repo stateからproduction applied stateを推測しない
- baseline以前のmigration retirementはOwner明示許可とreplacement proofが必要

PR #91へ混ぜず、別PRで`AGENTS.md`へ短く追加する候補。

## 7.4 Behavior / Data / FlavorのAGENTS導線

専用architecture documentを正本とし、`AGENTS.md`には短い定義と導線だけを置く候補。

---

# 8. 次の担当者の開始手順

1. `AGENTS.md`
2. `docs/open-questions.md`
3. current branch / PR / CI
4. current Ruleset authoring docs
5. current migration / deploy runbook
6. taskに関係するADR / decision
7. raw sourceが必要な仕様だけ`_references/`をread-only監査

PR #91継続時:

```text
main
release/2.6.1
PR #91
head 28196468...
Quality run 32912425822
latest Codex review
```

を再確認。

古いSHAを正本として作業しないでください。

---

# 9. 次のAI / Codexへ渡す開始プロンプト

```text
hakoniwa-worldの統合引継ぎです。

まず添付MDを読み、最新GitHubをread-onlyで確認してください。
正本は最新のreview済みコード、immutable Ruleset、ADR/decision、運用文書です。
古い引継ぎのSHAや当時TODOを、そのまま現在地として扱わないでください。

現在の確認対象:
- main
- release/2.6.1
- PR #91
- codex/ver-2.6.1-current-rebaseline
- exact head
- exact-head Quality
- latest review

統合MD作成時点ではPR #91 headは
28196468d0c43c2af9343a3b7fc0ae87f02c3035
でした。

独立レビューが発見したwildcard selector P2は同commitで修正済みです。
親containerのlist membershipを保持し、`*`をtrue list indexだけへ限定し、
numeric-key mapを明示selectorへ変更し、回帰testを追加しています。

exact-head QualityとCodex再reviewは成功しています。
PR #91はまだopenで、merge・deployされていません。

Ownerの明示指示なしにmerge、main更新、production接続、migration、deployを行わないでください。
gameplay、balance、v16 payload/checksum、RNG、schema、business data、v17を変更しないでください。
```

---

# 10. Gitへ格納するときの注意

この統合版は公開repositoryへ置ける内容を意識しており、次を含めていません。

- token / API key / password
- SSH秘密鍵
- DB接続文字列
- production IP
- private email
- OAuth secret
- backup passphrase
- private inquiry content
- local absolute file path

Git履歴へ入れた秘密情報は、後からfileを削除しても履歴へ残ります。
将来この文書へproduction値や鍵を追記しないでください。

## 推奨運用

```text
product/docs/handoffs/
├─ development-history-and-current-handoff.md
└─ archive/
   ├─ ver-1.4.0-handoff.md
   ├─ ver-1.7.0-to-2.0.0-handoff.md
   ├─ post-ver-2.3.1-handoff.md
   └─ ver-2.6.1-pr91-interrupted-handoff.md
```

- 統合版はcurrent sectionだけを更新
- 当時資料はarchiveとして原文保存
- 大きなreleaseごとにcurrent statusを更新
- 過去の「当時案」は削除せず、実現・撤回・未実装を明示
- exact SHAは必ずGitHubで再確認してから更新

---

# 11. 最終要約

```text
1.4.0:
  領土、伝言板、秘密通信、public/owner log

1.5.x:
  World expansion、H2 realignment、performance

1.6.x:
  Nation abandonment lifecycle

1.7.0 / v6:
  bulk queue、hidden defense/monument behavior、sea regions、config cache

2.0.0 / v7:
  User-persistent Secretary

2.1.0 / v8:
  defense interception、Secretary rename

2.2.0 / v9:
  warehouse、equipment foundation、inquiries

2.2.1 / v10:
  correctness、fingerprint/idempotency、privacy/CI hardening

2.3.0 / v11:
  equipment effects、Old Bow、Ring、Aoi、Zero、monster dispatch

2.3.1:
  Ruleset history・runtime resolution・test responsibility cleanup

2.4.0 / v12-v13:
  compatibility cutoff、fresh/current rebaseline、dormancy、KARMA/recovery

2.5.0 line / v14-v15:
  Secretary public profile、capacity modifier、monster experience、forest management

2.6.0 / v16:
  oil、trading post、Novice equipment

2.6.1:
  current authoring domain split・Behavior/Data/Flavor classification
  historical executable runtime retirement
  PR #91はP2修正・CI・Codex review済み、未merge・未deploy
```

この文書の役割は、失われた会話を推測で埋めることではありません。

```text
何が確定したか
何が後に変更されたか
何が現在も候補にすぎないか
次にどこから再開すべきか
```

を一つの場所から追えるようにすることです。
