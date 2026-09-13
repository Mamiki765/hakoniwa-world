# Underground Combat Laboratory and runtime architecture

## Authority and scope

この文書は`secretary-underground-alpha-v0` combat laboratoryから正式runtime、Trial、覚醒、装備、探索、custom AI、およびapplication `3.10.0`のskill rebuild・party rentalまでを扱うcurrent task-specific architecture authorityである。manual combat、unique、enhancement、enchant、market、companion育成は定義しない。

### Party boundary (Owner decision)

Partyは自分のSecretary 1人と、公開・貸出可能な他UserのSecretary最大3人からなる最大4 actorである。同じPT内のSecretary重複とself borrowを拒否し、`team`と`combatant_id`を分離する。同じ貸出秘書は複数の開始者が同時に利用できる。貸出元の読取と数値projection準備は短いtransactionで完了させ、戦闘中は原本のlockを保持しない。開始時snapshotは以後の貸出側profile変更から独立し、貸出側へHP・MP・覚醒その他の戦闘状態を書き戻さない。

借用memberのcombat level上限は開始時Leader本人のcombat level、装備item level上限はLeaderの対応slotとする。対応slotの原装備が上限IL以内なら同じ装備identityを保持し、固定装備の主能力はその装備定義から投影する。上限を超えるgenerated装備だけを上限ILへ再生成する。同期済み数値projectionは原本の数値依存入力・Leader上限・計算identityで再利用し、slotごとの装備projectionも再利用する。projection schema変更時は旧cacheを再計算する。現在HP・覚醒ゲージ・名前・画像・credit・演出文の変更だけでは装備を再生成しない。AI・Skill変更は次battleへ反映する。通常state取得では公開候補を読まず、編成一覧を開いた際のページング取得だけが候補の装備中5枠を読む。

表示用のactor metadataとinitial／round境界／final stateを分離する。round startを保存していない場合は前round終了の時点だと明示する。大画像はsolo・Trial・PTとも開始／覚醒／終了に限定する。画像metadataはbattle当時のものを保存し、現在のviewer設定を返却時に適用する。画像ファイルの保持は詳細logの保持期間と結び付け、差し替え時に全battle JSONを走査しない。

Trial 1/2はsoloのままとする。探索のenemy数はcontentごとのparty-size table、報酬は従来の1 encounter authorityとして別にauthorする。PT Bossは`none`またはparty-size別HP・攻撃倍率tableだけを受け、engineへ人数式を埋め込まない。通常攻略報酬はLeader側だけ、貸出報酬は決着済みbattleとborrowed memberの組をidempotent identityとして10参加ごとにskip ticket 1枚、canonical dayごとに100枚を上限とする。skipは狩場ごとのactual win 50回で1枚消費、Trialごとのactual full clear 5周で10枚消費を解禁し、combatやcooldown、貸出参加を発生させずcanonical repeatable reward settlementだけを再利用する。UIは50%・100%に加え、既存`execution_count`へ1からその時点の実行可能上限まで（1 request最大1,000）を渡す任意回数・周回数を提供する。skip clearは総clear数へ加えるが、解禁用actual countへは加えない。

party battle UIは操作主体の`PARTY`を上段、`ENEMY`を下段へ分離する。compact cardはicon、battle display name、色付きHP、MP current value、覚醒barを表示し、MPの固定上限値と覚醒の内部数値は繰り返し表示しない。覚醒barは満了待機を`Ready`、発動中を`Awaken!`として区別する。iconは1:1、bustとfull bodyは通常・覚醒とも3:4で、覚醒画像がなければ対応する通常画像へfallbackする。各画像の制作方法と権利・creditはslotごとに保存する。

current repository candidate releaseのapplication versionは`3.10.0`、surface Rulesetは`hakoniwa-2s-plus-v25`である。v25はv24の地上契約を保ち、新規島候補を安定順のbounded batchで安全なplanまで評価するbehaviorを追加する。immutable v24は変更しない。Underground laboratory/runtime identityとは別物であり、profile、run、history、intro/growth/skill/equipment/AI stateとpure build snapshotはpublished Ruleset、World、Nation、MapCell、TurnRun、Turn RNGへ依存しない。current combat、exploration、equipment、AI identityと追加contractは本文後半のrelease-specific節を正本とし、過去PR単位の節は各導入時点の境界として読む。

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

敏捷はinitiative、evasion、interrupt/action-delay resistanceへ使い、damage actionにはaction単位の敏捷comboを追加する。initiativeは実効敏捷が高い側を先とし、同値時だけ既存tie-breakを使う。evasionと敏捷comboは絶対値ではなく`max(0, (self - opponent) / (self + opponent))`相当の相対差を使う。このbounded式が敏捷比の増加に従って自然に漸近するため、有限の敏捷比で成長を止めるhard saturationは設けない。相手以下なら敏捷由来evasionとcomboは0である。既存`evasion_bps`は相対敏捷bonusへ加算してから既存total capを適用する。action impairment resistanceは従来どおり進行倍率のreferenceで正規化する。

敏捷comboはactionごとに1回だけ2・3・4連続ヒットを抽選し、通常のcritical・variance・防御・guard等を解決したpost-mitigation damageへ最終倍率を掛ける。action、damage event、critical、status、覚醒ゲージ、native multi-hit数は追加せず、native multi-hitにも同じaction単位のcombo結果を使う。logはnative damage行を増やさず、回避または完全防御ではない最初のdamage行に補助表示用hit数を1回だけ持つ。

current combat identityは`secretary-underground-alpha-v5`、skill tree identityは`secretary-underground-skill-tree-alpha-v2`。3.9.3のv4から3.10.0の技能・MP・会心・対象選択・持続回復・蘇生・行動継続へ更新する。`AlphaV1*`と`foundation-v1.json`は既存canonical implementation lineageであり、file名からpersisted identityを推測しない。過去battleの保存済みsummary/detailの再取得は保持し、旧戦闘engineの並行運用や過去勝敗の再計算は行わない。SP移行は3.10.0のforward migrationを正本とする。

### Alpha-v1 damage and recovery order

alpha-v1は`attack - defense`を使わず、次の順序を固定する。

1. 複数能力のweighted numeratorを合計し、一度だけ整数切捨てする。
2. weapon coefficient、fixed componentを加え、skill potencyを掛けて整数切捨てする。
3. target max-HP componentがあればsource stat由来capを先に適用する。
4. category/all/status modifierと消費stack bonusを適用する。
5. action単位の敏捷combo、critical判定と倍率、95〜105% variance、evasion判定の順に専用label RNGを消費する。
6. `defense reference / (defense reference + effective defense)`でphysical/magical mitigationを算出する。referenceはlevel/item-level benchmarkと同じcurveで伸びる。
7. damage-taken modifier、guard、parryを適用し、合成後の軽減は75% capを越えない。
8. 敏捷comboが成立した場合はpost-mitigation damageへ2・3・4倍の最終倍率を一度適用する。
9. barrierを先に消費し、残りをHPへ適用する。HPを越えるdamageはclampし、合法なhitは最低1 damageとする。

damage prevention metricはHP clamp前のpost-mitigation damageを基準にし、defense / guard / evasion / barrierが実際に防いだ量だけを数える。残HPを越えたoverkillは防御量へ含めない。

回復、barrier、periodic effectはsource stat coefficient、target max-HP coefficient、fixed componentの必要な組合せだけをtyped effectとして持つ。percentage damageはsource stat由来capを必須とし、boss/大HP targetをpercentageだけで倒さない。periodic tickは適用時にbaseをsnapshotし、round endで`base × status stack数 × periodic equipment倍率`を計算して最後に1回だけhalf-upで整数へ丸める。巨大な式DSLは導入しない。

### Trees, points, active slots, and role stacks

skill treeは`martial`（戦技）、`guardianship`（護身）、`miracle`（祝福）の3つで、固定classやbalanced専用treeはない。player-facing labelだけを祝福へ統一し、既存manifest/combat/node identityの`miracle` / `miracle_*`は維持する。取得費の合計は戦技138、護身126、祝福138 SP。すべてactive・rank 1で、微増passiveと投入SPゲートを撤去する。前提は同tree内の技のみ。初期20 SP、Trial 1/2初回clearで各40 SPを得る現行獲得経路を維持し、既得SPを全返還する。5 active枠に加え通常攻撃・防御はslot外。すべてのplayer skillで武器種制限を設けず、growth pathと取得可能なtreeも独立する。数値は3.10.0の調整案でありOwner固定値ではない。laboratoryの120 SP fixtureはruntime獲得上限の意味ではない。

`fighting_spirit`（闘志）は鏡陣を装備し、実際にguard/parry/barrier吸収が発生したときに最大5まで得る。攻撃されていない防御では増えない。反撃は1round1回まで。`grace`（回復恩寵）はハート・オブ・マーシー装備中、通常skillで実効HP回復または蘇生を行った1 actionにつき1（最大5）。1 action内の複数回復は重複せず、HoTのtick・空回復・障壁・解除だけ・覚醒時全回復・覚醒奥義では増えない。ハート・オブ・マーシーは3以上のときだけ使用でき、3を消費する。いずれもcanonical combat flow内のrole stackであり、別engineを持たない。

### Status and boss policy

statusはbuff/debuff disposition、duration、`refresh`または`stack_refresh`、max stacks、typed effectsを持つ。applyされたroundにはperiodic tickとduration decrementを行わず、次のeligible round endからtickして残durationを減らす。refreshはdurationを戻し、stack refreshはcapまで増やしてdurationを戻す。action impairmentの実効skip chanceは`authored chance × (10,000 - agility resistance) / 10,000`を整数切捨てし、agility resistanceはlevel正規化したreference時2,000 bps係数・5,000 bps capとする。cleanse/dispelは実際に対象statusを除去した時だけeffective actionとして数える。duration、barrier、cooldown、stackはunderflow/overflowをabnormal stateとして検出する。

normal targetのaction impairmentをbossへそのまま適用しない。boss profileはinitiative/damage等のsoft effectへ変換し、同種controlの反復には一時resistanceを積み、round endで減衰させる。permanent controlは許さない。

### Priority AI and deterministic equipment

priority AIは上からruleを評価し、最大16 rules・各rule最大2個のAND conditionsを持つ。empty conditionは`always`へ正規化する。vocabularyは`always`、own HP/MP threshold、enemy HP threshold、self/enemy status present/absent、role stack threshold、enemy telegraph、skill ready、round threshold/moduloである。条件成立後もactionが習得状態、装備、MP、cooldown、覚醒状態等により現在使用不能ならそのruleからfallbackせず次のruleへ進む。`jump`は後ろのruleだけを指し、loopを構造的に作れない。全ruleを評価しても実行できない場合だけ、現在使用可能な習得済みattack skillをcanonicalな決定順で選び、それもなければ通常攻撃を使う。AI設定によって何もせずturnを終える状態は作らない。MP不足で上位skillを選べなかったturnはreportへ別集計する。

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

`secretary-underground-targeting-alpha-v1`は`taunt`（挑発）をbattle-durationのtarget-selection modifierとして定義する。盾撃・闘志破砕・不屈反攻のauthoringと、`fighting_spirit_enabled`を持つactorの独立counterは、damage effectより先またはdamage結果と独立してenemyへ挑発sourceを記録するため、evasion/complete guardでdamage 0でも成立する。後発sourceが上書きし、partyでは明示targetingなしの敵対single-targetだけに有効なsource `combatant_id`を優先し、sourceが戦闘不能ならnormal selectionへfallbackする。明示random/lowest-HP/role/marked/scripted/ignore-taunt、self、area targetingを上書きせず、control resistanceやduration tickへ流用しない。既存1v1ではtarget結果を変えず、round snapshotとeffect logにsource actor、scope、duration、override policyを保存する。

PR108の軽量浅層観測は、各growth/buildについてLv1 full HPとLv20 full HPを分離し、別にHP持越しの連続探索を1本観測する。Lv1の相対分類は地底鼠・洞窟蟲・腐食スライムをattritionのある雑魚、再生肉塊・狂信者を消耗後に危険な厄介枠、迷い人の影を明確な強敵とし、輝石虫はbonus enemyのため比較対象外とする。win rateは固定契約にせず、enemy categoryの順序、role差、異常な必勝・必敗、MP economyを読む。

PR108時点のLv20 + starter knifeは正式equipment未実装下の参考観測であり、浅層enemyを弱体化するacceptance gateではない。PR108のschema/API/UIはequipment shop、Secretary-owned宝物庫、storage、強化、enchant、affix、unique、sellまたはresaleを先行実装しない。

## PR109 formal equipment, Shop, vault, and shallow balance adapter

PR109は護身用ナイフを含むSecretary-owned equipmentを`1 row = 1 owned instance`として保存し、武器・防具・アクセサリー各1枠と500枠の宝物庫へ接続する。護身用ナイフは既存・新規profileへexactly onceで付与・装備し、非売品かつ0Gとする。装備中instanceも宝物庫の1枠を使用し、500個分の空rowは事前作成しない。

装備Shopは`secretary-underground-shop-equipment-alpha-v1`の固定catalog authorityから12武器、3防具、15アクセサリーを販売する。購入・半額売却・装備変更はcurrent User→own Secretary→profile ownership、server-side definition/price、row lock、UUID fingerprint ledgerを使ってatomicにsettleする。購入は手持ちGだけを使用して銀行から自動引き出しせず、装備中itemを直接売却しない。weaponはreplacementのみ、armor/accessoryはunequip可能とする。max HP増加時はcurrent HPを維持し、減少時だけ新maxへclampする。

通常探索はsynthetic starter injectionを廃止し、progression stats、actual equipped weapon/armor/accessory、passive Skill effects、active loadout、built-in AIを同じcanonical combat snapshotへ渡す。weapon style requirementに適合しないactive skillはruntime snapshotとAI ruleから除外するが、persisted active slotは保持し、適合武器へ戻した時に再利用する。Tutorial、story battle、開発環境限定playtest、PR105 laboratory fixtureはcurrent equipmentへ依存させない。

正式な浅層benchmarkはLv1 Rank 1一式、Lv10 Rank 2一式、Lv20 Rank 3一式とcurrent progressionで観測する。雑魚、厄介、強敵の相対分類とHP持越しattritionを優先し、特定seedの勝率をhard gateにしない。99% complete guardの輝石虫は別軸として維持する。productionではplaytest entryを表示せず、通常探索だけをplayer-facing runtimeとして公開する。random drop、affix、unique、enhancement、enchant、Trialは後続sliceへ残す。

## release/3.2.0 hack-and-slash equipment foundation

release/3.2.0はPR109のowned instanceと宝物庫をforward migrationし、装備枠を武器1・防具1・アクセサリー3の計5枠へ拡張する。既存の`accessory`はrow identity、grant key、取得日時を維持したまま`accessory_1`へ変換し、equip APIはアクセサリーの`target_slot`を受ける。省略時は`accessory_1`を使い、3枠のstatsとmodifierをcanonical combat snapshotへそれぞれ1回だけ加算する。装備変更時のcurrent HPは増加させず、新しいmax HPまでのclampだけを行う。

固定Shop catalog v1は解決可能なまま保持し、v2に試練1初回clear後のItem Lv 40 Novice装備を追加する。generated instanceは固定definitionと同じowned tableへ、non-nullのdefinition/catalog identity、stable instance/generator identity、source battle、immutable JSONB payloadを保存し、表示・売却・combat projection時に再生成しない。runtime generatorはItem Lv 1-60のanchor補間、RegularからRelicまでのrarity slot、80-100% quality、accessory倍率、既存combat modifierだけを扱い、Uniqueと新しいeffect engineは導入しない。

## release/3.2.0 hunting grounds and equipment drops

通常探索は`secretary-underground-exploration-alpha-v2`をselector identityとし、requestの`hunting_ground_key`をserver-side allowlist、request fingerprint、battle snapshotへ含める。省略時は`shallow_caves`を選び、既存浅層のcontent identityとbattle seedを維持する。第二狩場`black_crystal_cave`は試練1の`first_cleared_at`だけを解放条件とし、新しい進行tableやactivity typeを作らない。黒晶洞の敵定義と報酬は狩場固有のversioned contentへ置き、PR122で確定したcombat identity、Trial 1、Wyvernを変更しない。

通常探索の勝利は`secretary-underground-exploration-drop-alpha-v1`のdrop contractを使い、1 battleにつき最大1個のgenerated装備を抽選する。presence、rarity、Item Lv、category、weapon style/accessory main stat、affixはbattle seedから独立したdomainで導出し、combat RNGへ影響させない。Trial、敗北、撤退では装備dropを行わない。generated payloadはbattle settlementと同じprofile lock・database transaction内でowned instanceへ保存し、`source_battle_id`とgrant keyのunique contractによりretry・並行requestでも二重付与しない。

宝物庫が500枠の場合もbattle、XP、Gはrollbackしない。生成結果は付与せず、battle snapshotへ`drop.status=vault_full`と失われたitemの名称、Item Lv、rarity、affix概要を保存する。付与成功時も同じ自己完結summaryを保存し、history表示でcurrent generator/catalogを再実行しない。浅層と黒晶洞は同じruntime generator、owned equipment、combat projectionを再利用し、drop専用combat engine、proc、status、追加action、別RNG semanticsを導入しない。

## application 3.4.0 custom AI

custom AIはSecretaryごとに有効な設定を1つだけ持ち、`underground_profiles.custom_ai_rules`へnormalized rule listを保存する。`null`はcurrent default presetを使用する状態、`[]`はcustom ruleを持たず最終fallbackだけを使用する状態であり、両者を同一視しない。default presetを複製して編集できるが、複数の名前付きset、OR/NOTを含む汎用論理式DSL、manual combatは導入しない。

更新APIはcurrent User自身のSecretary/profileだけを対象にし、既存のprofile lock、database transaction、UUID request ledgerを再利用する。同じrequest IDと同じnormalized intentは保存済みprojectionを返し、異なるintentでの再利用はconflictとする。actionまたは`skill_ready`条件にはcanonical catalogに存在する未習得skillも保存できる。戦闘時にそのskillが未習得、現在の武器で使用不能、MP不足、cooldown中等なら次のruleへ進む。

default presetは従来のbuilt-in AIの意図した挙動を最大16 rules・各rule最大2 conditionsの範囲で再現し、HP 20%以下の覚醒条件もpreset内の明示ruleとして持つ。覚醒actionは通常actionの時間を消費せず、成立後は同じturnの残りrule評価と既存の覚醒戦技orderingへ進む。custom rulesが覚醒ruleを持たなければ自動覚醒しない。

各battleは開始時に実際に使用するnormalized AI rules全文と、そのcanonical JSONに対するSHA-256 hashをsnapshotへ固定する。Trial進行中もbattle間の設定変更を許可し、次に生成するbattleだけが新設定を使用する。既に生成・保存されたbattle snapshot、projection、replayは後日のprofile設定へ再依存しない。このsnapshot/input semantics変更のためUnderground combat identityを`secretary-underground-alpha-v3`へ更新するが、Surface Rulesetは`hakoniwa-2s-plus-v19`を維持する。

`2026_09_03_020000_add_underground_custom_ai.php`はapplication 3.3.0から3.4.0へのappend-only forward migrationである。既存profileは`null`から開始してdefault presetを使用し、過去battle rowのbackfillやv1/v2 snapshotの書き換えは行わない。player UIはserver-provided catalogだけを選択肢として表示し、defaultへの復帰、defaultの複製、custom empty、rule追加・削除・並べ替え、最大2条件、forward-only jumpを区別して保存する。

## application 3.9.2 party target selection

custom AI ruleは、対応する行動に限ってoptionalな`target`を持てる。3.9.2で追加するselectorは、単体味方回復能力を持つ`mending_prayer`向けの`lowest_hp_ally`と、敵単体行動向けの`untaunted_enemy`だけである。対象指定は既存actionの対象能力を増やさず、self・全体・覚醒奥義やenemy通常攻撃のtarget方式を変更しない。旧ruleのように`target`がなければ従来の対象選択を維持する。

`mending_prayer`の単体味方回復能力は祝福growth pathや固定classではなく、祝福Skill Treeで取得する当該skillのeffectに属する。growth pathとSkill Treeは直交するため、戦技・護身・祝福・自由のどのgrowth pathでも、`mending_prayer`を取得・装備していればPT味方を回復できる。`renewing_guard`、`crystal_aegis`等のself-only skillはgrowth pathにかかわらずselfを維持する。custom AIのtarget validationとdefault AIの味方HP条件化も、growth pathではなくactionのauthoring済みtarget capabilityを参照する。soloで`ally_hp_lte`を評価するときは本人を味方候補に含め、PT用に保存した回復ruleを同じ意味で利用できる。

候補はbattle stateのstable orderから決定し、最低HP割合は生存味方だけ、未挑発敵は生存敵のうち有効な挑発sourceを持たないものだけを対象とする。挑発sourceが欠落または戦闘不能なら未挑発として扱う。対象候補が存在しないruleは実行不能として次のruleへ進み、候補選択と実際のaction targetを同じ`combatant_id`で結ぶ。自由文AIや汎用target DSLは導入しない。

天断一閃の宣言eventは生存敵全体を対象として保持するが、各damage/effect行の`target_ids`は実際に解決した一体だけを記録する。damage式、主対象・副対象倍率、報酬、settlementは変更しない。

## application 3.9.3 enemy single-target selection

### OWNER-DECIDED / IMPLEMENTED

party内の敵が単体の敵対actionを実行する場合、生存中の有効な挑発sourceがあればそのplayerをtargetにし、なければ生存playerからexisting battle RNG contractで決定的に1人を選ぶ。後発挑発による上書き、source死亡時の通常targetへのfallback、全体攻撃を一人へ吸わせない契約は維持する。

actionとscopeが確定し、実際に単体敵対targetが必要だと判明した時点で一actionにつき一度だけ抽選する。同じtargetをAI decision、damage、effect、logへ再利用し、行ごとに再抽選しない。self、全体のみのaction、明示targetを持つaction、action impairment、round-end処理では通常target抽選を消費しない。この導入時のsemantic changeによりcombat identityを`secretary-underground-alpha-v4`とし、v3以前のsnapshotはhistorical recordとして変更しない。

### 3.10.0 Owner decision: enemy AoEの被damage覚醒ゲージ

敵の1 action中に実HP damageまたはbarrier damageを受けたplayerそれぞれへ、最大1回ずつ加算する。複数hitは同じactorへ重複加算せず、回避・無傷だけのactorへは加算しない。soloの加算単位も維持する。Ownerが2026-09-13に決定したUG-06を実装する。

## application 3.10.0 skill rebuild and rental resources

3ツリーの簡易一覧は[release skill list](../../product/docs/releases/3.10.0-skill-list.md)、取得費・比較・検証条件は[release notes](../../product/docs/releases/3.10.0-underground-rebuild.md)を参照する。倍率・技数・名称は今後も調整対象である。

物理攻撃はMP 0とCT、魔法はMP消費と能動MP回復を主な管理軸にする。ルーセントドリームは使用roundの次から6回、round endにMPを回復する。自然回復は従来300のまま。瞬突は5枠の1枠を使い、CT中は選べない。使用後は同じactorの次AI ruleから続行し、通常actionを1回残す。途中の敵死亡で生存敵へ向き直り、反撃死では行動を終了する。

会心の相対技巧計算に渡す主能力は物理なら武力、魔法なら精神とする。体力を参照する物理技は、その技の体力・武力係数による合成値を使う。無関係な他方の能力を伸ばして会心が弱まることを避ける。護身の攻撃4技は体力40%・武力60%、反撃は体力6・武力4の重み。不屈反攻は闘志0でも使え、闘志は追加威力となる。

鏡陣の装備効果は防御・障壁で防いだ攻撃に対し、敵1体につき1round最大1回反撃する。同じ敵の多段hitは上限を共有する。`counter_per_attacker`を持たないNPCなどは従来の全体で1round1回を維持する。既定AIの鏡陣は敵の予告、または自身HP95%以下かつaegisなしで使用する。標準比較の体力60%・武力40%は検証用の配分例であり、取得制限や自動配分ではない。100roundの役割・被弾・MP・攻略実測は[再調整記録](../../product/docs/releases/3.10.0-balance-readjustment.md)を参照する。

single_allyは生存味方のHP割合で選ぶ。持続回復は既に同じ効果を持つ味方を避ける。fallen_allyは戦闘不能者のみ、debuffed_allyは解除可能な弱体を持つ生存者のみ。自己回復・味方保護と敵への挑発はeffectごとに別targetを解決する。HoTは付与者の精神と対象の最大HPからtick量を付与時にsnapshotする。単体蘇生は30% HP、従来の生命讃歌の全員全回復蘇生とは別の技である。

技能移行は旧skill identityのprofileに対してallocationsを削除、unspentをtotalへ再設定、custom AIを解除し、skill_rebuild_requiredを立てる。Lv・XP・STP・成長方針・装備・通貨・進行中の試練・過去結果・覚醒・再振り待ちを変更しない。新しい戦闘は装備枠を明示保存するまで止めるが、完了済みrequestの再取得は先に解決する。意図した通常攻撃構成として空の装備枠を保存することも許す。進行中の試練でも技能を再取得・保存でき、既存checkpointから再開できる。貸出元が未設定なら候補から除外し、既存編成からの新規借用も拒否する。既存battle snapshotや貸出参加履歴は保持する。

PUT rental-partyで編成を明示確定したとき、借用全員をHP満タン・覚醒0にする。空配列はレンタル解除。UUIDとpayloadをcanonical intro requestで記録し、同じrequest再送でリセットを繰り返さない。更新以後のHP・覚醒は借り手profileのrental_partyへ保存し、他の借り手や貸出元へ書き戻さない。戦闘で借用者がHP0になっても0を持ち越す。宿屋はHPだけを全回復する。能力再計算時はHP割合をfloorで再設定し、0は0、生存者は最低1を保つ。状態取得・能力再計算・戦闘再送では覚醒をリセットしない。編成候補の選択と確定をUIで区別し、結果不明の探索がある間は確定を止める。source snapshot準備はLeader lockの外で行い、Leaderの同期条件変更をlock内で検出した場合だけ再準備する。
