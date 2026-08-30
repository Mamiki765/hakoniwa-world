# Underground Combat Laboratory and runtime architecture

## Authority and scope

この文書は`secretary-underground-alpha-v0` combat laboratoryからPR109の正式equipment、装備Shop、宝物庫、通常探索までを扱うcurrent task-specific architecture authorityである。manual combat、AI editor、Trial content、random equipment/drop、affix、unique、enhancement、enchant、party、market、facility、surface bridgeは定義しない。

application versionは`3.0.0-alpha.5`である。surface Ruleset `hakoniwa-2s-plus-v18`とUnderground laboratory/runtime identityは別物であり、Ruleset payloadは変更しない。profile、run、history、intro/growth/skill/equipment stateとpure build snapshotはpublished Ruleset、World、Nation、MapCell、TurnRun、Turn RNGへ依存しない。PR107は通常探索とgrowth settlement、PR108はstatus/STP/SP/Skill Tree、PR109はSecretary-owned equipment、装備Shop、宝物庫とactual equipmentを用いる通常探索をalpha-v1 canonical pathへ接続する。Trial、party、market、facility、surface bridgeは解禁しない。

## Modular-monolith boundary

実装は同じLaravel repository内のmodular monolithだが、coreはframework-freeなpure PHPである。

- `product/app/Domain/Underground/Combat/`: rules、state、AI、private RNG、engine、result。
- `product/app/Application/Underground/`: manifest駆動simulationとreport aggregation。
- `product/app/Application/Underground/UndergroundProfileService.php`: Secretary row lockを使うprofile lazy-create adapter。
- `product/app/Models/UndergroundProfile.php`: Secretary-owned profileのEloquent persistence model。
- `product/app/Domain/Underground/Area/`: layerからfacility slot capacityを派生するpure calculator。
- Underground runtime adapter/orchestrator: Secretary snapshot、狩場/encounter、trial run、cooldown、canonical engine実行、settlementを接続する。pure engineの複製やround途中のpersistent sessionは作らない。
- runtime persistence models/tables: Secretary-owned permanent progression、active trial/run、battle summary/detail log/idempotencyをlifecycleごとに分離する。具体的なtableはcurrent migration/codeを正本とし、将来contentのための空tableを先回りして作らない。
- `product/app/Console/Commands/UndergroundBalance.php`: file I/OとCLI adapter。
- `product/config/underground/balance/foundation-v0.json`: immutableなalpha-v0 laboratory inputとinitial observation metadata。
- `product/config/underground/balance/foundation-v1.json`: alpha-v1の数値、build fixture、skill/status/equipment catalog、experiment定義の正本。
- `product/docs/underground-balance-foundation-v1-10000-seeds.json`: raw action logを除くalpha-v1 manual experiment summary。
- `product/tests/Underground/`: surface test suiteと分離したcontract tests。

`Domain/Underground`から`App\Models`、`App\Domain\Turn`、Laravel database、World、Nation、MapCell、TurnRun、surface Ruleset identityへ依存してはならない。Secretary接続とtransactionはApplication/Model境界で扱い、combat coreへEloquent modelを渡さず、runtime adapterがimmutable inputへ変換する。

## Underground persistence boundary

### Ownership and lifecycle

Undergroundの恒久的なplayer progression ownerは`Secretary`である。combat level/XP、輝石の欠片、STP/SP、Skill Tree allocation/loadout、trial unlock/progress、地下箱庭で解禁済みのarea layer等のSecretary固有状態はNationから独立して保持する。PR102では地下箱庭entitlementを、PR103ではcombat progressionとruntime stateを、PR107ではcurrent HP/銀行/STP foundationを、PR108では有限SPとskill allocationを追加する。equipmentと探索基地等の将来状態もこのowner境界を継承する。

`underground_profiles`はSecretaryと1:1で、`secretary_id`をunique FKとする。既存Secretaryはbackfillせず、必要になった時にApplication serviceがtransaction内でSecretary rowをlockしてprofileをlazy createする。profileはNationの破棄・再作成では削除しない。Secretaryそのものが正式に削除された場合だけ、Secretary skill/itemと同じcurrent child lifecycleに従ってcascade deleteする。current User→Secretary FKは`RESTRICT`であり、このPRはUser/Secretary lifecycleを変更しない。

### Area and facility boundary

地下箱庭はsurface World/MapCellとは別空間であり、PR102は地下cellを生成しない。`1 unlocked layer = 4 facility slots`とし、slot capacityは`unlocked_area_layers * 4`からpure calculationで派生する。梯子/vertical spineはnavigationであってfacility slotではない。空slotをrowとして保存せず、surface x/y、chunk、MapCellを流用しない。frontend layout、左右の描画順、adjacencyは固定しない。

将来、解禁slotへ配置する地下都市・農場・工場等の施設はNation-ownedとする。Nation破棄時は施設を失うがSecretary-ownedの解禁layer entitlementは残る。同じSecretaryが新しいNationを持つ場合、保持したcapacityを空配置から利用する。このfuture bridgeはPR102では実装しない。

combat level、combat XP、trial progress、地下箱庭の解禁layerは独立stateである。combat levelやXPからlayer数を算出せず、同じfieldへ格納しない。`combat_level`と`combat_xp`は`1`/`0`から開始し、XP curveはUnderground側のversioned alpha balance inputとする。輝石の欠片はsurface moneyと別のSecretary-owned非負整数balanceである。

PR103のruntime stateはlifecycleを混ぜない。恒久progression（level/XP、shard balance、trial unlock、unlocked layers）、active trial/run（trial identity、次回battle index、status）、battle history（summary、詳細log、timestamp、retention expiry、request/battle idempotency identity）をそれぞれの責務として保存する。既存の`underground_profiles`を無秩序に拡張せず、current migration/codeで必要なsmall dedicated model/tableへ分離する。profile/run/historyの全FKはSecretary ownershipを越えず、Nation、World、MapCell、TurnRunを参照しない。

`1 unlocked layer = 4 facility slots`はcapacityの派生規則である。trialのfirst clearだけがlayer entitlementを1増やし、同じtrialの再clearでは増やさない。combat level/XP、探索回数、stalemateではlayerを増やさない。

## Alpha-v0 combat model

alpha-v0は1 actor対1 enemy、最大5 skill、通常攻撃、防御、resource cap、cooldown、max roundsを持つ。starter knife actorと4 prototype enemyだけをauthoringしている。これはcontent frameworkではなく、formula、AI、RNG、集計を検証する最小fixtureである。

damageはalpha-v0 laboratory専用の次式を使う。

1. `effectiveDefense = defense * (100 - ignore%) / 100`を整数切捨て。
2. `raw = attacker.attack * actionPower / 100`を整数切捨て、最低1。
3. `mitigated = raw * 100 / (100 + effectiveDefense)`を整数切捨て、最低1。
4. action-label固有streamで95〜105%の整数varianceを適用。
5. guard中は結果の55%（45%軽減）、最低1。
6. 残HPを超えるdamageは残HPへclampする。

このformulaと現在のstatはfirst playable contractではない。human playtestと新しいsimulationによりversioned identityまたは明示的migration boundaryのもとで再調整できる。

## Alpha-v1 combat and build laboratory

### Identity and reuse boundary

PR105は`secretary-underground-alpha-v1`とdeterministic equipment generator `secretary-underground-equipment-alpha-v1`を追加する。alpha-v0 engineを複製せず、1 actor / 1 round / 1 actionのround envelopeだけを`CanonicalCombatOrchestrator`へ抽出する。alpha-v0とalpha-v1は同じenvelopeを使い、formula、AI、status timing、result projectionは各versioned modelが所有する。alpha-v0 manifest、fixed replay、report、Tutorial starter knife、scripted loss、XP +5 / 欠片0 / Lv1のcontractは変更しない。

alpha-v1はpure immutable manifest/snapshot/validator/simulatorであり、DB、Eloquent、World、Nation、Turn、surface Rulesetへ依存しない。skill allocation、equipment、priority AIはrepresentative simulation fixtureであり、player recordやclass recordではない。

### Five stats, HP, and MP

基礎能力のstable keyは`vitality`（生命）、`might`（武力）、`finesse`（技巧）、`spirit`（精神）、`agility`（敏捷）の5つである。representative base allocationは合計100とし、combat levelとアイテムLvのbenchmark倍率で成長する。PR105のprovisional倍率は`10,000 + 900 × (max(combat level, item level) - 1)` basis pointsであり、level 1〜1,000だけを受理する。

標準Lv1の各能力20・装備補正0では最大HPをexactly 500とする。最大HPは同倍率で伸びる500の基準、基準生命との差分、装備HPから導出する。最大MPは常に10,000であり、combat level、基礎能力、アイテムLvでは増えない。通常攻撃と防御はMP 0、戦闘開始時は10,000、自然回復はalpha-v1 balance dataの300 MP / roundである。150 / 200 / 250 / 300 / 400を100-round持久fixtureで比較し、20-round帯のrotationを維持しながら長期戦では通常攻撃へfallbackし、400のほぼ無制限rotationを避ける値として300を選んだ。skill recovery、overflow、MP不足action、最初の枯渇roundは別metricとして集計する。

敏捷はinitiative、evasion、interrupt/action-delay resistanceへ使うが、追加行動を作らない。critical/evasionのstat contributionは進行倍率のreferenceで正規化し、level上昇だけで確率capへ近づかない。

### Alpha-v1 damage and recovery order

alpha-v1は`attack - defense`を使わず、次の順序を固定する。

1. 複数能力のweighted numeratorを合計し、一度だけ整数切捨てする。
2. weapon coefficient、fixed componentを加え、skill potencyを掛けて整数切捨てする。
3. target max-HP componentがあればsource stat由来capを先に適用する。
4. category/all/status modifierと消費stack bonusを適用する。
5. critical判定と倍率、95〜105% variance、evasion判定の順に専用label RNGを消費する。
6. `defense reference / (defense reference + effective defense)`でphysical/magical mitigationを算出する。referenceはlevel/item-level benchmarkと同じcurveで伸びる。
7. damage-taken modifier、guard、parryを適用し、合成後の軽減は75% capを越えない。
8. barrierを先に消費し、残りをHPへ適用する。HPを越えるdamageはclampし、合法なhitは最低1 damageとする。

damage prevention metricはHP clamp前のpost-mitigation damageを基準にし、defense / guard / evasion / barrierが実際に防いだ量だけを数える。残HPを越えたoverkillは防御量へ含めない。

回復、barrier、periodic effectはsource stat coefficient、target max-HP coefficient、fixed componentの必要な組合せだけをtyped effectとして持つ。percentage damageはsource stat由来capを必須とし、boss/大HP targetをpercentageだけで倒さない。periodic tickは適用時にbaseをsnapshotし、round endで`base × status stack数 × periodic equipment倍率`を計算して最後に1回だけhalf-upで整数へ丸める。巨大な式DSLは導入しない。

### Trees, points, active slots, and role stacks

skill treeは`martial`（戦技）、`guardianship`（護身）、`miracle`（祝福）の3つで、固定classやbalanced専用treeはない。player-facing labelだけを祝福へ統一し、既存manifest/combat/node identityの`miracle` / `miracle_*`は維持する。各treeの全node最大rankはexactly 100 points、全treeは300 points、representative final budgetは120 pointsである。同tree内prerequisite、max rank、tree投資済み15 / 35 / 60 / 85 points gateをvalidatorが確認する。最大5 active skillに加え、通常攻撃と防御はslot外である。weapon styleはdagger、rapier、shield、crystal staffをauthoringし、styleとtreeを固定classとして結合しない。

`fighting_spirit`（闘志）は実際にguard/parry/barrier吸収が発生した時だけ最大5 stackまで得る。攻撃されていない防御では増えない。`grace`（恩寵）はeffective healing、実際のcleanse、barrier吸収、または明示されたholy actionだけから得る。overhealだけでは増えない。いずれも通常combat flow内のstatus/role stackであり、variant専用engineを持たない。

### Status and boss policy

statusはbuff/debuff disposition、duration、`refresh`または`stack_refresh`、max stacks、typed effectsを持つ。applyされたroundにはperiodic tickとduration decrementを行わず、次のeligible round endからtickして残durationを減らす。refreshはdurationを戻し、stack refreshはcapまで増やしてdurationを戻す。action impairmentの実効skip chanceは`authored chance × (10,000 - agility resistance) / 10,000`を整数切捨てし、agility resistanceはlevel正規化したreference時2,000 bps係数・5,000 bps capとする。cleanse/dispelは実際に対象statusを除去した時だけeffective actionとして数える。duration、barrier、cooldown、stackはunderflow/overflowをabnormal stateとして検出する。

normal targetのaction impairmentをbossへそのまま適用しない。boss profileはinitiative/damage等のsoft effectへ変換し、同種controlの反復には一時resistanceを積み、round endで減衰させる。permanent controlは許さない。

### Priority AI and deterministic equipment

priority AIは上から最初に成立したruleを使い、最大16 rules・各rule最大2 conditionsである。vocabularyは`always`、own HP/MP threshold、enemy HP threshold、self/enemy status present/absent、role stack threshold、enemy telegraph、skill ready、round threshold/moduloである。cooldownまたはMP不足のskillを実行せず、合法な下位ruleまたは通常攻撃へfallbackする。MP不足で上位skillを選べなかったturnはreportへ別集計する。

equipment generationはgenerator identity、アイテムLv、slot / weapon style、rarity、seedが同じなら同じidentity・base・affix・unique effect・display projectionを返す。アイテムLvはbase budget、affix tier、roll rangeを、rarityはaffix数、roll quality、unique eligibilityを決める。common / uncommon / rare / epic / uniqueを区別し、能力、damage/healing/status、critical、MP cost、guard/barrier、periodic effectの少数affixだけを持つ。evasion、軽減、MP cost reduction等に明示capを置き、`max_mp` affixは作らない。

uniqueは水平sidegradeである。manual comparisonではアイテムLv40の吸収UniqueはアイテムLv45 Epicよりdamageが低い一方、effective healingが高く、低Lv Uniqueが絶対上位にならないtrade-offを確認する。inventory、drop、unidentified Item、settlement、Shop、売却/分解はPR105に含めない。

### Representative observations

同point budget・同アイテムLvのpure attacker、pure tank、pure healer/祝福、balanced buildをclass recordではなくfixtureとしてauthoringする。standardized pressure benchmarkのmanual targetはattacker 100に対してbalanced 88〜92、tank 82〜84、healer 79〜81であり、tankの平均はhealer以上とする。tankのpressure出力は通常攻撃の基礎値ではなく、実効guard/parry/barrier吸収から闘志を得てcounterへつなぐ護身固有loopの価値で調整する。これらは通常CIのhard gateにはしない。適正帯enemyはmedian 14〜26 rounds、全build solo可能、100-round stalemateなしをinitial targetとする。

seed 0〜9,999のpressure/appropriate実験、各1,000-seedのearly/mid/late、MP sweep、sidegradeを含むsummaryは[`underground-balance-foundation-v1-10000-seeds.json`](../../product/docs/underground-balance-foundation-v1-10000-seeds.json)を正本とする。reportはraw per-seed action logを含めず、observed ratio、round分布、outcome、healing/prevention、status/action usage、MP economy、最大10 abnormal seeds、再現argumentを保持する。数値はalpha-v1の初期観測であり、player-facing contentや永久balance gateではない。

## Dedicated deterministic RNG

`UndergroundRandom`はseed、laboratory domain、call label、labelごとのcounterからHMAC-SHA256を作るprivate implementationである。同一rules identity、input、seedならaction順、variance、result、compact logが完全一致する。Turnのrandom stream、World seed、current Turnへ接続しない。

labelごとにstreamを分け、別actionのcall追加で既存labelのsequenceを不必要にずらさない。algorithmを変える場合は`SIMULATOR_VERSION`またはrules identityを更新し、既存reportと区別する。

## Built-in AI and scenario semantics

built-in AIは予告への防御、低HP回復、resource finisher、armor対策、通常damage skill、低HP guard、通常攻撃fallbackの明示priorityを持つ。enemy AIはstandard、fast、armored guard cycle、telegraph→heavy strikeを表現する。

PR1のsemantic contractはabsolute win rateではなく、次の相対観測である。

- fastはstandardよりinitiative上有利であり、reportのenemy-first rateが高い。
- armoredはstandardより耐久的であり、同じsmokeでmedian roundsが長い。定義上もHP/defenseが高い。
- telegraphed threatは予告とheavy strikeを実行し、built-in AIのdefendによりheavy strikeがguardedになる。

telegraph guardはaction logに`guarded`を残し、unit testでunmitigated上限より45%軽減された上限内にあることを確認する。

## PR103 expedition runtime contract

PR103はlaboratoryのpure coreとPR102のSecretary-owned persistenceを接続するruntime adapterである。runtimeはsurfaceのWorld、Nation、MapCell、Turn、TurnRunner、surface Rulesetを参照せず、将来のplayer-facing API/UIへ渡す前のapplication service境界に留める。

### Atomic auto battle

- 通常探索はserverが選択された狩場に対応するencounter identityを解決し、試練はstableなsequential trial identityと次回battle indexを解決する。combat encounter以外（将来のtreasure等）を追加できるlocal result boundaryだけを残し、汎用event engineは導入しない。
- battle開始時にSecretary-owned stateからimmutable combat snapshot、loadout、built-in AI設定、enemy/encounter、Underground専用deterministic private seedを構成し、PR101のcanonical pure engineへ渡す。Eloquent modelやDB transactionをpure coreへ渡さない。
- 通常combatはbuilt-in AIによるatomic auto battleであり、round途中のresume、persistent round session、battle途中の帰還を提供しない。将来manual combatを追加する場合は別runtimeとして接続できる境界だけを保ち、canonical auto pathを変更しない。
- player-facing runtimeの`max_rounds`は100に固定する。100 roundを終えても未決着ならcanonical resultの`stalemate`をwithdrawalとして扱い、battleを正常終了する。通常探索・trialとも輝石の欠片loss/rewardはなく、通常勝利時base XPの`floor(base XP / 4)`を得る。通常探索は安全な撤退、trialはrun失敗としてprogressをbattle 1へresetし、HP 0 defeatの欠片50% lossとは区別する。
- damage logは障壁吸収後の計算damageをoverkill分も含めて表示し、HPだけを0で下限固定する。summaryのdamage metricは実際にHPまたは障壁へ適用された量を維持する。

### Settlement and progression

- `BattleResult`とcompactなplayer-facing action/round logを受け取った後、result settlement、XP付与、level-up、輝石の欠片報酬または敗北loss、trial progress、first-clear layer unlock、battle history、cooldownを一つのtransactionで一度だけ確定する。
- `combat_level`は`1`、`combat_xp`は`0`から開始する。XP curveはUnderground側のversioned alpha balance inputとしてretune可能にし、combat level/XPから`unlocked_area_layers`を算出しない。
- 輝石の欠片はsurface money/resourceと交換しないSecretary-owned非負整数balanceである。通常敗北時のsettlement前balanceを`floor(balance / 2)`へ減らし、runを終了して安全地点へ帰還する。追加の未鑑定Item lossやequipment penaltyはこのruntimeにない。

### Trial lifecycle and ownership

- trialのbattle間progressはSecretary-ownedで永続化し、browser close、logout、単なる離席では失わない。defeat、またはbattle終了後の明示的な帰還ではactive runを終了し、次回trial battleを1へresetする。ただしtrialのunlock済みidentityは保持する。
- 各trial authoringはそのtrial固有の明示的`content_identity`を持ち、active runは開始時identityを保存する。同じtrial自身のencounter順・数、enemy/boss identity・位置、battle reward、completion判定等を変えてidentityが不一致になった時だけ、row lock内でそのrunをcurrent identity / battle 1へresetする。このresetはdefeatではなく、欠片・XP、trial unlock、first clear、`unlocked_area_layers`、他trialのprogressを変更しない。application version、Underground runtime identity、別trialのidentity変更ではresetしない。表示名等のFlavorだけならidentity更新を要しない。
- 各trialのfirst clearだけが`unlocked_area_layers`を1増やす。capacityは`unlocked_area_layers * 4`から派生し、1 trial = 1 layer = 4 facility slotsである。同じtrialを再clearしてもlayerを重複取得せず、first clear後に次のtrialをsequentialにunlockする。
- stalemate withdrawalはHP 0 defeatと異なり欠片を失わないが、trial runは終了してprogressをbattle 1へresetする。通常探索・trialとも欠片rewardはなく、base XPの1/4だけを整数切り捨てでsettleする。trialの正確な戦闘数、enemy、boss、balanceはversioned content decisionへ残す。

### Cooldown, idempotency, and concurrency

- battle終了後の次回battle開始可能時刻をserver-authoritativeな`next_battle_at`で管理し、10秒未満のstart requestを拒否する。cooldown待ちでrequest/processをsleepまたはblockしない。
- runtimeはauthenticated adapterが解決したcurrent User→own Secretaryのcontextだけを受け取り、player-controlled Secretary IDをownershipの根拠にしない。profile、active run、battle/request identityをrow lockとunique identityで直列化する。
- duplicate request/retryは保存済みidempotency identityにより同じsettled resultを返すか再適用せずに拒否する。concurrent requestも同時battle、cooldown突破、XP/欠片の二重付与、trial progress/layer unlockの二重進行を許さない。idempotency/audit identityは詳細logのretention後も保持する。

### Battle history retention

`underground_battle_logs`にはordered action/round sequenceを保存し、retention windowはbattle終了から1時間とする。期限後は個別詳細を表示せず、安全な期限切れ案内を返し、`underground:prune-battle-logs`で削除する。backend projectionはcompact compatibility/audit境界として新しい順の最新20件までを取得するが、alpha.5のplayer-facing地下メインに表示する履歴はそのうち直近5件だけとし、paginationやarchive UIは設けない。詳細logはeager loadせず、画面に表示された個別戦闘を開いた場合だけ取得する。`underground_battles`にはencounter表示、runtime result（victory/defeat/withdrawal。canonical `stalemate`はwithdrawalへ分類）、round count、damage/recovery aggregate、XP/欠片delta、timestamp、request/idempotency identity等のcompact recordを引き続き保持する。内部debug objectやraw simulation payload全体は永続化しない。productionのcleanupは既存のOCI host cron thin-trigger patternを維持し、この変更でcron登録、Laravel scheduler、巨大なworkflow subsystemを追加しない。

## Permanent contracts and experiment observations

恒久的に保護するものは次である。

- deterministic executionと同一input＋seedの完全replay。
- HP、resource、cooldown、action selectionの合法なstate transition。
- abnormal stateゼロ。
- max-round到達を明示的`stalemate`として返すこと。
- manifest hash、source commit、seed range、simulator versionを含む再現可能なreport。
- alpha-v1では5 stats、HP 500、fixed MP 10,000、point/slot/AI cap、status timing、deterministic equipment identityも保護する。
- 上記scenario semantics。
- surface/domain/database dependencyゼロ。

alpha-v0の10,000-seed観測値はstandard 79.24%、fast 56.10%、armored 75.38%、telegraphed 67.83%である。これらとmanifestのprovisional rangeはlaboratory/statisticsを検証したinitial observation envelopeにすぎず、player-facing targetでも将来の固定acceptance thresholdでもない。将来balanceを維持する義務はなく、first playable前に自由にretuneできる。

exact source `9c8a17b7ca6b2e31e7cf0da28951b786826b4715`での集計は[`underground-balance-foundation-v0-10000-seeds.json`](../../product/docs/underground-balance-foundation-v0-10000-seeds.json)に保存する。raw per-seed action logは含めず、異常seedは最大10件だけを残す。

manifestの通常4scenarioには`acceptance`を設定しない。simulatorは別のexperiment manifestが任意の`acceptance`を指定する機能を保ち、結果を`experiment_thresholds_passed`として分離する。unit testでは`synthetic_stress` scenarioだけでthreshold violationの集計を確認し、通常4scenarioのwin rateをCI gateへ焼き込まない。

## Report contract

summary reportはraw action logを全seed分保存しない。alpha-v0はscenarioごとにwin/loss/stalemate、round percentiles、damage、skill/action/resource usage、initiative、telegraph/heavy/guarded-heavyを持つ。alpha-v1はbuild definition、item-level/point budget、selected MP recovery、skill cost、role damage ratio、appropriate round/outcome、healing/prevention、status/action usage、MP sweep、scale/sidegrade observationを追加する。どちらもabnormal seeds最大10件とreproduction argumentを持つ。

`resource_overflow_units`はcapを越えて破棄されたgain量であり、不正なout-of-range stateではない。`abnormal_rate`は実際にHP/resource invariantを破ったfightだけを数える。

full manifestを実行したreportはsemantic observationsと`laboratory_contract_passed`を返す。scenario filterで比較相手がないsemanticは評価しない。任意experiment thresholdsは別fieldであり、laboratory contractと混同しない。

## Verification boundary

pure combat CI smokeは32程度のseedでdeterminism、legality、abnormal=0、report再現性、semantic behaviorを確認する。10,000-seed runはmanual experimentであり、CIへ常設しない。pure combat testはsurface/database fixtureを持たず、PR102以降のDB-backed testは`tests/Underground/Feature`へ分離し、User、Secretary、Underground専用profile/run/history tableだけを使う。transaction、row lock、unique idempotencyの代表的な競合テストはこの境界で実行できるが、World construction、Nation、MapCell、official Turn、surface bridge fixtureは実行しない。local fullは`composer test:underground`、Surface fullは`composer test:surface`、repository-wide verificationは`composer test:all`を使用し、通常の片側作業で他方のlocal fullを追加しない。Quality CIは両側の全test fileをshardしてcoverする。

代表command:

```text
php artisan underground:balance --manifest=config/underground/balance/foundation-v0.json --seed-start=0 --count=10000 --commit-sha=<40-hex-sha> --output=<report.json>
```

alpha-v1 full summary:

```text
php artisan underground:balance --manifest=config/underground/balance/foundation-v1.json --seed-start=0 --count=10000 --commit-sha=<40-hex-sha> --output=docs/underground-balance-foundation-v1-10000-seeds.json
```

異常seedまたは任意seedのreplay:

```text
php artisan underground:balance --manifest=config/underground/balance/foundation-v0.json --scenario=telegraphed_threat --replay-seed=41
```

alpha-v1 replayは`experiment:build:tier`を指定する。

```text
php artisan underground:balance --manifest=config/underground/balance/foundation-v1.json --scenario=pressure:pure_attacker:early --replay-seed=41
```

reportはmanifest path/hash、raw `manifest_contents`、exact source commit、seed rangeを必ず記録する。外部またはignored experiment manifestもreportから復元でき、embedded contentsとhash/decoded inputが一致しなければ生成を拒否する。replay情報はshell command文字列ではなくargument arrayとして記録し、pathをshellへ再解釈させない。Git metadataがimage内にない場合は`--commit-sha`を明示する。Git HEADを検出した場合はclean worktreeを必須とし、dirtyまたはclean確認不能ならsummary生成をfail closedする。

## Tutorial and future runtime adapters

player-facing first Tutorialはlaboratory `standard_enemy`とalpha-v1 build fixtureのどちらとも別物である。正常操作またはbuilt-in AIで100%勝利できるdeterministic教育encounterを別fixtureとしてauthoringし、laboratoryのstat、win rate、provisional rangeをdifficultyへ流用しない。PR105はTutorial/runtime adapterをalpha-v1へ切り替えない。

PR103 runtimeはpure engineへidentity/profile snapshot、loadout、encounter、built-in AI、seedを渡し、resultを一度だけtransaction内でsettleするadapterとして実装する。Secretary-owned entitlementとprofile初回作成lockはUG-02で決定済みである。PR104は同じpure engine/historyをcurrent User自身のSecretaryへ接続し、variantごとにengineを複製しない。party borrowing/market/Nation-owned facility placement/surface benefitはUG-04のOwner decision後に限る。

## PR104 first-player intro contract

PR104は汎用visual novel/script engineではなく、Secretary-ownedの一方向finite-state introである。短いダミーscene内のpage番号はfrontend local stateでよいが、Tutorial clear、XP settlement、脱出帰還、店員命名とbranch、scripted loss完了、shop説明、地下メイン解禁はserverで永続化する。mutationはSecretary/profile/intro rowを同じlock順で直列化し、profile単位のUUID fingerprint ledgerとbattle unique identityでduplicate、別payload reuse、stage skip、逆戻りを拒否する。

Tutorialはversioned `tutorial_giant_rat` inputと固定starter-knife projectionをcanonical pure engineへ渡す。starter knifeはinventory Item、weapon instance、rarity/affix/durability schemaを作らない。期待resultは100 round未満のplayer victoryだけであり、contract外ならtransactionをrollbackする。settlementはcombat XP +5、shard +0、combat level 1維持だけで、normal cooldown、Trial、通常探索reward/penaltyを通らない。battle compact record/detailはPR103のtable/logを再利用し、詳細action logには共通の1時間retentionを適用する。

PR104時点では、脱出完了後に一度Secretaryメインへ戻し、2回目のentryを店員遭遇・一度だけの1〜20 Unicode grapheme plain-text命名へ進めた。temporary placeholder branchは命名時に保存して後から再判定せず、scripted lossも固定snapshotをcanonical coreへ渡してexpected enemy victory以外をrollbackする。XP、shard、level、cooldown、Trialは前後一致を要求する。このplaceholder branchはPR106以降も既存profileのlegacy identityとしてだけ維持する。

PR104の地下メインはprogression、店員名、Tutorial/story battle historyをread-only投影した。通常狩場、Trial、実shopはdisabledな準備中entryであり、PR103 expedition serviceをplayer APIとして公開しない。この非公開境界はPR106でも維持する。

## PR106 formal intro and alpha-v1 player adapter

PR106はPR104のfinite-state introを拡張し、正式本文、案内人との契約、4 growth pathの一度だけの選択、選択後story、main unlockを明示stageとして保存する。clientはstage keyを指定せず、現在stageで合法なoperationだけをserverが受理する。contract timestamp、growth key、versioned identity、selected timestampはSecretary-owned profileへ保存し、同じprofileのrow lock、UUID fingerprint、database constraintで二重契約、二重選択、別payload reuseを拒否する。

growth catalogは戦技・護身・祝福・自由の固定Lv1能力、derived HP、MP 10,000、自然回復300、default playtest build、Lv2以降の自然成長と未使用STP予定を持つ。全pathのLv1能力は合計100で固定し、自由にも手動割り振り特例を作らない。identityを変えずに既存profileが解釈する初期能力や成長定義を書き換えない。実際のlevel-up stat settlement、STP persistence/配分/reset、growth path変更はこのadapterに含めない。

特別branchのstory戦闘はalpha-v1 canonical combat modelへlocal story build/enemy deltaを渡す。案内人は通常のalpha-v1 Tank action、guard、barrier、闘志、counter、damage/result projectionを使い、別engineを作らない。expected resultは短いdeterministic player defeatで、progression、currency、cooldown、Trial、growth stateの前後一致を要求する。具体的なhidden aliasと背景設定はimplementation-onlyである。

player-facing「力試し（α）」はPR105 immutable manifestのrepresentative 4 buildと3 opponentだけをallowlistし、request-derived private seedでcanonical alpha-v1 modelを実行する。current authenticated User自身のSecretaryかつ契約・growth選択・main unlock済みの場合だけ利用できる。compact battle historyと1時間detail logを再利用するが、XP、輝石の欠片、G、drop、Trial、Combat Lv、cooldown、surface economyのmutationはない。settlement時にbuild/enemy/player表示名、summary、roundごとのaction/status/value、終了HP/MP/barrierを自己完結したplayer-facing projectionとして保存し、後日の表示でcurrent catalogを再参照しない。表示順は実際のevent順を維持し、AI判断理由、private seed、raw manifest、internal database identityを公開しない。

PR104までにmainへ到達したprofileはforward migrationで正式Shop説明へ戻すが、命名や旧scripted lossを再実行せず、growth pathを自動付与しない。既存のplaceholder branch resultはlegacy identityとして保持し、新しいhidden判定で再分類しない。forward migration後もalpha-v0 Tutorial/historyのXP +5、欠片0、Lv1契約を維持する。

## PR107 normal exploration and player growth adapter

PR107はcurrent authenticated User→own Secretary→profileを解決し、通常探索をalpha-v1 canonical combatへ接続する。PR107時点のplayer snapshotは`Lv1 baseline + growth path自然成長 × (Lv - 1) + 確定STP`を構成し、synthetic starter knifeのequipment補正を別段階で加えた。XP/Lv/自然成長/未使用STP、終了HP、G reward/loss、history/cooldownを同じtransactionと既存lock orderでsettleする。Defeatだけ手持ちG半減とHP全回復を行い、銀行Gは保護する。MPはcolumnを追加せず毎battle 10,000から開始する。

## PR108 status and skill progression adapter

PR108はgrowth path選択時にfinite initial 20 SPと`secretary-underground-skill-tree-alpha-v1`を一度だけ保存する。forward migrationは既存のgrowth-selected profileだけを20/20へreconcileし、未選択profileは0/0/nullを維持する。`underground_skill_allocations`はprofile/nodeのrankとnullable active slotを保持し、profile/node unique、profile/slot unique、rank positive、slot 1〜5をdatabaseでも保護する。既存migrationは変更せずforward-only migrationを追加する。

STP allocation、SP node acquisition、active loadout更新は`UndergroundIntroService`の既存UUID fingerprint ledger、Secretary/profile row lock、operation-specific fingerprintを再利用する。同じrequest ID + 同じintentは保存済みprojectionを返し、別payload reuseはconflictにする。node取得はcurrent identity、max rank、同tree prerequisite、lower-tier invested points gate、unspent SPをlock内で再検証してからSPを減算する。STPとSPのreset/refund、Trial SP grantはこのadapterに含めない。

Skill Treeの表示はdesktop 3 column / mobile 3 tabとし、mobileで長い3 treeを連続stackしない。宿は既存の10G・carried balance・UUID retry contractを維持したまま、request中disableと成功後の案内人台詞・HP全回復statusだけをclient feedbackとして追加する。

growth pathとSkill Treeは直交する。growth pathは自然成長、Skill TreeはSP使用先を決め、同じplayer-facing「祝福」名でも組合せを制限しない。祝福treeでは治癒祈祷を0 SP段、精神導路を15 SP段に置き、initial 20 SP内で初期回復役を構成できる。combat snapshotはprogression stats→starter equipment→passive modifiersの順にcanonical runtime inputへ統合し、tree identity、取得node/rank、active skill、effective passive modifierをbattle settlement時に自己完結保存する。後日のhistory表示でcurrent allocationへ再依存しない。

祝福treeの「輝石循環」は既存`mp_restore` effectとpriority AIを使うlocal content deltaで、専用engineを持たない。MP cost 0、cooldown 7、restore 3,000、cap 10,000とし、緊急域で使用可能なhealを先、低MP時のcycleを後に評価する。10,000-seed reportはMP exhaustion、healのMP-block、emergency heal availability、cycle usage/effective restore、overflow、終端MP、round分布を観測する。

`secretary-underground-targeting-alpha-v1`は`taunt`（挑発）をbattle-durationのtarget-selection modifierとして定義する。盾撃・闘志破砕・不屈反攻のauthoringと、`fighting_spirit_enabled`を持つactorの独立counterは、damage effectより先またはdamage結果と独立してenemyへ挑発sourceを記録するため、evasion/complete guardでdamage 0でも成立する。後発sourceが上書きし、将来selectorは明示targetingなしの敵対single-targetだけに適用し、sourceがtarget不能ならnormal selectionへfallbackする。明示random/lowest-HP/role/marked/scripted/ignore-taunt、self、area targetingを上書きせず、control resistanceやduration tickへ流用しない。current canonical engineは1v1のためtarget結果を変えず、round snapshotとeffect logにsource actor、scope、duration、override policyを保存する。party selectorはUG-04のOpen gateに残す。

PR108の軽量浅層観測は、各growth/buildについてLv1 full HPとLv20 full HPを分離し、別にHP持越しの連続探索を1本観測する。Lv1の相対分類は地底鼠・洞窟蟲・腐食スライムをattritionのある雑魚、再生肉塊・狂信者を消耗後に危険な厄介枠、迷い人の影を明確な強敵とし、輝石虫はbonus enemyのため比較対象外とする。win rateは固定契約にせず、enemy categoryの順序、role差、異常な必勝・必敗、MP economyを読む。

PR108時点のLv20 + starter knifeは正式equipment未実装下の参考観測であり、浅層enemyを弱体化するacceptance gateではない。PR108のschema/API/UIはequipment shop、Secretary-owned宝物庫、storage、強化、enchant、affix、unique、sellまたはresaleを先行実装しない。

## PR109 formal equipment, Shop, vault, and shallow balance adapter

PR109は護身用ナイフを含むSecretary-owned equipmentを`1 row = 1 owned instance`として保存し、武器・防具・アクセサリー各1枠と500枠の宝物庫へ接続する。護身用ナイフは既存・新規profileへexactly onceで付与・装備し、非売品かつ0Gとする。装備中instanceも宝物庫の1枠を使用し、500個分の空rowは事前作成しない。

装備Shopは`secretary-underground-shop-equipment-alpha-v1`の固定catalog authorityから12武器、3防具、15アクセサリーを販売する。購入・半額売却・装備変更はcurrent User→own Secretary→profile ownership、server-side definition/price、row lock、UUID fingerprint ledgerを使ってatomicにsettleする。購入は手持ちGだけを使用して銀行から自動引き出しせず、装備中itemを直接売却しない。weaponはreplacementのみ、armor/accessoryはunequip可能とする。max HP増加時はcurrent HPを維持し、減少時だけ新maxへclampする。

通常探索はsynthetic starter injectionを廃止し、progression stats、actual equipped weapon/armor/accessory、passive Skill effects、active loadout、built-in AIを同じcanonical combat snapshotへ渡す。weapon style requirementに適合しないactive skillはruntime snapshotとAI ruleから除外するが、persisted active slotは保持し、適合武器へ戻した時に再利用する。Tutorial、story battle、開発環境限定playtest、PR105 laboratory fixtureはcurrent equipmentへ依存させない。

正式な浅層benchmarkはLv1 Rank 1一式、Lv10 Rank 2一式、Lv20 Rank 3一式とcurrent progressionで観測する。雑魚、厄介、強敵の相対分類とHP持越しattritionを優先し、特定seedの勝率をhard gateにしない。99% complete guardの輝石虫は別軸として維持する。productionではplaytest entryを表示せず、通常探索だけをplayer-facing runtimeとして公開する。random drop、affix、unique、enhancement、enchant、Trialは後続sliceへ残す。
