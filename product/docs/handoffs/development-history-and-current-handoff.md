# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-08-29 JST
> 対象リポジトリ: `Mamiki765/hakoniwa-world`  
> 推奨配置先: `product/docs/handoffs/development-history-and-current-handoff.md`  
> 用途: 開発経緯、現在も有効な設計判断、廃止・変更された計画、現行作業の引継ぎ  
>
> この文書は、残存していた4本の引継ぎ資料とGitHub上のPR・commit・CIを照合して再構成したものです。
> 会話の一字一句を復元するものではありません。仕様・実装・運用状態が食い違う場合は、最新のreview済みコード、
> immutable Ruleset、ADR／decision、運用文書、Ownerの最新明示決定を優先してください。

## Maintenance ownership

このhandoffは、OwnerとWeb版ChatGPTのdevelopment-advisor workflowが管理する開発状況の外部記憶です。

Codexおよびimplementation agentは、この文書をread-onlyの参考資料として読み、現在の方針・経緯の把握に利用して構いません。
ただし、Ownerがhandoff更新そのものを明示的に依頼しない限り、編集・再生成・format・SHA更新・commitを行ってはいけません。

implementation agentは、完了した作業をcode、test、PR本文、CI、review evidenceとして報告します。
handoffへの確定反映は、それらとGitHubの現物、Owner decisionを確認した後に別workflowで行います。

---

# 0. 情報の読み方

この文書では、情報の由来を次のように扱います。

- **GitHub確認**: PR、commit、branch、CIなど、2026-08-28時点のGitHubで再確認した事実
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
mainはver 2.8.0 / Ruleset v18。
PR #99 `release: merge 2.8.0 into main` はmerge済みで、release merge commitは
c91e66d330cd4f8ae5f5297a794fd27582e4ddcf。

productionはOwner確認済みのver 2.8.0 / Ruleset v18。
このhandoff更新ではproductionへ再接続して独立検証せず、Ownerの最新明示確認を運用上の正本とする。

active development lineは`release/3.0.0-alpha`。
PR #101で、playerから到達できないTurn非依存のUnderground Combat Laboratoryをreview中。
surface production baselineはapplication 2.8.0 / Ruleset v18のまま。

ver 2.9.0のItem拡張は破棄しておらず、Ownerの具体案待ちとして一時保留。
現在実装済みItemの索引はproduct/docs/items/current-item-catalog.md。
```

## 1.2 main / ver 2.8.0

**GitHub確認**

```text
version: 2.8.0
Ruleset key: hakoniwa-2s-plus-v18
Ruleset version: 18
checksum: 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b
PR #99 merge commit: c91e66d330cd4f8ae5f5297a794fd27582e4ddcf
```

PR #99 merge後にもdocs-only commitがあるため、exact `main` HEADは作業開始時にGitHubで再確認してください。
ver 2.8.0でmainへ入った主要要素は、PR #97の資源推計・表示改善と、PR #98の海底都市・immutable Ruleset v18です。

ver 2.7.0で導入されたtheme options / dark mode、Regular / Cursed Secretary Item、怪獣Item drop、人口skill、
Trading Post settlement visibility、owner map queue tooltip、canonical Bow execution統一もそのまま現行基盤です。

## 1.3 production / release status

**Owner確認**

Ownerが確認しているproductionはver 2.8.0 / Ruleset v18です。
2.8.0 production deployとv18 migrationは完了済みとして扱います。

このhandoff更新ではproductionへSSH接続せず、production DB、TurnRun、web health、official Turn、asset実体を独立再確認していません。
productionの現況が必要な作業では、repository stateから推測せず、Ownerの明示確認または許可されたread-only確認を行ってください。

## 1.4 ver 2.8.0 release completion

**GitHub確認 / Owner確認**

```text
release branch: release/2.8.0
finalized release head: 8a976d1b03a57218efcf268176ffabbdca61e979
application: 2.8.0
Ruleset: hakoniwa-2s-plus-v18
version: 18
checksum: 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b
supported source: exact v17
v17 checksum: 8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3
release PR: #99 merged
merge commit: c91e66d330cd4f8ae5f5297a794fd27582e4ddcf
```

PR #99 finalized exact-head Quality run `33025809349`はsuccess、Codex exact-head reviewはmajor issueなし、
unresolved review threadは0でした。
`release/2.8.0`はrelease完了済みで、次開発のcurrent branchとして扱わないでください。

## 1.5 ver 2.8.0 integration evidence

**GitHub確認**

```text
PR #97  ver 2.8.0 PR1: 資源まわりの調整
         final head:   5d8b5f0ab6676034b7ef275d7e19490e91ded555
         merge commit: e7cfa912844853289b3362aade18c1195eba20d3

PR #98  ver 2.8.0 PR2: 海底都市
         final head:   d52a415ea22f8cf06be66ccde9f9ea667b743fe1
         merge commit: 6f069eab350509eaa5b952534b0e7688054c0fee

PR #99  release: merge 2.8.0 into main
         finalized head: 8a976d1b03a57218efcf268176ffabbdca61e979
         Quality: 33025809349 success
         Codex review: major issueなし
         unresolved review thread: 0
         merge commit: c91e66d330cd4f8ae5f5297a794fd27582e4ddcf
```

PR #98 final head `d52a415ea22f8cf06be66ccde9f9ea667b743fe1`:

- exact-head Quality run `33022126772`: success
- Codex exact-head review: major issueなし（reviewed commit `d52a415ea2`）
- dormant Nationのmaintenance forecastを除外するP2は`056d28c880...`で修正済み
- disguised facilityがcommand previewから漏れるP1は`3151132361...`でviewer-safe stateへ修正済み
- foreign `seabed_base` / `undersea_city`双方のneutral sea同値regressionは`d52a415...`で追加済み
- raw locked execution / revalidation contractは変更なし
- unresolved review thread: 0

## 1.6 直近の作業方向

ver 2.8.0 release作業は完了済みです。

active development lineは`release/3.0.0-alpha`です。
[`3.0.0-alpha Underground roadmap`](../../../docs/roadmap/3.0.0-alpha-underground.md)に従い、
foundation [PR #101](https://github.com/Mamiki765/hakoniwa-world/pull/101)でplayerから到達できない
DB-free combat laboratory、専用deterministic RNG、built-in AI、manifest/report、独立test suiteを追加しています。

これはTurn非依存のside gameを段階的に試すalphaであり、player-facing地底RPG、schema、API、UI、
surface benefitは未実装です。application 2.8.0 / immutable surface Ruleset v18 / production contractは変更しません。
地底testは`Underground` suiteへ分離し、World、Nation、MapCell、TurnRun、DB fixtureを作らず、
10,000-seed simulationは通常CI外のmanual experimentとします。

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

# 3.16 ver 2.6.1 — current authoring / runtime rebaselineと交易場表示改善

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

Stage 2 reviewでは`CurrentRulesetAuthoringInspector`の`*` selectorがnumeric-key associative mapにも一致するP2を発見しました。
`28196468...`でparent containerのlist membershipを保持し、`*`をtrue list indexだけへ限定して修正しています。
最終v16 checksumとcoverageは不変です。

## Documentation / development handoff

PR #91 merge後、統合handoff `product/docs/handoffs/development-history-and-current-handoff.md`をrepositoryへ追加しました。
その後、handoffはOwner / Web版ChatGPT development-advisor workflowが管理し、Codex / implementation agentはOwner明示指示がない限りread-onlyとする役割分離を採用しました。

## Trading Post follow-up / PR #92

ver 2.6.0のplayer feedbackを受け、次を追加しました。

- 現在の最高額入札者Nation名
- viewer自身の入札状態 `seller / none / highest / outbid`
- 秘書Itemのcanonical effect text
- Item名 / Lv横の`i`情報UI（hover / focus / tap / Escape / outside click）
- viewerの過去bid有無はactive listing全体へのbatch queryで取得し、listing N+1を回避
- effect textは`SecretaryItemGameplayContract::effectText()`を再利用
- gameplay、settlement、escrow、Ruleset、schema、migrationは不変

reviewで発見された「hoverで開いたtooltipをEscapeで閉じるとfocusで即再表示される」P2は最終head`66ae294...`で修正し、既存frontend testへ回帰ケースを追加しました。

同PRで`AGENTS.md`へ短いagent guardrailを追加:

- Production migration policy
- Current Ruleset Behavior / Data / Flavor quick definition
- Development handoff ownership

## Release / PR #93

`release/2.6.1` final head `4a55c66...`をPR #93で`main`へmergeしました。

- release exact-head Quality `32924183852`: success
- final-head Codex review: major issueなし
- unresolved review thread: 0
- merge commit: `4e7cf209964d2c84698b1361eb52a371b7e91869`
- merge commit treeはrelease head treeと同一
- main application version: 2.6.1
- Ruleset: v16 / checksum不変
- v17なし

Ownerはその後、ver 2.6.1をrelease済みと確認しました。
このhandoff更新時にはproductionへ接続してrelease後のTurnやDB状態を再検証していません。

---

# 3.17 ver 2.7.0 — theme、Item拡張、怪獣drop、人口skill

## PR #94: theme options / dark mode

- `system` / `light` / `dark` theme
- `hakoniwa_theme` cookie
- Option画面を未認証時から利用可能
- app / manual / community guidelinesをdark対応
- server-rendered画面もCSS読込前にtheme確定
- 満腹のハーブ表示を「食料最大値」へ整理
- gameplay / Ruleset v16 / schema不変
- merge commit: `9ccd257400d078cfceb24cdaee0a4497d9fcd65e`

## PR #95: Ruleset v17 / Secretary Item foundation

- immutable Ruleset v17
- exact v16→v17 forward migration
- v16 payload/checksum不変
- Regular / Cursed rarity
- エルフの弓 / 遠当ての弓 / 機械弓 / 首輪
- 古びた弓はplayer/NPC auction不可、固定売却も秘書が拒否
- 怪獣撃破によるItem drop
- foreign host時はkiller 75% / host 25%
- Regular / Cursedはplayer trading可、箱庭連合NPC listingなし
- Trading Post winner public event / seller private event
- 少子化対策 / 不屈
- `population_high_water`追加
- 誘致上限超過時の通常集落人口減少
- map tooltipにowner command queue表示
- Trading Post table CSS修正

Ruleset v17 identity:

```text
key: hakoniwa-2s-plus-v17
version: 17
checksum: 8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3
supported migration source: exact v16
v16 checksum: 331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d
```

PR #95 final head `6b51daca62e7b291f3ee21f6a8108f6bda9d0fd0`はexact-head Quality
`32955989743`を通過し、最終Codex reviewはmajor issueなし、全review thread解決済みです。
release/2.7.0へのmerge commitは`ffc3386cdac094902d92eda9bd51ce90604bccfd`です。

### Inventory soft-cap

倉庫50個は通常の新規取得gateであり、DB上の絶対上限ではありません。

49個の時点で確定済みのauction deliveryと怪獣dropが重なった場合、
既に確定した受取によって51個以上になることを許容します。
actual inventoryが50個以上の間は新しいgrant / dropを拒否し、
49個以下へ戻れば再び取得可能です。

player manualではこの内部例外を詳説せず「最大50個」と案内します。

### Bow canonicalization

PR reviewで、productionが`SecretaryBowAttackService`へ移行している一方、
旧`SecretaryOldBowService`を直接実行するOld Bow contract testsが残っているP1を発見しました。

最終修正では:

- `SecretaryOldBowService`を削除
- `SecretaryBowAttackService`を唯一のcanonical Bow execution pathに統一
- Old Bowの全詳細contract testもproduction serviceを通す
- Old Bow RNG identity / timing / safety / retry / drop契約を維持

final fix commitは`6b51daca62e7b291f3ee21f6a8108f6bda9d0fd0`です。

reviewで事故防止上重要だった経緯:

- stale Bow targetを除外し、nonlethal damage後のHPを後続Nationへ同期
- refugee receiptへturn-start attraction capを適用
- Collar crime doublingから意図しないattacker-KARMA gateを除去
- auction inventory overfillはOwner decisionによりsoft-cap仕様として確定
- duplicate Bow engineを削除し、production canonical pathへ統一

---

# 3.18 ver 2.8.0 — 資源推計、海底都市、Ruleset v18

## PR #97: 資源まわりの調整

- owner resource forecastへ食料 / 工業品 / 鉱物 / 石油の生産 / 消費 / 予測 / 所持を追加
- 食料所持はresource definitionのnutrition換算
- 失業率 / 労働力飽和を表示
- Turnとowner APIでside-effect-free canonical economy calculatorを共有
- owner APIはaggregate / current-state inputだけを収集し、Turn simulatorや全MapCell hydrateを行わない
- 資源売却UIへcanonical unit labelを表示
- owner / public Nation event windowを24 Turnから12 Turnへ変更
- public World event windowは2 Turnのまま
- Nation詳細欄をKARMA、capacity、食料内訳中心へ整理

## PR #98: 海底都市 / Ruleset v18

- immutable Ruleset v18とexact v17→v18 forward migration
- 過去のver 2.7.0 upgradeはtargetを明示的なv17 snapshotへ固定
- `undersea_city`は施設のないseaへ1000億円で建設し、自国領から3hex以内を要求
- 首都人口3100人以上から3000人を移住し、海底都市人口3000人で開始
- 通常集落の人口増加を再利用し、自然増加上限10000、誘致上限20000
- Nation総人口と通常食料消費へ含むが、陸地面積や難民受入先には含めない
- 1都市ごとに工業品1000 + 鉱物1000 / Turnを維持費として消費
- 片方の不足は、もう片方の基本分を確保した余剰から2:1で代替
- 満額を払えない都市は両資源とも消費0で、その都市だけへcanonical famine相当の人口減少
- 通常飢餓とのpopulation lossは二重適用せず、人口3000は存続、3000未満で破棄
- 火災では即消滅し、森 / 記念碑による防止なし
- 火災以外の災害分類は`seabed_base`と同一
- disguised facilityとして非owner / publicには通常のneutral seaを提示
- command previewもviewer-safe stateを初期値にし、秘密施設の有無やownerをwarning/statusから漏らさない
- raw locked execution / revalidationは変更しない
- 陸地破壊弾で破壊可能、foreign undersea city破壊でcanonical KARMA ledgerへ+3
- foreign-owned wastelandの領土拡張成功時だけ同ledgerへ+1
- `tile.undersea_city`は`undersea-city.gif`へ解決

Ruleset identity:

```text
current: hakoniwa-2s-plus-v18 / 18
checksum: 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b

supported source: hakoniwa-2s-plus-v17 / 17
checksum: 8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3
```

v17 payload / checksumは不変です。v18はpublish済みimmutable gameplay contractであり、
船、探索、海底財宝、視界、海洋系秘書skillなどの将来候補はv18を書き換えずv19以降で扱います。

repositoryへ海底都市の実画像は追加していません。期待pathは
`/srv/hakoniwa-assets/tiles/undersea-city.gif`です。production asset実体の確認が必要な場合はOwnerまたは許可されたread-only確認を正本にしてください。

PR #98 final head `d52a415ea22f8cf06be66ccde9f9ea667b743fe1`はQuality run
`33022126772`を通過し、Codex exact-head reviewはmajor issueなし、P2 maintenance forecastと
P1 disguised command-preview leakはいずれも修正済み、unresolved review threadは0です。

PR #99 final head `8a976d1b03a57218efcf268176ffabbdca61e979`はQuality run
`33025809349`を通過し、Codex exact-head reviewはmajor issueなし、unresolved review threadは0。
`main`へのmerge commitは`c91e66d330cd4f8ae5f5297a794fd27582e4ddcf`です。
Ownerはその後、productionがver 2.8.0 / Ruleset v18であることを確認しています。

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

### 2.7.0

- current Rulesetはv17
- v16は直前supported migration sourceとしてexact immutable保持
- 新gameplayはv17だけへ追加
- application versionとRuleset gameplay identityは別にfinalize可能

### 2.8.0

- current Rulesetはv18
- v17は直前supported migration sourceとしてexact immutable保持
- v17→v18 upgradeはqueued definitionをstable keyでrebindし、request provenanceを保持
- v18は公開後immutableで、後続gameplay contractはv19以降へ追加

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

production canonical pathから外れた旧serviceを直接試験して、
「同じcontractを別engineで保証」しません。
代表contract testはproductionで実際に使われるcanonical execution pathを所有します。

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
application main: 2.8.0
main Ruleset: hakoniwa-2s-plus-v18 / 18
Ruleset checksum: 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b
PR #99 merge commit: c91e66d330cd4f8ae5f5297a794fd27582e4ddcf

production: Owner確認済み 2.8.0 / Ruleset v18

supported migration source:
  hakoniwa-2s-plus-v17 / 17
  checksum: 8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3

next development line:
  release/3.0.0-alpha Underground foundation
  roadmap: docs/roadmap/3.0.0-alpha-underground.md
  PR: #101 (open; player-inaccessible foundation)

temporarily paused, not discarded:
  ver 2.9.0 Item拡張（Ownerの具体案待ち）

implemented Item index:
  product/docs/items/current-item-catalog.md
```

exact `main` HEADはdocs-only更新でも進むため、作業開始時に必ずGitHubで再確認してください。
surfaceの新gameplayはimmutable v18を書き換えずv19以降へ追加します。
Undergroundはsurface Rulesetと分離した`secretary-underground-alpha-v0` laboratory identityを使い、
UG-02 / UG-03 / UG-04のRequired before gateへ到達するまでpersistence、player access、surface bridgeを実装しません。

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

disguised `seabed_base` / `undersea_city`のcommand previewは、非ownerに見える
neutral sea stateからown queued command projectionを重ねます。実Turnのraw locked
revalidationはこのpresentation境界と分離します。

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

## 7.1 release/3.0.0-alpha Underground development line

ver 2.8.0 release / production移行は完了済みです。

active roadmapは[`3.0.0-alpha Underground`](../../../docs/roadmap/3.0.0-alpha-underground.md)、
foundationは[PR #101](https://github.com/Mamiki765/hakoniwa-world/pull/101)です。
PR #101はpure combat laboratoryだけで、player-facing地底、schema、migration、API、UI、探索map、party、market、
facility、Tutorial、surface rewardを実装しません。surface application 2.8.0 / Ruleset v18 / productionは不変です。

次の実装前に`docs/open-questions.md`のgateを確認してください。

- UG-02: persistence ownership / schema / run / retry / lock / upgrade
- UG-03: first playable / Tutorial / progression / defeat / API / UI / security / version
- UG-04: borrowed party / market / facility / surface benefit bridge

first player-facing Tutorialはlaboratory standard benchmarkと分離し、正常操作またはbuilt-in AIで100%勝利する
deterministic教育encounterとして後続sliceで扱います。alpha-v0の4scenario win rateをTutorial difficultyへ流用しません。

## 7.2 ver 2.9.0 Item拡張の一時保留

ver 2.9.0 Item拡張は破棄していません。Ownerの具体案待ちとして一時保留しています。
再開時は現行Itemを`product/docs/items/current-item-catalog.md`で確認し、
新しいItem / rarity / acquisition path / effectのOwner決定を既存実装と混同しないよう整理してください。

現時点でHigh Quality / Artifactなどの将来rarityは未実装です。
新gameplayを追加する場合はimmutable v18を変更せずv19以降へ載せます。

船、港、探索船、海賊船、宝船、海底財宝、視界、海洋系秘書skillなどは将来候補であり、
Ownerが改めて仕様を確定するまでver 2.9.0の確定scopeとして扱いません。

## 7.3 handoff maintenance

この文書はOwner / Web版ChatGPT development-advisor workflowが節目で更新します。
Codex / implementation agentは通常のfeature実装やreview対応では変更しません。

次回更新候補は、PR #101のmerge、UG-02以降のOwner決定、ver 2.9.0の再開、またはOwnerが明示的に引継ぎ更新を求めた時です。

---

# 8. 次の担当者の開始手順

1. `AGENTS.md`
2. `product/docs/handoffs/development-history-and-current-handoff.md`
3. documentation guide `docs/README.md`
4. `docs/open-questions.md`
5. guideに従ってtask-specificなcurrent Ruleset authoring / architecture / operations docsとADR / decisionを選ぶ
6. Underground作業なら`docs/roadmap/3.0.0-alpha-underground.md`と`docs/architecture/underground-combat-laboratory.md`
7. Item作業ならcurrent index `product/docs/items/current-item-catalog.md`
8. `main`のcurrent HEAD、open PR、CI、review thread
9. raw sourceが必要な仕様だけ`_references/`をread-only監査

全documentationを無差別に読むのではなく、`docs/README.md`の探索停止条件に従う。
historical implementation、audit、future文書は、その経緯・比較・将来案がtask scopeに入る場合だけ読む。

現在の基準点:

```text
application: 2.8.0
current Ruleset:
  key: hakoniwa-2s-plus-v18
  version: 18
  checksum: 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b

supported migration source:
  hakoniwa-2s-plus-v17 / 17
  checksum: 8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3

release evidence:
  PR #99 merged
  merge commit: c91e66d330cd4f8ae5f5297a794fd27582e4ddcf

production:
  Owner確認済み 2.8.0 / Ruleset v18

next development direction:
  release/3.0.0-alpha Underground development line
  foundation PR #101 (open; player-inaccessible pure laboratory)
  roadmap: docs/roadmap/3.0.0-alpha-underground.md

temporarily paused, not discarded:
  ver 2.9.0 Item拡張（Ownerの具体案待ち）
```

exact SHAは作業開始時に必ずGitHubで再確認してください。
PR #97 / #98 / #99は2.8.0 releaseとして完了済みです。
repository stateだけからproductionの細部を推測せず、必要ならOwner確認または許可されたread-only確認を使ってください。

---

# 9. 次のAI / Codexへ渡す開始プロンプト

```text
hakoniwa-worldの統合handoffです。

まずAGENTS.mdとこのhandoffを読み、最新GitHubをread-onlyで確認してください。
Item関連の作業ならproduct/docs/items/current-item-catalog.mdも読んでください。
正本は最新のreview済みコード、immutable Ruleset、ADR/decision、運用文書、Ownerの最新明示決定です。
古いhandoffのSHAや当時TODO、将来候補を現在仕様として復活させないでください。

main applicationはver 2.8.0、current Rulesetは
hakoniwa-2s-plus-v18 / version 18 / checksum
40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8bです。
直前supported migration sourceはexact v17 / checksum
8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3です。

PR #99 release/2.8.0 -> mainはmerge済みで、merge commitは
c91e66d330cd4f8ae5f5297a794fd27582e4ddcfです。
exact main HEADはdocs-only commit等で進むため、作業開始時にGitHubで再確認してください。

productionはOwner確認済みver 2.8.0 / Ruleset v18です。
この確認をrepository stateから推測で置き換えず、productionの細部が必要ならOwner明示確認または
許可されたread-only確認を使ってください。

active development lineはrelease/3.0.0-alphaです。
docs/roadmap/3.0.0-alpha-underground.mdとfoundation PR #101を確認してください。
現時点の地底はplayerから到達できないpure combat laboratoryで、Turn、World、Nation、MapCell、
surface Ruleset、schema、productionへ依存しません。Underground testは独立suite、10,000-seed runはmanualです。
UG-02 / UG-03 / UG-04のgateへ到達したらOwner decisionを得てください。

ver 2.9.0 Item拡張は破棄ではなく、Ownerの具体案待ちとして一時保留です。
再開時はproduct/docs/items/current-item-catalog.mdを正本にし、
High Quality / Artifactなどの将来rarityや未確定Item案を現行仕様と混同しないでください。
surfaceの新しいgameplay contractはimmutable v18を書き換えずv19以降へ追加してください。

船、港、探索、海賊、宝船、海底財宝、視界、海洋系秘書skillは将来候補です。
Ownerが改めてscopeとして確定するまで実装対象に含めないでください。

product/docs/handoffs/development-history-and-current-handoff.mdはCodex / implementation agentにはread-onlyです。
Ownerがhandoff更新そのものを明示した場合だけ変更してください。

production migrationについてはRepository state is not evidence of production migration stateを守り、
Ownerがproduction baselineを明示しない限りexisting migrationをmodify/delete/squash/rebaselineしないでください。

Ownerの明示指示なしにmain merge、production接続、migration、deploy、backup、cron操作を行わないでください。
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
  current-only runtime・historical executable runtime retirement
  fresh install final-v16 rebaseline・already-current v16 business-data no-op
  AGENTS guardrails・Trading Post bidder/status/item-effect presentation
  PR #93でmainへmerge、Owner確認でrelease済み

2.7.0:
  theme options / dark mode
  Regular・Cursed Secretary Items
  three advanced bows / Collar
  monster Item drops
  Trading Post settlement visibility
  demographic skills / population_high_water
  queue tooltip integration
  canonical Bow execution unification

2.8.0 / v18:
  resource forecast / workforce presentation / 12-turn Nation events
  undersea city / disguised presentation / maintenance / famine / fire
  exact v17 -> v18 forward migration
  terrain-destruction and KARMA +3 / foreign wasteland KARMA +1
  application 2.8.0 finalized
  PR #99でmainへmerge
  Owner確認でproduction 2.8.0 / Ruleset v18

next:
  active: release/3.0.0-alpha Underground development line
  foundation: PR #101 / player-inaccessible pure combat laboratory
  surface production baseline: application 2.8.0 / Ruleset v18 unchanged
  tests: Underground suite分離、World / Nation / MapCell / TurnRun / DB fixtureなし
  manual: 10,000-seed balance experimentは通常CI外
  paused, not discarded: ver 2.9.0 Item拡張（Ownerの具体案待ち）
  implemented Item index: product/docs/items/current-item-catalog.md
  future surface gameplayはv19以降
```

この文書の役割は、失われた会話を推測で埋めることではありません。

```text
何が確定したか
何が後に変更されたか
何が現在も候補にすぎないか
次にどこから再開すべきか
```

を一つの場所から追えるようにすることです。
