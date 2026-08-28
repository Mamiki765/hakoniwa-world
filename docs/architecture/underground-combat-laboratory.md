# Underground Combat Laboratory architecture

## Authority and scope

この文書は`secretary-underground-alpha-v0` combat laboratoryのcurrent task-specific architecture authorityである。playerから到達できないalpha-v0実験環境を定義し、将来のplayer-facing combat balance、Tutorial、persistence、API、UI、surface bridgeを定義しない。

地上のcurrent baselineはapplication 2.8.0 / `hakoniwa-2s-plus-v18`であり、このlaboratory identityとは独立している。PR1はpublished Ruleset、schema、migration、World、Nation、MapCell、TurnRun、Turn RNG、production dataを変更しない。

## Modular-monolith boundary

実装は同じLaravel repository内のmodular monolithだが、coreはframework-freeなpure PHPである。

- `product/app/Domain/Underground/Combat/`: rules、state、AI、private RNG、engine、result。
- `product/app/Application/Underground/`: manifest駆動simulationとreport aggregation。
- `product/app/Console/Commands/UndergroundBalance.php`: file I/OとCLI adapter。
- `product/config/underground/balance/foundation-v0.json`: versioned laboratory inputとinitial observation metadata。
- `product/tests/Underground/`: surface test suiteと分離したcontract tests。

`Domain/Underground`から`App\Models`、`App\Domain\Turn`、Laravel database、World、Nation、MapCell、TurnRun、surface Ruleset identityへ依存してはならない。将来User/Secretary identityを接続する場合も、coreへEloquent modelを渡さず、明示的application adapterがimmutable inputへ変換する。

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

exact source `f9255ffb30b9ee8fae5908f8d0b1d96c75b41d18`での集計は[`underground-balance-foundation-v0-10000-seeds.json`](../../product/docs/underground-balance-foundation-v0-10000-seeds.json)に保存する。raw per-seed action logは含めず、異常seedは最大10件だけを残す。

manifestの通常4scenarioには`acceptance`を設定しない。simulatorは別のexperiment manifestが任意の`acceptance`を指定する機能を保ち、結果を`experiment_thresholds_passed`として分離する。unit testでは`synthetic_stress` scenarioだけでthreshold violationの集計を確認し、通常4scenarioのwin rateをCI gateへ焼き込まない。

## Report contract

summary reportはraw action logを全seed分保存せず、scenarioごとにwin/loss/stalemate、round percentiles、damage、skill/action/resource usage、initiative、telegraph/heavy/guarded-heavy、abnormal seeds最大10件とreproduction commandを持つ。

`resource_overflow_units`はcapを越えて破棄されたgain量であり、不正なout-of-range stateではない。`abnormal_rate`は実際にHP/resource invariantを破ったfightだけを数える。

full manifestを実行したreportはsemantic observationsと`laboratory_contract_passed`を返す。scenario filterで比較相手がないsemanticは評価しない。任意experiment thresholdsは別fieldであり、laboratory contractと混同しない。

## Verification boundary

small CI smokeは32程度のseedでdeterminism、legality、abnormal=0、report再現性、semantic behaviorを確認する。10,000-seed runはmanual experimentであり、CIへ常設しない。surface、database、World construction、official Turn、migration、concurrency fixtureを実行しない。

代表command:

```text
php artisan underground:balance --manifest=config/underground/balance/foundation-v0.json --seed-start=0 --count=10000 --commit-sha=<40-hex-sha> --output=<report.json>
```

異常seedまたは任意seedのreplay:

```text
php artisan underground:balance --manifest=config/underground/balance/foundation-v0.json --scenario=telegraphed_threat --replay-seed=41
```

reportはmanifest path/hash、exact source commit、seed rangeを必ず記録する。Git metadataがimage内にない場合は`--commit-sha`を明示する。Git HEADを検出した場合はclean worktreeを必須とし、dirtyまたはclean確認不能ならsummary生成をfail closedする。

## Tutorial and future runtime adapters

player-facing first Tutorialはlaboratory `standard_enemy`と別物である。正常操作またはbuilt-in AIで100%勝利できるdeterministic教育encounterを別fixtureとしてauthoringし、standardのstat、win rate、provisional rangeをdifficultyへ流用しない。PR1はTutorialを実装しない。

future runtimeはpure engineへidentity/profile snapshot、loadout、encounter、seedを渡し、resultをtransaction内でsettleするadapterを追加する。persistence/lock/idempotencyはUG-02、Tutorial/progression/defeat/API/UI/versionはUG-03、party borrowing/market/facility/surface benefitはUG-04のOwner decision後に限る。variantごとにengineを複製しない。
