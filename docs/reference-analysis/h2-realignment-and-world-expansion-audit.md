# H2 gameplay realignment・World拡張・Nation放棄 read-only監査

## 1. Executive summary

この文書は、箱庭諸島2S＋の次期改修に先立つread-only監査である。gameplay code、公開済みruleset、migration、DB、World、production data、reset、backfill、`_references/`は変更していない。

- `FACT` 比較baselineは最新の`origin/main`、commit `74e4ba76416db355c0b84c09425b677d24e23fe1`である。H2/H2＋はraw source、現行2S＋はこのcommitのsourceを正本とした。
- `OWNER-DIRECTION` 共有World、cell ownership、領土、初期島の考え方、resource stock、現行の災害発生率・World event modelは維持する。原版H2との差だけを理由に削除しない。
- `OWNER-DIRECTION` 人口から海際度依存を将来除去し、H2寄りへ戻す。これは公開済みpayloadの上書きではなく、新しいimmutable rulesetと明示的なWorld ruleset移行を要する。
- `FACT` 海底基地はH2とH2＋の双方で経験値を得て、経験値50/200で発射数が1/2/3へ上がり、陸地破壊弾以外には海として振る舞う。現行2S＋は発射数1固定で、通常・PP・SPPでもwater facilityとして破壊される。
- `FACT` 森・記念碑による火災防止と台風被害軽減はH2、H2＋、現行2S＋のすべてに存在する。既存監査文書の「現行には森の保護がない」という行は誤りだが、指定どおり既存文書は編集しない。
- `FACT` 怪獣が防衛施設へ接触した場合の自爆は現行にも実装・回帰試験済みである。将来候補は「既存防衛施設へ同じ建設commandを実行して起爆予約する」側だけである。
- `FACT` 固定範囲inventoryは28件で、`DYNAMIC-BOUNDS-SAFE` 18件、`POSITIVE-COORDINATE-ONLY` 0件、`FIXED-60` 4件、`FIXED-ORIGIN` 2件、`PARTIAL-CHUNK-RISK` 3件、`NEGATIVE-COORDINATE-RISK` 1件、`UNKNOWN` 0件である。非safeのremediation-riskは合計10件である。
- `INFERENCE` 負座標のDB型、chunk除算、neighbor/distance、Frontend投影、API routeは対応済みだが、横断実装開始を妨げるblockerは4群に整理できる。
- `FACT` 最初の60→64拡張は3,600→4,096セル、追加496セルである。chunk rowは16件のまま増えず、既存の外周7 chunkがpartialからfullへ変わる。
- `INFERENCE` World拡張blockerは8群、明示的Nation放棄blockerは9群である。どちらもforward-only operation、同一World mutation lock、TurnRun gate、冪等性、履歴保持を先に契約化しない限りproduction実装へ進めない。
- `INFERENCE` 次PRは「現在範囲を表すsigned bounds contract、負座標E2E、World mutation lock、矩形coverage validator」の前提整備だけに限定し、production bounds、登録flow、gameplay ruleset、Nation dataを変更しないのが最小である。

### 判定ラベル

| ラベル | 意味 |
|---|---|
| `FACT` | raw source、現行source、schema、test、Git refから直接確認した事実 |
| `INFERENCE` | 複数のFACTから導いた設計・risk評価。実装承認ではない |
| `OWNER-DIRECTION` | 今回の依頼でownerが明示した方向・文言 |
| `UNKNOWN` | 実装前にowner decisionまたはproduction evidenceが必要 |

## 2. 監査baselineと変更境界

| 項目 | 判定 | 根拠種別 |
|---|---|---|
| remote baseline | `origin/main = 74e4ba76416db355c0b84c09425b677d24e23fe1`。`git fetch origin`後のlocal refと`git ls-remote --heads origin main`が一致した | `FACT` |
| audit対象 | H2 raw source、H2＋ 0.94系raw source、上記`origin/main`の2S＋source/docs/schema/tests | `FACT` |
| checkout | 監査時のcheckoutは`release/1.4.1`、HEAD `b55a27b3c0e07dd0f4e44282038c0ae1bf94c6e2`。`origin/main`とのpath差分はなく、比較名は常にbaseline SHAへ固定した | `FACT` |
| 既存監査 | `docs/reference-analysis/hakoniwa-2-vs-2plus-spec-audit.md`は変更しない。監査開始時blob hashは`661f4a748a5d6fc5a3ad40508547cd68deaf33af` | `FACT` |
| encoding | H2 CGI sourceはEUC-JP、H2＋ extracted C sourceはShift-JISとしてread-only decodeした。新規文書はUTF-8 | `FACT` |
| production boundary | 公開済みruleset payloadを上書きせず、既存World/Nation/cell/queue/TurnRun/eventをresetまたは再解釈しない | `OWNER-DIRECTION` |

参照優先順位は `raw source > versioned ruleset/runtime/schema/test > architecture/decision docs > 既存analysis` とした。既存analysisとraw sourceが衝突する場合はraw sourceと現行testを採用する。

## 3. H2 / H2＋ / 2S＋ intent分類

排他的な主分類は次のとおりである。

| 記号 | 主分類 | 意味 |
|---|---|---|
| A | `SHARED-WORLD-ESSENTIAL` | common World、Nation/cell ownership、territory、map上の相互作用など、共有World化に不可欠 |
| B | `LIKELY-SHARED-WORLD-BALANCE` | H2＋でH2から意図的に追加・変更された可能性が高い共有World向けbalance |
| C | `LIKELY-BUG-OR-PORTING` | bug、copy/paste mistake、移植漏れの可能性をcontrol flowから評価する対象 |
| D | `CURRENT-OMISSION` | H2/H2＋の両方に存在するのに現行2S＋だけ欠ける・簡略化された可能性がある対象 |

`FACT` 分類件数は全18件、A=5、B=8、C=1、D=4である。

| # | 仕様 | H2 | H2＋ | 現行2S＋ | 主分類 | 結論 | 根拠種別 |
|---:|---|---|---|---|---|---|---|
| 1 | common World map | 個別島map | 共有60×60 | 共有World | A | 構造的前提として維持（H2＋ `config.cgi:42-45`） | `OWNER-DIRECTION` |
| 2 | Nation/cell ownership | 島単位owner | 各cell owner | `owner_nation_id` | A | 維持（H2＋ `map.c:211-260`; schema `:132-151`） | `OWNER-DIRECTION` |
| 3 | territory/influence | 個別島内 | 共有map上の影響・owner更新 | active Nation influence | A | 維持（H2＋ `map.c:211-260`; `TerritoryInfluenceService.php:49-60,130-200`） | `OWNER-DIRECTION` |
| 4 | 他Nationとのmap interaction | 島間command中心 | 同一mapでmissile/怪獣/ownership変化 | 同一surfaceで解決 | A | 維持（H2＋ `map.c:419-640`; current missile `:214-248`） | `OWNER-DIRECTION` |
| 5 | 共有map上の初期島配置 | 島mapを生成 | 共有mapへ島を生成 | Capital中心の予約領域生成 | A | 現行conceptを維持（`CapitalPlacementService.php:13-105`; `LegacyInspiredInitialIslandGenerator.php:25-177`） | `OWNER-DIRECTION` |
| 6 | missile射程 | 島単位targetで基地距離制限なし | 非弾道弾は基地からtargetまで射程判定 | owner decisionで現行は距離制限なし | B | H2＋の共有World balance。自動導入しない（H2＋ `map.c:419-435`; `open-questions.md:332-336`） | `FACT` |
| 7 | 海際度score | なし | 海/SBase/World外からradius 4へ加算 | population用に継承 | B | 共有Worldで島状/coastal開発を促すproxyの可能性（H2＋ `turn.c:26-36`; `map.c:191-209`） | `INFERENCE` |
| 8 | 通常人口成長 | 100–1,000人、通常cap 10,000人 | 海際度別100–300/600/900人、cap 2,000/5,000/10,000人 | H2＋型3 band | B | owner方針によりH2 realign候補（H2＋ `map.c:343-377`; engine `:933-981`） | `FACT` |
| 9 | 誘致人口成長 | cap前100–3,000人、cap後100–300人、最大20,000人 | 海際度倍率あり | H2＋型 | B | owner方針によりH2 realign候補（H2 `hako-turn.txt:1427-1468`） | `FACT` |
| 10 | 農場・工場の海際度立地制限 | なし | 農場12、工場24以上 | なし | B | 現行は既にH2型。H2＋ `command.c:442-459`は意図的balanceに見える | `FACT` |
| 11 | 海底基地の建設地 | 深い海、距離制限なし | 浅瀬/深海かつ自領から3hex以内 | 深い海かつ自領から3hex以内 | B | 現行hybrid。現行建設条件維持が有力（H2＋ `command.c:509-533`） | `OWNER-DIRECTION` |
| 12 | World単位災害geometry/rate | 個別島ごと | 共有World中心・radius判定 | versioned World event | B | 共有60×60向けbalanceの可能性が高く、現行を維持 | `OWNER-DIRECTION` |
| 13 | 火災対象人口閾値 | 3,100人以上 | 10,000人以上 | 10,000人以上 | B | H2＋のbalance変更。realignment対象にするかowner decision（H2＋ `map.c:295-311`） | `FACT` |
| 14 | H2＋の集落発生`ratio` | 固定20% | 20/10/5を計算するが未使用、判定は固定20% | 固定20% | C | H2＋側の`LIKELY-BUG`。現行bugではない（H2＋ `map.c:321-341`） | `INFERENCE` |
| 15 | 海底基地の経験値・発射数 | 経験値、50/200、1/2/3 | 同じ | 経験値を発射数へ使わず1固定 | D | 新ruleset候補（H2＋ `map.c:419-448,1376-1400`） | `FACT` |
| 16 | 海底基地のmissile耐性 | 陸地破壊弾以外は海扱い | 同じ | 通常・PP・SPPでも破壊 | D | 新ruleset候補（H2 `hako-turn.txt:1041-1081`; current `:340-382`） | `FACT` |
| 17 | 防衛施設再建による自爆予約 | あり | あり | なし | D | 将来command候補（H2 `hako-turn.txt:721-797`; H2＋ `command.c:626-660`） | `FACT` |
| 18 | 記念碑再建による飛行 | target島へ飛行 | target島へ飛行 | なし | D | owner案はWorld pending巨大隕石へ再設計（H2 `hako-turn.txt:798-815,1899-1916`） | `FACT` |

`OWNER-DIRECTION` resource stockは2S＋独自の継続仕様であり、H2＋由来のA–D分類には算入しない。森/記念碑の災害保護、怪獣接触時の防衛自爆、固定20%の集落発生は三者照合上の「現行に実装済み」であり、C/D件数には算入しない。

## 4. Gameplay realignment候補

### 4.1 人口

- `OWNER-DIRECTION` 海際度による人口成長差を除去し、H2寄りに戻す。
- `FACT` H2は通常時、村・町が100–1,000人増え10,000人で通常成長を止める。誘致中は10,000人未満が100–3,000人、以後が100–300人、絶対上限20,000人である（`_references/hakoniwa-2/hako-turn.txt:1421-1468`）。
- `FACT` H2＋は海際度に応じて通常増加量と通常capを3 band化し、現行2S＋も`sea_edge_bands`を採る（`_references/hakoniwa-2plus/extracted/map.c:343-377`; `product/config/hakoniwa/rulesets/roadmap-pr11-v1.php:60-72`; `product/app/Application/CompleteTurnEngine.php:933-981`）。
- `FACT` H2＋はWorld外4hexまでを走査し、海・海底基地・World外を海際度の源としてradius 4へ加算する。score 12/24は人口だけでなく農場/工場立地にも使われる（`_references/hakoniwa-2plus/extracted/turn.c:26-36`; `map.c:191-209`; `map.h:166-168`; `command.c:442-459`）。
- `INFERENCE` H2の各島が海に囲まれる前提を、連続60×60 World内でも維持するため、海際度を「島状・coastalな開発密度」のproxyとして導入した可能性が最も高い。内陸の人口capを下げ、農工場を海の多い場所へ寄せるcontrol flowがこの説明と整合する。ただし設計文書やcommit historyがないため意図の断定はしない。
- `INFERENCE` 現行2S＋は農工場制限を既に不採用とし、Capital spacingと初期島reservationを別contractで持つ。したがって人口から海際度を外しても、共有World、島間距離、territoryそのものを除去する技術的必然はない。
- `INFERENCE` 将来rulesetでは、集落の通常/誘致growthだけをH2値へ戻し、共有World、Capital、resource stock、territory、現行災害modelを分離して維持するべきである。
- `UNKNOWN` 現行独自のCapital minimum populationと25,000人capを維持するかは、H2 realignment ruleset公開前のowner decisionである。H2にはCapitalがないためraw sourceからは決められない。

### 4.2 海底基地

| 観点 | H2 | H2＋ | 現行2S＋ | 将来判定 | 根拠種別 |
|---|---|---|---|---|---|
| 初期経験値 | 0 | 0 | facility経験値fieldはあるが発射数判定へ不使用 | 0開始 | `FACT` |
| 経験値源 | 都市命中、怪獣撃破 | 都市命中、怪獣撃破 | land missile baseだけ増加対象 | 海底基地も同じeventから増加させる候補 | `FACT` |
| 閾値/発射数 | 50/200、1/2/3 | 50/200、1/2/3 | 常時1 | H2/H2＋へ揃える候補 | `FACT` |
| missile耐性 | 陸地破壊弾以外は無効、海表示 | 同じ | water facilityを除去 | 非陸地破壊弾を無効化する候補 | `FACT` |
| 陸地破壊弾 | 破壊 | 破壊して深海 | 破壊 | 維持 | `FACT` |
| disguise | 海として表示 | 海として表示 | `visibility_policy=disguised`、ownerもneutral表示 | 維持 | `FACT` |
| 建設地 | 深い海、距離なし | 浅瀬/深海、自領3hex以内 | 深い海、自領3hex以内 | 現行条件維持が有力 | `OWNER-DIRECTION` |

- `FACT` H2の経験値上限は200、海底基地level閾値は50/200、最大levelは3である（`_references/hakoniwa-2/hako-main.txt:186-199,1409-1429`）。発射時はland/seabed双方を探索してlevel分を発射する（`_references/hakoniwa-2/hako-turn.txt:928-958`）。
- `FACT` H2＋も`Land::SBase`へ同じ50/200閾値を適用し、発射時に`getLevel()`を使う（`_references/hakoniwa-2plus/extracted/map.c:419-448,1376-1400`）。
- `FACT` 現行はland baseだけ`MissileBaseRules::launchCapacity()`を使い、それ以外を1とする（`product/app/Application/MissileImpactResolver.php:66-77`）。
- `UNKNOWN` 経験値の獲得量、既存海底基地への初期experience、UIでownerへlevelを見せる範囲、通常/PP/SPPの全てを耐性対象にするかは新ruleset gateで確定する。

### 4.3 森による災害保護

- `FACT` H2は隣接する森または記念碑が1つでもあれば火災を防止し、台風は隣接数だけ被害閾値を下げる（`_references/hakoniwa-2/hako-turn.txt:1603-1621,1851-1878`）。
- `FACT` H2＋も同じ構造を明示する（`_references/hakoniwa-2plus/extracted/map.c:840-856,947-982`）。
- `FACT` 現行の`adjacentProtectionCount()`は隣接terrain `forest`または設定された施設（現行は`monument`）を数え、火災と台風の双方が利用する（`product/app/Application/DisasterTurnService.php:380-410,517-538,722-739`; `product/config/hakoniwa/rulesets/roadmap-pr15-v1.php:32-38,55-60`）。森林による火災防止testもある（`product/tests/Feature/DisasterAndOilTurnTest.php:203-219`）。
- `INFERENCE` H2＋のbugでも現行のporting漏れでもない。変更不要である。既存監査`docs/reference-analysis/hakoniwa-2-vs-2plus-spec-audit.md:108-119`の該当結論だけを本書で訂正し、旧文書自体は保存する。

### 4.4 防衛施設自爆と記念碑飛行

- `FACT` H2は既存防衛施設へ防衛施設建設を実行すると自爆flagを立て、cell phaseで広域被害を発生させる（`_references/hakoniwa-2/hako-turn.txt:721-797,1485-1492`）。H2＋も既存施設への同commandでflagを立てる（`_references/hakoniwa-2plus/extracted/command.c:626-660`）。
- `FACT` 怪獣接触時の自爆はH2にあり、現行にも`defense_self_destruct`として存在し、報酬なしの怪獣除去と巨大blastを回帰試験している（`_references/hakoniwa-2/hako-turn.txt:1591-1597`; `product/app/Application/MonsterTurnService.php:180-223`; `product/tests/Feature/MonsterSystemTest.php:425-473`）。
- `FACT` H2は既存記念碑への再建commandでtarget島の`bigmissile`を増やし、後のturnにrandom落下させる（`_references/hakoniwa-2/hako-turn.txt:798-815,1899-1916`）。
- `INFERENCE` 防衛施設再建自爆を将来追加する場合、通常の建設と同じ見た目で即予約させず、command UIに不可逆な広域被害の強い警告と明示確認を置く必要がある。
- `OWNER-DIRECTION` 2S＋で採用を検討する記念碑飛行はNation targetを直接増やさず、World levelのpending巨大隕石数を増やし、後にWorld内random位置へ「何かとてつもないものが落ちてきました！」のmajor news付きで落下させる案とする。今回は実装しない。
- `UNKNOWN` command cost、対象指定の有無、落下turn、同turn複数個の順序、seed stream、空中防御、World外/海への落下、pending countのruleset migrationを決めるまで実装不可である。

## 5. Likely bug / porting candidate

| ID | 対象 | 判定 | 監査結果 | 根拠種別 |
|---|---|---|---|---|
| BUG-01 | H2＋平地の集落発生`ratio` | `LIKELY-BUG` | 海際度から20/10/5を計算するが、直後の判定は固定`dice(100) < 20`。変数がdeadでcommentと実装が不一致。効果はH2/現行の20%と一致 | `INFERENCE` |
| BUG-02 | 森による火災/台風保護 | `NOT-BUG` | H2＋と現行の双方に存在。旧監査側の見落とし | `FACT` |
| BUG-03 | H2＋海底基地に経験値がないという仮説 | `DISPROVED` | H2＋は発射元`land->param`へ都市/怪獣経験値を加え、SBaseの50/200 level判定を使う | `FACT` |
| PORT-01 | 現行海底基地の発射数1固定 | `CURRENT-OMISSION` | H2/H2＋双方との差。ただし既存実装時の意図が記録から確定できないため、bug断定はしない | `UNKNOWN` |
| PORT-02 | 現行海底基地が通常弾でも破壊される | `CURRENT-OMISSION` | H2/H2＋双方との差。採用時は新ruleset contractにする | `FACT` |
| PORT-03 | H2＋の農場/工場海際度制限 | `DELIBERATE-DIVERGENCE` | codeとcommentが一致し、閾値12/24も定数化。bugではない。現行が不採用にした仕様 | `FACT` |
| PORT-04 | 火災人口閾値3,100→10,000 | `DELIBERATE-DIVERGENCE` | H2＋は`param >= 100`、現行も10,000人。H2 realignment対象にするかはowner decision | `FACT` |

## 6. 固定60 / origin / dynamic bounds inventory

`FACT` 集計は28件である。`DYNAMIC-BOUNDS-SAFE` 18、`POSITIVE-COORDINATE-ONLY` 0、`FIXED-60` 4、`FIXED-ORIGIN` 2、`PARTIAL-CHUNK-RISK` 3、`NEGATIVE-COORDINATE-RISK` 1、`UNKNOWN` 0。`DYNAMIC-BOUNDS-SAFE`以外の10件をremediation-riskとして扱う。

| # | 層/箇所 | 現状 | 分類 | 将来対応 | 根拠種別 |
|---:|---|---|---|---|---|
| 1 | ruleset authoring validator | initial boundsを0..59へ固定 | `FIXED-60` | immutable initial contractとして残し、current boundsと分離 | `FACT` |
| 2 | published v1/v2/v3 settings | initial 0..59 | `FIXED-60` | 上書き禁止。拡張先の正本にしない | `FACT` |
| 3 | `WorldBounds` constructor | `minX/minY != 0`を拒否 | `FIXED-ORIGIN` | signed current-bounds value objectを分離 | `FACT` |
| 4 | generation profile | production 60×60、debug 32×32 | `FIXED-60` | initial generation専用と明記 | `FACT` |
| 5 | `OceanWorldGenerator` bounds照合 | live boundsとinitial ruleset boundsの差をreset要求 | `FIXED-ORIGIN` | initial generatorを拡張serviceとして再利用しない | `FACT` |
| 6 | generator再実行 | 完了runなら即return、未完了かつcell有りは拒否 | `PARTIAL-CHUNK-RISK` | expansion operationで欠損検証・再試行 | `FACT` |
| 7 | `map_spaces` bounds columns | signed integer、min/maxを保持 | `DYNAMIC-BOUNDS-SAFE` | current boundsの正本として使用 | `FACT` |
| 8 | `map_cells`/`map_chunks`座標 | signed integerと複合unique/index | `DYNAMIC-BOUNDS-SAFE` | constraint追加時も負値を許す | `FACT` |
| 9 | DB chunk/local backfill式 | PostgreSQL `FLOOR`、local 0..15 | `DYNAMIC-BOUNDS-SAFE` | 同じ式を契約testへ固定 | `FACT` |
| 10 | `ChunkCoordinateService` | floor division/moduloで負値対応 | `DYNAMIC-BOUNDS-SAFE` | 維持 | `FACT` |
| 11 | `GridCoordinate` | absolute y parity、signed distance | `DYNAMIC-BOUNDS-SAFE` | 負y E2Eを追加 | `FACT` |
| 12 | Capital placement query | MapSpace min/maxとsigned cube式 | `DYNAMIC-BOUNDS-SAFE` | expansion後再探索testを追加 | `FACT` |
| 13 | initial island generator | reservation内の既存cellを使用 | `DYNAMIC-BOUNDS-SAFE` | expansion completion後だけ呼ぶ | `FACT` |
| 14 | `MapSpaceResource` | live min/maxを返す | `DYNAMIC-BOUNDS-SAFE` | bounds revisionを追加検討 | `FACT` |
| 15 | `MapChunkService` | chunk rowがあればcell不足でも`generated` | `PARTIAL-CHUNK-RISK` | bounds交差に対する期待cell数を検証 | `FACT` |
| 16 | backend chunk routes | `-?\d+`を許可 | `DYNAMIC-BOUNDS-SAFE` | 維持 | `FACT` |
| 17 | frontend chunk range | live boundsをfloor-divしてrequest | `DYNAMIC-BOUNDS-SAFE` | 負bounds integration test追加 | `FACT` |
| 18 | frontend projection/viewport | floorModによる負y parity、min/max投影 | `DYNAMIC-BOUNDS-SAFE` | 負x/y screenshot/test追加 | `FACT` |
| 19 | frontend loaded/empty cache | 同一MapSpaceのbounds/version変更を自動検知しない | `PARTIAL-CHUNK-RISK` | MapSpace revisionまたは明示reload契約 | `FACT` |
| 20 | command target lookup | x/y integerとMapCell存在で検証 | `DYNAMIC-BOUNDS-SAFE` | 負座標command E2E追加 | `FACT` |
| 21 | territory/influence | current cell集合とMapSpaceで処理 | `DYNAMIC-BOUNDS-SAFE` | 拡張境界回帰test | `FACT` |
| 22 | missile impact | live MapSpace min/maxでWorld外判定 | `DYNAMIC-BOUNDS-SAFE` | bounds変更とsame-seed retry test | `FACT` |
| 23 | monster turn | live bounds/MapCellで移動 | `DYNAMIC-BOUNDS-SAFE` | 負y parity回帰test | `FACT` |
| 24 | disaster/oil center | live min/maxからrandom center | `DYNAMIC-BOUNDS-SAFE` | expanded bounds seed fixture追加 | `FACT` |
| 25 | population/turn aggregation | surface cell集合を走査 | `DYNAMIC-BOUNDS-SAFE` | 4,096超の計測 | `FACT` |
| 26 | deterministic random consumers | 動的候補集合を使う | `DYNAMIC-BOUNDS-SAFE` | unresolved TurnRun中の拡張を禁止 | `FACT` |
| 27 | initialize/reset verification | ruleset bounds、3,600/16件を前提に検証 | `FIXED-60` | production拡張経路から隔離。resetで拡張しない | `FACT` |
| 28 | integration/performance fixtures | 多くが0..59/3,600/60×60のみ | `NEGATIVE-COORDINATE-RISK` | 64、-16、79、負y parityを追加 | `FACT` |

主要根拠は`product/app/Domain/Ruleset/RulesetAuthoringValidator.php:17-25,109-122`、`product/app/Domain/World/WorldBounds.php:10-23`、`product/app/Application/OceanWorldGenerator.php:24-144`、`product/app/Application/MapChunkService.php:14-49`、`product/database/migrations/2026_07_26_020000_replace_axial_coordinates_with_staggered_xy.php:19-55,143-158`、`product/resources/js/state/mapState.ts:34-40,53-80,113-168`、`product/tests/Feature/WorldInitializationTest.php:27-55`である。

## 7. 負座標対応matrix

| 層 | `x=-1/-16/-17` | `y=-1/-16/-17` | 判定 | blocker/不足 | 根拠種別 |
|---|---|---|---|---|---|
| DB column/index | signed integerで保存可能 | 同左 | 対応済み | local範囲/所属chunkのDB CHECKは未実装 | `FACT` |
| chunk変換 | `-1→chunk -1/local15`, `-16→-1/0`, `-17→-2/15` | 同左 | 対応済み | backend unit testはある | `FACT` |
| neighbor/distance | signed cube変換 | absolute y parityをfloorMod | 対応済み | 負yの全direction fixture不足 | `FACT` |
| backend route | negative chunk pathを受理 | 同左 | 対応済み | authorization/empty/generated E2E不足 | `FACT` |
| capital placement | SQL `FLOOR((y+1)/2.0)` | signed候補範囲 | source上対応 | 負bounds Worldの登録testなし | `FACT` |
| missile/monster/disaster | live min/max参照 | live min/max参照 | source上対応 | expanded negative Worldのsame-seed fixtureなし | `FACT` |
| frontend chunk loader | `floorDiv()` | `floorDiv()` | 対応済み | same MapSpace bounds更新cacheがpartial | `FACT` |
| frontend projection | signed x | `floorMod(y,2)` | 対応済み | negative viewport/component test不足 | `FACT` |
| World bounds contract | minX<0を拒否 | minY<0を拒否 | 未対応 | blocker N-1 | `FACT` |
| initial generator | ruleset初期範囲との差を拒否 | 同左 | 未対応 | blocker N-2 | `FACT` |

`INFERENCE` 負座標blockerは4群である。

1. `FACT` N-1: `WorldBounds`が負のminを明示的に拒否する（`product/app/Domain/World/WorldBounds.php:10-18`）。
2. `FACT` N-2: initial ruleset boundsとlive boundsを同一視するgenerator/init contractが、拡張済みWorldをreset-requiredとして扱う（`product/app/Application/OceanWorldGenerator.php:53-88`）。
3. `FACT` N-3: chunk completeness、expansion provenance、MapSpace revisionがなく、負方向追加cellの公開完了を表せない。
4. `FACT` N-4: DB→service→turn→API→Frontendを横断する負x/負yのproduction-shaped E2Eがない。現在のunit testだけでは`up`拡張をreleaseできない。

`INFERENCE` published rulesetの`initial_x/y_min=0`は変更すべきblockerではない。初期生成契約としてimmutableのまま残し、live `map_spaces` boundsを扱う別contractを作るのが安全である。

## 8. 60→64 partial chunk risk

### 正確な差分

| 項目 | 0..59 | 0..63 | 差 | 根拠種別 |
|---|---:|---:|---:|---|
| 幅×高さ | 60×60 | 64×64 | 各軸+4 | `OWNER-DIRECTION` |
| cell数 | 3,600 | 4,096 | +496 | `FACT` |
| chunk row数 | 4×4=16 | 4×4=16 | 0 | `FACT` |
| 右端non-corner 3 chunk | 各12×16 | 各16×16 | 3×64=192 cell | `FACT` |
| 下端non-corner 3 chunk | 各16×12 | 各16×16 | 3×64=192 cell | `FACT` |
| 右下corner chunk | 12×12=144 | 16×16=256 | +112 cell | `FACT` |

- `FACT` 初回拡張では新しいchunk rowを作らず、既存7 edge chunkへcellを追加する。このため「chunk rowが存在する=完成」は成立しない。
- `FACT` 現行`MapChunkService`はchunk rowがあれば、返したcell数にかかわらず`state=generated`を返す（`product/app/Application/MapChunkService.php:14-49`）。
- `FACT` 現行のedge chunkは0..59というcurrent boundsに対しては正しく完成済みである。完成条件は常に256ではなく、`chunk rectangle ∩ published current bounds`の期待cell集合である。
- `FACT` 同一chunkにinitial generator由来cellとexpansion由来cellが混在するため、単一の`map_chunks.generator_id/version/seed`を上書きすると監査provenanceを失う。
- `FACT` clientは`loadedChunks`/`confirmedEmptyChunks`を保持する。MapSpace IDが同じままboundsだけ変わった場合の自動invalidate契約はない（`product/resources/js/state/mapState.ts:53-80,113-168`）。
- `INFERENCE` 最初の496セルは単一DB transactionで十分小さい可能性が高いが、実測なしに断定しない。後続の16幅拡張と同じoperation contractを使うべきである。

### 将来のidempotent expansion contract

1. `INFERENCE` `world_expansion_operations`をforward-only migrationで追加し、`world_id/map_space_id`、idempotency key、sequence、direction、before/target bounds、generator ID/version/seed、status、expected/inserted/verified cell数、started/completed、failureを保存する。
2. `INFERENCE` 共通World mutation lockを最初に取得し、同じWorldのturn、登録、拡張、放棄と直列化する。
3. `FACT` `pending/running/failed/blocked` TurnRunがあれば拡張をfail closedにする。cell集合が変わるとsame-target-turn/same-ruleset/same-seed retryのrandom候補集合が変わるためである（`product/app/Console/Commands/ReleasePreflight.php:39-43`; `product/app/Application/TurnRunner.php:156-176`）。
4. `INFERENCE` before boundsがoperation記録と完全一致することをlock下で検証する。現在値からdirectionを推測して進めない。
5. `INFERENCE` target rectangleの不足座標だけを決定的に列挙し、各座標からsigned chunk/localを導出する。既存cellやpublished payloadを上書きしない。
6. `INFERENCE` 新cellはownerなし、population 0、facilityなしで生成し、terrain/seed contractはowner決定済みgeneratorへ固定する。
7. `INFERENCE` 期待496座標、重複0、欠損0、chunk所属、provenanceを検証し、touched chunk versionを増やす。operation-level provenanceを正本とし、既存chunkのinitial provenanceを上書きしない。
8. `INFERENCE` `map_spaces` boundsは全cell検証後に最後に更新し、operationをcompletedにする。同一transactionならpartial stateは外から見えない。
9. `INFERENCE` 同一idempotency keyのretryはcompleted後条件を再検証して同じ結果を返す。異なるbefore/target/seedでの再利用は拒否する。
10. `INFERENCE` APIへMapSpace revisionまたはexpansion sequenceを公開し、clientは変更検知時にloaded/empty chunk cacheをclearして再取得する。

## 9. 自動World拡張の実装gap

`FACT` `docs/architecture/registration-and-world-expansion.md:9-32,82-117`には「候補なし→拡張→再探索→原子的Nation作成」が設計されているが、同文書`149`と現行runtimeでは自動拡張がMVP外である。

| 設計要素 | architecture | 現行runtime | gap判定 | 根拠種別 |
|---|---|---|---|---|
| current boundsで候補探索 | 設計済み | MapSpace min/maxで実装済み | `IMPLEMENTED` | `FACT` |
| client requestの冪等化 | 設計済み | `nation_creation_requests`で実装済み | `IMPLEMENTED` | `FACT` |
| no-space時の拡張 | 設計済み | 一般`DomainException`で即失敗 | `NOT-IMPLEMENTED` | `FACT` |
| deterministic cell/chunk生成 | 設計済み | initial generatorのみ | `NOT-IMPLEMENTED-FOR-EXPANSION` | `FACT` |
| bounds更新後の候補再探索 | 設計済み | なし | `NOT-IMPLEMENTED` | `FACT` |
| Nation作成との原子性 | 拡張を含め同一transaction | Nation作成部分だけtransaction | `PARTIAL` | `FACT` |
| turn/registration共通lock | 設計済み | 別advisory keyかつlock順も異なる | `DEVIATES` | `FACT` |
| capacity/error分類 | 設計済み | no-space一種類 | `PARTIAL` | `FACT` |
| 16-aligned expansion | 設計済み | serviceなし。60→64 partial edgeも未設計 | `NOT-IMPLEMENTED` | `FACT` |

| blocker | 現状 | 必要な将来contract | 根拠種別 |
|---|---|---|---|
| E-1 expansion service/schema | operationもserviceもない | section 8のforward-only operation | `FACT` |
| E-2 no-space signal | `CapitalPlacementService::choose()`が一般`DomainException`を投げる | typed `NoCapitalPlacementAvailable`と再探索 | `FACT` |
| E-3 lock mismatch | turnはsession advisory `hakoniwa.turn.world.*`、登録はWorld row lock後に別xact advisory lock | 共通World mutation lockを最初に取得する同一順序 | `FACT` |
| E-4 partial chunk/provenance | row存在だけでgenerated | bounds-intersection completenessとoperation provenance | `FACT` |
| E-5 TurnRun retry | 登録flowにfailed/blocked run gateなし | pending/running/failed/blockedをfail closed | `FACT` |
| E-6 client invalidation | same MapSpaceのbounds revisionなし | revision付きrefresh | `FACT` |
| E-7 initial/current bounds混同 | rulesetとgeneratorがinitialをliveへ再適用 | immutable initialとmutable currentを分離 | `FACT` |
| E-8 production conversion | 60→64のforward operation/migrationなし | exact +496を検証する一度きりのoperation | `FACT` |

推奨される将来flowは次のとおりである。

1. `INFERENCE` client request idempotency keyを確保する。
2. `INFERENCE` 共通World mutation advisory lockを取得する。現在のようにWorld row lockを先に取らない。
3. `INFERENCE` DB transactionを開始し、World/MapSpace/registration requestをlockし、ruleset guardとTurnRun gateを検証する。
4. `INFERENCE` Capital候補を探索する。候補があれば既存登録flowを継続する。
5. `INFERENCE` typed no-spaceの場合だけ、許可された最大回数内で次のexpansion operationを実行する。
6. `OWNER-DIRECTION` 初回は0..59→0..63。その後はleft `x=-16`、down `y=79`、right `x=79`、up `y=-16`の順で16ずつ追加し、同じleft/down/right/up rotationを反復する。既存座標は変更しない。
7. `INFERENCE` expansion後に候補を再探索し、reservation、Nation、resource、Capital、Territory、Membership、audit eventまで同一transactionで確定する。
8. `INFERENCE` commit後にMapSpace revisionを返し、clientを新boundsへrefreshする。失敗時は全体rollbackし、同一keyで安全にretryする。

`UNKNOWN` 1登録で許す最大expansion回数、capacity上限、同点direction、拡張terrain/seed、lock待機時間、管理者alertは実装前owner decisionである。既存設計文書のhistorical open question（`docs/architecture/registration-and-world-expansion.md:133-150`）を暗黙に決めない。

## 10. 明示的Nation放棄の依存

### 確定しているtarget semantics

- `OWNER-DIRECTION` 本人による明示操作だけを入口とする。
- `OWNER-DIRECTION` 現在所有する全surface cellを`sea`、ownerなし、population 0、facilityなしへ変え、現在Capitalを終了する。
- `OWNER-DIRECTION` Nationをinactive/sunken stateへ移すが、User、Nation identity、履歴、auditを物理削除しない。旧領土や旧Capitalは復元しない。
- `OWNER-DIRECTION` 解放された海は将来の新Nation配置で再利用できる。
- `OWNER-DIRECTION` major news本文は正確に `「○○島は破棄され、忘れ去られた。」` とする。
- `FACT` architecture上の`sunken_archived`も、現領土/首都を消しidentity/historyを残す（`docs/architecture/nation-lifecycle.md:61-72`）。ただしruntime lifecycle operationは未実装である。

### 依存matrix

| 領域 | 現行 | 放棄時に必要な将来処理 | 判定/根拠種別 |
|---|---|---|---|
| 本人確認 | APIなし。B-14は初期公開で未提供 | reauth、国家名確認、待機/取消、予定表示、cooldown | `UNKNOWN` |
| lifecycle state | `nations.state` stringのみ | state transition/history/idempotency fieldsまたはoperation table | `FACT` |
| World排他 | turn lockは存在、lifecycle未共有 | turn/登録/拡張/放棄の共通lock | `FACT` |
| TurnRun | running turn中はintent/stateが存在 | pending/running/failed/blocked時fail closed | `INFERENCE` |
| territory cells | current rowが唯一の所有正本 | owner=null、sea、facility=null、population=0、version/chunk version更新 | `OWNER-DIRECTION` |
| territory history | 専用tableなし | cleanup前にcurrent ownership interval/snapshotをforward-only保存 | `FACT` |
| Capital | `nation_capitals`がcurrentと事実上の履歴を兼用 | old coordinateをhistoryへ保存後、current relationを終了 | `FACT` |
| queued commands | rowをcancelledにでき、audit/version更新あり | queued全件を一括cancelし、abandonment reasonを監査 | `INFERENCE` |
| missile launch intents | running TurnState内の一時状態 | 共通lockとTurnRun gateで存在しない時だけ実行 | `FACT` |
| monsters | instance/occupancyが別table、rewardなしremoval経路あり | 所有cell上のoccupancyを`removed`、reason=`nation_abandonment`、報酬/kill statなし | `INFERENCE` |
| messages | sunken宛の新規送信は拒否するが履歴rowは残る | archived boardのread policyを決め、rowは削除しない | `FACT` |
| resource balances | Nationへ紐づき生産/消費される | frozen audit stateとして保持する案。zero/deleteはしない | `UNKNOWN` |
| Membership | `(user_id, world_id)` unique | identity保持と新Nation作成/再入植の両立方法を決める | `FACT` |
| name | `(world_id,name)` unique | archived nameを永久予約するか再利用するか決める | `UNKNOWN` |
| foreign references | Nation childにはcascade/null/restrictが混在し、messageはNation deleteをrestrict | Nation自体を削除せず、全`nation_id`参照を保持/終端/除外のいずれかへ明示分類 | `FACT` |
| rankings | 現行public rankingはstateでfilterせず全Nationを含む | current rankingからsunkenを除外し、historyは保持 | `FACT` |
| awards/kill stats | immutable/forward-only history | 保持し、current competition対象からだけ除外 | `FACT` |
| events/news | `nation.abandoned`は「○○島が消えました。」へ集約 | 指定のexact major newsとprivate/admin auditを分離 | `FACT` |
| large cleanup | checkpoint operationはdoc案のみ | stable chunk order、retry、final postcondition | `FACT` |

`INFERENCE` 現時点の放棄blockerは9群である。

1. `UNKNOWN` A-1: reauth、確認入力、待機・取消、cooldownのUX/security contract。
2. `FACT` A-2: lifecycle transition/operation schemaとidempotency keyが未実装。
3. `FACT` A-3: turn/registration/expansion/abandonmentの共通World lockとTurnRun gateが未統一。
4. `FACT` A-4: territory historyとpast Capitalを失わない保存先がない。
5. `INFERENCE` A-5: queue一括cancel、monster無報酬removal、cell cleanup、chunk versionを一つの検証可能なoperationへ束ねる契約がない。
6. `UNKNOWN` A-6: money/resource balancesを凍結保持するか、表示だけ非公開にするかが未決定。
7. `UNKNOWN` A-7: Membership、同一Userの新Nation、再入植、archived name、cooldownの整合が未決定。B-15はDeferred（`docs/open-questions.md:467-470`）。
8. `UNKNOWN` A-8: archived Nationのpublic detail/message history/current ranking表示policyが未決定。
9. `UNKNOWN` A-9: 単一transaction上限、batch移行閾値、checkpoint、operator retry/postconditionが未決定。T-02もOpen（`docs/open-questions.md:377-382`）。

`INFERENCE` 資源row、message、award、kill stat、audit event、Nation membershipは物理削除しない。production audit historyを壊さず、active gameplay queryからだけ除外するのがowner方向と最も整合する。

## 11. 推奨実装順序

| 順序 | 将来PR scope | 含めるもの | 含めないもの | 根拠種別 |
|---:|---|---|---|---|
| 1 | signed current-bounds foundation | initial/current bounds分離、負座標E2E、bounds-intersection coverage validator、共通World mutation lock contract、MapSpace revision設計 | production bounds変更、cell追加、登録変更、ruleset変更 | `INFERENCE` |
| 2 | expansion operation/service | forward-only operation schema、idempotent generator、provenance、chunk version、TurnRun gate、retry tests | Nation登録との自動連携、gameplay変更 | `INFERENCE` |
| 3 | production 60→64 | exact before SHA/data audit、+496 operation、4,096/16/7 edge chunk postcondition、client refresh | reset、published ruleset payload編集 | `OWNER-DIRECTION` |
| 4 | no-space auto expansion | typed no-space、rotation、登録transaction連携、rate/capacity/error UI | abandonment、gameplay realignment | `INFERENCE` |
| 5 | explicit abandonment | owner decisions、lifecycle operation、history、queue/monster/message/ranking処理、exact news | automatic dormancy全体、re-colonization実装 | `INFERENCE` |
| 6 | gameplay ruleset | H2 population realign、決定済み海底基地contract。必要なら防衛自爆/記念碑を別PRへ分離 | World拡張、Nation cleanup | `INFERENCE` |

- `INFERENCE` 提示された「fixed-60/negative→service→60→64→auto expansion→abandonment→gameplay」の順序は妥当である。
- `INFERENCE` defense self-destruct commandとWorld pending meteorは相互に独立し、gameplay ruleset PRへ一括しない方がreview可能である。
- `INFERENCE` 最初の次PRは順序1だけに限定する。特にpublished rulesetのinitial 0..59を緩めず、production `map_spaces`も変更しない。

## 12. 未解決owner decision

| ID | decision | 選択肢/影響 | Required before | 根拠種別 |
|---|---|---|---|---|
| G-1 | Capital人口 | 現行minimum/capを維持 / H2集落と同じにする。H2にCapital根拠なし | population ruleset公開 | `UNKNOWN` |
| G-2 | 海底基地exp | H2/H2＋完全一致 / 獲得源や量を限定 / 現行維持 | seabed ruleset公開 | `UNKNOWN` |
| G-3 | 海底基地耐性 | 全非LD missile無効 / 種類別 / 現行維持 | seabed ruleset公開 | `UNKNOWN` |
| G-4 | 海底基地建設 | 現行の深海+自領3hexを維持する有力案 / H2＋の浅瀬も許可 / H2の距離なし | seabed ruleset公開 | `OWNER-DIRECTION` |
| G-5 | 火災閾値 | H2 3,100人 / H2＋・現行10,000人 | gameplay realignment scope確定 | `UNKNOWN` |
| G-6 | 防衛再建自爆 | cost、起爆phase、cancel、secret/public log | command definition公開 | `UNKNOWN` |
| G-7 | World meteor | pending model、drop turn/count/seed/target、防御、exact logs | monument command公開 | `UNKNOWN` |
| E-1 | expansion capacity | 1登録あたり最大回数、World上限、operator alert | auto expansion実装 | `UNKNOWN` |
| E-2 | expansion terrain/provenance | 全sea / deterministic terrain、seedとgenerator version | expansion service実装 | `UNKNOWN` |
| E-3 | live refresh | MapSpace revision、poll/push、cache invalidation | 60→64 release | `UNKNOWN` |
| A-1 | abandonment confirmation | reauth、name confirmation、waiting/cancel/cooldown | abandon API実装 | `UNKNOWN` |
| A-2 | balances | money/resourcesを凍結保持し履歴表示 / zero化。削除は非推奨 | lifecycle migration | `UNKNOWN` |
| A-3 | post-abandon identity | 同Userの新Nation可否、Membership、旧名、new protection、同じnation_idを使わないか | abandon API/再登録 | `UNKNOWN` |
| A-4 | archived visibility | public detail、message、ranking history、award表示 | abandon UI/API | `UNKNOWN` |
| A-5 | batch threshold | 単一transactionの計測閾値、checkpoint retry、operator command | production cleanup | `UNKNOWN` |

`FACT` B-14は「初期公開版で放棄を提供しない」という意味でDecidedであり、公開後の再認証・取消期間・cooldown等を決めたものではない（`docs/open-questions.md:391-396`）。今回のowner directionでmap上の最終状態とnews文言は具体化したが、安全策gateは残る。

## 13. Exact source references

### H2 raw source（EUC-JP read-only decode）

- `_references/hakoniwa-2/hako-main.txt:186-205` — base最大経験値、land/seabed level、怪獣接触自爆設定。
- `_references/hakoniwa-2/hako-main.txt:1409-1429` — land/seabed experienceからlevelへの変換。
- `_references/hakoniwa-2/hako-turn.txt:721-824` — 防衛施設再建自爆、記念碑再建飛行。
- `_references/hakoniwa-2/hako-turn.txt:877-887` — 海底基地の深海建設、経験値0、秘密座標。
- `_references/hakoniwa-2/hako-turn.txt:928-958` — land/seabed base探索とlevel分発射。
- `_references/hakoniwa-2/hako-turn.txt:1041-1081` — 海底基地の非LD耐性とLD破壊。
- `_references/hakoniwa-2/hako-turn.txt:1096-1105` — 都市命中によるland/seabed base経験値。
- `_references/hakoniwa-2/hako-turn.txt:1421-1477` — H2人口成長、誘致、cap、集落発生20%。
- `_references/hakoniwa-2/hako-turn.txt:1485-1492` — 予約済み防衛施設自爆。
- `_references/hakoniwa-2/hako-turn.txt:1591-1621` — 怪獣接触自爆、森/記念碑による火災防止。
- `_references/hakoniwa-2/hako-turn.txt:1851-1878` — 森/記念碑による台風保護。
- `_references/hakoniwa-2/hako-turn.txt:1899-1916` — pending巨大missileのrandom落下。

### H2＋ raw source（Shift-JIS read-only decode）

- `_references/hakoniwa-2plus/extracted/turn.c:26-36` — sea edge計算のturn組込み。
- `_references/hakoniwa-2plus/extracted/map.c:191-209` — 海/SBase/World外からradius 4へsea level加算。
- `_references/hakoniwa-2plus/extracted/map.h:166-168` — sea level閾値12/24。
- `_references/hakoniwa-2plus/extracted/map.c:295-377` — 火災10,000人閾値、dead village ratio、海際度人口成長。
- `_references/hakoniwa-2plus/extracted/command.c:442-459` — 農場12/工場24の立地制限。
- `_references/hakoniwa-2plus/extracted/command.c:509-533` — 海底基地の3hex/海・深海建設。
- `_references/hakoniwa-2plus/extracted/map.c:419-448,575-615,1376-1400` — SBase発射level、経験値、50/200閾値。
- `_references/hakoniwa-2plus/extracted/map.c:491-520,587-615` — SBaseのLD破壊、非LD耐性と海表示。
- `_references/hakoniwa-2plus/extracted/map.c:840-856,947-982` — 森/記念碑の火災・台風保護。
- `_references/hakoniwa-2plus/extracted/command.c:626-660` — 既存防衛/記念碑への再建flag。
- `_references/hakoniwa-2plus/extracted/map.c:1168-1179` — legacy island deleteのowner解除と海化。random浅瀬化は今回のowner仕様には採用しない。

### 現行2S＋ gameplay

- `product/config/hakoniwa/rulesets/roadmap-pr11-v1.php:60-72`; `product/app/Application/CompleteTurnEngine.php:933-981` — sea-edge population contract/runtime。
- `product/config/hakoniwa/rulesets/roadmap-pr15-v1.php:32-38,55-60`; `product/app/Application/DisasterTurnService.php:380-410,517-538,722-739` — fire/typhoon forest/monument保護。
- `product/tests/Feature/DisasterAndOilTurnTest.php:203-219` — forest fire prevention回帰。
- `product/config/hakoniwa/rulesets/roadmap-pr22-v1.php:45-55,139-145,217-224` — seabed disguise/build/launch settings。
- `product/app/Application/MissileImpactResolver.php:66-77,214-248,340-382,418-443` — seabed発射数1、live bounds、water facility破壊。
- `product/app/Application/MonsterTurnService.php:180-223`; `product/tests/Feature/MonsterSystemTest.php:425-473` — 怪獣接触防衛自爆。
- `product/app/Application/PlayerIslandEventService.php:102,653` — `nation.abandoned`の現行major event文言。

### World bounds / expansion / negative coordinates

- `product/app/Domain/Ruleset/RulesetAuthoringValidator.php:17-25,109-122` — initial 0..59とchunk 16。
- `product/app/Domain/World/WorldBounds.php:10-23` — origin 0強制。
- `product/app/Domain/Map/ChunkCoordinateService.php:16-40`; `product/app/Domain/Map/GridCoordinate.php:31-64,117-146` — signed chunk/local、neighbor、distance。
- `product/database/migrations/2026_07_26_020000_replace_axial_coordinates_with_staggered_xy.php:19-55,143-158` — signed DB columns、FLOOR、unique/index。
- `product/app/Application/OceanWorldGenerator.php:24-144` — initial generation transaction、bounds equality、partial rerun拒否、insertOrIgnore。
- `product/app/Application/MapChunkService.php:14-49` — chunk row基準のgenerated表示とrepresentation hash。
- `product/app/Application/CapitalPlacementService.php:13-105` — dynamic bounds候補とno-space exception。
- `product/app/Application/NationCreationService.php:30-78,116-127`; `product/app/Domain/Turn/WorldTurnLock.php:14-48`; `product/app/Application/TurnRunner.php:41-91` — 登録/turnの異なるlock。
- `product/app/Console/Commands/ReleasePreflight.php:39-43`; `product/app/Application/TurnRunner.php:156-176` — unresolved TurnRun status。
- `product/routes/web.php:48-49,67-68` — negative chunk route。
- `product/resources/js/map/projection.ts:16-43`; `product/resources/js/state/mapState.ts:34-40,53-80,113-168`; `product/resources/js/components/HexMap.vue:116-122` — negative projection、bounds、chunk cache。
- `product/tests/Feature/WorldInitializationTest.php:27-55`; `product/tests/Feature/MonsterPerformanceTest.php:77-133`; `product/tests/Feature/TerritoryInfluencePerformanceTest.php:48-84` — 60×60/3,600 fixture。

### Nation lifecycle / schema / audit history

- `docs/open-questions.md:377-396,467-470` — T-02 Open、B-14の限定的Decided、B-15 Deferred。
- `docs/architecture/nation-lifecycle.md:61-85,120-167,186-201` — sunken target、確認、安全なoperation/history/batch案。
- `docs/architecture/registration-and-world-expansion.md:9-32,82-117,127-150` — atomic registration/expansion設計、再入植境界、現行MVP gap。
- `product/database/migrations/2026_07_26_000000_create_hakoniwa_schema.php:88-116,132-186` — Nation/resource/membership/map/capital/request schema。
- `product/app/Application/CommandQueueService.php:321-328` — cancel status、queue version、audit。
- `product/app/Application/MessageBoardService.php:401` — sunken宛送信拒否。
- `product/app/Application/PublicWorldService.php:55-66,127-149,172-200` — state filterなしのcurrent ranking/public fields。
- `product/app/Application/MonsterRemovalService.php:54-132` — historyを残すrewardなしremoval経路。

`FACT` 本文とこのledgerに記したsourceはすべてread-onlyで参照した。`_references/`、既存監査、公開済みruleset、runtime、schema、production stateへの変更はない。
