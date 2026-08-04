# 新作の設計判断と未解決事項

本書は実装前に確認する設計索引である。実装済みの判断と、現在作業を止める未決事項を分離する。

- `Status: Decided`: 方針と実装境界を決定済み。記載した`Decision record`を正本とする。
- `Status: Open`: `Required before`に到達する前にowner判断が必要。
- `Status: Deferred`: 現在のroadmapへ含めず、別のowner承認まで拡張境界だけを維持する。

`Open`または`Deferred`を実装中の仮定で確定してはならない。関連する選択肢、影響、provenanceを報告して判断を得る。

## Current blocking gates

| Milestone | Blocking Open IDs | 実装境界 |
|---|---|---|
| monster | MONSTER-02、MONSTER-03 | 怪獣実装前にsource-derived ruleとterrain-changing disaster相互作用を確定する。MONSTER-01は決定済み。 |
| missile / commands / combat | B-03、B-05、B-07、B-10、B-12、B-13 | 対応するmissile、attack、territory、dormancy機能の実装前にだけ停止する。怪獣単体PRの一律blockerではない。 |
| lifecycle / automatic turn operations | T-02、D-02 | lifecycle Job実装前、またはproduction automatic retry / cron enablement前に確定する。 |
| public release | RELEASE-01、AUTH-05、B-14、D-03、D-04、D-05、D-07 | release-freezeと本公開準備に入る前に確定する。 |
| post-MVP deferred | AUTH-06〜AUTH-09、B-08、B-15、D-06、D-08、C-02、C-04、E-01、E-02、E-04〜E-09 | 別のowner-approved roadmapまで実装しない。 |

## Decided architecture

以下は現在の実装を止めるOpen gateではない。長い契約はDecision recordへ集約し、本書では現在有効な要点だけを示す。

### A-02 チャンク辺長

- Status: Decided
- Implemented: Yes
- Decision: `chunk_size = 16`。負座標では数学的な`floorDiv`と`floorMod`を使う。
- Decision record: `docs/architecture/chunk-storage.md`、`docs/decisions/ADR-0003-hex-coordinate-system.md`

### A-03 DB製品

- Status: Decided
- Implemented: Yes
- Decision: 箱庭専用PostgreSQLを使用し、Nextcloud用MariaDBと分離する。
- Decision record: `docs/architecture/target-architecture.md`

### A-04 国家登録地点

- Status: Decided
- Implemented: Yes
- Decision: serverが安全な空き地点へ自動配置し、同時登録をtransactionとlockで直列化する。
- Decision record: `docs/architecture/registration-and-world-expansion.md`

### A-05 初期領土と首都間距離

- Status: Decided
- Implemented: Yes
- Decision: 初期領土はCapitalからgrid distance 2以内、Capital間最低距離は12を現在のruleset既定値とする。
- Decision record: `docs/architecture/capital-and-territory.md`、`docs/architecture/registration-and-world-expansion.md`

### A-09 rulesetのpre-release境界

- Status: Decided
- Implemented: Yes
- Decision: 各Worldは不変の`ruleset_version_id`を参照し、公開済みruleset payloadを上書きしない。pre-release runtimeは最新active rulesetだけを保証し、historical Worldはread-only、mutationは`reset_required`とする。
- Implementation provenance: PR16でpre-release reset/runtime例外を記録し、PR17で`CurrentRulesetGuard`とlatest-only runtimeを実装し、PR19まで同じ境界を維持した。
- Rebaseline plan: 怪獣と残るcommand roadmapが完了した後、本公開準備へ入るrelease-freeze PRでcanonical fresh schemaを再構成する。固定PR番号へ結び付けない。
- Decision record: `docs/architecture/configuration-management.md`、`docs/architecture/target-architecture.md`

### A-10 認証方式

- Status: Decided
- Implemented: Yes
- Decision: Discord/Google identity、User、Nationを分離し、provider email一致では自動統合しない。
- Decision record: `docs/architecture/authentication-and-identities.md`、`docs/decisions/ADR-0005-authentication-identities.md`

### A-11 初期生成範囲

- Status: Decided
- Implemented: Yes
- Decision: 初期地上Worldは`x = 0..59`、`y = 0..59`の3,600 cellsとする。
- Decision record: `docs/architecture/world-and-map-space.md`、`docs/decisions/ADR-0003-hex-coordinate-system.md`

### C-05 API版管理

- Status: Decided
- Implemented: Yes
- Decision: URL prefixは`/api/v1`とする。
- Decision record: `docs/architecture/ui-and-map-loading.md`

### C-06 chunk応答形式

- Status: Decided
- Implemented: Yes
- Decision: readable JSONをchunk単位で返し、canonical absolute x/yを含める。
- Decision record: `docs/architecture/ui-and-map-loading.md`

### AUTH-01 Discord用OAuth adapter

- Status: Decided
- Implemented: Yes
- Decision: Laravel Socialite 5.29.0とSocialiteProviders/Discord 4.2.0を使用し、Discordは`identify`、Googleは`openid profile`だけを要求する。
- Decision record: `docs/decisions/ADR-0006-oauth-packages.md`

### AUTH-02 SPAのsession認証

- Status: Decided
- Implemented: Yes
- Decision: 同一originのLaravel session、OAuth state、CSRF、callback後のsession regenerationを使用し、Sanctumは追加しない。
- Decision record: `docs/decisions/ADR-0006-oauth-packages.md`

### AUTH-03 VueとLaravelの配信origin

- Status: Decided
- Implemented: Yes
- Decision: production buildしたVueをLaravelの`public/`から同一origin配信する。
- Decision record: `docs/decisions/ADR-0006-oauth-packages.md`

### AUTH-04 ログイン・連携後のredirect UX

- Status: Decided
- Implemented: Yes
- Decision: login/link intentをsessionへ分離保存し、結果status付きでtopへ戻す。
- Decision record: `docs/architecture/authentication-and-identities.md`

### B-01 Capital人口契約

- Status: Decided
- Implemented: Yes
- Decision: initial populationは1,000人、地図上に存在するCapitalのminimum populationは100人、ordinary growth capは25,000人とする。
- Decision record: `docs/architecture/capital-and-territory.md`、`product/docs/disaster-oil-audit-pr15.md`

### B-06 初期Territoryへ含められる地形

- Status: Decided
- Implemented: Yes
- Decision: 初期島のdistance 2以内にある陸地19 cellsだけを所有させ、範囲外の生成陸地は中立のまま残す。
- Decision record: `docs/architecture/capital-and-territory.md`

### B-18 登録候補地点の評価

- Status: Decided
- Implemented: Yes
- Decision: distance 5以内の91 cellsが生成済みの海・無所有・施設なしで、Capital間距離12以上の候補を使う。既存Capitalから遠い順、同値はy/x昇順とする。
- Decision record: `docs/architecture/registration-and-world-expansion.md`

### C-01 地図描画方式

- Status: Decided
- Implemented: Yes
- Decision: DOM/CSS rendererを採用し、API、map state、projection、rendererを分離する。
- Decision record: `docs/architecture/ui-and-map-loading.md`

### C-03 霧・未発見領域

- Status: Decided
- Implemented: Yes
- Decision: 現行rulesetに霧はない。秘密facilityはserver presenterでviewer-safe表現へ置換する。
- Decision record: `docs/architecture/ui-and-map-loading.md`

### C-07 国際化

- Status: Decided
- Implemented: Yes
- Decision: UIは日本語、新規source・DB text・APIはUTF-8とする。
- Decision record: `docs/architecture/ui-and-map-loading.md`

### C-08 アクセシビリティ

- Status: Decided
- Implemented: Yes
- Decision: 六方向keyboard移動、選択cellの通常HTML text、owner Nation名/番号表示を最低要件とする。
- Decision record: `docs/architecture/ui-and-map-loading.md`

### A-06 ターンの確定順序

- Status: Decided
- Implemented: Yes
- Decision: phase固有のrandomized sequential causalityを維持し、暗黙のsimultaneous resolutionを導入しない。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### A-07 1ターンのtransaction規模

- Status: Decided
- Implemented: Yes
- Decision: 同じWorldの1 turnを1 PostgreSQL transactionとWorld advisory lockで処理し、外部I/Oはtransaction外へ分離する。
- Decision record: `docs/architecture/turn-runner-scaffold.md`、`docs/architecture/turn-pipeline.md`

### POP-01 population random rangeのcanonical化

- Status: Decided
- Implemented: Yes
- Decision: legacyの100人単位rangeをcanonical 1人単位のinclusive integer rangeへ変換する。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### B-09 災害抽選単位

- Status: Decided
- Implemented: Yes
- Decision: World単位、successful command単位、cell単位の抽選を混在させず、versioned deterministic streamで既存契約どおり処理する。
- Decision record: `product/docs/disaster-oil-audit-pr15.md`、`docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### DISASTER-01 Nation単位の地盤沈下

- Status: Decided
- Implemented: Yes
- Decision: active Nationごとに所有陸地101 cells以上で2/100を独立判定し、World事前snapshotから海岸変化を確定する。
- Decision record: `product/docs/land-subsidence-audit-pr18.md`

### T-01 乱数seedと再現方式

- Status: Decided
- Implemented: Yes
- Decision: private 256-bit master seedとHMAC-SHA-256派生のversioned counter streamを使い、same-run retryでseedを再利用する。
- Decision record: `docs/architecture/turn-randomness.md`

### A-08 コマンド件数・順序・予約

- Status: Decided
- Implemented: Yes
- Decision: queue limitはruleset値、positionは1始まり、optimistic concurrencyとrequest keyを使用し、資金・資源は実行時に再検証する。
- Decision record: `docs/architecture/roadmap-pr2-systems.md`

### RES-01 food生産量のton換算

- Status: Decided
- Implemented: Yes
- Decision: legacy food 1 unitを100 tons、farm scale 1の生産を1,000 tonsへ変換し、非負整数切捨てを維持する。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### CMD-01 箱庭諸島2＋コマンドの採否

- Status: Decided
- Implemented: Yes
- Decision: 整地、地ならし、埋め立て、掘削、農場、工場、採掘場を別々のversioned commandとして採用する。
- Decision record: `docs/architecture/roadmap-pr2-systems.md`、`product/docs/command-audit-pr14.md`

### CMD-02 地ならし由来の即時地震

- Status: Decided
- Implemented: Yes
- Decision: successful `land_level`ごとに5/2000をcommand内で抽選し、通常global earthquakeと独立して完結させる。
- Decision record: `product/docs/disaster-oil-audit-pr15.md`、`docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### B-16 settlement_seed

- Status: Decided
- Implemented: Yes
- Decision: randomized sequential cell processingで村発生、人口成長、飢餓減少を処理する。Capitalはidentityを維持し、minimum 100、ordinary growth cap 25,000を適用する。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`、`docs/architecture/capital-and-territory.md`

### B-17 緊急農場

- Status: Decided
- Implemented: Not applicable; the command is intentionally excluded.
- Decision: emergency farm commandは現行MVPへ導入しない。automatic financeと明示的なabandonment/recreationを立て直し境界とする。
- Decision record: `docs/architecture/roadmap-pr2-systems.md`

### E-03 追加資源

- Status: Decided
- Implemented: Yes
- Decision: catalog/rulesetから`industrial_goods`と`minerals`を追加し、PR19時点で双方のunit、capacity、production、sale、overflow契約を実装済みとする。
- Decision record: `product/docs/resource-profile-audit-pr19.md`、`docs/future-systems/resources.md`

## Monster/combat gates

### MONSTER-01 怪獣actorとoccupancy

- Status: Decided
- Implemented: No
- Decision: 怪獣はterrain/facilityへ埋め込まず、独立したactorと別occupancy layerを持つ。最初の怪獣PRは1 actor = 1 cellとするが、将来のmulti-cell footprintを禁止しない。Capital cellのoccupancyは禁止する。
- Decision record: `docs/decisions/ADR-0007-monster-actor-and-occupancy.md`

### MONSTER-02 source-derived怪獣rule

- Status: Open
- Required before: 怪獣実装前
- Open decision: source-derived movement、acted flags、ghost、hardening parity、spawn、HP、rewardの新作契約を怪獣実装前source auditで確定する。legacy cell encodingは採用しない。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`、`docs/decisions/ADR-0002-reference-integration-policy.md`

### MONSTER-03 terrain-changing disasterとの相互作用

- Status: Open
- Required before: 怪獣実装前
- Open decision: monster occupancy中のcellへterrain-changing disasterが作用する場合に、occupancy維持、移動/退去、damage/消滅、event順序のどれを採るかowner判断を得る。無効なterrain/occupancy組合せを暗黙に作らない。
- Decision record: `docs/decisions/ADR-0007-monster-actor-and-occupancy.md`、`product/docs/disaster-oil-audit-pr15.md`

### B-02 Capitalへの複数被害

- Status: Decided
- Implemented: Partially; disaster path is implemented, combat path is not.
- Decision: 各event開始時点の現在人口へ割合damageを逐次適用し、各event後にfloorとminimum 100を適用する。
- Decision record: `docs/architecture/capital-and-territory.md`、`product/docs/disaster-oil-audit-pr15.md`

### B-19 Capitalと怪獣・戦闘damage

- Status: Decided
- Implemented: Boundary only
- Decision: 怪獣はCapitalへ侵入できない。combat damageでもCapital identityを維持し、荒地化相当10%、一段階掘削相当30%、深海化相当90%を逐次適用する。
- Decision record: `docs/architecture/capital-and-territory.md`、`product/docs/disaster-oil-audit-pr15.md`

### B-03 Capital機能停止と復旧

- Status: Open
- Required before: Capital operational damageを含む戦闘実装前
- Open decision: 自然回復、復旧command、生産連動、時限停止の組合せを決める。
- Decision record: `docs/architecture/capital-and-territory.md`

### B-05 防壁都市

- Status: Open
- Required before: 防壁または占領抵抗を含む戦闘実装前
- Open decision: 周辺抵抗、倍率、耐久、重複、攻略方法を決める。
- Decision record: `docs/architecture/capital-and-territory.md`

### B-07 国境影響の解決

- Status: Open
- Required before: territory influence実装前
- Open decision: source-derived逐次因果を前提に、exact algorithm、同値競合、tie handlingを確定する。暗黙のsimultaneous resolutionは採用しない。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-world-map.md`

### MISSILE-01 launch intentと基地単位解決

- Status: Decided
- Implemented: Boundary only
- Decision: commandはturn-scoped launch intentを登録し、randomized cell processing中に各基地が自身のlevel、残数、資金、射程、現在状態を再検証して発射する。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### B-10 ミサイル可視性

- Status: Open
- Required before: ミサイルcommand実装前
- Open decision: normal missileの発射Nation公開、ST missile匿名を方向性とし、target、impact、damage、failureのexact public/private payloadをsource auditで確定する。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### B-12 dormant国家への攻撃詳細

- Status: Open
- Required before: dormant Nationを対象にするcombat実装前
- Open decision: `dormant_contestable`の施設・防壁処理と、怪獣討伐例外の実行契約を決める。
- Decision record: `docs/decisions/ADR-0004-nation-dormancy-lifecycle.md`

### B-13 Capital周辺の占領保護

- Status: Open
- Required before: dormant territory占領実装前
- Open decision: `dormant_contestable`でCapitalから何ringを保護するか決める。
- Decision record: `docs/decisions/ADR-0004-nation-dormancy-lifecycle.md`、`docs/architecture/capital-and-territory.md`

## Release gates

### RELEASE-01 public-release data migrationとruntime互換性

- Status: Open
- Required before: release-freeze canonical rebaselineと本公開準備前
- Open decision: 正式なdata migration方針、runtime backward compatibility範囲、failed/pending runのrelease後の扱い、historical audit verificationを確定する。
- Decision record: `docs/architecture/configuration-management.md`、`product/docs/resource-profile-audit-pr19.md`

### AUTH-05 provider障害時の復旧

- Status: Open
- Required before: 本番公開前
- Open decision: provider停止時の案内、既存session、再試行、別identity loginの運用を決める。
- Decision record: `docs/architecture/authentication-and-identities.md`

### T-02 休眠状態遷移Job

- Status: Open
- Required before: 休眠状態遷移実装前
- Open decision: scheduler、World lock、turnとの直列化、batch checkpointを確定する。
- Decision record: `docs/decisions/ADR-0004-nation-dormancy-lifecycle.md`、`docs/architecture/nation-lifecycle.md`

### D-02 turn失敗時の再試行

- Status: Open
- Required before: production automatic retryまたはproduction cron enablement前
- Implemented checkpoint: game state rollbackと、same run / target turn / ruleset / seedによる明示的な手動retryは実装済み。
- Open decision: bounded automatic retryの回数、backoff、retryable error分類、stale-running recovery、上限到達後の保留状態とoperator通知経路を決める。
- Decision record: `docs/architecture/turn-runner-scaffold.md`、`docs/operations/turn-cron.md`

### B-14 明示的放棄の安全策

- Status: Open
- Required before: 本番公開前
- Open decision: 再認証、待機/取消期間、確認入力、cooldown、監査を決める。
- Decision record: `docs/decisions/ADR-0004-nation-dormancy-lifecycle.md`

### D-01 scheduler・queue基盤

- Status: Decided
- Implemented: Application command and lock boundary only; production registration is not enabled.
- Decision: Asia/Tokyoのhost cronをthin triggerとし、DB/application lockを正本、host `flock`を任意の一次filterとする。
- Decision record: `docs/operations/turn-cron.md`

### D-03 ruleset公開承認

- Status: Open
- Required before: 本番公開前
- Open decision: 単独管理者承認か二者承認かを決める。
- Decision record: `docs/architecture/configuration-management.md`

### D-04 backupのRPO・RTO

- Status: Open
- Required before: 本番公開前
- Open decision: PostgreSQL継続backup、snapshot、復旧演習、目標RPO/RTOを決める。
- Decision record: `docs/operations/database-backup-and-restore.md`

### D-05 event・log保持期間

- Status: Open
- Required before: 本番公開前
- Open decision: player表示、監査、分析の保持期間と削除境界を分離して決める。
- Decision record: `docs/future-systems/event-log-and-notifications.md`

### D-07 moderation

- Status: Open
- Required before: 本番公開前
- Implemented minimum: 島主名1–30文字、comment 0–100文字のsingle-line plain text、control/format character拒否、OAuth表示名の非流用、owner-only更新と`nation.profile_updated` auditはPR19で実装済み。
- Open decision: 国家名・島主名・commentの禁止語、なりすまし、通報、hide/freeze/unfreeze、appeal、対応期限、operator authorization、moderation log保持だけをremaining public-release gateとする。
- Decision record: `product/docs/resource-profile-audit-pr19.md`、`docs/architecture/public-lobby-and-island-dashboard.md`

## Deferred post-MVP

以下はOpen gateではない。対応する別roadmapがowner承認されるまで実装しない。

### AUTH-06 管理者用緊急復旧

- Status: Deferred
- Activation gate: post-MVP account recovery roadmap
- Boundary: identity差替え、本人確認、二者承認、auditを設計してから実装する。

### AUTH-07 account merge

- Status: Deferred
- Activation gate: post-MVP account merge roadmap
- Boundary: Nation、resource、history、identityのconflict policyを決めるまでUser mergeを実装しない。

### AUTH-08 ログイン手段の解除UI

- Status: Deferred
- Activation gate: post-MVP identity management roadmap
- Boundary: identityが2個以上なら解除可能、最後の1個は解除不可とする。

### AUTH-09 追加providerとローカル認証

- Status: Deferred
- Activation gate: post-MVP authentication roadmap
- Boundary: provider adapter境界を使い、`users`へprovider固有列を追加しない。

### B-08 初期保護

- Status: Deferred
- Activation gate: attack/occupation command roadmap
- Boundary: protection期間、敵対行為、解除条件を決めるまで国内commandへ保護を追加しない。

### B-15 再入植

- Status: Deferred
- Activation gate: post-MVP re-colonization roadmap
- Boundary: 初期resource、旧Nation名、identity、ranking、保護期間を決める。

### D-06 通知dead letter

- Status: Deferred
- Activation gate: notification outbox roadmap
- Boundary: retry、discard、auditを決めてから実装する。

### D-08 複数World

- Status: Deferred
- Activation gate: multi-World roadmap
- Boundary: Worldごとのruleset、season、終了/archiveを決める。

### C-02 turn更新通知

- Status: Deferred
- Activation gate: post-MVP notification roadmap
- Boundary: polling、WebSocket、outbox deliveryを同じ通知設計で比較する。

### C-04 低zoom集約

- Status: Deferred
- Activation gate: measured map-performance roadmap
- Boundary: World規模とviewport計測後にaggregation tileとcache契約を決める。

### E-01 地下

- Status: Deferred
- Activation gate: underground layer roadmap
- Boundary: 地上との座標関係、portal、ownership、visibilityを決める。

### E-02 宇宙

- Status: Deferred
- Activation gate: space layer roadmap
- Boundary: hex planeかnode graphかを決める。

### E-04 Modifier

- Status: Deferred
- Activation gate: shared modifier roadmap
- Boundary: add、multiply、cap、priority、cycle preventionを共通設計する。

### E-05 研究・熟練度

- Status: Deferred
- Activation gate: research/proficiency roadmap
- Boundary: Nation、facility、command、Userのownershipを決める。

### E-06 隕石itemと対象指定

- Status: Deferred
- Activation gate: meteor item roadmap
- Boundary: cell、range、Nation、layer、eventへのtarget contractを決める。

### E-07 Mariachang連携

- Status: Deferred
- Activation gate: separately approved integration roadmap
- Boundary: authentication、data ownership、one-way reference、failure isolationを決める。

### E-08 season

- Status: Deferred
- Activation gate: season roadmap
- Boundary: coordinate、Nation、researchのcarry-overを決める。

### E-09 binary map API

- Status: Deferred
- Activation gate: measured API-performance roadmap
- Boundary: JSON API計測後にcompact array、binary、compressionの必要性を判断する。

## Historical initial MVP

以下は2026-07-26に開始した最初のMVP縦切りのhistorical scopeであり、現在の実装承認、未決gate、今後のroadmapを表さない。provenanceは`docs/requirements/initial-game-direction.md`と`docs/architecture/target-architecture.md`に残す。

1. Laravel project作成。
2. PostgreSQL接続。
3. Discord OAuthとGoogle OAuthによるlogin。
4. 1 Userへの複数authentication identityの明示link。
5. 共有地上Worldの初期生成。
6. Nation作成とserverによる空き地点への自動配置。
7. Capitalと初期Territoryの生成。
8. Capital周辺のchunk取得API。
9. Vueによるmap表示。

当時の縦切りではturn、command、production/consumption、disaster、combat、territory change、automatic development、dormancy Job、notification deliveryを含めなかった。その後のroadmap PRで実装された項目を、現在も未実装であることの根拠にしてはならない。

## Decision record operation

設計判断を更新するときは、選択肢と採否理由、変更するinvariant/API/schema/test、rulesetかcode releaseか、既存Worldの移行、観測指標と見直し条件をDecision recordへ残す。参考実装の観察と新作のowner decisionを分離し、`_references/`はread-onlyとする。
