# Underground Combat Laboratory and runtime architecture

## Authority and scope

この文書は`secretary-underground-alpha-v0` combat laboratory、PR102 persistence foundation、PR103 expedition runtime backend、PR104 first-player Tutorial flowのcurrent task-specific architecture authorityである。manual combat、正式equipment/shop、normal hunt/Trial content、surface bridgeは定義しない。

PR104のapplication versionは`3.0.0-alpha.1`である。surface Ruleset `hakoniwa-2s-plus-v18`とUnderground laboratory/runtime identityは別物であり、Ruleset payloadは変更しない。PR102〜104のprofile、run、history、intro stateはpublished Ruleset、World、Nation、MapCell、TurnRun、Turn RNGへ依存しない。

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
- `product/config/underground/balance/foundation-v0.json`: versioned laboratory inputとinitial observation metadata。
- `product/tests/Underground/`: surface test suiteと分離したcontract tests。

`Domain/Underground`から`App\Models`、`App\Domain\Turn`、Laravel database、World、Nation、MapCell、TurnRun、surface Ruleset identityへ依存してはならない。Secretary接続とtransactionはApplication/Model境界で扱い、combat coreへEloquent modelを渡さず、runtime adapterがimmutable inputへ変換する。

## Underground persistence boundary

### Ownership and lifecycle

Undergroundの恒久的なplayer progression ownerは`Secretary`である。combat level/XP、輝石の欠片、trial unlock/progress、地下箱庭で解禁済みのarea layer等のSecretary固有状態はNationから独立して保持する。PR102では地下箱庭entitlementを、PR103ではcombat progressionとruntime stateを追加する。equipment、skill、探索基地等の将来状態はこのowner境界を継承するが、今回のPRで先回りして永続化しない。

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

### Settlement and progression

- `BattleResult`とcompactなplayer-facing action/round logを受け取った後、result settlement、XP付与、level-up、輝石の欠片報酬または敗北loss、trial progress、first-clear layer unlock、battle history、cooldownを一つのtransactionで一度だけ確定する。
- `combat_level`は`1`、`combat_xp`は`0`から開始する。XP curveはUnderground側のversioned alpha balance inputとしてretune可能にし、combat level/XPから`unlocked_area_layers`を算出しない。
- 輝石の欠片はsurface money/resourceと交換しないSecretary-owned非負整数balanceである。通常敗北時のsettlement前balanceを`floor(balance / 2)`へ減らし、runを終了して安全地点へ帰還する。追加の未鑑定Item lossやequipment penaltyはこのruntimeにない。

### Trial lifecycle and ownership

- trialのbattle間progressはSecretary-ownedで永続化し、browser close、logout、単なる離席では失わない。defeat、またはbattle終了後の明示的な帰還ではactive runを終了し、次回trial battleを1へresetする。ただしtrialのunlock済みidentityは保持する。
- 各trialのfirst clearだけが`unlocked_area_layers`を1増やす。capacityは`unlocked_area_layers * 4`から派生し、1 trial = 1 layer = 4 facility slotsである。同じtrialを再clearしてもlayerを重複取得せず、first clear後に次のtrialをsequentialにunlockする。
- stalemate withdrawalはHP 0 defeatと異なり欠片を失わないが、trial runは終了してprogressをbattle 1へresetする。通常探索・trialとも欠片rewardはなく、base XPの1/4だけを整数切り捨てでsettleする。trialの正確な戦闘数、enemy、boss、balanceはversioned content decisionへ残す。

### Cooldown, idempotency, and concurrency

- battle終了後の次回battle開始可能時刻をserver-authoritativeな`next_battle_at`で管理し、10秒未満のstart requestを拒否する。cooldown待ちでrequest/processをsleepまたはblockしない。
- runtimeはauthenticated adapterが解決したcurrent User→own Secretaryのcontextだけを受け取り、player-controlled Secretary IDをownershipの根拠にしない。profile、active run、battle/request identityをrow lockとunique identityで直列化する。
- duplicate request/retryは保存済みidempotency identityにより同じsettled resultを返すか再適用せずに拒否する。concurrent requestも同時battle、cooldown突破、XP/欠片の二重付与、trial progress/layer unlockの二重進行を許さない。idempotency/audit identityは詳細logのretention後も保持する。

### Battle history retention

battle historyにはencounter identity、runtime result（victory/defeat/withdrawal。canonical `stalemate`はwithdrawalへ分類）、round count、ordered action/round sequence、damage/recovery等のplayer-facing summary、battle timestamp、retention expiryを保存する。内部debug objectやraw simulation payload全体は永続化しない。詳細logのretention windowはbattle終了から1000時間とし、期限後は`underground:prune-battle-logs`で削除する。productionでは既存のOCI host cron thin-trigger patternから日次実行し、battle summary、aggregate、idempotency/audit identityは保持する。Laravel schedulerや巨大なworkflow subsystemは追加しない。

## Permanent contracts and experiment observations

恒久的に保護するものは次である。

- deterministic executionと同一input＋seedの完全replay。
- HP、resource、cooldown、action selectionの合法なstate transition。
- abnormal stateゼロ。
- max-round到達を明示的`stalemate`として返すこと。
- manifest hash、source commit、seed range、simulator versionを含む再現可能なreport。
- 上記scenario semantics。
- surface/domain/database dependencyゼロ。

alpha-v0の10,000-seed観測値はstandard 79.24%、fast 56.10%、armored 75.38%、telegraphed 67.83%である。これらとmanifestのprovisional rangeはlaboratory/statisticsを検証したinitial observation envelopeにすぎず、player-facing targetでも将来の固定acceptance thresholdでもない。将来balanceを維持する義務はなく、first playable前に自由にretuneできる。

exact source `9c8a17b7ca6b2e31e7cf0da28951b786826b4715`での集計は[`underground-balance-foundation-v0-10000-seeds.json`](../../product/docs/underground-balance-foundation-v0-10000-seeds.json)に保存する。raw per-seed action logは含めず、異常seedは最大10件だけを残す。

manifestの通常4scenarioには`acceptance`を設定しない。simulatorは別のexperiment manifestが任意の`acceptance`を指定する機能を保ち、結果を`experiment_thresholds_passed`として分離する。unit testでは`synthetic_stress` scenarioだけでthreshold violationの集計を確認し、通常4scenarioのwin rateをCI gateへ焼き込まない。

## Report contract

summary reportはraw action logを全seed分保存せず、scenarioごとにwin/loss/stalemate、round percentiles、damage、skill/action/resource usage、initiative、telegraph/heavy/guarded-heavy、abnormal seeds最大10件とreproduction commandを持つ。

`resource_overflow_units`はcapを越えて破棄されたgain量であり、不正なout-of-range stateではない。`abnormal_rate`は実際にHP/resource invariantを破ったfightだけを数える。

full manifestを実行したreportはsemantic observationsと`laboratory_contract_passed`を返す。scenario filterで比較相手がないsemanticは評価しない。任意experiment thresholdsは別fieldであり、laboratory contractと混同しない。

## Verification boundary

pure combat CI smokeは32程度のseedでdeterminism、legality、abnormal=0、report再現性、semantic behaviorを確認する。10,000-seed runはmanual experimentであり、CIへ常設しない。pure combat testはsurface/database fixtureを持たず、PR102/PR103のDB-backed testは`tests/Underground/Feature`へ分離し、User、Secretary、Underground専用profile/run/history tableだけを使う。transaction、row lock、unique idempotencyの代表的な競合テストはこの境界で実行できるが、World construction、Nation、MapCell、official Turn、surface bridge fixtureは実行しない。

代表command:

```text
php artisan underground:balance --manifest=config/underground/balance/foundation-v0.json --seed-start=0 --count=10000 --commit-sha=<40-hex-sha> --output=<report.json>
```

異常seedまたは任意seedのreplay:

```text
php artisan underground:balance --manifest=config/underground/balance/foundation-v0.json --scenario=telegraphed_threat --replay-seed=41
```

reportはmanifest path/hash、raw `manifest_contents`、exact source commit、seed rangeを必ず記録する。外部またはignored experiment manifestもreportから復元でき、embedded contentsとhash/decoded inputが一致しなければ生成を拒否する。replay情報はshell command文字列ではなくargument arrayとして記録し、pathをshellへ再解釈させない。Git metadataがimage内にない場合は`--commit-sha`を明示する。Git HEADを検出した場合はclean worktreeを必須とし、dirtyまたはclean確認不能ならsummary生成をfail closedする。

## Tutorial and future runtime adapters

player-facing first Tutorialはlaboratory `standard_enemy`と別物である。正常操作またはbuilt-in AIで100%勝利できるdeterministic教育encounterを別fixtureとしてauthoringし、standardのstat、win rate、provisional rangeをdifficultyへ流用しない。PR1はTutorialを実装しない。

PR103 runtimeはpure engineへidentity/profile snapshot、loadout、encounter、built-in AI、seedを渡し、resultを一度だけtransaction内でsettleするadapterとして実装する。Secretary-owned entitlementとprofile初回作成lockはUG-02で決定済みである。PR104は同じpure engine/historyをcurrent User自身のSecretaryへ接続し、variantごとにengineを複製しない。party borrowing/market/Nation-owned facility placement/surface benefitはUG-04のOwner decision後に限る。

## PR104 first-player intro contract

PR104は汎用visual novel/script engineではなく、Secretary-ownedの一方向finite-state introである。短いダミーscene内のpage番号はfrontend local stateでよいが、Tutorial clear、XP settlement、脱出帰還、店員命名とbranch、scripted loss完了、shop説明、地下メイン解禁はserverで永続化する。mutationはSecretary/profile/intro rowを同じlock順で直列化し、profile単位のUUID fingerprint ledgerとbattle unique identityでduplicate、別payload reuse、stage skip、逆戻りを拒否する。

Tutorialはversioned `tutorial_giant_rat` inputと固定starter-knife projectionをcanonical pure engineへ渡す。starter knifeはinventory Item、weapon instance、rarity/affix/durability schemaを作らない。期待resultは100 round未満のplayer victoryだけであり、contract外ならtransactionをrollbackする。settlementはcombat XP +5、shard +0、combat level 1維持だけで、normal cooldown、Trial、通常探索reward/penaltyを通らない。battle summary/detailはPR103のtable/logと1000時間retentionを再利用する。

脱出完了後は一度Secretaryメインへ戻す。2回目のentryは店員遭遇・一度だけの1〜20 Unicode grapheme plain-text命名へ進む。temporary alpha authoringのexact `ダミー`だけをspecial branchとして命名時に保存し、後から再判定しない。scripted lossも固定snapshotをcanonical coreへ渡し、expected enemy victory以外はrollbackする。XP、shard、level、cooldown、Trialは前後一致を要求する。

shop説明後の地下メインはprogression、店員名、Tutorial/story battle historyをread-only投影する。通常狩場、Trial、実shopはdisabledな準備中entryであり、PR103 application serviceをplayer APIとして公開しない。story本文、正式trigger、equipment、skill tree、status effect、normal hunt/Trial balance、shop economyは引き続きOpen/Deferredである。
