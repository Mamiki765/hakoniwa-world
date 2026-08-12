# 箱庭諸島2 / 箱庭諸島2＋ / 箱庭諸島2S＋ gameplay 仕様由来監査

## 文書の位置づけ

この文書は、箱庭諸島2S＋の仕様を自動的に変更するための実装指示書ではない。箱庭諸島2原版、箱庭諸島2＋、現行箱庭諸島2S＋の由来と意味差を、raw sourceを優先して記録するread-only比較監査である。

- 共有World、共通map、Nation ownership、領土、他Nationとのmap上の相互作用は箱庭諸島2S＋の構造的前提として維持する。
- 本監査を理由に、公開済み`hakoniwa-2s-plus-v1`、v2、v3、turn処理、production semantics、World、migrationを変更しない。
- `CANDIDATE-H2-REALIGN`はowner decisionを要する将来候補であり、変更承認ではない。
- ver 1.4.1へ利用するのは既存scope内のログ・UI表示の根拠だけとし、依頼時に指定された「新ruleset、DB migration、reset、backfillなし」を維持する。
- 比較表の「現行2S＋」は、別作業中の1.4.1 working treeではなく、固定baseline `origin/release/1.4.0`（`5452bc75e805e66aaf26fa30cb4a2c68cacdd712`）を`git show`でread-only参照した結果を指す。local `release/1.4.0` refはこのcommitより古いため使用していない。
- 1.4.1 working treeは比較値、分類件数、結論の正本に含めない。ログ節で「1.4.1への補助根拠」と明記した箇所だけが別作業への参考情報である。
- `_references/`は一切変更していない。

## 一次資料と優先順位

判定順は `raw source > 現行のversioned ruleset / runtime code > 既存analysis document` とした。既存analysisは索引とowner decisionの確認に使い、由来の正本にはしていない。

### 箱庭諸島2原版

- `_references/hakoniwa-2/hako-main.txt` — `箱庭諸島 ver2.30`、設定、terrain、command、怪獣定義。
- `_references/hakoniwa-2/hako-turn.txt` — turn、command、人口、生産、災害、怪獣、missile、log。
- `_references/hakoniwa-2/hako-top.txt` — TOPと通常ログ。
- `_references/hakoniwa-2/hako-map.txt` — 個別島map、owner/visitor表示、個別島ログ。
- `_references/hakoniwa-2/hako-mente.txt` — 作成・削除・backup復元等の運用。gameplay本体には原則不使用。
- `_references/hakoniwa-2/hako-readme.txt` — 配布物構成とencoding指示。

`hako-main/top/map/turn/mente`はEUC-JP、保存されている`hako-readme.txt`はShift-JISとして解読した。readme自身はCGI sourceをEUCにするよう指示する（`hako-readme.txt:57-75`）。新規文書はUTF-8で作成した。`jcode.pl`はgameplay監査対象外である。

### 箱庭諸島2＋

- 配布archive: `_references/hakoniwa-2plus/source/hakow094.tar`
- archive内raw sourceの展開物: `_references/hakoniwa-2plus/extracted/`
- 主な監査対象: `config.cgi`, `turn.c`, `map.c`, `map.h`, `command.c`, `command.h`, `info.c`, `info.h`, `monster.c`, `monster.h`, `new_island.c`, `hako_io.c`, `hako_io.h`, `owner.c`, `sight.c`, `hakow.js`

2＋ sourceはShift-JISとして解読した。このsnapshotは0.94系の挙動を示すが、変更履歴一式ではない。

### 現行箱庭諸島2S＋ baseline

- 固定ref: `origin/release/1.4.0`、commit `5452bc75e805e66aaf26fa30cb4a2c68cacdd712`。
- 公開ruleset chain: 同refの`product/config/hakoniwa/rulesets/roadmap-pr2-v1.php`、`roadmap-pr11-v1.php`、`roadmap-pr15-v1.php`、`roadmap-pr18-v1.php`、`roadmap-pr21-v1.php`、`roadmap-pr22-v1.php`、`hakoniwa-2s-plus-v1.php`からv3まで。
- runtime: 同refの`product/app/Application/CompleteTurnEngine.php`、`DisasterTurnService.php`、`DomesticCommandExecutor.php`、`MissileImpactResolver.php`、`MonsterTurnService.php`、`LegacyInspiredInitialIslandGenerator.php`、`PlayerIslandEventService.php`等。
- owner decision: 同refの`docs/open-questions.md`、`docs/decisions/`、各`product/docs/*-audit-*.md`。

`origin/release/1.4.0..HEAD`では上記gameplay ruleset/runtimeと`PlayerIslandEventService`に差がないことも確認した。1.4.1差分はこの確認から除外した。

## 分類方法

比較表の主分類は件数集計のため排他的に付ける。`CANDIDATE-H2-REALIGN`は意味上`PLUS-DIVERGENCE`を内包し得るが、件数は二重計上しない。

| 由来 | 意味 |
|---|---|
| A | 箱庭諸島2原版の仕様 |
| B | 箱庭諸島2＋で追加・変更された仕様 |
| C | 共有Worldを成立させるために維持する2＋/2S＋仕様 |
| D | owner decisionで明示的に追加・採用した2S＋仕様 |
| E | 2＋由来だが、原版2由来と誤認していた可能性がある仕様 |
| F | 根拠不足または意図不明 |

| 主分類 | 判定 |
|---|---|
| `KEEP-SHARED` | 共有Worldを成立させるため維持する。原版との差だけで削除しない。 |
| `KEEP-EXPLICIT` | owner decisionで明示採用済み。原版との差だけで再検討しない。 |
| `MATCH-H2` | 現行の主要semanticsが原版2と一致する。 |
| `PLUS-DIVERGENCE` | 2＋で原版2から変わり、現行にも残る。現時点では自動変更候補にしない。 |
| `CANDIDATE-H2-REALIGN` | 共有Worldを壊さず原版へ寄せられる可能性があり、owner decision候補とする。 |
| `UNKNOWN` | snapshotまたはowner intentの根拠が不足する。 |

## 主要仕様比較表

数値は現在の表示単位へ換算した。原版と2＋の内部人口1は100人、食料1は100トンである。

| 分野 | 箱庭2 | 箱庭2＋ | 現行2S＋ | 由来 | 主分類 | 今後の検討・根拠 |
|---|---|---|---|---|---|---|
| World構造 | 12×12の個別島map | 60×60の共有World | 60×60の共有World | C | `KEEP-SHARED` | 大枠を維持。H2 `hako-main.txt:137-145`; 2＋ `config.cgi:42-45`; ADR-0003。 |
| ownership / territory | 島単位でowner、島内cell ownershipなし | cell ownershipと周囲影響 | Nation別cell ownership、領土、active Nation間influence | C | `KEEP-SHARED` | 削除対象外。2＋ `map.c:211-260`; v3 ruleset `territory_influence`。 |
| Capital | 独立概念なし | 島中心はあるがCapital facilityなし | 永続Capital、初期1,000人、最低100人 | D | `KEEP-EXPLICIT` | B-01/B-19。共有Worldのidentity core。 |
| 村・町・都市閾値 | 村100–2,900、町3,000–9,900、都市10,000以上 | 同じ | 同じ | A | `MATCH-H2` | H2 `hako-map.txt:446-461`; 2＋ `map.c:1336-1345`; PR11 `:60-64`。 |
| 通常人口成長 | 全村町が100–1,000人、通常cap 10,000人。海隣接不問 | 海際度で100–300/600/900人、cap 2,000/5,000/10,000人 | 2＋と同じ3 band | E | `CANDIDATE-H2-REALIGN` | H2 `hako-turn.txt:1421-1464`; 2＋ `map.c:343-377`; engine `:933-981`。owner decision待ち。 |
| 誘致人口成長 | 10,000人未満は100–3,000人、以後100–300人、cap 20,000人 | 海際度倍率つき100–1,000/2,000/3,000人、通常cap後100/200/300人、cap 20,000人 | 2＋と同じ | E | `CANDIDATE-H2-REALIGN` | H2 `hako-turn.txt:1427-1468`; 2＋ `map.c:343-377`; PR11/PR22。 |
| 食料不足時人口減 | 食料<0で各集落100–3,000人減、0以下で平地 | 同じ | 同じ。Capitalだけ最低100人を維持 | A | `MATCH-H2` | H2 `hako-turn.txt:1424-1451`; 2＋ `map.c:313-320`; engine `:891-926`。Capital差は別行。 |
| 平地への集落発生 | 各平地20%。隣接6セルの有人集落または規模のある農場 | 20%。海際度別ratioを計算するが判定は固定20% | 20%。隣接有人集落または農場 | A | `MATCH-H2` | H2 `hako-turn.txt:1470-1477,1625-1650`; 2＋ `map.c:321-341`; engine `:844-888`。 |
| 海際度による農工場立地 | なし | 農場は12以上、工場は24以上 | 海際度制限なし | A | `MATCH-H2` | 2＋独自制約を継承していない。2＋ `command.c:442-459`; PR2 `:57-73,91-93`。 |
| Capital人口成長 | 該当なし | 該当なし | 通常cap 25,000人、最低100人 | D | `KEEP-EXPLICIT` | B-01/B-16。原版集落capへ自動統合しない。 |
| 食料消費 | 人口×0.2トン/turn相当 | 同じ | `population_per_nutrition=5`、同じ0.2トン | A | `MATCH-H2` | H2 `hako-turn.txt:483-485`; 2＋ `info.c:295-303`; PR11 `:44-47`。 |
| 労働配分 | 農場優先、余剰人口だけ工場・採掘場 | 同じ | 農場優先、残りを工場/採掘場へcapacity比例配分 | A | `MATCH-H2` | H2 `hako-turn.txt:460-485`; 2＋ `info.c:285-303`; PR11 `:48-54`。後段配分方式は明示的再構成。 |
| 工場・採掘場の生産物 | 能力と余剰労働に応じ資金へ直接変換 | 同じ | 工業品・鉱物stockを生産 | D | `KEEP-EXPLICIT` | E-03。原版にないことは削除理由にしない。 |
| 食料cap / overflow | 999,900トン。超過を1,000トン→1億円で自動売却 | 同じ | aggregate食料cap後、既存sale contractで超過売却 | A | `MATCH-H2` | H2 `hako-turn.txt:2026-2030`; 2＋ `turn.c:81-90`; resource profile audit。追加stockは別契約。 |
| 資金cap / overflow | 9,999億円、超過切捨て | 同じ | 9,999億円capacity、受取時にcap | A | `MATCH-H2` | H2 `hako-turn.txt:2032-2035`; PR11 `base_money_capacity`。 |
| 資金繰り基本効果 | 0cost、+10億円、turn消費 | 同じ | 明示またはempty queue時+10億円、1 Nation 1 turn最大1回 | A | `MATCH-H2` | H2 `hako-turn.txt:520-536`; 2＋ `command.c:127-180`; engine `finance()`。lifecycle差は別行。 |
| 資金援助 | 100億円×quantity、非turn消費 | 同じ | 100億円×quantity、capacityまで | A | `MATCH-H2` | H2 `hako-turn.txt:1362-1391`; 2＋ `command.c:566-603`; PR22 `:197-200`。 |
| 食料援助quantity | 10,000トン×quantity、非turn消費 | 同じ | 1,000トン×quantity、capacityまで | D | `KEEP-EXPLICIT` | PR22 command contract。将来変更するならunitと既存queueをowner判断。 |
| 追加resource stock | 資金・食料のみ | 資金・食料のみ | 小麦、魚、怪獣肉、工業品、鉱物 | D | `KEEP-EXPLICIT` | E-03、MONSTER-04。原版にないことだけで削除しない。 |
| 基本command cost | 整地5、地ならし100、埋立150、掘削200、植林50、農20、工100、採掘300、基地300、防衛800、海底8,000、記念碑9,999、ハリボテ1、誘致1,000、怪獣3,000 | 同値 | 同値をversioned command catalogへ採用 | A | `MATCH-H2` | H2 `hako-main.txt:484-540`; 2＋ `command.c:5-35`; PR22。 |
| 非turn消費command | 地ならし、失敗、食料輸出、援助は次計画へ進む | 同系 | queueから消費して再検証し、非turn消費結果は次計画へ進む | A | `MATCH-H2` | H2 `hako-turn.txt:367-371`; current command executor/tests。 |
| facility quantity | 農場/工場/採掘場をquantity回、1 turn 1段階で継続 | 同系、quantity 0..99 | queue quantityで継続し各turn再検証 | A | `MATCH-H2` | H2 `hako-turn.txt:830-875`; 2＋ `command.c:127-137`; current queue contract。 |
| settlement上書き | 植林、農工場、基地、防衛、記念碑、ハリボテは町を上書き可 | 同じ | village/town/cityを上書き可 | A | `MATCH-H2` | H2 `hako-turn.txt:721-847`; 2＋ `command.c:626-697`; `SettlementOverbuildPolicy.php`。 |
| Capitalへのcommand | 該当なし | 該当なし | terrain/facility commandで上書き禁止 | D | `KEEP-EXPLICIT` | B-01/B-19、current execution policy。 |
| ST / 弾道 / SPP | ST 50億、通常弾相当、発射者匿名 | 弾道ミサイル150億、通常誤差、射程無制限 | SPP 500億、誤差0、発射Nation公開 | D | `KEEP-EXPLICIT` | PR22 owner decision。名称だけで同一視しない。 |
| 伐採後terrain | 森→平地、木100本×5億円 | 森→平地、同額 | 森→荒地、capacity内で受領 | E | `CANDIDATE-H2-REALIGN` | H2 `hako-turn.txt:705-720`; 2＋ `command.c:376-394`; command audit `:54`。owner decision待ち。 |
| 埋立・掘削transition | 海→浅瀬→荒地。山→荒地、陸→浅瀬、浅瀬→深海 | 同系 | 同系。共有ownership境界を追加 | A | `MATCH-H2` | H2 `hako-turn.txt:593-704`; 2＋ `command.c:248-374`; executor `:560-578,1068-1099`。 |
| 海底基地建設水深 | 深海のみ | 深海・浅瀬、自領半径3条件 | 自領近傍半径3の`sea`のみ | F | `UNKNOWN` | H2 `hako-turn.txt:877-891`; 2＋ `command.c:509-533`; PR22 `:143-145`。意図的H2回帰か未記録。 |
| ミサイル跡 | 荒地paramで通常荒地と画像だけ区別 | 同じ | 独立`scorched` terrain | D | `KEEP-EXPLICIT` | ver 1.4.0の領土影響を含む明示拡張。 |
| 農場規模 | 初期10,000人、+2,000、最大50,000 | 同じ | 同じ | A | `MATCH-H2` | H2 `hako-turn.txt:721-770`; PR2 `:57-61`。 |
| 工場規模 | 実コード初期30,000人、+10,000、最大100,000 | 同じ | 同じ | A | `MATCH-H2` | H2 `hako-turn.txt:772-818`; PR2 `:63-67`。原版commentの10,000は実コードと不一致。 |
| 採掘場規模 | 初期5,000人、+5,000、最大200,000 | 同じ | 同じ | A | `MATCH-H2` | H2 `hako-turn.txt:848-876`; PR2 `:69-73`。 |
| ミサイル基地経験値 | Lv1–5、20/60/120/200、最大200、Lv数発射 | 同じ | 同じ | A | `MATCH-H2` | H2 `hako-main.txt:186-199`; PR2 `:75-83`; `MissileBaseRules.php`。 |
| 海底基地経験値 / 発射capacity | Lv1–3、50/200、1/2/3発 | 同じ | 経験値を持たず常に1発 | E | `CANDIDATE-H2-REALIGN` | H2 `hako-main.txt:186-199`; 2＋ `map.c:419-446`; resolver `:72-77`。owner decision待ち。 |
| 防衛施設の同cell再建 | 自爆flagを設定し後に広域被害 | 同じ | facility存在として失敗。怪獣接触時だけ自爆 | E | `CANDIDATE-H2-REALIGN` | H2 `hako-turn.txt:795-807`; 2＋ `command.c:646-660`; current executor/monster service。 |
| 記念碑の同cell再建 | 対象島へ飛翔し広域被害 | 同じ | 再建失敗。種類を選んで設置のみ | E | `CANDIDATE-H2-REALIGN` | H2 `hako-turn.txt:809-829`; 2＋ `map.c:718-737`; current executor。 |
| ハリボテ | 1億、防衛施設へ偽装 | 同じ | 1億、防衛施設としてpublic表示 | A | `MATCH-H2` | H2 `hako-turn.txt:753-757`; 2＋ `command.c:679-683`; PR22。 |
| 災害発生scope / rate | 島ごとまたはcellごと。地震0.5%、津波1.5%、台風2%、隕石1.5%、巨大0.5%、噴火1% | World中心＋半径10。8%、30%、40%、20%、10%、20% | 2＋型のWorld中心＋半径10。4%、15%、20%、10%、5%、10% | E | `CANDIDATE-H2-REALIGN` | H2 `hako-main.txt:207-228`; 2＋ `config.cgi:76-101,map.c:742-782`; PR15 `:16-68`。大きいbalance決定。 |
| 地震damage | 都市10,000+、工場、ハリボテを各1/4。地ならし回数で追加 | 対象は同系、World中心型 | 対象と1/4は同系。globalと地ならしを別stream | A | `MATCH-H2` | H2 `hako-turn.txt:1663-1688`; current PR15。Capital割合damageはD。 |
| 津波damage | 島全域で海隣接数に応じ集落・農工場・基地・防衛・ハリボテ | World半径10、同系 | World半径10、海隣接判定、Capitalは割合damage | B | `PLUS-DIVERGENCE` | H2 `hako-turn.txt:1718-1749`; 2＋ `map.c:906+`; PR15。scopeは2＋由来。 |
| 台風・火災防御 | 森または記念碑が防御。台風は農場/ハリボテ、火災は町3,100+・工場・ハリボテ | World型。近傍防御を継承 | 記念碑を防御とし、森林は防御keyに含めない。火災都市閾値10,000 | B | `PLUS-DIVERGENCE` | H2 `hako-turn.txt:1603-1621,1851-1879`; PR15 `typhoon/fire`。森林防御はowner確認候補。 |
| 隕石・巨大隕石・噴火transition | 山→荒地、通常地形の海化、巨大半径2、噴火中心山・周囲段階変化 | 基本transitionをWorld半径処理へ移植 | 基本transitionを共有World用に実装、Capital割合damage | A | `MATCH-H2` | H2 `hako-turn.txt:1881-2024`; current `DisasterTurnService.php`。scope/rate差は別行。 |
| 地盤沈下 | 面積90超、3%。沿岸陸→浅瀬、浅瀬→海 | 実装なし | active Nation所有陸地101以上、2%。山保護、Capital割合damage | D | `KEEP-EXPLICIT` | DISASTER-01。H2を共有Worldへ明示再設計したもの。 |
| 食料不足施設被害 | 食料<=0で農場、工場、基地、防衛を各1/4破壊 | riotとして海底基地・ハリボテ等へ対象拡張 | riotとして農場、工場、基地、海底基地、防衛、ハリボテを1/4 | B | `PLUS-DIVERGENCE` | H2 `hako-turn.txt:1690-1716`; 2＋ `map.c`; PR11 `riot`。対象setのowner確認余地。 |
| 怪獣8種catalog | HP、硬化、移動、経験値、残骸価値を定義 | 同じ | 同じ数値をversioned catalog化 | A | `MATCH-H2` | H2 `hako-main.txt:224-280`; 2＋ `monster.c`; PR21 `:22-105`。 |
| 怪獣移動・硬化 | 通常1歩、dark最大2、Sanjira奇数硬化、Whale偶数硬化 | 同じ | 同じ | A | `MATCH-H2` | H2 `hako-turn.txt:1132-1152,1520-1600`; PR21。 |
| Ghost特殊移動 | scan順で移動済みflagを立てず、同turnに不定回移動可能 | 同じ | source-derived上限9999を明示し、deterministic passで再現 | D | `KEEP-EXPLICIT` | MONSTER-02。legacy scan artifactをそのまま永続表現にはしていない。 |
| 怪獣自然出現 | 島面積×0.03%、島ごと最大1体 | Worldで毎turnランダムcell 2回 | eligible active Nationごと面積比例、最大1体 | C | `KEEP-SHARED` | MONSTER-04。共有Worldの公平性とattributionに必要な再設計。 |
| 怪獣撃破reward | killerへ基地exp/kill mark、hostへ残骸資金全額 | 同じ | killerへ資金半分、hostへ残り相当の怪獣肉、種類別count | D | `KEEP-EXPLICIT` | MONSTER-04。 |
| 怪獣派遣 | 3,000億、対象島の町へメカいのら予約 | 3,000億、対象Nationへ | 3,000億、共有Worldの対象Nation settlementへ | A | `MATCH-H2` | H2 `hako-turn.txt:1392-1407,1751-1803`; PR22。共有target選択だけ適応。 |
| 通常/PP/陸破cost | 20/50/100億/発 | 同じ | 同じ | A | `MATCH-H2` | H2 `hako-main.txt:516-524`; PR22 `:159-162`。 |
| missile deviation | 通常・ST・陸破は半径2、PPは半径1 | 通常・弾道・陸破2、PP1 | 通常2、PP1、陸破2、SPP0 | A | `MATCH-H2` | 共通3種について一致。SPPは別行。H2 `hako-turn.txt:921-926`; PR22。 |
| missile射程 | 島指定で距離制限なし | 通常/PP/陸破は12、弾道は無制限 | 全弾種距離制限なし | D | `KEEP-EXPLICIT` | MISSILE-01で明示的に2＋ rangeを採用しなかった。 |
| 防衛施設迎撃 | 半径2。防衛施設自身への直撃は迎撃しない | 同系 | 半径2 interception contract | A | `MATCH-H2` | H2 `hako-turn.txt:990-1039`; current military tests/rules。 |
| 難民 | 通常/PPで破壊人口の半分。ST/陸破なし | 通常/PP等で半分 | 通常/PP/SPPで半分、陸破なし | D | `KEEP-EXPLICIT` | SPPへの適用を含むowner military contract。H2 `hako-turn.txt:1262-1309`; PR22。 |
| 海底基地の通常弾耐性 | 海に偽装され通常弾無効、陸破等で破壊 | 同系 | 通常/PP/SPPでもwater facilityを破壊 | E | `CANDIDATE-H2-REALIGN` | H2 `hako-turn.txt:1041-1118`; 2＋ `map.c:498-627`; current resolver。owner decision待ち。 |
| missile発射者privacy | 通常/PP/陸破は公開。STだけ匿名public＋攻撃者private | 全弾のaim/impactを通常公開 | 全弾で発射Nation、弾種、発射数、意味ある着弾をpublic | D | `KEEP-EXPLICIT` | B-10。原版ST anonymityへ自動回帰しない。 |
| TOP通常ログ | 最新1 turnの全通常ログ | 最新2 turnの通常/publicログ | public World logをturn別pagination | A | `MATCH-H2` | H2 `hako-top.txt:223-237`; 2＋ `hakow.js:395-429`; current projection。保持turn数はUI契約差。 |
| 個別島/ownerログ | 関係する通常ログ＋ownerだけのsecret | 同じtimelineへsecret flagでfilter | release/1.4.0 owner pageは自Nationのnation/private detail中心で、関係するpublic島timelineを併載しない | E | `CANDIDATE-H2-REALIGN` | H2 `hako-main.txt:1462-1492`; 2＋ `hako_io.c:593-631`; baseline `PlayerIslandEventService.php:146-223`。1.4.1別作業の表示候補。 |
| 植林・基地・ハリボテlog | owner exact。植林/基地はpublic「森」、ハリボテはpublic「防衛施設」 | 同じ | public blurred rowとowner exact rowを別projectionで持つ。owner pageはexact側 | A | `MATCH-H2` | H2 `hako-turn.txt:2318-2331`; 2＋ `command.c:669-686`; baseline `PlayerIslandEventService.php:1156-1168`。 |
| 伐採log privacy | 座標・収入を通常公開 | 同じ | publicは曖昧、ownerに座標・額 | D | `KEEP-EXPLICIT` | 現行privacy contract。原版根拠で公開へ広げない。 |
| 資金繰りlog | source上コメントアウト、表示なし | 表示なし | release/1.4.0 owner logは明示/自動資金繰りとidle counterを表示 | E | `CANDIDATE-H2-REALIGN` | H2 `hako-turn.txt:2501-2505`; baseline `PlayerIslandEventService.php:26-72,1149-1154`。1.4.1別作業ではaudit保持・player noise抑制の根拠にできる。 |
| 通常command / 災害log | 多くの成功・失敗、援助、誘致、災害を通常公開 | 同じ | meaningfulなpublic eventをTOP/関係Nationへ投影 | A | `MATCH-H2` | H2 `hako-turn.txt:2231-2316,2507-2735`; 2＋ `secret=0`; current 1.4.1。 |
| missile log detail | aim、impact、terrain、結果を公開。STだけ匿名 | aim/impactを公開 | B-10 summaryをpublic、private launch detailをownerへ | D | `KEEP-EXPLICIT` | 1.4.1では表示契約のみ確認。gameplayへ拡張しない。 |
| 繁栄/平和/災難threshold | 30万/50万/100万、2万/5万/8万、5万/10万/20万 | 50万/100万/200万、2万/5万/8万、10万/25万/50万 | 原版2と同じthreshold | A | `MATCH-H2` | H2 `hako-turn.txt:1306-1329,2040-2071`; AWARD-01。 |
| turn杯 / 怪獣記録 | 100 turnごと人口1位。怪獣種類bit mark | 100/300/1000 turn首位。種類bit mark | 100 turnごと人口同率首位全員＋区間最大kill Nation。累積種類別count | D | `KEEP-EXPLICIT` | ADR-0009 awards。 |
| 初期島の配置方式 | 個別12×12内で生成 | shared Worldへ半径5予約、半径2所有 | 2＋由来の共有配置、Capital間距離12、半径2 ownership | C | `KEEP-SHARED` | 2＋ `new_island.c:50-105`; current generator/CapitalPlacementService。 |
| 初期人口 | 村500人×2、計1,000人 | 村500人×2、計1,000人 | Capital1,000人＋村500人、計1,500人 | D | `KEEP-EXPLICIT` | B-01。current generator `:104-145`。 |
| 初期terrain / facility | 森4、村2、山1、基地1 | 森3、村2、山1、基地1 | 森3、村1、Capital1、山1、基地1、starter plain、浅瀬最低3 | D | `KEEP-EXPLICIT` | H2 `hako-turn.txt:130-240`; 2＋ `new_island.c:107-209`; current generator。 |
| 初期資金 / 食料 | 100億円、10,000トン | 同じ | 100億円、小麦10,000トン | A | `MATCH-H2` | H2 `hako-main.txt:153-166`; 2＋ `config.cgi:38-41`; PR2/PR6。 |
| 初期queue / automatic finance | 20件すべて資金繰り | empty/financeで処理 | 初期queueを埋めず、empty queueでautomatic finance | D | `KEEP-EXPLICIT` | shared queue UI/idle contract。H2 `hako-turn.txt:100-110`; current executor。 |
| inactivity state | 連続資金繰り回数 | absent counter 25開始/28放棄予約 | idle counterと時間ベースNation state | C | `KEEP-SHARED` | ADR-0004。共有領土と復帰・監査を保つ。 |
| 自動放棄 / 島消滅 | 28回連続資金繰り後放棄。人口0または放棄で島file削除 | 放棄/人口0で領域を海へ戻し島削除 | player/operator向け放棄・物理削除なし。Capital最低人口、履歴保持 | D | `KEEP-EXPLICIT` | ADR-0004、ADR-0008、`docs/open-questions.md:394-395`。将来roadmapでowner decision。 |
| 海際度の導入時期 | ver2.30 snapshotには存在しない | 0.94 snapshotには存在 | 2＋仕様を初期rulesetから採用 | F | `UNKNOWN` | 手元に中間version履歴がないため、「原版後・0.94以前」までしか証明できない。 |
| 1発分ちょうどの資金 | raw codeは`money > cost`で発射せず | 2＋側は別実装 | currentは`money < cost`時だけ停止し、ちょうどでも発射 | F | `UNKNOWN` | H2 `hako-turn.txt:952-958`が意図かbugか不明。realign候補にしない。 |
| 原版工場初期規模comment | commentは10,000人、実コードとUIは30,000人 | 30,000人 | 30,000人 | F | `UNKNOWN` | H2 `hako-turn.txt:772-784`; runtime値を比較に採用。作者意図は不明。 |

## 海際度 case study

### 1. 原版2に海際度概念は存在するか

人口成長・人口上限・集落発生を補正する一般化された海際度は、追加された原版2 ver2.30 sourceには存在しない。

- 人口増減の全経路`doEachHex`は海・浅瀬・隣接海数を参照しない（`hako-turn.txt:1421-1477`）。
- 集落発生`countGrow`が見るのは隣接6セルの有人集落または農場だけである（`:1625-1650`）。
- 原版source全体に「海際」「海岸」「海辺」「沿岸」に相当する設定・共通指標はない。

原版にも海隣接判定自体はある。津波、地盤沈下、埋め立てで個別に使い、`countAround`は盤外を海として数える（`:1718-1749,1805-1849,2885-2912`）。これは人口用の海際度とは別仕様である。

### 2. 原版2の人口上限

- 通常: 10,000人未満だけ100–1,000人増。10,000人到達後は通常増加しない。
- 誘致: 10,000人未満は100–3,000人、以後100–300人、最終20,000人cap。
- 難民受入も1集落20,000人cap。

### 3. 2＋での計算と導入時期

2＋は各turn冒頭に`calcSea()`を実行する（`turn.c:26-31`）。海、海底基地、盤外座標を起点に、半径4の61セルへ1ずつ加算する（`map.c:191-209`）。閾値は12と24（`map.h:167-168`）。

この仕様は0.94 snapshotには存在するが、手元の一次資料に中間versionの変更履歴がないため、正確な追加version・年月は`UNKNOWN`である。「原版2にはなく、遅くとも2＋0.94にはある」までが証明範囲となる。

### 4. 影響範囲

| 影響先 | 2＋ raw behavior | 現行2S＋ |
|---|---|---|
| 通常人口上限 | `<12`: 2,000、`12..23`: 5,000、`>=24`: 10,000 | 同じ |
| 通常成長 | 100–300 × 1/2/3 | 同じ |
| 誘致 | 通常cap前100–1,000 × 1/2/3、以後100 × 1/2/3、最終20,000 | 同じ |
| 集落発生 | ratio 20/10/5を計算するが、実判定は固定20%。実効影響なし | 固定20%、影響なし |
| 農場建設 | 12以上 | 海際度不問 |
| 工場建設 | 24以上 | 海際度不問 |
| 災害・怪獣・missile | `seaLevel`参照なし | sea-edge band参照なし |

2＋の`seaLevel`使用箇所は`map.c:321-377`、`command.c:442-459`、load時初期化に限られる。現行では`CompleteTurnEngine.php:88-120,933-981`とruleset `sea_edge_bands`が人口へ使うが、facility建設には使わない。海底基地は`sea` terrain上にあるため現行でも海として数えられ、浅瀬は数えない。

### 5. 共有World上の技術的必要性

海際度は共有mapの永続化、ownership、territory、influence、Nation identity、配置予約に参照されないturn-local派生値である。したがって、共有Worldを成立させる技術的必要条件ではなく、2＋由来のgameplay extensionと判断する。

### 6. 将来原版2へ寄せる場合の影響

変更するかどうかはowner decision待ちである。変更する場合も公開済みv1/v2/v3 payloadを変更してはならず、新しいimmutable rulesetとforward migration/compatibility方針が必要になる。影響点は少なくとも次のとおり。

- `sea_edge_bands`とruleset authoring validator。
- `CompleteTurnEngine`の通常成長・誘致とevent metadata。
- すでに2,000/5,000人capで止まった集落、または過去の誘致でcapを超えた既存人口の扱い。
- preview/UI説明、deterministic random stream、turn regression tests。
- 既存Worldを旧rulesetで継続するか、新rulesetへforward migrationするかというrelease判断。

本監査では削除、新ruleset追加、migration、既存人口補正を行っていない。

## Command詳細比較

`quantity`は別記がない限りcurrentでは1–99。登録時だけでなくturn実行時にownership、terrain、facility、資金等を再検証する。Capitalとforeign ownershipの保護は共有World向け追加境界である。

| command | 箱庭2 / 2＋のcost・対象・結果 | 現行2S＋ | turn / quantity上の要点 |
|---|---|---|---|
| 整地 | 5億。海、海底基地、油田、山、怪獣以外を平地。町も上書き。1%で100–1,000億円 | 所有する荒地/平地/森/settlement。Capital・怪獣を保護 | 成功はturn消費、失敗は非消費 |
| 地ならし | 100億。整地と同対象。実行回数ごと地震率増加 | 同系。成功ごと5/2000の独立地震 | 非turn消費 |
| 埋め立て | 150億。海系、隣接陸必須。海→浅瀬→荒地、周囲浅瀬化 | 所有境界とforeign近傍拒否を追加。facility付きwaterは通常不可 | 成功はturn消費 |
| 掘削 | 200億基準。山→荒地、浅瀬→海、陸→浅瀬。深海は投資額で油田探索 | 同transition。quantityを投資回数としてoil探索 | 成功はturn消費 |
| 伐採 | 0。森→平地、木100本×5億円 | 森→荒地、capacity内受領 | 成功はturn消費。terrain差はowner判断候補 |
| 植林 | 50億。平地/町→森100本 | 平地/settlement→森。Capital不可 | 成功はturn消費。public blur/private exact |
| 農場整備 | 20億。平地/町または既存農場。10,+2,max50 | 同じ規模。海際度不問 | 1段階/turnでquantity継続 |
| 工場建設/整備 | 100億。平地/町または既存工場。30,+10,max100 | 同じ規模。海際度不問 | 1段階/turnでquantity継続 |
| 採掘場整備 | 300億。山。5,+5,max200 | 同じ | 1段階/turnでquantity継続 |
| ミサイル基地 | 300億。平地/町、経験値0 | 同じ。Capital不可 | 成功はturn消費、secret facility |
| 防衛施設 | 800億。平地/町。同施設再実行で自爆flag | 同施設再実行は失敗 | 自爆再建はowner判断候補 |
| 海底基地 | 8,000億。原版は深海、2＋は深海/浅瀬＋自領近傍 | `sea`＋自領3hex近傍 | 水深・capacity・耐性はowner判断候補 |
| 記念碑 | 9,999億。平地/町。同施設再実行で飛翔 | 種類をquantityで選択。同施設再実行は失敗 | 飛翔はowner判断候補 |
| ハリボテ | 1億。平地/町、防衛施設へ偽装 | owner向けkeyは`build_decoy`、public表示は防衛施設 | 成功はturn消費 |
| 通常missile | 20億/発、半径2誤差 | 同じ | 基地capacityと資金まで |
| PP missile | 50億/発、半径1誤差 | 同じ | 基地capacityと資金まで |
| ST / SPP | 原版ST 50億・匿名・半径2。2＋弾道150億・半径2 | SPP 500億・誤差0・発射Nation公開 | 同名・後継と決めつけない |
| 陸地破壊 | 100億/発、半径2。山→荒地、陸→浅瀬、浅瀬→海 | 同系、Capital割合damage | 難民なし |
| 怪獣派遣 | 3,000億。対象の町へメカいのら | 対象Nationのeligible settlementへ | 出現turnには移動しない |
| 資金繰り | 0、+10億 | 同じ。empty queueでも自動実行 | turn消費、routine player logは抑制 |
| 資金援助 | 100億×quantity | 同じ、receiver capacity適用 | 非turn消費 |
| 食料援助 | 10,000トン×quantity | 1,000トン×quantity | 非turn消費。unitはowner判断候補 |
| 誘致 | 1,000億、当該turn人口成長強化 | 同じ目的、海際度bandを使用 | turn消費 |
| 放棄 | 0、島をdead化 | player/operator commandなし | lifecycle roadmapのowner decisionが必要 |

2＋には領土拡張100億、首都移動相当1,000億等の共有World commandがある。現行の`territory_expand`、Capital relocationは共有Worldの構造として別扱いとし、「原版にない」ことを削除理由にしない。

## 災害詳細比較

| 災害 | 箱庭2 | 箱庭2＋ | 現行2S＋ |
|---|---|---|---|
| 地震 | 島ごと0.5%。都市10,000+、工場、ハリボテを1/4 | World中心8%、半径10 | World中心4%、半径10。Capital割合damage |
| 津波 | 島ごと1.5%。海隣接数で集落・農工場・基地・防衛・ハリボテ | World中心30%、半径10 | World中心15%、半径10。Capital割合damage |
| 台風 | 島ごと2%。農場/ハリボテ。森/記念碑で防御 | World中心40%、半径10 | World中心20%、半径10。記念碑防御 |
| 火災 | eligible cellごと1%。町3,100+、工場、ハリボテ。森/記念碑で防御 | eligible cell 1% | eligible cell 0.5%。都市10,000+、工場、ハリボテ。記念碑防御 |
| 隕石 | 島ごと1.5%、最低1個、1/2で連続 | World中心20%、半径10内、1/2継続 | World中心10%、半径10内、1/2継続 |
| 巨大隕石 | 島ごと0.5%、半径2広域被害 | World中心10% | World中心5% |
| 噴火 | 島ごと1%、中心山、隣接段階変化 | World中心20% | World中心10%、Capital割合damage |
| 地盤沈下 | 面積90超で3% | なし | Nation所有陸地101以上で2%、山保護 |
| 食料不足 | 人口減少＋農場/工場/基地/防衛1/4 | 人口減少＋riot | 人口減少＋拡張riot対象、Capital最低人口 |

分母差に注意する。現行は2＋と同じ分子を`/2000`へ置いたため「2＋の半率」だが、原版と同率ではない。例えば現行津波15%は原版1.5%の10倍である。

## 怪獣・military補足

| 怪獣 | HP | 特殊能力 | 基地経験値 | 原版残骸価値 |
|---|---:|---|---:|---:|
| メカいのら | 2 | 派遣専用 | 5 | 0 |
| いのら | 1–2 | なし | 5 | 400億 |
| サンジラ | 1–2 | 奇数turn硬化 | 7 | 500億 |
| レッドいのら | 3–4 | なし | 12 | 1,000億 |
| ダークいのら | 2–3 | 最大2歩 | 15 | 800億 |
| いのらゴースト | 1 | legacy scan上の不定回移動 | 10 | 300億 |
| クジラ | 4–5 | 偶数turn硬化 | 20 | 1,500億 |
| キングいのら | 5–6 | なし | 30 | 2,000億 |

catalog値は3系統で概ね一致する。大きな差は共有World向け自然出現単位、monster actor/occupancy、reward分割、討伐統計であり、MONSTER-01〜04の明示owner decisionを優先する。

原版のturn順はcommands → 全cell人口/怪獣移動 → 島全体災害/新規怪獣出現であるため、そのturnに新規出現した怪獣は次turnまで移動しない（`hako-turn.txt:367-383,1411-1803`）。現行もこの境界を維持する。

## Logs / privacy 詳細監査

### 原版2の表示contract

- `logOut` / `logLate`は通常ログ、`logSecret`はowner-only（`hako-turn.txt:2158-2175`）。
- TOPは最新1 turnの通常ログを表示する（`hako-main.txt:109-113`; `hako-top.txt:223-237`）。
- 個別島logは関係する通常ログを表示し、ownerだけが`id1`自島のsecretを追加で見る。相手ownerにはsecretを見せない（`hako-main.txt:1462-1492`; `hako-map.txt:558-563`）。

### 項目別

| 項目 | 箱庭2 | 箱庭2＋ | release/1.4.0 baseline / 1.4.1への補助根拠 |
|---|---|---|---|
| 植林 | ownerへ座標・植林をsecret、publicは座標なしで森が増えた | exact secret1＋blur secret2 | baselineにもowner exact/public blurが別rowで存在。1.4.1でowner timelineを統合する場合はexact優先の根拠 |
| ミサイル基地 | owner exact、publicは植林と区別せず森 | 同じ | baselineにも両projectionが存在。1.4.1でcompanionを併載する場合のdedupe根拠 |
| ハリボテ | ownerはハリボテ、publicは防衛施設 | 同じ | baselineはowner「ハリボテ」/public「防衛施設」。1.4.1のowner label補正根拠 |
| 海底基地 | 通常ログだが座標`(?,?)`、mapでは海 | owner exact＋public座標不明 | exact coordinateをownerだけへ出す根拠 |
| 伐採 | 座標・金額を通常公開 | 同じ | 現行のowner-only exactはlegacy由来ではなく現行privacy判断 |
| 資金繰り | log callがcomment out、表示なし | 表示なし | baselineはownerへ表示しており差分。1.4.1でautomatic finance/idle内部計数をroutine noiseとして隠す根拠 |
| 通常command | 成功、失敗、援助、誘致等を通常公開 | `secret=0` | meaningful成功をTOP/publicへ出す根拠 |
| 災害 | 通常公開 | 通常公開 | World public eventと関係Nation投影の根拠 |
| 通常/PP/陸破 | 発射島、対象島、aim、impact、結果を公開 | 発射/impactを公開 | current B-10 summary/public impactの補助根拠 |
| ST / SPP | STは発射者付き詳細を攻撃ownerへsecret、public/被害側は匿名 | 2＋missileは通常公開 | currentはB-10によりSPPを含め発射Nation公開。原版へ戻さない |

2＋のsecret flagは`0=通常`、`1=関係島のみ`、`2=無関係島のみ`で、source側filterは`hako_io.c:593-631`、owner pageは`owner.c:13-16,51-52`にある。release/1.4.0はpublic島logとowner exact logを別endpoint/projectionに分け、owner pageはnation/private中心である。owner timelineが自島関連publicも含む原版/2＋構造、public blurとprivate exactのcompanionが共存する構造は、別作業中1.4.1のinclusion/dedupeを判断する直接的根拠になる。

ただし、現行の伐採privacyとB-10 missile visibilityはowner decision由来であり、raw legacyへ合わせて表示範囲を変えない。本監査からgameplay変更へ広げてもいない。

## 分類集計

以下は「主要仕様比較表」の主分類だけを排他的に集計する。補足表は重複計上しない。

<!-- CLASSIFICATION_COUNTS_START -->

| 主分類 | 件数 |
|---|---:|
| `KEEP-SHARED` | 5 |
| `KEEP-EXPLICIT` | 21 |
| `MATCH-H2` | 33 |
| `PLUS-DIVERGENCE` | 3 |
| `CANDIDATE-H2-REALIGN` | 10 |
| `UNKNOWN` | 4 |

<!-- CLASSIFICATION_COUNTS_END -->

## 優先度の高い差分 上位10件

1. **人口用海際度** — 原版にはなく、2＋/現行は2,000/5,000/10,000人capと成長倍率を持つ。共有Worldの技術要件ではない。
2. **災害の発生scope・rate** — 原版の島単位低率から、2＋型のWorld半径高率へ大きく変わる。現行は2＋の半率だが原版より大幅に高い。
3. **生産方式** — 原版/2＋は工場・採掘場が資金を直接生産し、現行は工業品・鉱物stockを生産する。E-03で明示採用済み。
4. **Nation lifecycle** — 原版/2＋は資金繰り連続で自動放棄・物理削除、現行は共有領土と監査性を守るstate lifecycleである。
5. **伐採後terrain** — 原版/2＋は平地、現行は荒地。共有World構造とは独立したgameplay差。
6. **防衛施設再建** — 原版/2＋は自爆flag、現行は失敗。広域damageを伴うためowner decision必須。
7. **記念碑再建** — 原版/2＋は飛翔攻撃、現行は失敗。combat追加として別roadmapが必要。
8. **海底基地semantics** — 水深、経験値/発射capacity、通常弾耐性の3点が異なる。
9. **怪獣の自然出現・reward** — 共有World公平性とattributionのため現行が明示再設計済み。原版へ単純回帰できない。
10. **missile visibility / SPP** — 原版ST匿名、2＋通常公開、現行SPPはB-10で発射Nation公開。privacyとgameplay名称を分離して扱う必要がある。

## Owner decisionが必要な将来候補

この一覧は将来patch候補のdecision backlogであり、ver 1.4.1 scopeではない。

1. 人口用海際度を維持するか、原版2の通常10,000人cap・成長100–1,000人へ寄せるか。
2. 海際度を維持する場合、2＋の農場12/工場24という立地制約を採用しない現状を明示仕様にするか。
3. 伐採後terrainを荒地のまま維持するか、原版/2＋の平地へ寄せるか。
4. 災害のWorld scopeと率を維持するか、共有Worldに適応した原版感覚の率を新rulesetで設計するか。
5. 台風・火災で森林を防御として扱う原版semanticsへ寄せるか。
6. 食料不足riotの対象setを原版の農場/工場/基地/防衛へ絞るか、現行拡張を維持するか。
7. 防衛施設の同cell再建による自爆を採用するか。
8. 記念碑再建による飛翔攻撃を採用するか。採用する場合はcombat roadmapとCapital/territory damage decisionが先に必要。
9. 海底基地の浅瀬建設、経験値/1–3発capacity、通常弾耐性をそれぞれどうするか。
10. 食料援助を1,000トン×quantityのまま維持するか、原版/2＋の10,000トンへ寄せるか。
11. 将来の明示放棄・自動放棄・dormancy・territory解放をどう接続するか。現行ADR-0004/0008を前提に別roadmapで決める。

次はowner decision不要、または既に決定済みである。

- shared World、ownership、territory、Capitalを原版との差だけで削除しない。
- 工業品・鉱物stock、monster actor/reward分割、SPP、missile射程なし、B-10 visibility、scorched、現行awardsを本監査だけで再オープンしない。
- 正確な海際度導入versionはowner decisionではなく、追加の歴史sourceが得られた場合のprovenance調査とする。

## 監査時点の結論

- 原版2の人口処理に海際度はない。
- 現行2S＋の人口用海際度は2＋由来で、共有Worldの技術的必要条件ではない。
- 現行は2＋の人口bandを採用する一方、2＋の農場/工場海際度立地制約は採用していない。
- gameplayの多くは原版と一致するが、災害scope/rate、海際度、食料不足対象等には2＋由来の差が残る。
- 共有World、Capital、追加stock、怪獣reward、SPP、lifecycle等は構造上またはowner decisionにより維持する。
- 固定baseline release/1.4.0はowner logへの関係public併載とroutine finance表示で原版/2＋と差がある。ver 1.4.1の別作業では、通常public＋owner exact、finance表示なしという原版/2＋構造を補助根拠として利用できる。
- 本監査によるruleset、turn処理、production semantics、World、migration、人口、災害、missile、怪獣、facilityのコード変更は行っていない。
