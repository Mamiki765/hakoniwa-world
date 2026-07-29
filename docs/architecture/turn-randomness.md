# Deterministic turn randomness

## Status and scope

T-01のDecisionとして、実ターン処理が使う乱数、stable enumeration、shuffle、turn-scoped stateの共通契約を確定する。この基盤自体はgameplay effectを実行せず、required phase stubをimplementedへ変更しない。

## Master seed

`turn_runs.random_seed`のlowercase 64 hex文字を、privateな256-bit master seedの正本とする。

- seedはturn実行前にプレイヤーへ公開しない。
- failedまたはblocked runのretryは同じrowとseedを再利用する。
- 新しいtarget turnは新しいseedを生成する。
- seedの目的はretry整合性と障害調査であり、プレイヤーが結果を調整するためではない。
- random call log全件は保存しない。master seed、ruleset/input state、versioned algorithm、stable input、phase resultsを調査境界とする。

## Versioned labelled stream derivation

固定derivation versionはUTF-8文字列`hakoniwa-turn-random-stream-v1`である。master seedを32 bytesへhex decodeした値を`K`、空でないUTF-8用途labelを`L`とし、stream keyを次で派生する。

```text
stream_key = HMAC-SHA-256(
  key  = K,
  data = UTF8("hakoniwa-turn-random-stream-v1") || 0x00 || UTF8(L)
)
```

各streamは独立したcounterを0から持つ。各blockは次のとおりである。

```text
block(counter) = HMAC-SHA-256(
  key  = stream_key,
  data = counter encoded as unsigned 64-bit big-endian
)
```

blockをcounter順に連結し、4 bytesごとにunsigned 32-bit big-endian wordとして読む。counterのincrementは8 bytes上で明示的に行い、native signed integer overflowやplatform依存serializationに依存しない。`mt_rand()`、`rand()`、global RNG state、float乱数を使わない。

空label、UTF-8でないlabel、lowercase 64 hexでないseedはfail-closedとする。labelごとにstream keyとcounter stateが分離されるため、label Aへのdraw追加はlabel Bの結果を変えない。

予約済みlabelは次のとおりである。

- `development_commands:nation_order`
- `process_cells:surface_cell_order`

将来の用途例は`global_disasters:earthquake`と`global_disasters:typhoon`である。handler実装前に必要なdraw populationとlabelをtestで固定し、推測で先行追加しない。

## Inclusive bounded integer draw

対応する`min`と`max`はsigned 32-bit integerで、`min <= max`、width `max - min + 1`は`1..2^32`とする。`R = 2^32`、`W = width`、`limit = floor(R / W) * W`とする。

1. streamからunsigned 32-bit word `u`を読む。
2. `u >= limit`ならrejectして次のwordを読む。
3. `min + (u mod W)`を返す。

これによりmodulo biasを避ける。同一値だけのrangeも許し、範囲外、`min > max`、32-bitでないPHP runtimeはfail-closedとする。

## Deterministic shuffle and stable inputs

list `a[0..n-1]`に対し、`i = n - 1`から`1`まで降順に、対応streamから`j = drawInt(0, i)`を取得し、`a[i]`と`a[j]`を交換するFisher-Yatesを使う。空listと1要素listはそのまま返す。

shuffle前のstable inputと1 turnあたりの境界は次のとおりである。

| 用途 | Stable input | Label | 境界 |
|---|---|---|---|
| Nation command order | immutable Nation ID ascending | `development_commands:nation_order` | 1 turnに1回 |
| Surface cell order | surface MapSpace ID、canonical x、canonical y、MapCell ID ascending | `process_cells:surface_cell_order` | 1 turnに1回 |

ranking、DBの暗黙順、memory layout、chunk load順には依存しない。同じseed、World state、label、stable inputは同じ順列を返す。異なるlabelは同じfactory内でも独立する。

## Turn-scoped mutable state

`TurnRunner`は各attemptのgame-state transaction内で、新しい`TurnRandomStreamFactory`と`TurnState`を作り`TurnContext`から参照させる。どちらもtransaction外へ共有せず、static、global、singleton、DB persistenceを使わない。rollback後のretryはDB stateと同じmaster seedから新しく構築する。

`TurnState`はgeneric key-value bagではなく、将来のMISSILE-01に必要なlaunch intent collectionだけを持つ。intentはNation ID、missile/command definition key、canonical target x/y、非負のrequested shots、非負のremaining shotsを保持する。同じNationの複数intentを禁止しない。

`development_commands`から登録し`process_cells`からremaining shotsを消費できるAPI境界だけを提供する。この基盤ではmissile commandを接続せず、発射、課金、range/accuracy/experience/damage判定、queue item消費を行わない。

## Fixed test vector

次の値をlanguage/runtimeを越えた回帰vectorとする。

```text
master seed:
0000000000000000000000000000000000000000000000000000000000000000

label:
development_commands:nation_order

stream key:
f381c8b2819ed08236403a45b9e088204e5d128ce95880971e00a835605d6c15

counter 0 block:
af9944ad0c94d2c9786c576f4b5f1ff088ce3e69b6268b8d3492043f52300917

first unsigned 32-bit words:
2946057389, 211079881, 2020366191, 1264525296,
2295217769, 3055979405, 881984575, 1378879767

drawInt(0, 2147483647), first eight values:
798573741, 211079881, 2020366191, 1264525296,
147734121, 908495757, 881984575, 1378879767

shuffle([1,2,3,4,5,6,7,8,9,10]):
[9,3,5,4,1,6,7,8,2,10]
```
