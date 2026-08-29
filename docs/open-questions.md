# 新作の設計判断と未解決事項

本書は実装前に確認する設計索引である。実装済みの判断と、現在作業を止める未決事項を分離する。

- `Status: Decided`: 方針と実装境界を決定済み。記載した`Decision record`を正本とする。
- `Status: Open`: `Required before`に到達する前にowner判断が必要。
- `Status: Deferred`: 現在のroadmapへ含めず、別のowner承認まで拡張境界だけを維持する。

`Open`または`Deferred`を実装中の仮定で確定してはならない。関連する選択肢、影響、provenanceを報告して判断を得る。

## Current blocking gates

| Milestone | Blocking Open IDs | 実装境界 |
|---|---|---|
| monster | — | MONSTER-01〜04はPR21、AWARD-01はver 1.3.0のowner decisionで決定・実装済み。 |
| missile / commands / combat | B-03、B-05、B-12、B-13 | Capital operational damage、防壁・占領抵抗、またはv12のdistance 2休眠保護を変更する将来combatを実装する前に停止する。ver 2.4.0のKARMA/recoveryはADR-0015で決定済み。 |
| lifecycle / automatic turn operations | T-02 | ver 2.4.0はADR-0014/ADR-0015によりdormant/recoveryを専用Jobではなくofficial Turn開始/終端へ統合する。将来専用scheduler/batchへ変更する前に停止し、production cronと手動retry境界はD-02を維持する。 |
| public release | — | RELEASE-01、AUTH-05、B-14、D-03、D-04、D-05、D-07はPR23 owner decisionで決定済み。 |
| underground alpha | UG-03、UG-04 | E-01/UG-01/UG-02によりpure combat laboratoryとSecretary-owned persistence foundationを実装する。player accessとsurface bridgeは各gateで停止する。 |
| post-MVP deferred | AUTH-06〜AUTH-09、B-08、D-06、D-08、C-02、C-04、E-02、E-04〜E-09 | 別のowner-approved roadmapまで実装しない。 |

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
- Rebaseline: PR23で`hakoniwa-2s-plus-v1`をcanonical rulesetとし、go-live後はpre-release reset例外を終了する。以後のdata保護契約はRELEASE-01を正本とする。
- Decision record: `docs/architecture/configuration-management.md`、`docs/architecture/target-architecture.md`、`docs/decisions/ADR-0008-first-production-release.md`

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
- Decision: randomized sequential cell processingで村発生、人口成長、飢餓減少を処理する。`hakoniwa-2s-plus-v5`では海際度を廃止し、通常settlementを位置不問の100〜1,000人増加・通常上限10,000人、誘致を上限前100〜3,000人・以後100〜300人・最終上限20,000人とする。Capitalはidentityを維持し、minimum 100、ordinary growth cap 25,000を適用する。
- Decision record: `docs/architecture/capital-and-territory.md`、`product/docs/ver-1.5.0-beta3-sea-edge-removal.md`

### B-17 緊急農場

- Status: Decided
- Implemented: ver 2.4.0 dormant entry/heartbeat recovery only. Player command remains excluded.
- Decision: 汎用のemergency farm commandは導入しない。dormant Nationのfarm capacityが0の場合だけ、首都distance 2以内のallowlist候補から`distance, y, x`順・乱数なしで最小農場を1つ作る。
- Decision record: `docs/architecture/roadmap-pr2-systems.md`、`docs/decisions/ADR-0014-ver-2.4.0-nation-dormancy.md`

### E-03 追加資源

- Status: Decided
- Implemented: Yes
- Decision: catalog/rulesetから`industrial_goods`と`minerals`を追加し、PR19時点で双方のunit、capacity、production、sale、overflow契約を実装済みとする。
- Decision record: `product/docs/resource-profile-audit-pr19.md`、`docs/future-systems/resources.md`

### E-01 地下

- Status: Decided
- Implemented: Partially; alpha-v0 pure combat laboratory、Secretary-owned persistence foundation、PR103のplayer-access前段となるUnderground runtime backend。first-player accessとsurface bridgeは未実装。
- Decision: 地下roadmapを`release/3.0.0-alpha`として開始する。Turn非依存の任意side gameをmodular monolith内の独立domainとして育てる。PR1はpure combat laboratory、PR102はSecretary-ownedな地下箱庭layer entitlementの最小persistence、PR103はcanonical pure combat coreを再利用するatomic auto battle、通常探索・試練run、progression、報酬settlement、履歴、cooldown、idempotencyのbackend foundationを実装する。player-facing API/UI、Tutorial、security/rate-limitのacceptanceとsurface bridgeは各gateで決める。
- Decision record: `docs/roadmap/3.0.0-alpha-underground.md`、`docs/architecture/underground-combat-laboratory.md`

## Underground RPG gates

### UG-01 pure combat laboratory contract

- Status: Decided
- Implemented: Yes
- Decision: `secretary-underground-alpha-v0`はplayer-inaccessible、DB-free、surface-independentな1対1laboratoryとする。恒久gateはdeterminism、same-input/seed replay、合法状態、abnormal=0、max-round/stalemate、report再現性、scenario相対semanticとする。10,000-seed win rateとprovisional rangeは観測値でありbalance targetではない。Tutorialは別fixtureとし、PR1では実装しない。
- Decision record: `docs/architecture/underground-combat-laboratory.md`、`docs/roadmap/3.0.0-alpha-underground.md`

### UG-02 Underground persistenceとdata ownership

- Status: Decided
- Implemented: Yes; PR102 persistence foundation only.
- Decision: 地底RPGの恒久進行、将来のcombat/exploration progression、装備、探索基地、地下箱庭の解禁済みarea layer、Secretary固有の地下状態はSecretary-ownedとし、Nationの破棄・再作成を越えて保持する。PR102はSecretaryと1:1のlazy-created profileへ非負の`unlocked_area_layers`だけを保存し、`1 layer = 4 facility slots`をstorageせず派生する。梯子はslotではなく、空slot/cell row、surface World/Nation/MapCell/Turn identity、combat XP/level/checkpointを保存しない。profile初回作成はSecretary row lockとunique FKで直列化し、Secretaryが正式に削除された場合だけcurrent child lifecycleと同じcascadeで削除する。pure combat coreへEloquentを持ち込まない。
- Future boundary: 実際に解禁slotへ置く地下施設はNation-ownedとする。Nation破棄時に施設は消えるがSecretaryの解禁layer entitlementは残り、同じSecretaryの新Nationでは空配置から同じslot capacityを利用できる。施設、persistent combat run、request idempotency、resume、API/UIはPR102では実装しない。
- Decision record: `docs/architecture/underground-combat-laboratory.md`、`docs/roadmap/3.0.0-alpha-underground.md`

### UG-03 first player-accessible alpha

- Status: Open
- Required before: Tutorialを含むfirst player-accessible alphaのAPI/UI/version更新
- Implemented: PR103でplayer-facing導線の前段となるbackend runtime contractを実装。Secretary-ownedのcombat level/XP（開始値`1`/`0`、alpha中にretune可能なversioned curve）、非負の地底通貨「輝石の欠片」、通常探索とsequential trial、atomic built-in-AI auto battle、persistentなtrialの戦闘間progress、結果settlement、battle history、server-authoritative 10秒cooldown、transaction/row-lock/unique idempotencyによる重複・競合保護を追加する。surface World/Nation/MapCell/Turn/Rulesetとは独立し、PR101のpure combat coreへsnapshot・loadout・AI・encounter・private seedを渡す。combatは最大100 roundで、未決着ならstalemate withdrawalとして終了する。通常探索・trialとも欠片loss/rewardなしで通常勝利時base XPの`floor(base XP / 4)`を得て、通常探索は安全撤退、trialはrun失敗としてbattle 1へresetする。
- Decided backend portion: defeatは輝石の欠片を`floor(balance / 2)`まで減らしてrunを終了する。trialのdefeatまたはbattle後の明示的な帰還は次回battle 1へresetするが、既に解禁したtrialは失わない。browser close/logoutはtrialの戦闘間progressを保持する。trialのfirst clearだけが`unlocked_area_layers`を1増やし、capacityは1 layer = 4 slotsから派生し、次のtrialをsequentialにunlockする。同じtrialの再clearでlayerを重複取得しない。battle詳細logは終了から1000時間保持し、期限後もsummaryとidempotency/audit identityは保持する。
- Open decision: first-player API/UI、Tutorial（laboratory standardと分離した正常操作またはbuilt-in AIで100%勝利するdeterministic教育encounter）、authenticated ownership adapterを含むsecurity、rate limit、minimum UI、human playtest、release acceptance、application versionを決める。combat level/XP、trial progress、unlocked layerは独立stateとして維持し、manual round combat、equipment、shop、surface bridge、Turn integrationはこのgateの未承認範囲に残す。
- Options: application `3.0.0-alpha.1`を最初のplayer buildとする案と、`3.0.0-alpha`へ直接更新する案を比較する。PR1は2.8.0を維持する。
- Decision record: `docs/roadmap/3.0.0-alpha-underground.md`

### UG-04 party・market・facility・surface bridge

- Status: Open
- Required before: 借用秘書、複数人party、地底market、facility効果、または地上gameへ利益を渡す最初の実装
- Open decision: party snapshot/同時利用/報酬配分、market transaction、不正対策、Nation-owned地下facilityのplacement/lifecycle、地上benefitの上限/逓減/移行、published Rulesetとの関係を決める。facilityのownerはNation、解禁layer entitlementのownerはSecretaryという境界自体はUG-02で決定済み。
- Options: 早期頭打ちの段階式、逓減curve、限定utility/cosmetic中心を比較し、非参加playerへ不可逆な不利益を作らない。
- Decision record: `docs/roadmap/3.0.0-alpha-underground.md`

## Monster/combat gates

### MONSTER-01 怪獣actorとoccupancy

- Status: Decided
- Implemented: Yes
- Decision: 怪獣はterrain/facilityへ埋め込まず、独立したactorと別occupancy layerを持つ。最初の怪獣PRは1 actor = 1 cellとするが、将来のmulti-cell footprintを禁止しない。Capital cellのoccupancyは禁止する。
- Decision record: `docs/decisions/ADR-0007-monster-actor-and-occupancy.md`

### MONSTER-02 source-derived怪獣rule

- Status: Decided
- Implemented: Yes
- Decision: 8種のHP、movement上限、硬化parity、Nation単位の自然出現、人口pool、報酬値を`roadmap-pr21-v1`へ固定する。cell順の一回pass、移動先cellが未処理なら同turn再行動できるsource-derived因果を採用し、legacy cell encodingと永続`moves_taken`は採用しない。
- Decision record: `product/docs/monster-audit-pr21.md`、`docs/architecture/monster-system.md`

### MONSTER-03 terrain-changing disasterとの相互作用

- Status: Decided
- Implemented: Yes
- Decision: earthquake、tsunami、typhoon、fire、riotはoccupancyを維持して怪獣cellを通常対象から除外する。meteor shower、huge meteor、eruption、land subsidence、terrain-destruction missile、administrative terrain overwriteは先にoccupancyを報酬なしで除去してから地形を変更する。防衛施設接触は怪獣を明示除去して一度だけ巨大隕石相当blastを解決し、killer・報酬・kill statを作らない。
- Decision record: `product/docs/monster-audit-pr21.md`、`docs/decisions/ADR-0007-monster-actor-and-occupancy.md`

### MONSTER-04 共有Worldの自然出現・報酬・討伐統計

- Status: Decided
- Implemented: Yes
- Decision: 自然出現はeligibleなactive Nationごとに1 turn 1回、`min(10,000, owned_land_cells * 2) / 10,000`で判定する。人口100,000未満は出現なし、100,000〜249,999はinora/sanjira、250,000〜399,999はさらにred/dark/ghost、400,000以上はさらにwhale/kingを加えたuniform poolからsettlementへ最大1体出現させ、mechaは自然出現させない。Nation attributed final blowではkillerへ価値の切捨て半分を賞金、死亡時cell ownerへ残りを現行sale contractと同価値の怪獣肉（1億円=500トン）としてcapacity上限付きで配分する。`nation_monster_kill_stats`のWorld/Nation/definition別countを種類別討伐数、`SUM(kill_count)`を総トドメ数、count>0をkill markの正本とし、個別撃破はstructured eventだけへ残す。PR21ではawardを実装せず、後続のAWARD-01でver 1.3.0として実装する。
- Asset decision: 箱庭諸島2＋の原GIFは`_references/hakoniwa-2plus/assets/hakogif`で監査するが、Git、`product/public`、container imageへ収録せず、既存のGit外read-only tile asset directoryから原名・GIF形式のまま配信する。`monster4.gif`はkind 2/6の硬化状態専用とし、不足時はAPIと画面を失敗させず安全なCSS fallbackを使う。
- Decision record: `product/docs/monster-audit-pr21.md`、`docs/architecture/monster-system.md`、`docs/assets/tile-asset-mapping.md`、`docs/reference-analysis/license-and-provenance.md`

### AWARD-01 Nation awards

- Status: Decided
- Decision: owner提示のver 1.3.0条件表を正本とし、災難50,000/100,000/200,000人純減、繁栄300,000/500,000/1,000,000人最終人口、平和20,000/50,000/80,000人実受入とする。各系列は下位から1 turn 1段階、一度限りで取消なし。100 turnごとの人口最大全Nationへturn賞、同区間のNation attributed final blow最大全Nationへ最大0を除き討伐turn賞を反復付与する。公開TOPだけへ全受賞turn、種類別永久討伐count、stable source kind由来markを表示する。pre-1.3.0の周期countはmigrationが固定した全要求Nationへのexplicit operator seedだけとし、完了までnon-dry turnと周期順位をfail closedにする。award backfillは行わず、gameplay bonusもない。
- Implemented: ver 1.3.0
- Decision record: `docs/decisions/ADR-0009-ver-1.3.0-awards-and-classic-top.md`、`product/docs/operations/ver-1.3.0-monster-cycle-seed.md`

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

- Status: Decided
- Implemented: Yes
- Decision: `hakoniwa-2s-plus-v3`ではactive Nation間だけを対象に、共有surface cell shuffle順で各対象cellを1回訪問し、6方向から1方向だけを専用乱数streamで選ぶ。失敗時の再抽選は行わず、成功したowner変更は即時反映して後続cellから観測できる。`territory_expand`は従来の中立陸地取得に加え、隣接自領がある別active Nation所有のwasteland/scorchedだけを取得できる。各active NationのCapitalからhex distance 2以内は他Nationへのownership transferを禁止するが、core内cellは外側へのinfluence sourceとして通常どおり機能する。neutral、dormant、sunken、monster occupancyは今回のinfluence対象にしない。v1/v2 payloadと既存semanticsは変更しない。
- Remaining Open/Deferred: 防壁都市・占領抵抗はB-05、dormant territory占領はB-12、dormant Capital保護はB-13、報復・反撃は別roadmapの判断を維持する。
- Decision record: `product/docs/territory-expansion-influence-ver-1.4.0.md`、`docs/reference-analysis/hakoniwa-2plus-world-map.md`

### MISSILE-01 launch intentと基地単位解決

- Status: Decided
- Implemented: Yes
- Decision: 箱庭諸島2＋sourceには基地射程があるが、PR22ではowner decisionにより射程制限を採用しない。World内のactive Nation所有cellは距離にかかわらずtargetにでき、randomized cell processing中に各基地はcurrent owner、facility、level/capacity、残弾、資金を再検証する一方、targetとの距離は検証しない。intent登録だけでは通常command成功とせず、Nation単位で1発以上実発射された場合だけidle counterをresetする。全intentが0発ならidle counterを維持し、process_cells後のautomatic financeは追加しない。距離制限は必要時に新しいversioned rulesetとして検討し、未使用metadataやextension hookを先行実装しない。報復・反撃systemはPR22範囲外とする。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`、`product/docs/command-audit-pr22.md`

### B-10 ミサイル可視性

- Status: Decided
- Implemented: Yes
- Decision: 発射Nation、弾種、発射数と意味のある着弾はpublic、効果のない着弾はlaunch単位で集約する。発射Nationには狙点、費用、弾種、全着弾結果をprivate詳細として表示する。SPPを含む全弾種で発射Nationを公開する。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`、`product/docs/command-audit-pr22.md`

### B-12 dormant国家への攻撃詳細

- Status: Open
- Required before: Ruleset v12のdistance 2保護を変更する将来のdormant combat実装前
- Implemented minimum: ver 2.4.0ではtarget Turn開始時に`dormant`だったNationのCapitalからhex distance 2以内を保護する。missile、disaster、territoryは最終候補決定後にno-opとする。monsterは開始cellが範囲内なら即`stayed`、範囲外から範囲内cellを引いた場合はmonument等と同じ進入不可candidateとして1 attemptを消費し、`candidate_attempts_per_action = 3`の残り候補へ進む。範囲外は通常のattack・damage契約を適用し、全領土凍結や怪獣討伐専用例外は設けない。旧v1/v2/v8 payloadとhistorical記録は変更しない。
- Open decision: v12より後に保護ring、対象mutation、または範囲外のdormant combat契約を変更する場合の互換性とmigrationを決める。v12のowner決定を再度の実装gateにはしない。
- Decision record: `docs/decisions/ADR-0014-ver-2.4.0-nation-dormancy.md`、`docs/decisions/ADR-0009-ruleset-v2-missile-targeting.md`、`docs/decisions/ADR-0012-ver-2.1.0-defense-and-secretary-rename.md`

### B-13 Capital周辺の占領保護

- Status: Open
- Required before: Ruleset v12のdistance 2保護を変更する将来のdormant territory占領実装前
- Implemented minimum: ver 2.4.0では`dormant` Capitalからhex distance 2以内をterritory influenceとmanual expansionによるowner変更から保護し、範囲外のdormant territoryは通常の対象とする。
- Open decision: v12より後に保護ringまたはdormant territory占領条件を変更する場合の互換性とmigrationを決める。v12のowner決定を再度の実装gateにはしない。
- Decision record: `docs/decisions/ADR-0014-ver-2.4.0-nation-dormancy.md`、`docs/architecture/capital-and-territory.md`

## Release gates

### RELEASE-01 public-release data migrationとruntime互換性

- Status: Decided
- Implemented: PR23でgo-live境界、canonical fresh baseline、deploy preflight、将来migration契約を文書・schema・運用手順へ反映する。
- Decision: production Worldの最終fresh生成、一般Nation登録開放、初回正式turn開始の3条件が揃うまでは仮データをfresh resetできる。以後はWorld、Nation、cell、queue、TurnRun、eventを破壊せず、schema/gameplay data変更へforward migrationまたは明示的変換を必須とする。公開済みruleset payloadは不変とし、deploy前に次回non-dry TurnRunのpending/running/failedがないことを確認する。releaseを跨ぐautomatic retryは禁止し、監査記録を保持する。
- Decision record: `docs/decisions/ADR-0008-first-production-release.md`、`docs/architecture/configuration-management.md`

### AUTH-05 provider障害時の復旧

- Status: Decided
- Implemented: PR23でprovider失敗時の利用者向け案内を更新する。
- Decision: 一時障害と再試行を案内し、既存sessionは通常期限まで維持する。事前link済みの別providerだけを代替loginに使える。email一致統合、緊急identity差替え、operator付替えは実装しない。
- Decision record: `docs/decisions/ADR-0008-first-production-release.md`、`docs/architecture/authentication-and-identities.md`

### T-02 休眠状態遷移Job

- Status: Open
- Required before: official Turn統合を専用lifecycle schedulerまたはbatch処理へ変更する前
- Implemented minimum: ver 2.4.0では専用Lifecycle Jobや実時間判定を作らない。official TurnのWorld transaction内で開始stateをfreezeし、counter確定後の終端で`active ↔ dormant`と`dormant → abandoned`を確定する。manual requestはWorld lockとunresolved TurnRun guardで直列化し、大規模batch/checkpoint engineは追加しない。
- Open decision: 将来official Turnから分離する必要が生じた場合にscheduler、World lock、turnとの直列化、batch checkpointを決める。ver 2.4.0のowner決定を再度の実装gateにはしない。
- Decision record: `docs/decisions/ADR-0014-ver-2.4.0-nation-dormancy.md`、`docs/decisions/ADR-0015-ver-2.4.0-karma-recovery.md`、`docs/architecture/nation-lifecycle.md`

### D-02 turn失敗時の再試行

- Status: Decided
- Implemented: game state rollbackとsame run / target turn / ruleset / seedによる明示的な手動retryは実装済み。PR23でproduction cron、非ゼロ終了、application log、TurnRun確認手順を固定する。
- Decision: 初期公開版はautomatic retryを行わない。失敗時はoperatorが非ゼロ終了、application log、TurnRun状態を確認し、既存の明示的manual retryだけを行う。stale-running自動回収、backoff、retry上限、外部通知連携は公開後TODOとする。
- Decision record: `docs/decisions/ADR-0008-first-production-release.md`、`docs/operations/turn-cron.md`

### B-14 明示的放棄の安全策

- Status: Decided
- Implemented: ver 1.6.0 manual abandonment、ver 2.4.0 automatic abandonment
- Decision: manualは既存の危険領域button、modal、現在の島名完全一致を維持する。automaticはidle 2160到達Turn終端から同じinternal cleanup operationをsystem actorで呼ぶ。どちらも単一transactionでsurface map、monster、現役asset、Capital、membershipを終了し、Nation、Secretary、歴史recordを保持する。
- Decision record: `product/docs/ver-1.6.0-nation-lifecycle.md`、`docs/decisions/ADR-0014-ver-2.4.0-nation-dormancy.md`

### B-15 再入植

- Status: Decided
- Implemented: ver 1.6.0で、owner本人による手動破棄後の最小再登録を実装。
- Decision: owner本人が手動破棄した後、同じUserは同じWorldへ通常のNation作成経路から別の新しいNationを登録できる。旧Nationはphysical deleteせず`abandoned`として歴史を保持し、旧membershipを終了する。新規登録は新しいrequest keyと新しいNation numberを使い、historical creation requestを保持する。将来、島をまたいで保持するゲーム上のデータはAccount/Userに紐づく「秘書」側の永続データとして扱い、Nation AからNation Bへ直接移送しない。この将来境界はsecretary/account gameplay schema、item、proficiency、turn反映、bonus、ranking・award引き継ぎ、保護期間を今回決定または実装するものではない。
- Decision record: `product/docs/ver-1.6.0-nation-lifecycle.md`

### SECRETARY-01 User永続Secretary v1

- Status: Decided
- Implemented: ver 2.0.0でUser永続Secretary、命名、4 passive skill、turn attempt batch load、transactional XP、ruleset v7、forward migrationを実装する。
- Decision: SecretaryはNationではなくUserへ1:1で所属し、最初のNation登録成功時にidempotentに作成する。abandon/re-registerを跨いで同じ状態を保持する。production migrationはactive/abandonedを問わずNation登録履歴のあるUserだけをbackfillし、履歴のないUserは将来の初回登録成功まで作成しない。命名前も効果とXPを有効にし、v1の詳細gameplay contractはADR-0011を正本とする。
- Decision record: `docs/decisions/ADR-0010-product-generations-and-2x-identity.md`、`docs/decisions/ADR-0011-secretary-v1-contract.md`、`docs/roadmap/2.x.md`

### SECRETARY-02 Secretary rename

- Status: Decided
- Implemented: ver 2.1.0で既存の命名済みSecretaryをプロフィール編集から何度でも改名できる。
- Decision: ADR-0011のver 2.0.0一度だけ命名contractは歴史として維持する。ver 2.1.0ではUser所有・1 User = 1 Secretaryを変えず、plain text 1〜30文字、duplicate可、skill/XP不変、abandon/re-register後も保持する。rename APIはrowを作成せず、private auditへold/new nameとSecretary/User ID、時刻を残す。過去logと実行中attemptは保存済みname snapshotを維持し、次attemptから最新名をloadする。
- Decision record: `docs/decisions/ADR-0012-ver-2.1.0-defense-and-secretary-rename.md`、`docs/roadmap/2.x.md`

### SECRETARY-03 公開プロフィールと秘書Lvcapacity bonus

- Status: Decided
- Implemented: ver 2.5.0で公開「メイン」プロフィール、3:4メイン画像、画像制作metadata、viewer単位のAI画像表示設定、1000文字の経歴、現在装備5slot表示、秘書Lvcapacity bonus、ruleset v14、exact v13→v14 forward migrationを実装する。
- Decision: 秘書Lvは既存4 passive skill levelの合計であり、独立XP/level systemを作らない。v14では資金capacityと食料capacityへ秘書Lvと同じpercentを乗算し、既存の非負整数切捨てとcanonical capacity/credit経路を維持する。E-04のgeneric ModifierはDeferredのままとし、この確定済み1種類だけを局所的に解決する。画像はUser永続Secretaryへ最新1枚だけ保持し、既存問い合わせ画像のvalidation/storage boundaryを共有するが、公開disk/URL/cache policyは分離する。AI画像の非表示はviewer presentationであり、保存fileを削除しない。
- Decision record: `docs/decisions/ADR-0016-ver-2.5.0-secretary-profile.md`、`product/docs/ver-2.5.0-secretary-profile.md`、`docs/roadmap/2.x.md`

### D-01 scheduler・queue基盤

- Status: Decided
- Implemented: Application command、lock境界、host cron用wrapper、登録例を実装済み。PR23のgo-live手順でoperatorがproduction hostへ登録する。
- Decision: Asia/Tokyoのhost cronをthin triggerとし、DB/application lockを正本、host `flock`を任意の一次filterとする。
- Decision record: `docs/operations/turn-cron.md`

### D-03 ruleset公開承認

- Status: Decided
- Implemented: Git、pull request、ruleset validator、CI、merge履歴を初期版の承認記録とする。
- Decision: 単独管理者承認とする。application内publish画面、二者承認、公開操作専用audit eventは作らず、将来in-app publishを実装するときにapplication auditを追加する。
- Decision record: `docs/decisions/ADR-0008-first-production-release.md`、`docs/architecture/configuration-management.md`

### D-04 backupのRPO・RTO

- Status: Decided
- Implemented: PR23でcredentialを含まないbackup/restore script、設定例、operator手順、非ゼロ失敗境界を用意する。実在しないoff-host環境をtestで偽装しない。
- Decision: 暗号化off-host PostgreSQL backupを6時間ごとに取得し、日次backupを30日保持する。deploy前backup、正式公開前1回と以後月1回を目安にrestore確認する。初期目標RPOは6時間以内、RTOは12時間以内。continuous WAL、PITR、15分RPOは公開後TODOとする。
- Decision record: `docs/decisions/ADR-0008-first-production-release.md`、`product/docs/operations/database-backup-and-restore.md`

### D-05 event・log保持期間

- Status: Decided
- Implemented: PR23のTOP全体ログはpublic visibilityだけをpaginationする。event retentionとは分離する。
- Decision: player turn event、gameplay audit event、moderation記録を初期版では自動削除しない。application/web server運用logは30日を目安に保持し、分析専用基盤は作らない。event 100万件または実測性能問題を再判断gateとする。
- Decision record: `docs/decisions/ADR-0008-first-production-release.md`、`docs/future-systems/event-log-and-notifications.md`

### D-07 moderation

- Status: Decided
- Implemented: PR19のplain-text validationに加え、PR23で禁止行為の方針、設定可能な外部窓口link、状態を変更しないoperator-onlyのmoderation記録境界を追加する。
- Decision: 違法内容、個人情報、なりすまし、差別・嫌がらせ・脅迫、明らかな荒らしを禁止する。通報とappealは外部窓口で受け、固定対応期限は設けない。PR23ではUser/Nation/turn/map/profileの状態を変更する停止・BAN・罰則を実装しない。moderation記録は自動削除しない。ゲーム内停止・天罰、完全自動禁止語判定、通報管理画面、期限管理、高度appeal workflowは公開後TODOとする。
- Decision record: `docs/decisions/ADR-0008-first-production-release.md`、`docs/architecture/public-lobby-and-island-dashboard.md`

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
