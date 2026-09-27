# 箱庭諸島2＋ 設定値

## 読込方式

`HakoIO::readConfigFile` は実行時カレントディレクトリの `config.cgi` をテキストで開き、失敗すれば応答なしで終了する（`hako_io.c:23-36`）。`Value::input` は `key=value`、`#` コメント、`:name` セクションを解釈する（`value.c:79-164,167-205`）。

CGI入力 `cname` が `Value::configName` を設定し、同名の `:name` セクションだけを有効化する意図である（`hako_io.c:397-401`; `Value::modeLine`, `value.c:183-193`）。同梱 `config.cgi` にはセクション行がないため全体設定として読まれる。

## `config.cgi` で変更できる値

| キー | コード既定 | 同梱値 | 用途・根拠 |
| --- | --- | --- | --- |
| `titleName` | 箱庭諸島2+ | 同じ | タイトル。`value.c:13,103-104`; `config.cgi:1-2` |
| `masterPassword` | 改行文字列 | `yourmasterpassword` | 管理者。`value.c:7,91-92`; `config.cgi:4-5` |
| `specialPassword` | 改行文字列 | `yourspecialpassword` | 特殊管理者。`value.c:8,93-94`; `config.cgi:7-8` |
| `cgiURL` | ダミーURL | 設置用プレースホルダー | CGI URL。`value.c:9,95-96`; `config.cgi:10-11` |
| `fileDir` | ダミーfile URL | 画像URLプレースホルダー | 静的ファイルBASE。`value.c:10,99-100`; `config.cgi:13-14` |
| `bbsURL` | ダミーURL | プレースホルダー | 掲示板。`value.c:11,97-98`; `config.cgi:16-17` |
| `htmlBody` | `BGCOLOR="#EEFFFF"` | 同じ | BODY属性。`value.c:12,101-102`; `config.cgi:19-20` |
| `dirName` | `data` | `data` | データディレクトリ。`value.c:15,105-106`; `config.cgi:22-23` |
| `endTurn` | 300 | 300 | 終了ターン。`value.c:19,113-114`; `config.cgi:25-26` |
| `dirMode` | `0755` | `0705`相当 | ディレクトリmodeを`%o`で読む。`value.c:16,107-108`; `config.cgi:28-29` |
| `initialAbsent` | 25 | 25 | 初期連続資金繰り数。`value.c:17,109-110`; `config.cgi:31-32` |
| `giveupTurn` | 28 | 28 | 自動放棄閾値。`value.c:18,111-112`; `config.cgi:34-35` |
| `initialMoney` | 100 | 100 | 初期資金。`value.c:20,115-116`; `config.cgi:37-38` |
| `initialFood` | 100 | 100 | 初期食料。`value.c:21,117-118`; `config.cgi:40-41` |
| `worldSize` | 60 | 60 | 固定正方形の一辺。`value.c:22,119-120`; `config.cgi:43-44` |
| `maxNumber` | 50 | 30 | 国家上限。`value.c:23,121-122`; `config.cgi:46-47` |
| `commandMax` | 20 | 20 | コマンドキュー長。`value.c:24,123-124`; `config.cgi:49-50` |
| `unitTime` | 21,600 | 21,600 | ターン秒数=6時間。`value.c:25,125-126`; `config.cgi:52-53` |
| `backUpTurn` | 4 | 4 | バックアップ周期。`value.c:26,127-128`; `config.cgi:55-56` |
| `backUpMax` | 4 | 2 | バックアップ保持数。`value.c:27,129-130`; `config.cgi:58-59` |
| `debugMode` | 1 | 0 | 手動ターンUI/入力。`value.c:28,131-132`; `config.cgi:61-62` |
| `eatenFood` | 20 | 20 | 人口当たり食料消費率/100。`value.c:29,133-134`; `config.cgi:64-65` |
| `logMax` | 8 | 8 | ログ保持数。宣言・同梱値はあるが読込分岐なし。`value.c:30,79-164`; `config.cgi:67-68` |
| `treeValue` | 5 | 5 | 木1単位売価。`value.c:31,135-136`; `config.cgi:70-71` |
| `monumentVar` | 3 | 3 | 記念碑種類数。`value.c:32,137-138`; `config.cgi:73-74` |
| `disFire` | 10 | 10 | 対象セルごとの火災/1000。`value.c:33,139-140`; `config.cgi:76-77` |
| `disEarthquake1` | 80 | 80 | 全体地震/1000/turn。`value.c:34,141-142`; `config.cgi:79-80` |
| `disEarthquake2` | 5 | 5 | 地均し時地震/1000。`value.c:35,143-144`; `config.cgi:82-83` |
| `disTsunami` | 300 | 300 | 津波/1000/turn。`value.c:36,145-146`; `config.cgi:85-86` |
| `disTyphoon` | 400 | 400 | 台風/1000/turn。`value.c:37,147-148`; `config.cgi:88-89` |
| `disMeteo` | 200 | 200 | 流星群/1000/turn。`value.c:38,149-150`; `config.cgi:91-92` |
| `disHugeMeteo` | 100 | 100 | 巨大隕石/1000/turn。`value.c:39,151-152`; `config.cgi:94-95` |
| `disEruption` | 200 | 200 | 噴火/1000/turn。`value.c:40,153-154`; `config.cgi:97-98` |
| `disMaizo` | 10 | 10 | 整地時埋蔵金/1000。`value.c:41,155-156`; `config.cgi:100-101` |
| `disMonster` | 2 | 2 | 怪獣出現の座標試行回数/turn。`value.c:42,157-158`; `config.cgi:103-104` |
| `missileReach` | 12 | 12 | 弾道以外の六角距離射程。`value.c:43,159-160`; `config.cgi:106-107` |

**確定した不一致**：`Value::logMax` と `config.cgi` の `logMax=8` は存在するが、`Value::input` の分岐に `logMax` がない（`value.c:79-164`）。したがって同梱コードでは設定を変更しても既定8のままである。

## コードへ直接埋め込まれた値

主なものをカテゴリ別に示す。

| カテゴリ | 直書き値 | 根拠 |
| --- | --- | --- |
| コマンド費用 | 全23コマンドの費用 | `costTable`, `command.c:5-35` |
| 保存上限 | 資金/食料9,999、食料余剰換金10:1 | `turn.c:81-91` |
| 施設初期/増設/上限 | 森5、農場10/+2/50、工場30/+10/100、採掘+5/200 | `command.c:438-507,626-667` |
| 都市 | 成長上限20/50/100/200、飢餓減少1..30 | `map.c:313-377` |
| 村発生 | 固定20%、隣接条件 | `map.c:321-341` |
| 油田 | 収入1,000、枯渇40/1000、探索費用倍率 | `map.c:275-290`; `command.c:320-341` |
| 防災/災害効果 | 地震1/4、暴動1/4、各半径と対象 | `map.c:840-1073` |
| ミサイル | 誤差7/19候補、基地レベル閾値、難民半数 | `map.c:438-641,1075-1105,1377-1397` |
| 怪獣 | 8種のHP、能力、経験、価値 | `monster.c:3-23` |
| 新規国家 | 半径5空地、半径2初期領、100回成長、森3、村2、山1、基地1 | `new_island.c:50-209` |
| 入力/表示 | 8,192 byte buffer、最大16項目、Cookie30日 | `hako_io.h:19-26`; `hako_io.c:243-267,452-465` |
| ログ | 履歴10行 | `hako_io.c:530-580` |
| 近傍 | 半径11まで397座標 | `map.c:5-10` |

受賞条件も `Island::getPrize` の比較値とビットへ直書きされる（`info.h:57-68`; `info.c:306-373`）。

## 管理画面またはDB設定へ移す候補

### 運用中に安全に変更しやすい設定

- ターン間隔、終了条件。
- 災害発生率と災害の有効/無効。
- 新規国家の初期資金・食料・休眠猶予。
- 国家数上限、コマンドキュー長。
- バックアップ周期・保持数、ログ保持期間。
- ミサイル射程。
- 公開表示名、静的ファイルURL、掲示板URL。

### ルールセットとして版管理すべき設定

- コマンド費用。
- 施設の初期値・増設量・上限・生産量。
- 人口成長、食料生産・消費、資金生産。
- ミサイル誤差・威力・迎撃・経験値。
- 怪獣テーブル。
- 災害範囲・対象地形・被害率。
- 初期領域生成テンプレート、国家間距離。

これらは単純な管理画面の自由入力ではなく、ルールセットID、適用開始ターン、値域検証、監査ログ、再現可能性を持たせるべきである。

### 秘密管理へ分離するもの

管理者認証情報はゲーム設定DBの平文項目にせず、ハッシュ化されたアカウント/認可とsecret管理へ移す。国家パスワードも同様である。`htmlBody` のような生HTML属性入力、URLからCookie名を組み立てる方式も廃止する（`template.c:3-25`; `hako_io.c:211-237,452-465`）。

## 動的世界との関係

`worldSize` は新作では単一の可変設定として残さない。初期範囲60×60、現在の有効境界、拡張単位、拡張条件、最大安全範囲を別概念にする。既存座標を動かさないなら、負座標の許否と各方向への拡張方針も設定/仕様として明示する必要がある。
