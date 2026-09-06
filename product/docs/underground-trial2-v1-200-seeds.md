# Trial 2「黒曜石の魔窟」200-seed balance report

## Status and source

このreportは、既存の基礎戦闘式を変更せず、追加覚醒奥義とTrial 2の10連戦をcanonical Underground runtimeで確認したmanual simulation evidenceである。完全JSONは一時生成物とし、repositoryへ保存しない。

- Branch: `codex/3.6.0-trial2-gameplay`
- Base: `origin/release/3.6.0` / `70871f9e3345ac183e3c2b97b3f499ad672ba6bb`
- Simulation source: `a644447f62bfb3a3749bd57916471c6a58d977b7`
- Simulator: `underground-trial-balance-v3`
- Manifest: `config/underground/balance/trial2-v1.json`
- Manifest SHA-256: `069c03893ac7389e3c71917c2a5dbc332c17c14e06f7d4de174124bc3b66e487`
- Seeds: 16 scenariosそれぞれ`0..199`
- Conditions: Lv150 / Lv180、SP60、generated IL50 uncommon（ゲーム内表示はハイクオリティ）の武器・防具・アクセサリ各1部位、追加覚醒奥義を選択。IL50ノービス装備ではない
- Primary interbattle heal: 20%。Lv150では10% / 20% / 30%も比較
- MP: 各battle開始時10,000へreset、roundごとに300回復
- Result: `working_tree_dirty=false`、`trial_contract_passed=true`、`laboratory_contract_passed=true`
- Abnormal seed / stalemate / failure後の継続 / HP回復overflow: すべて0

`AlphaV1CombatRules`、critical、agility、growth path自然成長、STP entitlement、既存攻撃魔法係数は変更していない。既存combat modelへの追加は、新奥義、解除可否、デュラハンのround-start buff、表示用snapshotに局所化した。新奥義4種のdirect attackは既存damage actionと同じく一行動一回のagility combo抽選を共有し、native hit、critical、statusの追加抽選は増やしていない。Trial 1、Wyvern、黒晶洞の既存parameterも変更していない。

## Battle UI and snapshot

旧表示は戦闘ログより先に最終HPを見せていた。新表示は遭遇から始まり、各roundの開始state、action log、最後に勝敗・戦闘中の最終state・報酬を並べる。分析値は初期状態closedの「戦闘詳細」へ移し、「末尾へ」は維持した。

新battleは`presentation_log_version=2`と次をsnapshotへ保存する。

- battle initial state
- player / enemyの各round end state。次roundの開始cardは直前roundの全終了処理後stateを使用
- HP、MP、barrier、status、role stack、cooldown、taunt
- awakening unlocked / gauge / gauge maximum / active / technique used
- 絶対護界と修羅の血脈の残round

旧snapshotは再実行・推測補完せず、存在する情報だけを従来形式で表示する。

## Additional awakening techniques

覚醒解禁時点で各growth pathの既存奥義と新奥義を両方表示し、説明を読んで戦闘間に一つ選択する。一度の覚醒で使う奥義は一つ、覚醒自体は従来どおり一戦につき最大一回である。選択がNULLのlegacy playerは既存奥義へfallbackし、再振りでは選択をNULLへ戻す。追加SP・skill node・Trial 2 clear条件はない。

| Growth | 新奥義 | 採用値 | Action |
|---|---|---|---|
| 戦技 | 修羅の血脈 | 発動時にpotency 160%、武力80%・技巧20%、weapon 100%のphysical初撃。初撃を含む3round、能動的direct attackが敵HPへ与えた実damageの15%を吸収。既存lifestealとの合計は25% cap | 消費 |
| 護身 | 城塞撃 | potency 180%、生命75%・武力25%、weapon 80%。一回のphysical damage後に次のdirect hitを既存guardで受ける | 消費 |
| 祝福 | 裁きの天光 | potency 220%、精神85%・技巧15%、weapon 100%、会心可。damage後に解除可能buffをkey順で1個解除 | 消費 |
| 自由 | 無相の一撃 | potency 240%、技巧100%、weapon 100%、会心可。実効physical / magical defenseの低い側を一度だけ参照。同値はphysical | 消費 |

修羅の血脈は初撃から吸収が成立する。通常の`lifesteal_bps`は敵・player共通、修羅の加算だけをplayer固有とし、夜宴の血侯の4%吸収も有効にした。いずれもoverkill、barrier吸収、periodic damage、counterを吸収元に含めず、実HP damageを基準として回復は最大HPで止まる。再生は別処理である。裁きの天光は覚醒本体、boss trait、innate effect、`dispellable=false`を解除しない。デュラハンの憎悪は解除可能であり、解除後は次round開始時に1 stackから再開する。

## Trial 2 contract

- Trial 1初回clearで解禁。level hard gateは置かない
- 10戦、HP carry、battle 1～9勝利後に最大HPの20%を回復、MPは毎戦reset
- 各勝利のXP・欠片・dropを同じsettlement transactionで即時確定
- battle間の明示的撤退はrunを終了して次回battle 1へ戻すが、勝利済み報酬を保持
- 未勝利battleのwithdrawal rewardと、勝利後のplayer retreatを混同しない
- defeat時の既存欠片penalty、request idempotency、row lock、active run content identityを再利用
- Trial共通の初回clear報酬としてSP40を付与。Trial 1と合わせてSP総額は100になる
- Trial 2初回clearで第2層を解放し、地底設備枠を8マスまで拡張。初回clear storyを表示する
- 追加覚醒、特別item、称号は付与しない。再clearでSP・地底layer・storyは重複しない

## Enemy stats and AI

能力表記は`生命/武力/技巧/精神/敏捷`、防御表記は`物理/魔法`である。

| # | Enemy / species | 能力 | HP | 防御 | WP | Skill / AI |
|---:|---|---:|---:|---:|---:|---|
| 1 | 煤牙の斥候 / ゴブリン | 350/550/220/150/220 | 2,300 | 175/145 | 180 | 軽いphysical通常攻撃 |
| 2 | 嘲炎の道化 / インプ | 360/180/230/650/170 | 2,700 | 160/175 | 195 | 嘲りの火をready時優先。miracle damage＋85%で2round与damage-6% |
| 3 | 鉄鎖の獄犬 / ヘルハウンド | 400/650/280/160/200 | 3,000 | 185/150 | 205 | 鉄鎖連牙をready時優先。1 action内3 hit |
| 4 | 不寝番の石翼 / ガーゴイル | 650/600/180/180/120 | 3,300 | 380/170 | 210 | 高物理防御、通常攻撃 |
| 5 | 赤角の破城兵 / ミノタウロス | 700/750/180/160/120 | 3,800 | 270/230 | 230 | 4round周期で予告後、破城突進 |
| 6 | 弔鐘の司祭 / レイス | 520/180/230/750/140 | 3,600 | 200/320 | 230 | 加護がなければ弔鐘賛歌。3round魔法damage+10%＋軽いperiodic heal |
| 7 | 蠱惑の蛇姫 / ラミア | 560/300/380/600/210 | 3,600 | 230/245 | 225 | 蠱惑の眼差し。85%で2round与damage-10%・initiative-15% |
| 8 | 夜宴の血侯 / ヴァンパイア | 650/600/320/400/190 | 4,000 | 255/255 | 220 | 会心可のphysical、lifesteal 4%、round回復0.3% |
| 9 | 誓約喰らいの黒騎士 / デーモンナイト | 800/800/250/320/130 | 4,500 | 300/270 | 250 | 黒誓で3round physical+10%、4roundごとにdefend |
| 10 | 首なき断罪卿 / デュラハン | 1000/900/270/300/150 | 5,200 | 340/320 | 300 | 5round周期のdefend・予告・必中ではない断罪突進。HP50%以下で首無しの構え、常時damage reduction 3% |

デュラハンの憎悪は各round開始時に1 stack増え、1 stackあたり全damage+0.5%、最大50 stack / +25%とした。想定ボス戦15～46roundでは主に+7.5～23%まで進み、短期buildを急に倒さず、長期buildへ終盤の圧を作る。hard enrage、固定damage、即死、専用phase engineは追加していない。

## XP, drop and expected battle time

表のroundはLv150 / 20%回復の4代表buildについて、各enemyへ実際に到達したseedの平均を`戦技 / 護身 / 祝福 / 自由`で示す。

| # | Enemy | XP | 欠片 | Drop profile | IL | 平均round（戦/護/祝/自） |
|---:|---|---:|---:|---|---:|---:|
| 1 | 煤牙の斥候 | 250 | 65 | shallow 55% | 55–66 | 6.58 / 4.49 / 5.01 / 10.93 |
| 2 | 嘲炎の道化 | 260 | 68 | shallow 55% | 56–67 | 7.46 / 4.54 / 7.03 / 14.82 |
| 3 | 鉄鎖の獄犬 | 270 | 70 | shallow 55% | 58–69 | 8.35 / 5.47 / 8.06 / 18.33 |
| 4 | 不寝番の石翼 | 280 | 72 | middle 65% | 60–72 | 12.14 / 7.94 / 9.41 / 26.20 |
| 5 | 赤角の破城兵 | 290 | 75 | middle 65% | 62–74 | 13.72 / 9.01 / 19.11 / 41.54 |
| 6 | 弔鐘の司祭 | 300 | 78 | middle 65% | 64–76 | 10.48 / 8.09 / 21.05 / 26.20 |
| 7 | 蠱惑の蛇姫 | 320 | 82 | middle 65% | 66–78 | 11.34 / 8.26 / 13.97 / 25.03 |
| 8 | 夜宴の血侯 | 350 | 88 | deep 80% | 68–82 | 13.20 / 8.07 / 18.58 / 31.14 |
| 9 | 誓約喰らいの黒騎士 | 400 | 95 | deep 80% | 72–86 | 13.62 / 14.67 / 19.36 / 32.58 |
| 10 | 首なき断罪卿 | 2,000 | 540 | deep 80% | 76–90 | 23.61 / 15.41 / 32.28 / 46.10 |

Rarity weightはshallow=`65/25/9/1`、middle=`50/32/15/3`、deep=`35/35/24/6`（common/uncommon/rare/epic）。10戦clear時の期待drop数は6.65個、内訳はcommon 3.2125、uncommon 2.0845、rare 1.1145、epic 0.2385、drop時の期待ILは約70.83である。

累積報酬は3戦でXP780・欠片203・期待drop 1.65個、5戦でXP1,350・欠片350・2.95個、7戦でXP1,970・欠片510・4.25個、9戦でXP2,720・欠片693・5.85個、全clearでXP4,720・欠片1,233・6.65個。深部ほどXP、drop率、IL、rarityが上がるため、battle 1だけで最高装備を完成できない。

黒晶洞のencounter weightによる名目期待値はXP240.25・欠片61.53 / battleである。黒晶虫の99% complete guardと100round上限による勝率63.40%も含めると、十分高Lvで他enemyへ全勝する場合のXPは約235.13 / battleとなる。Trial 2は9戦撤退までの平均がXP302.22・欠片77.00で黒晶洞の約1.25倍、全10戦clearの平均がXP472・欠片123.3で約2倍となる。

装備の売値はtier別例外を足さず、既存のIL共通式で決める。category weightを加味した黒晶洞産の条件付き平均は約236.5 G / drop、魔窟産は序盤約456 G、boss帯約784 G / dropであり、高IL装備の価値だけで約1.9–3.3倍の売値差がつく。

## 200-seed result

主比較は20%回復。平均roundは敗北したrunも含むattempt全体、final HPはclear runだけの平均である。simulation中はlevelとIL50装備を固定しており、実runtimeで途中獲得するlevel-upや新dropへの装備変更は織り込まない。

| Build | Lv | clear | Boss到達 | 平均round | clear時final HP |
|---|---:|---:|---:|---:|---:|
| 戦技・修羅の血脈 | 150 | 16.0% | 97.0% | 119.35 | 1,040.97 |
| 戦技・修羅の血脈 | 180 | 71.5% | 100% | 106.84 | 1,598.10 |
| 護身・城塞撃 | 150 | 100% | 100% | 85.94 | 6,825.00 |
| 護身・城塞撃 | 180 | 100% | 100% | 74.89 | 7,905.00 |
| 祝福・裁きの天光 | 150 | 100% | 100% | 153.84 | 2,230.53 |
| 祝福・裁きの天光 | 180 | 100% | 100% | 116.03 | 2,658.88 |
| 自由・無相の一撃 | 150 | 100% | 100% | 272.84 | 2,735.48 |
| 自由・無相の一撃 | 180 | 100% | 100% | 238.49 | 4,585.10 |

Lv150戦技の敗北はbattle 8が6 / 200、boss到達194 / 200のうちboss敗北が162で、道中farmは成立している。夜宴の血侯の4%吸収を有効にした修正前reportとの比較では、clear率17.0%から16.0%、boss到達97.5%から97.0%へ小幅に低下した。Lv180では71.5%まで上がり、低Lvhard gateではなくlevel・装備・skill・AIで改善できる開始帯から安定帯への勾配を残した。護身は最速かつ安全、祝福は遅いが安定、自由は非常に遅い代わりに自己回復で完走するという差が出た。

default AIはHP20%以下で覚醒するため、ほとんどdamageを受けない護身と自己回復を優先する祝福では新奥義を使用しなかった。これは奥義選択の不具合ではなくAI条件の結果であり、次の限定再測定で戦闘間custom AIにより実使用できることを確認した。新奥義4種の効果と一行動一抽選のagility comboは個別deterministic regressionでも確認した。

### Review後の限定再測定

戦技は装備、build、battle sequence、20%回復、seed `0..199`を同一にし、選択奥義だけを天断一閃と修羅の血脈で切り替えた。

| 奥義 | Lv | clear | Boss到達 | 平均round | clear時final HP | 奥義使用回数 |
|---|---:|---:|---:|---:|---:|---:|
| 天断一閃 | 150 | 16.0% | 97.0% | 116.36 | 1,024.50 | 322 |
| 修羅の血脈 | 150 | 16.0% | 97.0% | 119.35 | 1,040.97 | 322 |
| 天断一閃 | 180 | 71.5% | 100% | 105.83 | 1,602.92 | 161 |
| 修羅の血脈 | 180 | 71.5% | 100% | 106.84 | 1,598.10 | 161 |

この標本ではclear率とBoss到達率は同じで、天断一閃が平均1.01～2.99 round短く、survival差は小さい。修羅だけが常に上位になる結果ではない。

護身・祝福はLv150、20%回復、同じIL50 uncommon 3部位、seed `0..19`で、新奥義を選択したままdefault AIと覚醒優先AIを比較した。覚醒優先AIは既存ruleの先頭にある覚醒HP条件だけを20%から100%へ変え、gauge満了後に最初の行動機会で覚醒する。他のskill優先順は変更していない。

| Build / 新奥義 | AI | clear | 平均round | clear時final HP | 奥義使用回数 | 奥義使用run |
|---|---|---:|---:|---:|---:|---:|
| 護身・城塞撃 | default HP20% | 20 / 20 | 86.15 | 6,825.00 | 0 | 0 / 20 |
| 護身・城塞撃 | gauge満了優先 | 20 / 20 | 83.70 | 8,430.60 | 33 | 20 / 20 |
| 祝福・裁きの天光 | default HP20% | 20 / 20 | 152.30 | 2,248.45 | 0 | 0 / 20 |
| 祝福・裁きの天光 | gauge満了優先 | 20 / 20 | 126.85 | 3,934.20 | 57 | 20 / 20 |

少数比較では両新奥義を全20 runで実使用し、clearを維持したまま平均roundが護身2.45、祝福25.45短縮した。奥義使用回数がrun数を超えるのは、覚醒が一試練一回ではなく従来どおり一戦につき最大一回で、10連戦中に複数battleで発動するためである。この比較はAIで使用可能なことの確認であり、新たなbalance acceptance gateではない。

Lv150の回復率比較は次のとおり。

| Build | 10% | 20% | 30% |
|---|---:|---:|---:|
| 戦技 | 0.5% | 16.0% | 63.0% |
| 護身 | 100% | 100% | 100% |
| 祝福 | 100% | 100% | 100% |
| 自由 | 100% | 100% | 99.5% |

自由30%の1敗はseedごとの別scenario RNGによる標本差で、20%より回復したことが敗因ではない。20%は戦技の連戦負荷を残しつつ他growth pathを壊さないため採用する。

## Identity and migration

- Trial 2は新規`secretary-underground-trial-02-v1`。active runへidentityを保存し、同Trialのcontent変更時だけ既存reconcile contractでbattle 1へ無penalty resetする
- 覚醒選択contractは`secretary-underground-awakening-v2`
- UI logは`presentation_log_version=2`。旧battle rowを書き換えるmigrationはない
- `underground_profiles.awakening_technique_key`をnullableで追加し、growth pathと選択可能keyの組合せをDB CHECKで保護するforward-only migrationを1本追加
- NULLはlegacy fallback、再振りもNULLへ戻すためbackfill不要
- IL上限はexisting generated equipment systemを60から90へ拡張。60以下のanchor・seed出力を維持し、70/80/90 anchorと`obsidian_cavern`表示名だけを追加
- Trial 2産のgenerated equipmentは「魔窟の～」series。accessoryは主能力に応じて生命・武力・技巧・精神・敏捷の5種類の護符名を表示する
- generator identityは既存入力の意味と出力を変えないdeterministic domain extensionとして維持。owned equipment schema、inventory、装備slot、snapshot形式に第二systemやdata migrationを追加しない

Trial 2の初回clearは、SP40、第2層解放、Owner支給のstoryまで確定済みである。特別item・称号・追加覚醒報酬はない。

## Reproduction

```powershell
php artisan underground:balance --manifest=config/underground/balance/trial2-v1.json --count=200 --commit-sha=a644447f62bfb3a3749bd57916471c6a58d977b7
```

このcommandはGit HEADとworking treeを実際に検証できる環境で実行する。Git情報を取得できない環境では`--commit-sha`だけを指定しても`working_tree_dirty`をfalseと推測せず、fail closedする。

release/3.6.0、main、production、OCI、production DB、Owner管理handoffには未反映である。
