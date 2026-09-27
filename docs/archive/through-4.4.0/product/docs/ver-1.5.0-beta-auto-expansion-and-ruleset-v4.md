# ver 1.5.0-beta 自動World拡張・World災害補正・海底基地仕様

この文書は、ver 1.5.0-beta以降のcurrent architecture / specification / operationsを記録する。
`operations/ver-1.5.0-world-expansion.md`はproductionで実施した60×60→64×64手動拡張の
point-in-time runbookであり、その当時の境界は書き換えない。

## 新規Nation登録時の自動World拡張

`NationCreationService`は共通の`WorldMutationLock`を取得した後、Nation作成transaction内で
通常の`CapitalPlacementService`候補検索を行う。候補が1件以上あればWorldを変更しない。
候補が0件の場合だけ、current signed boundsから正規の次boundsを導出し、
`WorldExpansionService`で拡張してから候補を一度だけ再取得する。

- 64×64 `0..63 × 0..63`の次はLEFTで、`-16..63 × 0..63`となる。
- 追加されるのは4 chunks、1,024 cellsで、合計20 chunks、5,120 cellsとなる。
- 以後はLEFT→UP→RIGHT→DOWNを繰り返す。rotation位置専用のDB stateは持たない。
- boundsは64×64を基準とした各方向16 cells単位の拡張数へ分解し、完了cycle数と
  途中step数が正規sequenceと一致する場合だけ次方向を決める。不正なboundsはrepairせず失敗する。
- 60×60だけは、未充足の既存4×4 chunksを64×64まで埋める処理と最初のLEFT 1chunk帯を
  同一transactionで行い、直接`-16..63 × 0..63`にする。60→64だけで成功扱いにはしない。
- 一回の登録要求で自動拡張は一度だけである。再検索でも候補0件ならinvariant違反として
  Nation作成・bounds・cells・chunks・auditをすべてrollbackする。

登録、turn、手動拡張は同じWorld advisory lock keyで直列化する。拡張前にはcurrent ruleset、
未解決TurnRun、current coverage、expected-before boundsを再検証し、bounds更新をcomplete coverageの
publication markerとする。runtime GETにcoverage full scanは追加しない。bounds revision変更時の既存
frontend cache invalidationによりowner/public mapの再取得対象にはnegative chunkも含まれる。
拡張成功時は詳細な`world.expanded` admin監査とは別に、bounds・actor・reasonを含まない
`world.expanded_public`を同一transactionで1件記録し、重大ニュースへ
「大きな地響きが鳴り響き、世界がより広くなりました」と表示する。no-op retryやrollback時は記録しない。
登録APIはclient生成UUIDを既存`nation_creation_requests.request_key`へ保存する。完了応答を失って
同じkeyで再送した場合は、候補検索・拡張・生成を繰り返さず同じNationを返す。別user/Worldでの
key再利用、またはtransaction外に残り得ない未完了statusはfail closedする。

## World全体型災害の面積補正

補正対象はruntime上でWorldごとにtriggerと中心座標を選ぶ次の6種だけである。

- 地震 (`earthquake`)
- 津波 (`tsunami`)
- 台風 (`typhoon`)
- 流星群 (`meteor_shower`)
- 巨大隕石 (`huge_meteor`)
- 噴火 (`eruption`)

補正係数は仕様どおり`16 × chunk_count ÷ 225`とする。各災害について、係数の整数部の回数だけ
既存の発生抽選を行い、端数は専用のarea-fraction random streamで一度gateした後、通過時だけ
既存発生抽選をもう一回行う。これにより100%を超える期待値を複数発生機会として表現し、
期待発生数を正確に維持する。同じ災害が同一turnに複数回発生する場合がある。

災害種の順、整数機会の順、端数機会を最後にする順序は固定する。既存trigger/center/effect streamは
そのまま使い、端数gateだけ独立labelとする。同じTurnRun、ruleset、seed、boundsのrollback retryは
同じ機会数、trigger、中心、effectを再現する。中心はpaddingを含むcurrent signed bounds全体から選び、
effectは既存のradius/ring/neighborとbounds clippingを使う。

補正しないものは、Nation単位の地盤沈下・自然怪獣出現、cell単位の火災・飢餓暴動・油田枯渇・
人口処理、command起因の地ならし地震・埋蔵金・油田探索、shot単位のミサイル偏差、monsterの
移動・派遣、territory influence、防衛施設自爆である。これらはWorld面積と独立するか、既に
Nation/cell/actor/shotごとの発生機会を持つため追加補正しない。

なお60×60と64×64はいずれも4×4=16 chunksなので、明示されたchunk-count式では両方とも
係数`256/225`となる。実cell数へ読み替えない。

## ruleset v4と海底基地

新規gameplay contractは`hakoniwa-2s-plus-v4`として公開する。v1–v3のpublished payloadは変更しない。
forward-only migrationは、未解決の次turn非dry TurnRunがないこと、v3/v4以外に接続されていないこと、
command・monster definition集合とlive参照が一致することを確認してからshared-worldをv4へ移す。
既存海底基地の経験値`NULL`は0へ変換する。想定外の既存値やmetadataは推測せずtransaction全体を失敗する。

海底基地の経験値、level、1turnの発射可能数は次のとおりである。

| 経験値 | Lv | 発射可能数 |
|---:|---:|---:|
| 0–49 | 1 | 1 |
| 50–199 | 2 | 2 |
| 200以上 | 3 | 3 |

初期経験値は0、最大値は200である。通常ミサイル基地と海底基地は共通の経験値加算serviceと
level/capacity規則を使う。owner mapだけが経験値・Lv・発射可能数を返す。public mapでは従来どおり
中立深海へ偽装し、facility、owner、経験値、level、capacityを公開しない。建設条件は深海かつ
自国領域から3hex以内のまま変更しない。

H2+準拠の経験値契約は次のとおりである。

この命中経験値契約は海底基地だけでなく、v4の通常ミサイル基地にも適用する。v3までの通常基地は
怪獣final blowだけが経験値経路だったため、これはownerが選択したH2+準拠の明示的なgameplay変更である。

- 通常・PP・SPPミサイルが村・町・都市へ有効命中した場合、命中前人口÷2,000（端数切捨て）。
- Capitalは実際の人口減少量×2÷2,000（端数切捨て）。最低人口で減少0なら経験値も0。
- 怪獣へのdamageだけでは0。final blow時だけmonster definition既存値を加算する。
- 陸地破壊弾の都市・Capital命中は経験値対象外である。

海底基地は通常・PP・SPPミサイルを無効化する。陸地破壊弾だけが海底基地を破壊できる。
この耐性は海底油田など他の水上施設へは適用しない。

## 配備時の停止条件

migration前に既存release preflightを行い、次turnの非dry TurnRunが`pending`、`running`、`failed`、
`blocked`のいずれでもないことを確認する。migrationまたは登録時自動拡張がfail closedした場合は、
直接SQL repair、World reset、同一登録要求内の追加方向拡張、失敗TurnRunの自動retryを行わず、
transaction rollback後のbounds、coverage、ruleset参照、auditを保存して調査する。
