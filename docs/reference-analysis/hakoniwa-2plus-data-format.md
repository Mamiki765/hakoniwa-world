# 箱庭諸島2＋ データ保存形式

## 保存単位

**確定**：世界状態はデータディレクトリ以下の複数の平文 `.cgi` ファイルへ保存される。設定既定は `data`（`config.cgi:22-23`; `HakoIO::readInfoFile` / `writeInfoFile`, `hako_io.c:38-77`）。

| ファイル | 内容 | 根拠 |
| --- | --- | --- |
| `info.cgi` | 世界共通4項目 + 国家ごとの16行 | `Info::input/output`, `info.c:75-107,159-214` |
| `map.cgi` | 全世界の固定長セル列 | `Map::input/output`, `map.c:27-47`; `Land::input/output`, `map.c:1233-1255` |
| `command<ID>.cgi` | 対象国家のコマンドキュー | `HakoIO::readComFile/writeComFile`, `hako_io.c:79-114`; `Command::input/output`, `command.c:62-71` |
| `logfileN.cgi` | ターンログ、9整数/行 | `HakoIO::logOutput`, `hako_io.c:488-504` |
| `loghis.cgi` | 発見・放棄・消滅・改名履歴 | `HakoIO::hisOutput`, `hako_io.c:506-528` |

## `map.cgi`

### 行・セル順

`y=0..worldSize-1` を外側、`x=0..worldSize-1` を内側に出力し、各行末に改行を1つ置く（`Map::output`, `map.c:27-35`）。配列添字は `y * worldSize + x`（`Map::getLand`, `map.c:89-97`）。同梱設定 `worldSize=60` では、各行300文字 + 改行、60行である。

### 1セルの固定長表現

1セルは **5文字の小文字16進数** であり、「4桁16進数」ではない。

| 位置 | 長さ | 意味 | C型 | 表現上の範囲 |
| --- | ---: | --- | --- | ---: |
| 1 | 1 | `kind` 地形種別 | `unsigned char` | 出力上1桁を前提に0..15 |
| 2-3 | 2 | `param` 地形固有値 | `unsigned char` | 0..255 |
| 4-5 | 2 | `owner` 所有者順位 | `unsigned char` | 0..255 |

書式は `sprintf(..., "%x%02x%02x", kind, param, owner)`（`Land::output`, `map.c:1233-1237`）、読込も1+2+2文字固定（`Land::input`, `map.c:1240-1255`）。`kind` が16以上になると1桁前提が崩れるが、現行地形IDは0x0..0xbである（`map.h:136-150`）。

`owner=0` は中立、1以上は永続IDではなく、そのターン時点の人口順位で並ぶ `Info::islands` の1始まり添字である（`Info::getIsland`, `info.c:116-122`; `Land::changeOwner`, `map.c:1368-1374`）。人口順ソート後、全セルのownerを変換する（`Info::sortIslands`, `info.c:41-63`; `turn.c:124-131`）。

`flag` と `seaLevel` は一時値で保存しない。読込時に0へ戻す（`map.h:171-179`; `Land::input`, `map.c:1252-1254`）。

## `info.cgi`

先頭4行は次の順序で、いずれも10進テキストである（`Info::input/output`, `info.c:75-107`）。

1. `turn`
2. `lastTime`（Unix時刻を `time_t` へ格納）
3. `totalNumber`
4. `nextID`

続いて国家ごとに16行を出す（`Island::output`, `info.c:159-178`; `Island::input`, `info.c:181-214`）。

| 順 | 項目 | 意味 | 備考 |
| ---: | --- | --- | --- |
| 1 | `name` | 国家/島名 | `char[32]` |
| 2 | `id` | 不変ID | コマンドファイル名にも使用 |
| 3-4 | `centerX`, `centerY` | 表示・攻撃等の中心 | 首都施設ではない |
| 5 | `prize` | 受賞ビット集合 | `info.h:57-68` |
| 6 | `monster` | 討伐怪獣ビット集合 | 種類ごとのビット |
| 7 | `absent` | 連続資金繰り数 | 自動放棄に使用 |
| 8 | `comment` | コメント | `char[100]` |
| 9 | `password` | 平文パスワード | 現代化時は廃止必須 |
| 10-11 | `money`, `food` | 資金・食料 | ターン末に最大9,999 |
| 12-16 | `pop`, `area`, `farm`, `factory`, `mountain` | マップから再集計する派生値 | `Map::estimate` が再計算 |

構造体定義は `info.h:70-98`、派生値の初期化と集計は `Island::clear`, `info.c:247-254` および `Land::estimate`, `map.c:1293-1326`。

## `command<ID>.cgi`

`commandMax` 行を持ち、各行は空白区切りの5整数である。

```text
kind target x y amount
```

根拠は `Com::input/output`（`command.c:110-117`）。同梱設定では20行（`config.cgi:49-50`）。先頭コマンドだけを各ターンに実行し、通常は左詰めして末尾へ資金繰りを補充する。複数回指定の建設等は `amount` を減らして先頭へ残る（`Command::exec`, `command.c:74-103`; `Com::buildCommand`, `command.c:689-697`）。

## ログ

`logfileN.cgi` の1行は次の9整数である（`HakoIO::logOutput`, `hako_io.c:488-504`）。

```text
kind secret mainIsland subIsland commandKind x y landValue amount
```

`secret` は0=公開、1=主関連国だけ、2=主関連国以外だけ（`hako_io.h:70-76`）。`loghis.cgi` は `turn kind name name2`（`hako_io.c:506-528`）。通常ログは `logMax` 世代、履歴ログは直近10行へ切り詰める（`hako_io.c:530-580,582-591`）。

## 値の単位と上限

- セルの `param` と `owner` は1 byteで0..255（`map.h:171-179`）。
- 都市人口 `param` は100人単位で、初期値5は500人。通常成長は明示的に200へ制限されるため1セル20,000人（`new_island.c:153-169`; `Map::process`, `map.c:343-377`）。保存形式自体は255まで表現できる。
- 森は5=500本で始まり、200まで成長する（`new_island.c:136-150`; `map.c:382-387`）。
- 農場は初回10、増設+2、上限50。工場は初回30、増設+10、上限100（`command.c:438-459,626-667`）。
- 採掘場は山の `param` を+5し、上限200（`command.c:484-507`）。
- ミサイル/海底基地の経験値も200で頭打ち（`map.c:563-579,610-615`; `Land::getLevel`, `map.c:1377-1397`）。
- 資金・食料は通常の `int` で保存するが、ターン末に9,999へ制限。食料超過は10:1で資金へ変換してから資金も制限する（`turn.c:81-91`）。
- 地形IDは1 nibble内に収まる現行12種。所有者は1 byteのため保存形式上255国が限界だが、同梱設定の `maxNumber` は30（`config.cgi:46-47`; `map.h:139-150`）。
- 65,535を上限または番兵値として使う箇所は、調査対象の保存形式・ゲーム上限には確認できない。セル値は8-bit、国家集計値とIDはC++の `int` であり、16-bit固定値として設計されていない。
- `id`, `nextID`, 人口合計などの `int` には明示的な永続上限やオーバーフロー検査がない。

## 文字コード

データファイルはテキストストリームで書かれ、島名、コメント、パスワードは変換せず保存される。入力処理がShift_JIS系の2バイト境界を前提にするため、運用上の文字コードはShift_JIS/CP932互換範囲（`Util::cutColumn`, `util.c:67-97`）。HTMLもShift_JISを宣言する（`template.c:14-16`）。

## 破損時の処理

**確定**：体系的なフォーマット検証、チェックサム、スキーマ版、トランザクションはない。

- `info.cgi` が開けない場合は0を返すだけ。呼出側によっては初期状態のまま続く（`hako_io.c:38-59`）。
- `map.cgi` が開けない場合は `Info::totalNumber=0` にする（`hako_io.c:116-133`）。
- `Land::input` はEOF、行長、文字種を検証せず5文字ずつ読む（`map.c:1240-1255`）。
- `Util::hex` は小文字16進を前提にし、範囲外文字を拒否しない（`util.c:44-60`）。
- `Island::input` は最大8191 bytesの行を固定長配列へ `strcpy` するため、破損・細工された長行は境界超過の危険がある（`info.c:181-214`; `info.h:70-86`）。
- コマンド読込失敗時の復旧初期化はなく、ターン処理は読込成功を前提とする（`hako_io.c:79-97`; `turn.c:49-55`）。

したがって、バックアップからの復旧前に整合性を判定する仕組みもない。新作ではスキーマ版、長さ・範囲・参照整合性検査、チェックサムまたはDB制約、原子的保存、検証済みスナップショットが必要である。

## バックアップ形式

`data.bak0..N` はDBダンプではなく、現役データディレクトリ内の末尾 `cgi` ファイルを行単位で複製したディレクトリである（`Mentenance::slideBack`, `mentenance.c:107-151,163-175`）。復旧は現役ディレクトリ削除後のディレクトリrename（`mentenance.c:89-99`）。詳細なリスクは `hakoniwa-2plus-build.md` と `hakoniwa-2plus-open-questions.md` に分離した。
