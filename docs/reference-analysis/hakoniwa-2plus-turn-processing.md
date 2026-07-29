# 箱庭諸島2＋ ターン処理

## 更新の入口

`main()` は現在秒で `srandom` を初期化する（`main.c:3-5`）。メンテナンス以外で、ゲーム開始済み、国家あり、終了ターン前、かつ `lastTime + unitTime < time(0)` なら `MODE_TURN` へ切り替える（`main.c:25-41`）。デバッグモードでは `TurnMode` のCGI入力でも更新できる（`HakoIO::cgiInput`, `hako_io.c:364-367`）。

更新直前に `lastTime += unitTime` を1回行い、`Turn::main` を呼ぶ（`main.c:72-76`）。遅延ターンを一度に全消化するループはない。

配布物にcron設定や専用turn executableはない。通常のCGI requestが`main()`を通るたびに期限を判定し、期限到来時は本来の画面modeを`MODE_TURN`へ置き換える。したがって外部から定期的にHTTP requestを送る運用は考えられるが、このarchiveだけから呼出元、間隔、成功判定、再試行方法は確定できない。`Makefile`も`hakow.cgi`のbuildだけを担う。

`main()`はinfo読込前に`Util::lock()`を呼ぶ（`main.c:21-23`）。`Util::lock`はdata directoryをopenして`flock(LOCK_EX)`を呼ぶが、標準的な`flock`の0=成功という契約と分岐が逆に見えるため、そのまま新作の排他仕様とはしない（`util.c:123-142`）。新作はPostgreSQL advisory lockを正本にする。

## 主要な呼び出し関係

| 入口 | 呼出先 | 役割 |
|---|---|---|
| `main` (`main.c:3-147`) | `HakoIO::cgiInput` → `Util::lock` → `HakoIO::readInfoFile` | CGI入力、全体排他、保存済み状態読込 |
| `main` (`main.c:35-41,72-76`) | `Turn::main` | 期限到来またはdebug入力による1ターン実行 |
| `Turn::main` (`turn.c:9-148`) | `Map`, `Island`, `Command`, `HakoIO`, `Mentenance` | 1ターン全体の順序を固定 |
| `Command::exec` (`command.c:74-103`) | `Com::exec` | 先頭計画の実行、削除、保持、次計画への再帰 |
| `Com::exec` (`command.c:127-624`) | `Com::buildCommand`, `Map`, `Info`, `HakoIO::logOutput` | command固有の検証、費用、quantity、即時効果または遅延効果 |
| `Map::process` (`map.c:264-739`) | 災害、人口、森林、ミサイル、怪獣処理 | セル乱数順のその場更新 |
| `Map::globalDisaster` (`map.c:742-819`) | 災害function群、`disMonster` | 世界単位の災害・怪獣出現 |
| `Map::estimate` / `Land::estimate` (`map.c:1293-1326`) | `Island::clear` | 変更後セルから人口・面積・施設規模を再集計 |
| `HakoIO::writeMapFile` / `writeInfoFile` | `Map::output` / `Info::output` | 別fileへの非原子的な保存 |

## 全体の処理順序

`Turn::main` の確定順序は次の通り（`turn.c:9-148`）。

| 順 | フェイズ | 主な処理・根拠 |
| ---: | --- | --- |
| 1 | ターン番号 | `Info::turn++` (`turn.c:9-14`) |
| 2 | 準備 | マップ読込、国家順・セル順の乱数順列 (`16-18`) |
| 3 | ログ | 世代スライド、新ログを開く (`20-24`) |
| 4 | 海際度 | 範囲外4セル分も含む海から半径4へ加算 (`26-31`; `Map::calcSea`, `map.c:191-209`) |
| 5 | 国境感化 | 全セルを乱数順に `Map::infLand` (`turn.c:33-36`) |
| 6 | 国家収支 | 順位順に一時値初期化、収入・食料消費、開始人口記録 (`38-47`) |
| 7 | コマンド | 国家を乱数順に、各国の先頭コマンド1件を処理 (`49-55`) |
| 8 | セル処理 | 全セルを同じ乱数順列で `Map::process` (`57-60`) |
| 9 | 難民 | 国家を乱数順に受け入れ処理 (`62-66`) |
| 10 | 全体災害 | 地震、津波、台風、流星群、巨大隕石、噴火、怪獣 (`68-69`; `map.c:742-819`) |
| 11 | 再集計 | 全セルから人口、面積、産業を再計算 (`71-72`) |
| 12 | 上限・消滅・受賞 | 食料/資金制限、放棄/人口0、賞 (`74-115`) |
| 13 | 削除・順位 | 消滅国領土を海にし、人口順ソート、owner変換 (`117-131`) |
| 14 | 永続化 | ログclose、map、info、履歴切詰め (`133-143`) |
| 15 | バックアップ | `turn % backUpTurn == 0` でローテーション (`145-148`) |

コメント上は62行目も「コマンドフェイズ」だが、実処理は難民受入れである。

## 乱数と公平性

乱数はCGIプロセス開始時に `srandom(time(0))` で秒単位seedを設定し、`Util::dice(n) = random() % n` を使う（`main.c:3-5`; `util.c:62-65`）。国家順と全セル順はFisher-Yates型にシャッフルする（`Turn::makeOrder/makeOrderXY`, `turn.c:151-198`）。

**確定**：seed、各乱数結果、順列は保存しないため、ターンの完全再現はできない。同じ秒に同じ初期状態で別プロセスが始まれば同じ乱数列になる可能性もある。新作ではターンIDに結び付いた再現可能seedとイベント記録を検討する。

## 国家単位の収支

収支はターン開始時点で保存されていた `pop`, `farm`, `factory`, `mountain` を使う（`Island::income`, `info.c:285-303`）。

- 人口が農場規模×10を超える場合、食料を農場規模×10増やす。
- 余剰人口 `(pop - farm*10)/10` と `factory+mountain` の小さい方を資金収入とする。
- 人口が農場だけで吸収できる場合、食料を人口分増やす。
- その後、`food*100 - pop*eatenFood` を計算し、負なら食料0・飢餓フラグ1、非負なら100で切り捨てる。

同梱 `eatenFood=20` なので人口1単位あたり食料0.2単位を消費する（`config.cgi:64-65`）。食料不足は後のセル処理で町の人口減少、農場・工場・基地・防衛施設の暴動を引き起こす（`Map::process`, `map.c:295-419,643-656`）。

資金繰りコマンドは資金+10、`absent`+1。`giveupTurn` 到達で次の先頭行動を放棄へ差し替える（`Com::exec`, `command.c:144-159`）。有効な他コマンドは `absent=0` に戻す（`command.c:160-161`）。

## コマンドフェイズ

各国家の `command<ID>.cgi` を読み、先頭だけを実行し、直後に同ファイルへ書き戻す（`turn.c:49-55`; `Command::exec`, `command.c:74-103`）。無効コマンドはキューから除去し、再帰的に次を同ターン中に試す。ターンを消費しないコマンドも次を実行し得る。回数指定の農場・工場・採掘場等は `amount` を減らして先頭に残せる。

ミサイルコマンドはこの時点では国家一時状態 `tx`, `ty`, `command`, `amount` を設定するだけで、各ミサイル基地セルの処理時に実射する（`command.c:535-548`; `Map::process`, `map.c:411-641`）。怪獣派遣も対象国の `amonster` を増やし、対象国の有人口町セルが処理された時点で出現する（`command.c:550-564`; `map.c:295-304`）。

### 整地の埋蔵金

`Prepare`（新作の`land_clear`）は所有・資金・対象地形の検証に成功した後、費用5を差し引き、対象を人口0の平地へ変更してcommand成功logを記録する。その直後に`dice(1000) < disMaizo`を1回判定する（`command.c:205-245`）。同梱設定は`disMaizo=10`なので、draw 0–9が成功、10–999が失敗となる正確な10/1000である。成功時の報酬は`100 + dice(901)`、すなわち100–1,000億円inclusiveで、直ちにmoneyへ加算し、金額を含む通常公開log 211を記録する。invalid target、ownership failure、insufficient moneyでは費用控除、地形変更、抽選、報酬のいずれも発生しない。`Prepare2`では埋蔵金を抽選しない。

旧作は埋蔵金を含む途中収入にcapacityを適用せず、turn末にmoneyを9,999へhard truncationする。新作はowner決定済みのcapacity安全境界を優先し、同じ抽選と報酬rangeをcommand-owned labelled streamで再現した上で、受取可能額とoverflowをstructured resultへ記録する。stream labelは`development_commands:land_clear:buried_treasure`とし、別用途のdraw位置を進めない。

### 地ならし由来の即時地震

`Prepare2`（新作の`land_level`）も共通検証成功後に費用100を差し引き、対象を人口0の平地へ変更してcommand成功logを記録する。その後、同じcommand call内で`dice(1000) < disEarthquake2`を独立に1回判定する（`command.c:205-236`）。同梱設定は`disEarthquake2=5`であり、successful `Prepare2`ごとの正確な5/1000である。invalid target、ownership failure、insufficient moneyでは抽選しない。`Prepare2`はturn非消費なので、失敗時だけでなく成功時もqueueから除去した後に同じturnの次itemへ進む。

当選時はcounterやmodifierを蓄積せず、その場で`Map::disEarthquake(x, y)`を呼ぶ。震源半径10の331候補から範囲内cellを走査し、人口100 legacy単位以上（canonical 10,000人以上）の都市、工場、ハリボテを対象として、それぞれ`dice(4) == 0`なら荒地化する（`map.c:870-903`）。地震発生と各崩壊は通常公開logである。後の`Map::globalDisaster`が行う通常地震80/1000とは独立し、基礎確率への加算、counter、clampは存在しない。

Owner decisionにより、PR #11は`land_level`本体の検証、費用控除、平地化、turn非消費queue制御、structured event、transaction rollbackだけを実装する。このcommand-time earthquakeは抽選とdamageを分離できず、Capital被災時の新作invariantも未決定なため全体を延期する。架空の`TurnState`、未使用flag、`global_disasters`へのmodifier境界は追加しない。通常global disasterのB-09とは別に`docs/open-questions.md`のCMD-02を再開gateとする。

### 戻り値とturn消費

`Com::exec`の戻り値はqueue制御でもある。

| 戻り値 | `Command::exec`の扱い | 代表例 |
|---:|---|---|
| `0` | 先頭を削除し、同じturnに次の先頭を再帰実行 | validation失敗、資金・食料不足、地ならし、援助、食料輸出 |
| `1` | 先頭を削除し、この国家のcommand phaseを終了 | 通常成功、資金繰り、ミサイル予約 |
| `2` | 先頭itemをその位置に保持し、この国家のcommand phaseを終了 | 残quantityがある農場・工場・採掘場建設、放棄差替え |

資金繰りは明示計画がなくてもqueue末尾へ常に補充される`DoNothing`で、資金10億円を加算し`absent`を増やす（`command.c:89-96,144-159`）。失敗は例外ではなく数値logを生成し、itemを除去して次へ進む。費用は共通の事前確認後、各handlerが地形・対象を再検証して成功させる直前に差し引く。失敗経路では原則差し引かない。ミサイルは予約時に差し引かず、後の基地セル処理で1発ごとに差し引く（`map.c:441-448`）。

### `amount`（新作の`quantity`）の複数の意味

保存形式は`kind target x y amount`であり、`amount`は0..99へ補正される（`command.c:105-142`）。意味はcommand handlerが決めており、queue自体は解釈しない。

| command | 旧作での意味 | 根拠 | 新作の境界 |
|---|---|---|---|
| 農場・工場・採掘場 | 1回成功後に1減らし、残数があれば先頭へ保持 | `command.c:484-507,626-697` | handlerがdecrementとretain-headを返す |
| 海底油田の掘削 | 同一turnの予算倍率。`min(cost*amount, money)`を一括支出し、`floor(spend/cost)%`で抽選 | `command.c:320-341` | handlerが一括回数、費用、抽選を所有 |
| ミサイル | 発射上限。0は実質無制限の1000へ置換 | `command.c:535-548` | handler/後続combat phaseが消費 |
| 記念碑 | 繰返し回数ではなくdesign番号。種類数以上は0 | `command.c:471-477` | handlerがparameter valueとして解釈 |
| 資金・食料援助、食料輸出 | 100保存単位の倍数。0でも残高があれば最低100単位 | `command.c:566-603` | handlerが送付・売却単位とcapacityを判断 |

このため新作の`CommandQueueService`へdecrement、一括使用、design番号、費用倍率を戻してはならない。

## 保存単位、表示桁、整数演算

保存、計算、表示を追跡した結論は次の通り。`Island::output/input`は整数をそのまま保存し（`info.c:159-235`）、`Island::jsOut`が同じ整数をJavaScriptへ出し（`info.c:269-281`）、`hakow.js`のformatterが末尾桁を付加する。

| 項目 | 旧作の保存値1単位 | 旧作の表示 | 現在の正本 | 旧→現在 |
|---|---:|---|---:|---:|
| 人口 | 100人 | 保存値 + `00人` | 1人 | ×100 |
| 食料 | 100トン | 保存値 + `00トン` | 1トン | ×100 |
| 資金 | 1億円 | 保存値 + `億円` | 1億円 | ×1 |
| 農場規模 | 1,000人分 | param + `0` + `00人規模` | 既存scale 1、`scale_unit_people=1,000` | scaleは×1、人数は×1,000 |
| 工場規模 | 1,000人分 | 同上 | 既存scale 1、`scale_unit_people=1,000` | 同上 |
| 採掘場規模 | 1,000人分 | 同上 | 既存scale 1、`scale_unit_people=1,000` | 同上 |
| 農場生産 | scale 1につき食料10保存単位 | 1,000トン | food inventory 1トン | scale 1につき1,000トン |
| 工場・採掘場の収入能力 | 稼働可能scale 1につき1億円 | 億円 | inventory方式を予定 | 1,000労働者につき1億円相当 |

表示根拠は`hakow.js:18-31,551-593,700-718`、保存根拠は`info.c:159-235`、施設集計根拠は`Land::estimate`（`map.c:1293-1326`）である。初期村の`param=5`が500人とコメントされることも人口換算を裏付ける（`new_island.c:153-169`）。

`Island::income`の式を現在単位へ展開すると次になる（`info.c:285-303`）。

```text
農業労働者 = min(population, farm_scale × 1,000人)
食料生産 = 農業労働者 × 1トン
非農業労働者 = max(0, population - 農業労働者)
工業・採掘の稼働scale = min(floor(非農業労働者 / 1,000人),
                                factory_scale + mine_scale)
旧作の直接資金収入 = 稼働scale × 1億円
食料消費 = population × 0.2トン
```

C/C++整数除算は非負値について切捨てである。`(pop - farm*10)/10`、`food100/100`、余剰食料の`r/10`、食料輸出の`c/10`はすべて切捨てる。明示的な切上げは確認できない。他国向け資金表示だけは`((money + 500) / 1000) * 1000`で1,000億円単位へ四捨五入する（`info.c:271-280`）。地図表示側は`Math.floor`を怪獣種類等に使うが、国家資産換算の切上げには使わない。

### 新作の工業品・鉱物inventoryへの置換

旧作は工業と採掘を区別せず、合計稼働scaleをそのturnに直接整数億円へ変える。新作ではこの副作用を移植しない。

```text
工場労働者1人 → industrial_goods 1単位
採掘場労働者1人 → minerals 1単位
inventory 1,000単位 → 売却可能額1億円
1,000未満とmoney capacityで売れない分 → inventoryに残す
```

これにより旧作の「1,000労働者で1億円」という価値対応を保ちつつ、moneyを整数億円のまま維持できる。PR #7はproductionと自動売却を実行せず、整数quote/resultの境界だけを追加する。

## 資金・食料の保有上限

上限はconfigではなく`Turn::main`へ直接埋め込まれている（`turn.c:74-91`）。

| 項目 | 旧作上限 | 旧作1単位 | 現在の基礎上限 |
|---|---:|---:|---:|
| 資金 | 9,999 | 1億円 | 9,999億円 |
| 食料 | 9,999 | 100トン | 999,900トン |

適用順は食料、資金である。食料が9,999を超えると、超過全量を在庫から除き、`floor(超過/10)`億円を加算する。その後moneyを9,999へ切り詰める。食料超過の10保存単位未満、money上限を超えた換金額は失われる。援助は送信側残高を先に全量消費し、受信側へ全量加算するが、最終上限処理で受信側超過が失われる（`command.c:566-603`; `turn.c:82-91`）。費用支払後の空きcapacityに合わせて援助量や売却量を減らす処理はない。

森林売却、油田収入、怪獣残骸、埋蔵金、資金繰り、工場・採掘場収入も途中では上限を見ず、turn末に一括切捨てる。上限を増やす施設、item、command、設定値はこのsourceから確認できない。`rename.c:69-75`の特別password経路はmoney/foodを9,999へ直接設定する管理用debug動作であり、capacity modifierではない。

新作では超過を黙って消さない共通加算結果`before/requested/applied/overflow/after/capacity`を使う。food capacityは固定resource keyではなく`resource_definitions.category = food`の国家別合計へ適用する。工業品・鉱物の売却は受取可能なmoney分だけinventoryを消費し、売れない整数単位と1,000未満の端数を残す。

## 途中失敗と原子性

旧作はturn番号を処理冒頭で増やし、`lastTime`を`Turn::main`呼出前に進める。各国command fileはその国の実行直後に上書きされる一方、mapとinfoはturn末に別々のfileへ上書きされる（`main.c:72-76`; `turn.c:9-14,49-55,133-143`）。write functionはopen失敗を0で返すが、`Turn::main`は戻り値を検査しない（`hako_io.c:62-76,100-151`）。process crash、disk error、途中例外をrollbackする仕組みはなく、commandだけ更新、mapだけ更新、時刻だけ更新という部分状態が起こり得る。

新作はturn run履歴だけをtransaction外で開始・失敗記録に使い、game state、event、current turnはWorld単位の単一PostgreSQL transactionで確定する。失敗時はgame stateをrollbackし、同じrun/seedによる明示的な再試行境界を残す。

## セル単位処理

`Map::process` は地形種別の巨大な `switch` で処理する（`map.c:264-739`）。主な挙動は次の通り。

- 油田：資金+1,000、毎ターン40/1000で枯渇して中立深海（`map.c:275-290`）。
- 町：人造怪獣、都市火災、飢餓減少、村発生、人口成長、誘致活動（`295-380`）。
- 森：200まで+1（`382-387`）。
- 農場：飢餓時1/4で暴動・荒地（`389-396`; `Map::disRiot`, `map.c:858-868`）。
- 工場：火災、飢餓時暴動（`398-409`）。
- ミサイル基地/海底基地：射程、費用、基地レベル、誤差、防衛施設、着弾効果を処理（`411-641`）。
- 防衛施設/ハリボテ：飢餓暴動、ハリボテ火災、自爆広域被害（`643-664`）。
- 怪獣：硬化判定後、最大移動回数内で隣接セルへ移動し元を荒地化（`666-716`; `monster.c:55-86`）。
- 記念碑：起動済みなら自身を荒地にし、対象国中心の半径5内から着弾点を選んで広域被害（`718-733`）。

セルは乱数順でその場更新されるため、怪獣移動先が同じターンに後から処理され、種類によって複数歩動く仕組みになっている。`flag` が移動回数を抑制する（`map.c:666-715`）。

## 人口増減

町 `param` は100人単位。

- 飢餓時は各町から1..30単位を減らし、0未満を0にする（`map.c:313-320`）。
- 通常時、人口0の平地は隣接農場または有人口町があり、100面ダイス20未満なら村化する（`321-341`）。海際度に応じた `ratio` を計算するが、この判定では使われていない。
- 町は海際度に応じ20/50/100まで成長し、誘致中はその上も200まで成長する（`343-377`）。
- 通常ミサイルで他国都市を破壊すると人口を難民候補へ加算し、後で半数だけを攻撃国中心半径5の既存町へ1セル最大50、町上限200で収容する（`map.c:610-621,1075-1105`）。
- 火災・災害・ミサイル等は町を荒地または海へ変え、セル人口を全損させる。割合減少や最低保証はない。

### B-16 settlement appearance・成長のexact境界

人口0の`Land::Town`はlegacy上の平地であり、ownerが0なら`Map::process`冒頭で島を解決できず処理を終了する。このため候補は所有された人口0の平地である。legacyの単一`kind`表現では施設なしも暗黙に保証される。候補はまず`dice(100) < 20`を引き、その後に隣接6セルの農場数と人口1 legacy単位以上の`Land::Town`数を数える。合計が1以上なら`param`を0から1へ増やす（`map.c:321-341`）。隣接cellのownerは検査しない。海際度から算出した未使用のappearance用`ratio`は結果へ影響せず、確率は全候補で20/100である。drawが隣接条件より先なので、eligible plainは隣接対象がなくてもappearance streamを1回消費する。

発生人口1 legacy単位はcanonical 100人である。cellはrandomized orderでその場更新されるため、先に発生した村は同じturnの後続cellにとって人口ありの隣接集落となる。完全なsimultaneous candidate snapshotへ置き換えてはならない。

海際度はcell processing前に、海、海底基地、範囲外海から半径4の各cellへ加算する（`Turn::main`, `turn.c:26-31`; `Map::calcSea`, `map.c:191-209`）。`SeaLevel2=24`以上、`SeaLevel1=12`以上24未満、12未満について、通常上限とlegacy growth ratioはそれぞれ100/3、50/2、20/1である（`map.h:166-168`; `map.c:343-377`）。canonical populationへ展開すると通常上限は10,000/5,000/2,000人、通常成長rangeはinclusive 100–900/100–600/100–300人となる。POP-01に従って100人刻みを保存せず、同じminimum、maximum、expected valueを持つ1人単位のinteger rangeを使う。上限到達後の通常成長はない。

誘致中は通常上限未満でinclusive 100–3,000/100–2,000/100–1,000人、到達後は100–300/100–200/100人ずつ成長し、20,000人でclampする。PR #11対象commandには誘致がないため、この式は将来stateを接続できるruleset境界として残し、常時有効化しない。

presentation stageはlegacy表示と`Land::landValue`から、人口1–2,999人をvillage、3,000–9,999人をtown、10,000人以上をcityとする（`hakow.js:536-551`; `map.c:1336-1345`）。人口は正本であり、stageは閾値から決まる。飢餓時はappearanceとgrowthの分岐へ入らず、各`Land::Town`からlegacy 1–30単位を減らして0でclampする（`map.c:313-320`）。canonical rangeはinclusive 100–3,000人である。0になったcellは人口0の平地へ戻る。

legacyはNation center x/yを持つだけでCapital facility identityを持たない。新作ではowner decisionにより、Capitalはidentityを維持したまま同じ通常growthとfamine lossを受け、village/town/city facilityへ置換しない。Capital固有のdamage、最低人口、機能停止は戦闘・災害gateを越えて先行決定しない。

## ミサイル

通常、PP、陸地破壊、弾道の4種。弾道以外は `missileReach` を超える基地から撃てない（`map.c:419-436`; `command.h:47-52`）。1基地あたりの発射数は基地経験値によるレベル分で、国家の残数・資金の範囲で撃つ（`map.c:438-448`; `Land::getLevel`, `map.c:1377-1397`）。PPは誤差半径1（7候補）、他は半径2（19候補）（`map.c:450-461`）。

真の防衛施設が着弾点の半径2内にあれば空中爆破するが、防衛施設そのものへ直撃した場合は周辺防衛判定を省く（`map.c:479-489`）。陸地破壊弾は地形を海・荒地へ変え、通常系は怪獣へ1ダメージ、施設・町を荒地化する（`491-630`）。通常/PPで他国町を破壊した場合だけ難民が発生し、弾道では発生しない（`610-621`）。

## 怪獣

8種類のHP、変動HP、能力、基地経験値、残骸価値が配列へ直書きされる（`monster.c:3-23`）。セル `param` は `kind*20 + hp`（`Monster::makeMonster/fromParam/toParam`, `monster.c:31-71`）。能力には2歩、高速、奇数/偶数ターン硬化がある（`monster.c:11-17,55-86`）。

全体怪獣処理は `disMonster` 回だけランダム座標を試し、適切な陸地で所有国人口が1,000単位以上なら人口帯に応じた怪獣を出す（`Map::globalDisaster`, `map.c:784-818`）。`disMonster` は確率/1000ではなく1ターンの試行回数である。

## 災害

全体災害の地震、津波、台風、流星群、巨大隕石、噴火は、それぞれ独立に `dice(1000) < 設定値` で1回発生判定する（`Map::globalDisaster`, `map.c:742-782`）。火災は各対象セルで同じく `/1000`、地均し地震と埋蔵金はコマンド実行時 `/1000`（`Map::disFire`, `map.c:840-856`; `command.c:230-243`）。

- 地震：中心半径10の都市(人口100以上)、工場、ハリボテを各1/4で荒地化（`Map::disEarthquake`, `map.c:870-903`）。
- 津波：半径10の対象施設を、隣接海数に応じ荒地化（`map.c:905-945`）。
- 台風：半径10の農場・ハリボテを対象とし、隣接森/記念碑で被害率軽減。被害時は人口0平地へ（`map.c:947-982`）。
- 流星群：半径10内へ1発以上、継続確率1/2で複数着弾し、海底基地/油田/陸地等を深海化（`map.c:984-1028`）。
- 巨大隕石：中心半径2へ `wideDamage`（`map.c:767-775,1109-1165`）。
- 噴火：中心を山、隣接6セルを地形に応じ浅瀬・荒地化（`map.c:777-782,1030-1073`）。
- 火災：隣接する森または記念碑があれば完全防止（`map.c:840-856`）。

## ログ、保存、バックアップ

ターン開始時に過去ログをずらし、新しい `logfile0.cgi` を開く。各処理は数値イベントを書き、ターン末に閉じる（`turn.c:20-24,133-134`; `hako_io.c:467-504,582-591`）。その後 `map.cgi`、`info.cgi` の順に別々に上書きし、履歴を10行へ切り詰める（`turn.c:136-143`）。保存全体は非トランザクションである。

`backUpTurn` ごとに `.cgi` ファイル群を `data.bak0` へローテーションコピーする（`turn.c:145-148`; `Mentenance::slideBack`, `mentenance.c:107-151`）。

## 新作へ残す候補

- ターンを明示的フェイズへ分ける考え方。
- 国家順・セル順の偏りを避けるシャッフル。ただし再現可能なversioned labelled streamへ変更する。
- コマンド予約と1ターン1基本行動。
- 収支後にセルイベント、最後に再集計するドメイン上の順序。ただし新仕様とテストで再確定する。
- 災害・戦闘・国境変更をイベントログに残すこと。

新作へ持ち込まない「その場更新による順序依存」とは、DB取得順、memory layout、chunk load順、偶然のiteration順などlegacy実装技法への暗黙依存を指す。一方、A-06でDecidedとなったrandomized sequential causalityはゲームルールとして維持する。元のstable order、専用shuffle stream、逐次反映を明示的に実装してtestし、simultaneous resolutionへ勝手に変更しない。

この設計上の解釈は上記のsource確認事実を変更しない。グローバル可変状態、秒seed、非原子的保存、rank-based ownerは持ち込まず、全セル走査は必要性と性能測定に基づいて扱う。
